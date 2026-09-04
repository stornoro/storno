<?php

namespace App\Tests\Unit;

use App\Controller\Api\V1\EmailTemplateController;
use App\Controller\Api\V1\ProformaInvoiceController;
use App\Controller\Api\V1\ReceiptController;
use App\Controller\Api\V1\RecurringInvoiceController;
use App\Entity\Company;
use App\Entity\EmailTemplate;
use App\Entity\Organization;
use App\Entity\ProformaInvoice;
use App\Entity\Receipt;
use App\Entity\RecurringInvoice;
use App\Entity\User;
use App\Manager\ProformaInvoiceManager;
use App\Manager\ReceiptManager;
use App\Manager\RecurringInvoiceManager;
use App\Repository\EmailLogRepository;
use App\Repository\EmailTemplateRepository;
use App\Repository\RecurringInvoiceRepository;
use App\Security\OrganizationContext;
use App\Service\DocumentPdfService;
use App\Service\LicenseManager;
use App\Service\ReceiptEmailService;
use App\Service\RecurringInvoiceProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Cross-tenant (IDOR) guards on the document controllers: entities loaded by
 * uuid must belong to the caller's organization, conversion sources must
 * belong to the resolved company, and email templates must belong to the
 * document's company. Runs without a kernel: collaborators are mocked and the
 * controller container only exposes token storage.
 */
class CrossTenantControllerGuardTest extends TestCase
{
    private OrganizationContext&MockObject $organizationContext;
    private LicenseManager&MockObject $licenseManager;
    private Company $ownCompany;
    private Company $foreignCompany;

    protected function setUp(): void
    {
        $this->organizationContext = $this->createMock(OrganizationContext::class);
        $this->organizationContext->method('hasPermission')->willReturn(true);
        // Only $ownCompany belongs to the caller's organization.
        $this->organizationContext->method('ownsCompany')
            ->willReturnCallback(fn (?Company $c) => $c === $this->ownCompany);

        $this->licenseManager = $this->createMock(LicenseManager::class);

        $this->ownCompany = new Company();
        $this->foreignCompany = new Company();
    }

    // -------------------------------------------------------------------------
    // Ownership check on {uuid} routes
    // -------------------------------------------------------------------------

