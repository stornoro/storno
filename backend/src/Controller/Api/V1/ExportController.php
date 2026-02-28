<?php

namespace App\Controller\Api\V1;

use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\LicenseManager;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class ExportController extends AbstractController
{
    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly OrganizationContext $organizationContext,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('/exports/{filename}', methods: ['GET'], requirements: ['filename' => '.+\.zip'])]
    public function download(string $filename): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->organizationContext->hasPermission(Permission::EXPORT_DATA)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if ($org && !$this->licenseManager->canImportExport($org)) {
            return $this->json([
                'error' => 'Export is not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $storagePath = 'exports/' . basename($filename);

        if (!$this->defaultStorage->fileExists($storagePath)) {
            return $this->json(['error' => 'File not found.'], Response::HTTP_NOT_FOUND);
        }

        $stream = $this->defaultStorage->readStream($storagePath);

        $response = new StreamedResponse(function () use ($stream, $storagePath) {
            fpassthru($stream);
            fclose($stream);

            // Cleanup: delete the file after serving
            try {
                $this->defaultStorage->delete($storagePath);
            } catch (\Throwable) {
                // Non-critical — file will be orphaned but not break anything
            }
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', basename($filename)));

        return $response;
    }
}
