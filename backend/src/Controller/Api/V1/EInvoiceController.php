<?php

namespace App\Controller\Api\V1;

use App\Entity\CompanyEInvoiceConfig;
use App\Enum\EInvoiceProvider;
use App\Manager\InvoiceManager;
use App\Repository\CompanyEInvoiceConfigRepository;
use App\Repository\EInvoiceSubmissionRepository;
use App\Repository\InvoiceRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Storage\CredentialEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1')]
class EInvoiceController extends AbstractController
{
    public function __construct(
        private readonly InvoiceManager $invoiceManager,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EInvoiceSubmissionRepository $submissionRepository,
        private readonly CompanyEInvoiceConfigRepository $configRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly CredentialEncryptor $encryptor,
        private readonly ServiceLocator $connectionTesters,
    ) {}

    /**
     * Global ANAF submission stats for the last 72 hours.
     */
    #[Route('/anaf/stats', methods: ['GET'])]
    public function anafStats(): JsonResponse
    {
        $stats = $this->submissionRepository->getAnafStats72h();

        // Format timestamps as ISO 8601
        foreach (['lastSuccessAt', 'lastFailureAt', 'nextRetryAt'] as $key) {
            if ($stats[$key]) {
                $stats[$key] = (new \DateTimeImmutable($stats[$key]))->format('c');
            }
        }

        return $this->json($stats);
    }

    /**
     * List all available e-invoicing providers.
     */
    #[Route('/einvoice/providers', methods: ['GET'])]
    public function listProviders(): JsonResponse
    {
        $providers = array_map(fn (EInvoiceProvider $p) => [
            'value' => $p->value,
            'label' => $p->label(),
            'country' => $p->country(),
        ], EInvoiceProvider::cases());

        return $this->json($providers);
    }

    /**
     * Submit an invoice to an e-invoicing provider (ANAF, XRechnung, SDI, KSeF, Factur-X).
     *
     * For ANAF, delegates to the existing Romanian e-Factura submission flow.
     * For foreign providers, dispatches an async SubmitEInvoiceMessage.
     */
    #[Route('/invoices/{uuid}/submit-einvoice', methods: ['POST'])]
    public function submitEInvoice(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->invoiceRepository->findWithDetails($uuid);
        if ($invoice === null) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $company = $this->resolveCompany($request);
        if (!$company || !$invoice->getCompany()?->getId()->equals($company->getId())) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $providerValue = $data['provider'] ?? null;

        if ($providerValue === null) {
            return $this->json(['error' => 'Missing "provider" field.'], Response::HTTP_BAD_REQUEST);
        }

        $provider = EInvoiceProvider::tryFrom($providerValue);
        if ($provider === null) {
            return $this->json([
                'error' => 'Invalid provider. Valid values: ' . implode(', ', array_map(fn ($p) => $p->value, EInvoiceProvider::cases())),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            if ($provider === EInvoiceProvider::ANAF) {
                // Route through existing ANAF submission flow (unchanged behavior)
                $this->invoiceManager->submitToAnaf($invoice, $this->getUser());
            } else {
                $this->invoiceManager->submitToEInvoice($invoice, $provider, $this->getUser());
            }
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'message' => 'Invoice submitted to ' . $provider->label() . '.',
            'provider' => $provider->value,
        ]);
    }

    /**
     * List all e-invoice submissions for an invoice.
     */
    #[Route('/invoices/{uuid}/einvoice-submissions', methods: ['GET'])]
    public function listSubmissions(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->invoiceRepository->findWithDetails($uuid);
        if ($invoice === null) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $company = $this->resolveCompany($request);
        if (!$company || !$invoice->getCompany()?->getId()->equals($company->getId())) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $submissions = $this->submissionRepository->findByInvoice($invoice);

        return $this->json($submissions, context: ['groups' => ['einvoice_submission:list']]);
    }

    /**
     * List all e-invoice configs for a company.
     */
    #[Route('/companies/{uuid}/einvoice-config', methods: ['GET'])]
    public function listConfigs(string $uuid, Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company || !$company->getId()->equals(Uuid::fromString($uuid))) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $configs = $this->configRepository->findByCompany($company);

        $result = [];
        foreach ($configs as $cfg) {
            $configData = [];
            if ($cfg->getEncryptedConfig()) {
                $configData = $this->encryptor->decrypt($cfg->getEncryptedConfig());
            } elseif ($cfg->getConfig()) {
                $configData = $cfg->getConfig();
            }

            $result[] = [
                'id' => $cfg->getId()?->toRfc4122(),
                'provider' => $cfg->getProvider()->value,
                'enabled' => $cfg->isEnabled(),
                'maskedConfig' => $this->maskConfig($configData),
                'createdAt' => $cfg->getCreatedAt()->format('c'),
                'updatedAt' => $cfg->getUpdatedAt()->format('c'),
            ];
        }

        return $this->json($result);
    }

