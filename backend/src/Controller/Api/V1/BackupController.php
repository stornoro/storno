<?php

namespace App\Controller\Api\V1;

use App\Entity\BackupJob;
use App\Message\GenerateBackupMessage;
use App\Message\RestoreBackupMessage;
use App\Repository\BackupJobRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/v1/backup')]
class BackupController extends AbstractController
{
    public function __construct(
        private readonly BackupJobRepository $backupJobRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $defaultStorage,
        private readonly MessageBusInterface $messageBus,
        private readonly SerializerInterface $serializer,
        private readonly LicenseManager $licenseManager,
    ) {}

    /**
     * POST /api/v1/backup — Create a new backup job.
     */
    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $company = $this->organizationContext->resolveCompany($request);

        if (!$this->organizationContext->hasPermission(Permission::BACKUP_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $company->getOrganization();
        if (!$this->licenseManager->canBackupRestore($org)) {
            return $this->json([
                'error' => 'Backup & restore is not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        if ($this->backupJobRepository->hasActiveJob($company)) {
            return $this->json(['error' => 'A backup or restore job is already in progress.'], Response::HTTP_CONFLICT);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $includeFiles = $payload['includeFiles'] ?? true;

        $user = $this->getUser();

        $job = new BackupJob();
        $job->setCompany($company);
        $job->setInitiatedBy($user);
        $job->setType('backup');
        $job->setStatus('pending');
        $job->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new GenerateBackupMessage(
            backupJobId: (string) $job->getId(),
            companyId: (string) $company->getId(),
            userId: (string) $user->getId(),
            includeFiles: $includeFiles,
        ));

        $data = json_decode($this->serializer->serialize($job, 'json', ['groups' => ['backup_job:detail']]), true);

        return $this->json(['job' => $data], Response::HTTP_ACCEPTED);
    }

    /**
     * GET /api/v1/backup/{id}/status — Get backup job status.
     */
    #[Route('/{id}/status', methods: ['GET'])]
    public function status(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $company = $this->organizationContext->resolveCompany($request);

        if (!$this->organizationContext->hasPermission(Permission::BACKUP_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $job = $this->backupJobRepository->find($id);
        if (!$job || (string) $job->getCompany()->getId() !== (string) $company->getId()) {
            return $this->json(['error' => 'Backup job not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($this->serializer->serialize($job, 'json', ['groups' => ['backup_job:detail']]), true);

        return $this->json(['job' => $data]);
    }

    /**
     * GET /api/v1/backup/{id}/download — Download the backup ZIP.
     */
    #[Route('/{id}/download', methods: ['GET'])]
    public function download(string $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $company = $this->organizationContext->resolveCompany($request);

        if (!$this->organizationContext->hasPermission(Permission::BACKUP_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $job = $this->backupJobRepository->find($id);
        if (!$job || (string) $job->getCompany()->getId() !== (string) $company->getId()) {
            return $this->json(['error' => 'Backup job not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($job->getStatus() !== 'completed' || !$job->getStoragePath()) {
            return $this->json(['error' => 'Backup is not ready for download.'], Response::HTTP_BAD_REQUEST);
        }

        $storagePath = $job->getStoragePath();
        if (!$this->defaultStorage->fileExists($storagePath)) {
            return $this->json(['error' => 'Backup file not found.'], Response::HTTP_NOT_FOUND);
        }

        $stream = $this->defaultStorage->readStream($storagePath);

        $response = new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $job->getFilename() ?? 'backup.zip'));

        if ($job->getFileSize()) {
            $response->headers->set('Content-Length', (string) $job->getFileSize());
        }

        return $response;
    }

    /**
     * GET /api/v1/backup/history — Paginated list of backup jobs for current company.
     */
    #[Route('/history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $company = $this->organizationContext->resolveCompany($request);

        if (!$this->organizationContext->hasPermission(Permission::BACKUP_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $limit = min((int) ($request->query->get('limit', 20)), 50);
        $jobs = $this->backupJobRepository->findByCompany($company, $limit);

        $data = json_decode($this->serializer->serialize($jobs, 'json', ['groups' => ['backup_job:list']]), true);

        return $this->json(['data' => $data]);
    }

    /**
     * POST /api/v1/backup/restore — Upload a backup ZIP and start restore.
     */
    #[Route('/restore', methods: ['POST'])]
    public function restore(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $company = $this->organizationContext->resolveCompany($request);

        if (!$this->organizationContext->hasPermission(Permission::BACKUP_MANAGE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $company->getOrganization();
        if (!$this->licenseManager->canBackupRestore($org)) {
            return $this->json([
                'error' => 'Backup & restore is not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        if ($this->backupJobRepository->hasActiveJob($company)) {
            return $this->json(['error' => 'A backup or restore job is already in progress.'], Response::HTTP_CONFLICT);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getMimeType() !== 'application/zip' && $file->getClientOriginalExtension() !== 'zip') {
            return $this->json(['error' => 'Only ZIP files are accepted.'], Response::HTTP_BAD_REQUEST);
        }

        $purgeExisting = filter_var($request->request->get('purgeExisting', 'false'), FILTER_VALIDATE_BOOLEAN);

        $user = $this->getUser();

        $job = new BackupJob();
        $job->setCompany($company);
        $job->setInitiatedBy($user);
        $job->setType('restore');
        $job->setStatus('pending');
        $job->setFilename($file->getClientOriginalName());
        $job->setFileSize($file->getSize());
        $job->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        // Store the uploaded ZIP
        $storagePath = sprintf('backups/%s/restore-%s.zip', $company->getId(), $job->getId());
        $this->defaultStorage->write($storagePath, file_get_contents($file->getPathname()));
        $job->setStoragePath($storagePath);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new RestoreBackupMessage(
            backupJobId: (string) $job->getId(),
            companyId: (string) $company->getId(),
            userId: (string) $user->getId(),
            purgeExisting: $purgeExisting,
        ));

        $data = json_decode($this->serializer->serialize($job, 'json', ['groups' => ['backup_job:detail']]), true);

        return $this->json(['job' => $data], Response::HTTP_ACCEPTED);
    }

    /**
     * GET /api/v1/backup/restore/{id}/status — Get restore job status.
     */
    #[Route('/restore/{id}/status', methods: ['GET'])]
    public function restoreStatus(string $id, Request $request): JsonResponse
    {
        return $this->status($id, $request);
    }
}
