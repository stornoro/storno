<?php

namespace App\Service\Spv;

use App\Entity\Company;
use App\Entity\SpvDocument;
use App\Enum\SpvDocumentSeverity;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\SpvDocumentRepository;
use App\Repository\SpvRequestRepository;
use App\Entity\SpvRequest;
use App\Service\NotificationService;
use App\Service\Storage\OrganizationStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns an ANAF SPV `listaMesaje` response into archived, classified
 * SpvDocument rows and alerts the company's users about the important ones.
 *
 * The listing itself and every PDF download need the qualified certificate
 * (mTLS), so they are performed by the local storno-agent; this service only
 * consumes what the agent relays and tells it which PDFs to fetch next.
 */
final class SpvDocumentIngestionService
{
    public const ANAF_BASE_URL = 'https://webserviced.anaf.ro/SPVWS2/rest';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpvDocumentRepository $repository,
        private readonly SpvRequestRepository $requestRepository,
        private readonly SpvDocumentClassifier $classifier,
        private readonly SpvDocumentSummarizer $summarizer,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly NotificationService $notificationService,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly FilesystemOperator $defaultStorage,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<array<string, mixed>> $messages raw `mesaje[]` entries from ANAF
     * @return array{created: int, skipped: int, documents: list<array{documentId: string, anafUrl: string, messageType: string}>}
     */
    public function ingest(Company $company, array $messages): array
    {
        $cif = (string) $company->getCif();
        $created = [];
        $skipped = 0;

        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $anafId = (string) ($msg['id'] ?? '');
            if ($anafId === '') {
                $skipped++;
                continue;
            }
            // The certificate may cover several CIFs; keep only this company's messages
            $msgCif = (string) ($msg['cif'] ?? '');
            if ($msgCif !== '' && $msgCif !== $cif) {
                $skipped++;
                continue;
            }
            if ($existing = $this->repository->findOneByAnafMessageId($company, $anafId)) {
                // Already archived; still close a request that was registered after the document arrived.
                $this->linkRequest($company, $existing);
                continue;
            }

            $tip = trim((string) ($msg['tip'] ?? ''));
            $class = $this->classifier->classify($tip);

            $doc = new SpvDocument();
            $doc->setCompany($company);
            $doc->setAnafMessageId($anafId);
            $doc->setMessageType($tip !== '' ? $tip : 'NECUNOSCUT');
            $doc->setCategory($class['category']);
            $doc->setSeverity($class['severity']);
            $doc->setCif($msgCif !== '' ? $msgCif : $cif);
            $doc->setDetails(isset($msg['detalii']) ? trim((string) $msg['detalii']) : null);
            $doc->setIdSolicitare(isset($msg['id_solicitare']) ? (string) $msg['id_solicitare'] : null);
            $doc->setSummary($this->summarizer->summarize($doc->getMessageType(), $doc->getDetails(), $class['category']));
            $doc->setAnafCreatedAt($this->parseAnafDate($msg['data_creare'] ?? null));

            $this->entityManager->persist($doc);
            $created[] = $doc;

            $this->linkRequest($company, $doc);
        }

        $company->setSpvDocumentsSyncedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        if ($created !== []) {
            $this->notify($company, $created);
        }

