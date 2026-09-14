<?php

declare(strict_types=1);

namespace App\Service\Anaf;

use App\Entity\AnafFormVersion;
use App\Enum\DeclarationType;
use App\Repository\AnafFormVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Watches the manifest DUKIntegrator's own updater reads (versiuni.xml): one entry per ANAF form
 * with the validator (J) and PDF (P) versions. A change there means ANAF replaced the form; a
 * declaration built or validated with the old jars is rejected, so the change is recorded and
 * surfaced before anyone files. The local jar manifest (tools/duk-integrator/versiuni.xml,
 * written by update-jars.sh) tells whether this installation's validators lag behind.
 */
final class AnafFormVersionService
{
    public const DEFAULT_MANIFEST_URL = 'http://static.anaf.ro/static/10/Anaf/update5/versiuni.xml';
    /** Forms Storno builds, validates or files itself (App\Enum\DeclarationType + the assistant's). */
    public const STORNO_FORMS = ['D100', 'D101', 'D106', 'D112', 'D120', 'D130', 'D177', 'D180', 'D205', 'D208', 'D212', 'D300', 'D301', 'D311', 'D390', 'D392', 'D393', 'D394', 'D700', 'C168'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AnafFormVersionRepository $repository,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(default::DUK_VERSIUNI_URL)%')] private readonly ?string $manifestUrl = null,
        #[Autowire('%env(default::DUK_JARS_DIR)%')] private readonly ?string $jarsDir = null,
    ) {
    }

    /**
     * Parse versiuni.xml: form => [versionJ, versionP, validatorUrl, pdfUrl, historyUrl].
     * @return array<string, array{versionJ: string, versionP: string, validatorUrl: ?string, pdfUrl: ?string, historyUrl: ?string}>
     */
    public static function parseManifest(string $xml): array
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false || !isset($doc->declaratii)) {
            throw new \RuntimeException('versiuni.xml could not be parsed (no <declaratii>).');
        }
        $out = [];
        foreach ($doc->declaratii->children() as $node) {
            $form = strtoupper($node->getName());
            if (!preg_match('/^[A-Z]\d{3,4}$/', $form)) {
                continue;
            }
            $out[$form] = [
                'versionJ' => trim((string) $node->versiuneJ),
                'versionP' => trim((string) $node->versiuneP),
                'validatorUrl' => trim((string) $node->JURL) ?: null,
                'pdfUrl' => trim((string) $node->PURL) ?: null,
                'historyUrl' => trim((string) $node->DURL) ?: null,
            ];
        }

        return $out;
    }

    /**
     * Fetch the manifest and record it. Returns the forms whose version moved since the last
     * check: form => [from => [J, P], to => [J, P], storno => bool].
     * @return array<string, array{from: array{string, string}, to: array{string, string}, storno: bool}>
     */
    public function refresh(): array
    {
        $url = $this->manifestUrl ?: self::DEFAULT_MANIFEST_URL;
        $xml = $this->httpClient->request('GET', $url, ['timeout' => 60])->getContent();
        $manifest = self::parseManifest($xml);
        $rows = $this->repository->findAllIndexed();
        $changes = [];
        foreach ($manifest as $form => $v) {
            $row = $rows[$form] ?? null;
            if ($row === null) {
                $row = new AnafFormVersion($form);
                $this->em->persist($row);
            }
            $before = [$row->getVersionJ(), $row->getVersionP()];
            if ($row->observe($v['versionJ'], $v['versionP'], $v['validatorUrl'], $v['pdfUrl'], $v['historyUrl'])) {
                $changes[$form] = ['from' => $before, 'to' => [$v['versionJ'], $v['versionP']], 'storno' => in_array($form, self::STORNO_FORMS, true)];
            }
        }
        $this->em->flush();

        return $changes;
    }

    /**
     * What to show: every form with its versions, whether Storno builds it, when it changed,
     * and whether the validators installed here are behind ANAF.
     * @return array{checkedAt: ?string, forms: list<array<string, mixed>>, changedRecently: list<string>, localOutdated: list<string>}
     */
    public function overview(int $recentDays = 30): array
    {
        $local = $this->localManifest();
        $since = (new \DateTimeImmutable())->modify(sprintf('-%d days', $recentDays));
        $forms = [];
        $recent = [];
        $outdated = [];
        $checkedAt = null;
        foreach ($this->repository->findAllIndexed() as $form => $row) {
            $storno = in_array($form, self::STORNO_FORMS, true);
            $localV = $local[$form] ?? null;
            $isOutdated = $storno && $localV !== null && ($localV['versionJ'] !== $row->getVersionJ() || $localV['versionP'] !== $row->getVersionP());
            $isRecent = $row->getChangedAt() !== null && $row->getChangedAt() >= $since;
            if ($storno && $isRecent) {
                $recent[] = $form;
            }
            if ($isOutdated) {
                $outdated[] = $form;
            }
            $checkedAt = $checkedAt === null || $row->getCheckedAt() > $checkedAt ? $row->getCheckedAt() : $checkedAt;
            $forms[] = [
                'form' => $form,
                'storno' => $storno,
                'versionJ' => $row->getVersionJ(),
                'versionP' => $row->getVersionP(),
                'previousJ' => $row->getPreviousJ(),
                'previousP' => $row->getPreviousP(),
                'changedAt' => $row->getChangedAt()?->format('c'),
                'firstSeenAt' => $row->getFirstSeenAt()->format('c'),
                'localJ' => $localV['versionJ'] ?? null,
                'localP' => $localV['versionP'] ?? null,
                'localOutdated' => $isOutdated,
                'historyUrl' => $row->getHistoryUrl(),
            ];
        }

        return ['checkedAt' => $checkedAt?->format('c'), 'forms' => $forms, 'changedRecently' => $recent, 'localOutdated' => $outdated];
    }

    /** The manifest update-jars.sh saved next to the jars, i.e. the versions this installation validates with. */
    private function localManifest(): array
    {
        $dir = $this->jarsDir ?: $this->projectDir . '/tools/duk-integrator';
        $file = rtrim($dir, '/') . '/versiuni.xml';
        if (!is_file($file)) {
            return [];
        }
        try {
            return self::parseManifest((string) file_get_contents($file));
        } catch (\Throwable) {
            return [];
        }
    }
}
