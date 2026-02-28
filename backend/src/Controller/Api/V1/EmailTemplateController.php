<?php

namespace App\Controller\Api\V1;

use App\Entity\EmailTemplate;
use App\Repository\EmailTemplateRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class EmailTemplateController extends AbstractController
{
    /** Variables available for substitution in email templates. */
    private const AVAILABLE_VARIABLES = [
        '[[client_name]]',
        '[[invoice_number]]',
        '[[total]]',
        '[[due_date]]',
        '[[issue_date]]',
        '[[company_name]]',
        '[[balance]]',
        '[[currency]]',
    ];

    public function __construct(
        private readonly EmailTemplateRepository $emailTemplateRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('/email-templates', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EMAIL_TEMPLATE_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $templates = $this->emailTemplateRepository->findByCompany($company);

        // Auto-seed a default template if company has none
        if (empty($templates)) {
            $template = $this->createDefaultTemplate($company);
            $this->entityManager->persist($template);
            $this->entityManager->flush();
            $templates = [$template];
        }

        return $this->json([
            'data' => $templates,
            'availableVariables' => self::AVAILABLE_VARIABLES,
        ], context: ['groups' => ['email_template:list']]);
    }

    #[Route('/email-templates', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::EMAIL_TEMPLATE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $org = $company->getOrganization();
        if (!$this->licenseManager->canUseEmailTemplates($org)) {
            return $this->json([
                'error' => 'Email templates are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $data = json_decode($request->getContent(), true);
        $name = $data['name'] ?? null;
        $subject = $data['subject'] ?? null;
        $body = $data['body'] ?? null;

        if (!$name) {
            return $this->json(['error' => 'Field "name" is required.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$subject) {
            return $this->json(['error' => 'Field "subject" is required.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$body) {
            return $this->json(['error' => 'Field "body" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $template = new EmailTemplate();
        $template->setCompany($company);
        $template->setName($name);
        $template->setSubject($subject);
        $template->setBody($body);
        $template->setIsDefault($data['isDefault'] ?? false);

        if ($template->isDefault()) {
            $this->unsetOtherDefaults($company);
        }

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $this->json($template, Response::HTTP_CREATED, context: ['groups' => ['email_template:detail']]);
    }

    #[Route('/email-templates/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::EMAIL_TEMPLATE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $template = $this->emailTemplateRepository->find($uuid);
        if (!$template) {
            return $this->json(['error' => 'Email template not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $template->setName($data['name']);
        }
        if (isset($data['subject'])) {
            $template->setSubject($data['subject']);
        }
        if (isset($data['body'])) {
            $template->setBody($data['body']);
        }
        if (isset($data['isDefault'])) {
            $template->setIsDefault((bool) $data['isDefault']);
            if ($template->isDefault()) {
                $this->unsetOtherDefaults($template->getCompany(), $template);
            }
        }

        $this->entityManager->flush();

        return $this->json($template, context: ['groups' => ['email_template:detail']]);
    }

    #[Route('/email-templates/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::EMAIL_TEMPLATE_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $template = $this->emailTemplateRepository->find($uuid);
        if (!$template) {
            return $this->json(['error' => 'Email template not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($template);
        $this->entityManager->flush();

        return $this->json(['message' => 'Email template deleted.']);
    }

    private function unsetOtherDefaults(\App\Entity\Company $company, ?EmailTemplate $except = null): void
    {
        $templates = $this->emailTemplateRepository->findByCompany($company);
        foreach ($templates as $template) {
            if ($except && $template->getId()?->equals($except->getId())) {
                continue;
            }
            if ($template->isDefault()) {
                $template->setIsDefault(false);
            }
        }
    }

    private function createDefaultTemplate(\App\Entity\Company $company): EmailTemplate
    {
        $body = <<<'MD'
Stimate/Stimata **[[client_name]]**,

Va transmitem alaturat factura **nr. [[invoice_number]]** emisa in data de **[[issue_date]]**, in valoare totala de **[[total]] [[currency]]**.

---

### Detalii factura

- **Numar factura:** [[invoice_number]]
- **Data emitere:** [[issue_date]]
- **Data scadenta:** [[due_date]]
- **Total de plata:** [[total]] [[currency]]
- **Rest de plata:** [[balance]] [[currency]]

---

Va rugam sa efectuati plata pana la data de **[[due_date]]**.

Pentru orice intrebare legata de aceasta factura, nu ezitati sa ne contactati.

Cu stima,
**[[company_name]]**
MD;

        $template = new EmailTemplate();
        $template->setCompany($company);
        $template->setName('Sablon standard');
        $template->setSubject('Factura [[invoice_number]] - [[company_name]]');
        $template->setBody($body);
        $template->setIsDefault(true);

        return $template;
    }
}