    public function testProformaShowTreatsForeignCompanyAsNotFound(): void
    {
        $proforma = (new ProformaInvoice())->setCompany($this->foreignCompany);
        $manager = $this->createMock(ProformaInvoiceManager::class);
        $manager->method('find')->willReturn($proforma);

        $response = $this->proformaController($manager)->show('any-uuid');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'Proforma not found.'], json_decode($response->getContent(), true));
    }

    public function testProformaShowServesOwnCompanyDocument(): void
    {
        $proforma = (new ProformaInvoice())->setCompany($this->ownCompany);
        $manager = $this->createMock(ProformaInvoiceManager::class);
        $manager->method('find')->willReturn($proforma);

        $response = $this->proformaController($manager)->show('any-uuid');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEmailTemplateDeleteTreatsForeignTemplateAsNotFound(): void
    {
        $template = (new EmailTemplate())->setCompany($this->foreignCompany);
        $repository = $this->createMock(EmailTemplateRepository::class);
        $repository->method('find')->willReturn($template);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $controller = new EmailTemplateController($repository, $this->organizationContext, $entityManager, $this->licenseManager);
        $this->injectContainer($controller);

        $response = $controller->delete('any-uuid');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'Email template not found.'], json_decode($response->getContent(), true));
    }

    // -------------------------------------------------------------------------
    // Bulk endpoints skip foreign ids
    // -------------------------------------------------------------------------

    public function testRecurringBulkDeleteSkipsForeignIds(): void
    {
        $own = $this->createMock(RecurringInvoice::class);
        $own->method('getCompany')->willReturn($this->ownCompany);
        $foreign = $this->createMock(RecurringInvoice::class);
        $foreign->method('getCompany')->willReturn($this->foreignCompany);

        $manager = $this->createMock(RecurringInvoiceManager::class);
        $manager->method('find')->willReturnMap([
            ['own-id', $own],
            ['foreign-id', $foreign],
        ]);
        $manager->expects($this->once())->method('delete')->with($own);

        $this->organizationContext->method('getOrganization')->willReturn(null);

        $controller = new RecurringInvoiceController(
            $manager,
            $this->createMock(RecurringInvoiceRepository::class),
            $this->organizationContext,
            $this->createMock(RecurringInvoiceProcessor::class),
            $this->createMock(EntityManagerInterface::class),
            $this->licenseManager,
        );
        $this->injectContainer($controller);

        $response = $controller->bulkDelete($this->jsonRequest(['ids' => ['own-id', 'foreign-id']]));
        $data = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $data['deleted']);
        $this->assertSame([['id' => 'foreign-id', 'error' => 'Recurring invoice not found.']], $data['errors']);
    }

    // -------------------------------------------------------------------------
    // Conversion source must belong to the resolved company
    // -------------------------------------------------------------------------

    public function testReceiptConvertRejectsSourceFromAnotherCompany(): void
    {
        // Both companies are in the caller's org here; the source still must match the target.
        $otherOwnCompany = new Company();
        $receipt = (new Receipt())->setCompany($otherOwnCompany);

        $manager = $this->createMock(ReceiptManager::class);
        $manager->method('find')->willReturn($receipt);
        $manager->expects($this->never())->method('convertToInvoice');
        $this->organizationContext->method('resolveCompany')->willReturn($this->ownCompany);

        $response = $this->receiptController($manager)->convert('any-uuid', $this->jsonRequest([]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'Receipt not found.'], json_decode($response->getContent(), true));
    }

    // -------------------------------------------------------------------------
    // Email template must belong to the document's company
    // -------------------------------------------------------------------------

    public function testReceiptEmailRejectsTemplateFromAnotherCompany(): void
    {
        $receipt = (new Receipt())->setCompany($this->ownCompany);
        $template = (new EmailTemplate())->setCompany($this->foreignCompany);

        $manager = $this->createMock(ReceiptManager::class);
        $manager->method('find')->willReturn($receipt);
        $templateRepository = $this->createMock(EmailTemplateRepository::class);
        $templateRepository->method('find')->willReturn($template);
        $emailService = $this->createMock(ReceiptEmailService::class);
        $emailService->expects($this->never())->method('send');

        $controller = $this->receiptController($manager, $templateRepository, $emailService);
        $response = $controller->sendEmail('any-uuid', $this->jsonRequest([
            'to' => 'client@example.com',
            'templateId' => (string) $template->getId(),
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'Email template not found.'], json_decode($response->getContent(), true));
    }

    // -------------------------------------------------------------------------
    // Plan gate on PDF generation
    // -------------------------------------------------------------------------

    public function testProformaPdfIsGatedByPlan(): void
    {
        $organization = $this->createMock(Organization::class);
        $this->ownCompany->setOrganization($organization);
        $proforma = (new ProformaInvoice())->setCompany($this->ownCompany);

        $manager = $this->createMock(ProformaInvoiceManager::class);
        $manager->method('find')->willReturn($proforma);
        $this->licenseManager->method('canGeneratePdf')->with($organization)->willReturn(false);
        $pdfService = $this->createMock(DocumentPdfService::class);
        $pdfService->expects($this->never())->method('generateProformaPdf');

        $response = $this->proformaController($manager, $pdfService)->downloadPdf('any-uuid');

        $this->assertSame(402, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'PDF generation is not available on your plan.', 'code' => 'PLAN_LIMIT'],
            json_decode($response->getContent(), true),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function proformaController(ProformaInvoiceManager $manager, ?DocumentPdfService $pdfService = null): ProformaInvoiceController
    {
        $controller = new ProformaInvoiceController(
            $manager,
            $this->organizationContext,
            $pdfService ?? $this->createMock(DocumentPdfService::class),
            new NullLogger(),
            $this->licenseManager,
        );
        $this->injectContainer($controller);

        return $controller;
    }

    private function receiptController(
        ReceiptManager $manager,
        ?EmailTemplateRepository $templateRepository = null,
        ?ReceiptEmailService $emailService = null,
    ): ReceiptController {
        $controller = new ReceiptController(
            $manager,
            $this->organizationContext,
            $this->createMock(DocumentPdfService::class),
            new NullLogger(),
            $emailService ?? $this->createMock(ReceiptEmailService::class),
            $this->createMock(EmailLogRepository::class),
            $templateRepository ?? $this->createMock(EmailTemplateRepository::class),
            $this->licenseManager,
        );
        $this->injectContainer($controller);

        return $controller;
    }

    /**
     * Minimal container: token storage for getUser(), nothing else (so json() falls back to json_encode).
     */
    private function injectContainer(AbstractController $controller): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(User::class));
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn (string $id) => $id === 'security.token_storage');
        $container->method('get')->with('security.token_storage')->willReturn($tokenStorage);
        $controller->setContainer($container);
    }

    private function jsonRequest(array $body): Request
    {
        return Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    }
}
