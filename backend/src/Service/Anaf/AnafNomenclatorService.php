<?php

declare(strict_types=1);

namespace App\Service\Anaf;

use App\Entity\AnafNomenclatorEntry as Entry;
use App\Repository\AnafNomenclatorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Local mirror of ANAF's address and fiscal-office nomenclators (the codes the
 * declaration XSDs require: judet, cod_localit, cod_strada, ufisc).
 *
 * Sources (verified 2026-09-04):
 *   - counties + fiscal offices: https://webnom.anaf.ro/Nomenclatoare/api/judete/
 *   - localities:                https://www.anaf.ro/declaratii/c168/api/localitati/{judet}
 *   - streets:                   https://www.anaf.ro/declaratii/c168/api/strazi/{judet}/{localitate}
 *     (the ANAF web forms' own proxy to webnom; webnom itself answers 403 to direct calls)
 *
 * Counties and localities are synced in full; streets are fetched on first use for a
 * locality, stored, then refreshed by the weekly sync. Lookups never wait on ANAF once cached.
 */
final class AnafNomenclatorService
{
    public const JUDETE_URL = 'https://webnom.anaf.ro/Nomenclatoare/api/judete/';
    public const FORM_API_BASE = 'https://www.anaf.ro/declaratii/c168/api';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AnafNomenclatorRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function judete(): array
    {
        if ($this->repository->countChildren(Entry::KIND_JUDET, '') === 0) {
            $this->syncJudete();
        }

        return array_map(static fn (Entry $e) => $e->toArray(), $this->repository->children(Entry::KIND_JUDET, '', null, 100));
    }

    /** @return list<array<string, mixed>> */
    public function organeFiscale(string $judet): array
    {
        $judet = $this->digits($judet);
        if ($this->repository->countChildren(Entry::KIND_ORGAN_FISCAL, $judet) === 0) {
            $this->syncJudete();
        }

        return array_map(static fn (Entry $e) => $e->toArray(), $this->repository->children(Entry::KIND_ORGAN_FISCAL, $judet, null, 200));
    }

    /** @return list<array<string, mixed>> */
    public function localitati(string $judet, ?string $query = null): array
    {
        $judet = $this->digits($judet);
        if ($judet === '') {
            return [];
        }
        if ($this->repository->countChildren(Entry::KIND_LOCALITATE, $judet) === 0) {
            $this->syncLocalitati($judet);
        }

        return array_map(static fn (Entry $e) => $e->toArray(), $this->repository->children(Entry::KIND_LOCALITATE, $judet, $query, 500));
    }

    /** @return list<array<string, mixed>> */
    public function strazi(string $judet, string $localitate, ?string $query = null, int $limit = 50): array
    {
        $judet = $this->digits($judet);
        $localitate = $this->digits($localitate);
        if ($judet === '' || $localitate === '') {
            return [];
        }
        $parent = $judet . '-' . $localitate;
        if ($this->repository->countChildren(Entry::KIND_STRADA, $parent) === 0) {
            $this->syncStrazi($judet, $localitate);
        }

        return array_map(static fn (Entry $e) => $e->toArray(), $this->repository->children(Entry::KIND_STRADA, $parent, $query, $limit));
    }

    /** Counties and their fiscal offices. Returns the number of rows written. */
    public function syncJudete(): int
    {
        $data = $this->fetchJson(self::JUDETE_URL);
        if (!is_array($data) || $data === []) {
            throw new \RuntimeException('ANAF judete nomenclator unavailable');
        }
        $n = 0;
        foreach ($data as $j) {
            if (!is_array($j) || !isset($j['cod'], $j['denumire'])) {
                continue;
            }
            $code = (string) $j['cod'];
            $this->upsert(Entry::KIND_JUDET, '', $code, (string) $j['denumire'], null);
            $n++;
            foreach ((array) ($j['afp'] ?? []) as $afp) {
                if (is_array($afp) && isset($afp['arondare'], $afp['denumire'])) {
                    $this->upsert(Entry::KIND_ORGAN_FISCAL, $code, (string) $afp['arondare'], (string) $afp['denumire'], null);
                    $n++;
                }
            }
        }
        $this->flush();

        return $n;
    }

    public function syncLocalitati(string $judet): int
    {
        $judet = $this->digits($judet);
        $data = $this->fetchJson(self::FORM_API_BASE . '/localitati/' . $judet);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('ANAF localities for judet %s unavailable', $judet));
        }
        $n = 0;
        foreach ($data as $l) {
            if (!is_array($l) || !isset($l['cod'], $l['denumire'])) {
                continue;
            }
            $extra = array_filter(['siruta' => $l['siruta'] ?? null, 'codPrimarie' => $l['codPrimarie'] ?? null], static fn ($v) => $v !== null && $v !== '');
            $this->upsert(Entry::KIND_LOCALITATE, $judet, (string) $l['cod'], (string) $l['denumire'], $extra ?: null);
            $n++;
        }
        $this->flush();

        return $n;
    }

    public function syncStrazi(string $judet, string $localitate): int
    {
        $judet = $this->digits($judet);
        $localitate = $this->digits($localitate);
        $data = $this->fetchJson(self::FORM_API_BASE . '/strazi/' . $judet . '/' . $localitate);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('ANAF streets for %s/%s unavailable', $judet, $localitate));
        }
        $items = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
        $n = 0;
        foreach ($items as $s) {
            if (!is_array($s)) {
                continue;
            }
            $code = (string) ($s['id'] ?? $s['cod'] ?? '');
            $name = (string) ($s['name'] ?? $s['denumire'] ?? '');
            if ($code === '' || $name === '') {
                continue;
            }
            $this->upsert(Entry::KIND_STRADA, $judet . '-' . $localitate, $code, $name, null);
            $n++;
        }
        $this->flush();

        return $n;
    }

    /** @return list<string> "judet-localitate" keys whose streets are cached */
    public function cachedStreetParents(): array
    {
        return $this->repository->cachedParents(Entry::KIND_STRADA);
    }

    /** @var array<string, Entry> rows persisted in the current sync but not yet flushed (ANAF lists repeat entries) */
    private array $pending = [];

    /** @param array<string, mixed>|null $extra */
    private function upsert(string $kind, string $parent, string $code, string $name, ?array $extra): void
    {
        $key = $kind . '|' . $parent . '|' . $code;
        $entry = $this->pending[$key] ?? $this->repository->findOneByCode($kind, $parent, $code);
        if ($entry === null) {
            $entry = (new Entry())->setKind($kind)->setParentKey($parent)->setCode($code);
            $this->entityManager->persist($entry);
            $this->pending[$key] = $entry;
        }
        $entry->setName(trim($name))->setExtra($extra)->touch();
    }

    private function flush(): void
    {
        $this->entityManager->flush();
        $this->pending = [];
    }

    private function fetchJson(string $url): mixed
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0 (compatible; Storno/1.0; +https://storno.ro)'],
                'timeout' => 30,
            ]);
            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('ANAF nomenclator answered non-200', ['url' => $url, 'status' => $response->getStatusCode()]);

                return null;
            }

            return json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            $this->logger->warning('ANAF nomenclator fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function digits(string $s): string
    {
        return preg_replace('/\D/', '', $s) ?? '';
    }
}