    /**
     * Create or update an e-invoice config for a company.
     */
    #[Route('/companies/{uuid}/einvoice-config', methods: ['POST'])]
    public function upsertConfig(string $uuid, Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company || !$company->getId()->equals(Uuid::fromString($uuid))) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $providerValue = $data['provider'] ?? null;

        if ($providerValue === null) {
            return $this->json(['error' => 'Missing "provider" field.'], Response::HTTP_BAD_REQUEST);
        }

        $provider = EInvoiceProvider::tryFrom($providerValue);
        if ($provider === null) {
            return $this->json([
                'error' => 'Invalid provider. Valid values: ' . implode(', ', array_map(fn ($p) => $p->value, EInvoiceProvider::cases())),
            ], Response::HTTP_BAD_REQUEST);
        }

        $config = $this->configRepository->findByCompanyAndProvider($company, $provider);
        if ($config === null) {
            $config = new CompanyEInvoiceConfig();
            $config->setCompany($company);
            $config->setProvider($provider);
            $this->entityManager->persist($config);
        }

        if (array_key_exists('enabled', $data)) {
            $config->setEnabled((bool) $data['enabled']);
        }

        if (array_key_exists('config', $data)) {
            $configPayload = $data['config'];

            // When editing, merge non-empty fields with existing config
            if ($config->getEncryptedConfig() || $config->getConfig()) {
                $existing = $config->getEncryptedConfig()
                    ? $this->encryptor->decrypt($config->getEncryptedConfig())
                    : ($config->getConfig() ?? []);

                foreach ($configPayload as $key => $value) {
                    if ($value !== '' && $value !== null) {
                        $existing[$key] = $value;
                    }
                }
                $configPayload = $existing;
            }

            $config->setEncryptedConfig($this->encryptor->encrypt($configPayload));
            $config->setConfig(null);
        }

        $this->entityManager->flush();

        $configData = [];
        if ($config->getEncryptedConfig()) {
            $configData = $this->encryptor->decrypt($config->getEncryptedConfig());
        } elseif ($config->getConfig()) {
            $configData = $config->getConfig();
        }

        return $this->json([
            'id' => $config->getId()?->toRfc4122(),
            'provider' => $config->getProvider()->value,
            'enabled' => $config->isEnabled(),
            'maskedConfig' => $this->maskConfig($configData),
            'createdAt' => $config->getCreatedAt()->format('c'),
            'updatedAt' => $config->getUpdatedAt()->format('c'),
        ]);
    }

    /**
     * Test e-invoice provider connection with given credentials.
     */
    #[Route('/companies/{uuid}/einvoice-config/test', methods: ['POST'])]
    public function testConnection(string $uuid, Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company || !$company->getId()->equals(Uuid::fromString($uuid))) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $providerValue = $data['provider'] ?? null;

        if ($providerValue === null) {
            return $this->json(['error' => 'Missing "provider" field.'], Response::HTTP_BAD_REQUEST);
        }

        $provider = EInvoiceProvider::tryFrom($providerValue);
        if ($provider === null) {
            return $this->json(['error' => 'Invalid provider.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->connectionTesters->has($provider->value)) {
            return $this->json(['error' => 'Connection testing not available for this provider.'], Response::HTTP_BAD_REQUEST);
        }

        $tester = $this->connectionTesters->get($provider->value);
        $result = $tester->test($data['config'] ?? []);

        return $this->json($result);
    }

    /**
     * Delete an e-invoice config for a company.
     */
    #[Route('/companies/{uuid}/einvoice-config/{provider}', methods: ['DELETE'])]
    public function deleteConfig(string $uuid, string $provider, Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company || !$company->getId()->equals(Uuid::fromString($uuid))) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EFACTURA_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $providerEnum = EInvoiceProvider::tryFrom($provider);
        if ($providerEnum === null) {
            return $this->json(['error' => 'Invalid provider.'], Response::HTTP_BAD_REQUEST);
        }

        $config = $this->configRepository->findByCompanyAndProvider($company, $providerEnum);
        if ($config === null) {
            return $this->json(['error' => 'Config not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($config);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }

    private function maskConfig(array $config): array
    {
        $masked = [];
        foreach ($config as $key => $value) {
            if (!is_string($value) || strlen($value) <= 4) {
                $masked[$key] = '****';
            } else {
                $masked[$key] = '****' . substr($value, -4);
            }
        }
        return $masked;
    }
}