        return [
            'created' => count($created),
            'skipped' => $skipped,
            'documents' => $this->pendingDownloads($company),
        ];
    }

    /**
     * PDFs the agent should fetch (descarcare needs mTLS too).
     * @return list<array{documentId: string, anafUrl: string, messageType: string}>
     */
    public function pendingDownloads(Company $company, int $limit = 100): array
    {
        $out = [];
        foreach ($this->repository->findPendingDownload($company, $limit) as $doc) {
            $out[] = [
                'documentId' => $doc->getId()->toRfc4122(),
                'anafUrl' => self::ANAF_BASE_URL . '/descarcare?id=' . rawurlencode($doc->getAnafMessageId()),
                'messageType' => $doc->getMessageType(),
            ];
        }
        return $out;
    }

    /**
     * Store the PDF (or zip) the agent downloaded for a document.
     */
    public function storeDocumentFile(SpvDocument $doc, string $binary, ?string $contentType = null): void
    {
        $company = $doc->getCompany();
        $isPdf = str_starts_with($binary, '%PDF');
        $isZip = str_starts_with($binary, "PK\x03\x04");
        $ext = $isPdf ? 'pdf' : ($isZip ? 'zip' : 'bin');

        if (!$isPdf && !$isZip) {
            // ANAF answers HTML (logout page) when the session is gone — never archive that
            $preview = mb_substr(strip_tags($binary), 0, 200);
            $doc->setDownloadError('Raspuns neasteptat de la ANAF: ' . $preview);
            $this->entityManager->flush();
            throw new SpvNotADocumentException('ANAF did not return a PDF for message ' . $doc->getAnafMessageId());
        }

        $path = sprintf(
            'spv/%s/%s/%s.%s',
            $company->getId()->toRfc4122(),
            $doc->getCategory()->value,
            $doc->getId()->toRfc4122(),
            $ext,
        );

        // A somatie must never be lost because the customer's own bucket is
        // misconfigured: fall back to the platform storage and say so.
        $warning = null;
        try {
            $this->storageResolver->resolveForCompany($company)->write($path, $binary);
        } catch (\Throwable $e) {
            $this->logger->warning('SPV document: organization storage failed, using default storage', [
                'company' => $company->getName(),
                'error' => $e->getMessage(),
            ]);
            $this->defaultStorage->write($path, $binary);
            $warning = 'Stocarea proprie a organizatiei nu a raspuns; fisierul a fost salvat in stocarea Storno. ' . mb_substr($e->getMessage(), 0, 200);
        }

        $doc->setPdfPath($path);
        $doc->setFileName($this->buildFileName($doc, $ext));
        $doc->setFileSize(strlen($binary));
        $doc->setDownloadedAt(new \DateTimeImmutable());
        $doc->setDownloadError($warning);
        $doc->setPurgedAt(null);
        $this->entityManager->flush();
    }

    /** Where an archived file lives now: organization storage, else the platform default. */
    public function storageFor(SpvDocument $doc): FilesystemOperator
    {
        $path = (string) $doc->getPdfPath();
        try {
            $org = $this->storageResolver->resolveForCompany($doc->getCompany());
            if ($org->fileExists($path)) {
                return $org;
            }
        } catch (\Throwable) {
            // fall through
        }
        return $this->defaultStorage;
    }

    private function buildFileName(SpvDocument $doc, string $ext): string
    {
        $date = $doc->getAnafCreatedAt()?->format('Y-m-d') ?? $doc->getCreatedAt()->format('Y-m-d');
        $type = preg_replace('/[^A-Za-z0-9]+/', '-', $this->classifier->normalize($doc->getMessageType())) ?? 'document';
        return sprintf('%s_%s_%s.%s', $date, trim($type, '-'), $doc->getAnafMessageId(), $ext);
    }

    /**
     * One notification per critical/high document, one summary for the rest.
     * @param SpvDocument[] $docs
     */
    /** Answer to one of our SPV requests (solicitari): link the document and close the request. */
    private function linkRequest(Company $company, SpvDocument $doc): void
    {
        if ($doc->getIdSolicitare() === null) {
            return;
        }
        $spvRequest = $this->requestRepository->findOneByAnafRequestId($company, $doc->getIdSolicitare());
        if ($spvRequest !== null && $spvRequest->getAnswerDocument() === null) {
            $spvRequest->setAnswerDocument($doc)
                ->setStatus(SpvRequest::STATUS_ANSWERED)
                ->setAnsweredAt(new \DateTimeImmutable());
        }
    }

    private function notify(Company $company, array $docs): void
    {
        try {
            $users = $this->membershipRepository->findActiveUsersByCompany($company);
            if ($users === []) {
                return;
            }
            $companyName = $company->getName() ?? '—';
            $companyId = $company->getId()->toRfc4122();
            $now = new \DateTimeImmutable();

            $loud = array_values(array_filter($docs, static fn (SpvDocument $d) => $d->getSeverity()->rank() >= SpvDocumentSeverity::HIGH->rank()));
            $quiet = array_values(array_filter($docs, static fn (SpvDocument $d) => $d->getSeverity() === SpvDocumentSeverity::NORMAL));

            foreach ($loud as $doc) {
                $titleParams = ['company' => $companyName, 'type' => $doc->getMessageType()];
                $messageParams = ['details' => $doc->getDetails() ?: $doc->getMessageType()];
                foreach ($users as $user) {
                    $locale = $user->getLocale() ?? 'ro';
                    $this->notificationService->createNotification(
                        $user,
                        'spv.document_received',
                        $this->translator->trans('notification.spv.document_received.title', $this->prefixParams($titleParams), 'notifications', $locale),
                        $this->translator->trans('notification.spv.document_received.message', $this->prefixParams($messageParams), 'notifications', $locale),
                        [
                            'type' => 'spv.document_received',
                            'companyId' => $companyId,
                            'companyName' => $companyName,
                            'documentId' => $doc->getId()->toRfc4122(),
                            'messageType' => $doc->getMessageType(),
                            'category' => $doc->getCategory()->value,
                            'severity' => $doc->getSeverity()->value,
                            'anafCreatedAt' => $doc->getAnafCreatedAt()?->format('d.m.Y H:i'),
                            'titleKey' => 'notification.spv.document_received.title',
                            'titleParams' => $titleParams,
                            'messageKey' => 'notification.spv.document_received.message',
                            'messageParams' => $messageParams,
                        ],
                    );
                }
                $doc->setNotifiedAt($now);
            }

            if ($quiet !== []) {
                $byType = [];
                foreach ($quiet as $doc) {
                    $byType[$doc->getCategory()->label()] = ($byType[$doc->getCategory()->label()] ?? 0) + 1;
                }
                arsort($byType);
                $summary = implode(', ', array_map(static fn ($k, $v) => "$v $k", array_keys($byType), $byType));
                $titleParams = ['company' => $companyName];
                $messageParams = ['count' => count($quiet), 'summary' => $summary];
                foreach ($users as $user) {
                    $locale = $user->getLocale() ?? 'ro';
                    $this->notificationService->createNotification(
                        $user,
                        'spv.new_documents',
                        $this->translator->trans('notification.spv.new_documents.title', $this->prefixParams($titleParams), 'notifications', $locale),
                        $this->translator->trans('notification.spv.new_documents.message', $this->prefixParams($messageParams), 'notifications', $locale),
                        [
                            'type' => 'spv.new_documents',
                            'companyId' => $companyId,
                            'companyName' => $companyName,
                            'count' => count($quiet),
                            'titleKey' => 'notification.spv.new_documents.title',
                            'titleParams' => $titleParams,
                            'messageKey' => 'notification.spv.new_documents.message',
                            'messageParams' => $messageParams,
                        ],
                    );
                }
                foreach ($quiet as $doc) {
                    $doc->setNotifiedAt($now);
                }
            }

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('SPV document notification failed', [
                'company' => $company->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ANAF's SPV service sends `data_creare` as "DDMMYYYYHHMMSS" (e.g. 31082026094216);
     * the e-Factura service uses "YYYYMMDDHHMM". Detect which one by looking at where
     * the four-digit year sits, never rely on PHP's silent month overflow.
     */
    public static function parseAnafDate(mixed $raw): ?\DateTimeImmutable
    {
        $s = preg_replace('/\D/', '', (string) $raw) ?? '';
        if (strlen($s) < 8) {
            return null;
        }
        $tz = new \DateTimeZone('Europe/Bucharest');
        $dayFirst = self::looksLikeYear(substr($s, 4, 4)) && !self::looksLikeYear(substr($s, 0, 4));
        $formats = $dayFirst
            ? ['dmYHis' => 14, 'dmYHi' => 12, 'dmY' => 8]
            : ['YmdHis' => 14, 'YmdHi' => 12, 'Ymd' => 8];
        foreach ($formats as $format => $len) {
            if (strlen($s) < $len) {
                continue;
            }
            $d = \DateTimeImmutable::createFromFormat('!' . $format, substr($s, 0, $len), $tz);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($d && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $d;
            }
        }
        return null;
    }

    private static function looksLikeYear(string $four): bool
    {
        return preg_match('/^(19|20)\d\d$/', $four) === 1;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function prefixParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $out['%' . $k . '%'] = (string) $v;
        }
        return $out;
    }
}
