<?php

namespace App\Controller\Api\V1;

use App\Entity\DocumentEvent;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\DocumentStatus;
use App\Manager\InvoiceManager;
use App\Message\GeneratePdfMessage;
use App\Message\GenerateZipExportMessage;
use App\Enum\InvoiceDirection;
use App\Repository\CompanyRepository;
use App\Repository\EmailLogRepository;
use App\Repository\EmailTemplateRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Export\CsvExportService;
use App\Service\Export\SagaXmlExportService;
use App\Service\Export\ZipExportService;
use App\Service\Anaf\UblValidator;
use App\Service\InvoiceXmlResolver;
use App\Service\InvoiceEmailService;
use App\Service\InvoiceShareService;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\PaymentService;
use App\Service\DocumentPdfService;
use App\Service\PdfGeneratorService;
use App\Service\LicenseManager;
use App\Service\SignatureVerifierService;
use App\Constants\Pagination;
use App\Repository\InvoiceShareTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1')]
class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly InvoiceManager $invoiceManager,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly CompanyRepository $companyRepository,
        private readonly PdfGeneratorService $pdfGenerator,
        private readonly DocumentPdfService $documentPdfService,
        private readonly SignatureVerifierService $signatureVerifier,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseManager $licenseManager,
        private readonly MessageBusInterface $messageBus,
        private readonly FilesystemOperator $defaultStorage,
        private readonly CsvExportService $csvExportService,
        private readonly SagaXmlExportService $sagaXmlExportService,
        private readonly ZipExportService $zipExportService,
        private readonly InvoiceEmailService $invoiceEmailService,
        private readonly CentrifugoService $centrifugo,
        private readonly UblValidator $ublValidator,
        private readonly InvoiceXmlResolver $xmlResolver,
        private readonly PaymentService $paymentService,
        private readonly EmailTemplateRepository $emailTemplateRepository,
        private readonly EmailLogRepository $emailLogRepository,
        private readonly InvoiceShareService $shareService,
        private readonly InvoiceShareTokenRepository $shareTokenRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/invoices', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->query->all();
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));

        $result = $this->invoiceManager->listByCompany($company, $filters, $page, $limit);

        $response = $this->json($result, context: ['groups' => ['invoice:list']]);
        $response->setMaxAge(30);
        $response->setPrivate();
        $response->setVary(['X-Company', 'Authorization']);

        return $response;
    }

    #[Route('/invoices/bulk-delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            $invoice = $this->findInvoice($id);
            if (!$invoice) {
                $errors[] = ['id' => $id, 'error' => 'Invoice not found.'];
                continue;
            }
            try {
                $this->denyAccessUnlessGranted('INVOICE_DELETE', $invoice);
                $this->invoiceManager->delete($invoice);
                $deleted++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $this->json(['deleted' => $deleted, 'errors' => $errors]);
    }

    #[Route('/invoices/bulk-cancel', methods: ['POST'])]
    public function bulkCancel(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];
        $reason = $data['reason'] ?? null;

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $cancelled = 0;
        $errors = [];

        foreach ($ids as $id) {
            $invoice = $this->findInvoice($id);
            if (!$invoice) {
                $errors[] = ['id' => $id, 'error' => 'Invoice not found.'];
                continue;
            }
            try {
                $this->denyAccessUnlessGranted('INVOICE_CANCEL', $invoice);
                $this->invoiceManager->cancel($invoice, $reason, $user);
                $cancelled++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $this->json(['cancelled' => $cancelled, 'errors' => $errors]);
    }

    #[Route('/invoices/bulk-storno', methods: ['POST'])]
    public function bulkStorno(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $eligibleStatuses = [
            DocumentStatus::ISSUED,
            DocumentStatus::SENT_TO_PROVIDER,
            DocumentStatus::VALIDATED,
            DocumentStatus::SYNCED,
        ];

        $created = 0;
        $errors = [];

        foreach ($ids as $id) {
            $invoice = $this->findInvoice($id);
            if (!$invoice) {
                $errors[] = ['id' => $id, 'error' => 'Invoice not found.'];
                continue;
            }

            try {
                $this->denyAccessUnlessGranted('INVOICE_REFUND', $invoice);

                // Must be outgoing
                if ($invoice->getDirection() !== InvoiceDirection::OUTGOING) {
                    throw new \DomainException('Only outgoing invoices can be refunded.');
                }

                // Status must be eligible
                if (!in_array($invoice->getStatus(), $eligibleStatuses, true)) {
                    throw new \DomainException(sprintf(
                        'Invoice status "%s" is not eligible for storno.',
                        $invoice->getStatus()->value,
                    ));
                }

                // Must not be cancelled
                if ($invoice->getCancelledAt() !== null) {
                    throw new \DomainException('Cancelled invoices cannot be refunded.');
                }

                // Must not itself be a refund (has a parent)
                if ($invoice->getParentDocument() !== null) {
                    throw new \DomainException('Refund invoices cannot be refunded.');
                }

                // Must not already have refund children
                $refundChildren = $this->invoiceRepository->findRefundChildren($invoice);
                if (!empty($refundChildren)) {
                    throw new \DomainException('Invoice already has a storno refund.');
                }

                // Build the storno invoice
                $storno = new Invoice();
                $storno->setCompany($invoice->getCompany());
                $storno->setClient($invoice->getClient());
                $storno->setCurrency($invoice->getCurrency());
                $storno->setSenderName($invoice->getSenderName());
                $storno->setSenderCif($invoice->getSenderCif());
                $storno->setReceiverName($invoice->getReceiverName());
                $storno->setReceiverCif($invoice->getReceiverCif());
                $storno->setParentDocument($invoice);
                $storno->setStatus(DocumentStatus::DRAFT);
                $storno->setDirection(InvoiceDirection::OUTGOING);
                $storno->setDocumentType($invoice->getDocumentType());
                $storno->setIssueDate(new \DateTime());

                $originalNumber = $invoice->getNumber() ?? (string) $invoice->getId();
                $originalDate = $invoice->getIssueDate()?->format('d.m.Y') ?? '';
                $storno->setNotes(sprintf('Storno factura #%s din %s', $originalNumber, $originalDate));

                $storno->setPaymentTerms($invoice->getPaymentTerms());
                $storno->setDeliveryLocation($invoice->getDeliveryLocation());
                $storno->setProjectReference($invoice->getProjectReference());

                // Copy options
                $storno->setTvaLaIncasare($invoice->isTvaLaIncasare());
                $storno->setPlatitorTva($invoice->isPlatitorTva());
                $storno->setPlataOnline($invoice->isPlataOnline());

                // Copy e-Factura BT fields
                $storno->setOrderNumber($invoice->getOrderNumber());
                $storno->setContractNumber($invoice->getContractNumber());
                $storno->setBuyerReference($invoice->getBuyerReference());
                $storno->setBusinessProcessType($invoice->getBusinessProcessType());
                $storno->setPayeeName($invoice->getPayeeName());
                $storno->setPayeeIdentifier($invoice->getPayeeIdentifier());
                $storno->setPayeeLegalRegistrationIdentifier($invoice->getPayeeLegalRegistrationIdentifier());

                // Assign a temporary draft number
                $storno->setNumber('DRAFT-' . substr(Uuid::v7()->toRfc4122(), 0, 8));

                // Copy lines with negated quantities
                $position = 1;
                foreach ($invoice->getLines() as $originalLine) {
                    $stornoLine = new InvoiceLine();
                    $stornoLine->setPosition($position++);
                    $stornoLine->setDescription($originalLine->getDescription() ?? '');
                    $negatedQty = bcmul($originalLine->getQuantity(), '-1', 4);
                    $stornoLine->setQuantity($negatedQty);
                    $stornoLine->setUnitOfMeasure($originalLine->getUnitOfMeasure());
                    $stornoLine->setUnitPrice($originalLine->getUnitPrice());
                    $stornoLine->setVatRate($originalLine->getVatRate());
                    $stornoLine->setVatCategoryCode($originalLine->getVatCategoryCode());
                    $stornoLine->setDiscount($originalLine->getDiscount());
                    $stornoLine->setDiscountPercent($originalLine->getDiscountPercent());
                    $stornoLine->setVatIncluded($originalLine->isVatIncluded());
                    $stornoLine->setProductCode($originalLine->getProductCode());

                    // Recalculate line totals for negated quantity
                    $qty = (float) $negatedQty;
                    $price = (float) $stornoLine->getUnitPrice();
                    $discount = (float) $stornoLine->getDiscount();

                    if ($stornoLine->isVatIncluded()) {
                        $vatRate = (float) $stornoLine->getVatRate();
                        $grossTotal = ($qty * $price) - $discount;
                        $lineNet = $grossTotal / (1 + $vatRate / 100);
                        $vatAmount = $grossTotal - $lineNet;
                    } else {
                        $lineNet = ($qty * $price) - $discount;
                        $vatAmount = $lineNet * ((float) $stornoLine->getVatRate() / 100);
                    }

                    $stornoLine->setLineTotal(number_format($lineNet, 2, '.', ''));
                    $stornoLine->setVatAmount(number_format($vatAmount, 2, '.', ''));

                    $storno->addLine($stornoLine);
                }

                // Recalculate invoice totals from lines
                $subtotal = '0.00';
                $vatTotal = '0.00';
                $discountTotal = '0.00';
                foreach ($storno->getLines() as $line) {
                    $subtotal = bcadd($subtotal, $line->getLineTotal(), 2);
                    $vatTotal = bcadd($vatTotal, $line->getVatAmount(), 2);
                    $discountTotal = bcadd($discountTotal, $line->getDiscount(), 2);
                }
                $storno->setSubtotal($subtotal);
                $storno->setVatTotal($vatTotal);
                $storno->setDiscount($discountTotal);
                $storno->setTotal(bcadd($subtotal, $vatTotal, 2));

                // Add DRAFT document event
                $event = new DocumentEvent();
                $event->setNewStatus(DocumentStatus::DRAFT);
                $event->setCreatedBy($user);
                $event->setMetadata([
                    'action' => 'storno_created',
                    'originalInvoiceId' => (string) $invoice->getId(),
                    'originalInvoiceNumber' => $originalNumber,
                ]);
                $storno->addEvent($event);

                $this->entityManager->persist($storno);
                $this->entityManager->flush();

                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $this->json(['created' => $created, 'errors' => $errors]);
    }

    #[Route('/invoices/bulk-mark-paid', methods: ['POST'])]
    public function bulkMarkPaid(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $paymentMethod = $data['paymentMethod'] ?? 'bank_transfer';
        $paidAt = $data['paidAt'] ?? null;

        if (!$this->organizationContext->hasPermission(Permission::PAYMENT_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $marked = 0;
        $errors = [];

        foreach ($ids as $id) {
            $invoice = $this->findInvoice($id);
            if (!$invoice) {
                $errors[] = ['id' => $id, 'error' => 'Invoice not found.'];
                continue;
            }
            try {
                $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

                $balance = $invoice->getBalance();
                if (bccomp($balance, '0', 2) === 0) {
                    $errors[] = ['id' => $id, 'error' => 'Invoice is already fully paid.'];
                    continue;
                }

                if (bccomp($balance, '0', 2) < 0) {
                    // Negative invoice (storno/credit note) — mark as settled directly
                    $invoice->setAmountPaid($invoice->getTotal());
                    $invoice->setPaidAt(new \DateTimeImmutable($paidAt ?? 'now'));
                    $invoice->setPaymentMethod($paymentMethod);
                    $this->entityManager->flush();
                } else {
                    $this->paymentService->recordPayment($invoice, [
                        'amount' => $balance,
                        'paymentMethod' => $paymentMethod,
                        'paymentDate' => $paidAt,
                    ], $user);
                }

                $marked++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $this->json(['marked' => $marked, 'errors' => $errors]);
    }

    #[Route('/invoices/bulk-mark-unpaid', methods: ['POST'])]
    public function bulkMarkUnpaid(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0 || count($ids) > 100) {
            return $this->json(['error' => 'Provide between 1 and 100 IDs.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->organizationContext->hasPermission(Permission::PAYMENT_DELETE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $unmarked = 0;
        $errors = [];

        foreach ($ids as $id) {
            $invoice = $this->findInvoice($id);
            if (!$invoice) {
                $errors[] = ['id' => $id, 'error' => 'Invoice not found.'];
                continue;
            }
            try {
                $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

                if (!$invoice->getPaidAt() && bccomp($invoice->getAmountPaid(), '0', 2) === 0) {
                    $errors[] = ['id' => $id, 'error' => 'Invoice has no payments.'];
                    continue;
                }

                $this->paymentService->deleteAllPayments($invoice, $user);
                $unmarked++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $this->json(['unmarked' => $unmarked, 'errors' => $errors]);
    }

    #[Route('/invoices/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $response = $this->json($invoice, context: ['groups' => ['invoice:detail']]);

        // Include refund children for refunded invoices
        $refundChildren = $this->invoiceRepository->findRefundChildren($invoice);
        if ($refundChildren) {
            $data = json_decode($response->getContent(), true);
            $data['refundInvoices'] = $refundChildren;
            $response->setContent(json_encode($data));
        }

        return $response;
    }

    #[Route('/invoices', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_CREATE)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        // Enforce monthly invoice limit
        $org = $company->getOrganization();
        $maxPerMonth = $this->licenseManager->getMaxInvoicesPerMonth($org);
        if ($maxPerMonth > 0) {
            $thisMonthCount = $this->invoiceRepository->countThisMonth($company);
            if ($thisMonthCount >= $maxPerMonth) {
                return $this->json([
                    'error' => 'Monthly invoice limit reached.',
                    'code' => 'PLAN_LIMIT',
                    'limit' => $maxPerMonth,
                ], Response::HTTP_PAYMENT_REQUIRED);
            }
        }

        $data = json_decode($request->getContent(), true);

        // Basic validation
        if (empty($data['lines']) || !is_array($data['lines'])) {
            $this->logger->warning('Invoice create: input validation failed', [
                'error' => 'At least one line is required.',
                'companyId' => (string) $company->getId(),
                'userId' => (string) $user->getId(),
            ]);
            return $this->json(['error' => 'At least one line is required.'], Response::HTTP_BAD_REQUEST);
        }

        $isRefund = !empty($data['parentDocumentId']);

        foreach ($data['lines'] as $i => $line) {
            if (empty($line['description'])) {
                $this->logger->warning('Invoice create: input validation failed', [
                    'error' => "Line $i: description is required.",
                    'companyId' => (string) $company->getId(),
                    'userId' => (string) $user->getId(),
                ]);
                return $this->json(['error' => "Line $i: description is required."], Response::HTTP_BAD_REQUEST);
            }
            $qty = (float) ($line['quantity'] ?? 0);
            if ($isRefund) {
                // Refund: quantity must be non-zero (negative for refund lines, positive for new lines)
                if ($qty == 0) {
                    $this->logger->warning('Invoice create: input validation failed', [
                        'error' => "Line $i: quantity must be non-zero.",
                        'companyId' => (string) $company->getId(),
                        'userId' => (string) $user->getId(),
                    ]);
                    return $this->json(['error' => "Line $i: quantity must be non-zero."], Response::HTTP_BAD_REQUEST);
                }
            } else {
                if ($qty <= 0) {
                    $this->logger->warning('Invoice create: input validation failed', [
                        'error' => "Line $i: quantity must be positive.",
                        'companyId' => (string) $company->getId(),
                        'userId' => (string) $user->getId(),
                    ]);
                    return $this->json(['error' => "Line $i: quantity must be positive."], Response::HTTP_BAD_REQUEST);
                }
            }
            if (!isset($line['unitPrice']) || (float) $line['unitPrice'] < 0) {
                $this->logger->warning('Invoice create: input validation failed', [
                    'error' => "Line $i: unitPrice must be non-negative.",
                    'companyId' => (string) $company->getId(),
                    'userId' => (string) $user->getId(),
                ]);
                return $this->json(['error' => "Line $i: unitPrice must be non-negative."], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $invoice = $this->invoiceManager->create($company, $data, $user);
        } catch (\Throwable $e) {
            $this->logger->error('Invoice create failed', [
                'error' => $e->getMessage(),
                'companyId' => (string) $company->getId(),
                'userId' => (string) $user->getId(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // Inline payment on create (collect parameter)
        if (!empty($data['collect'])) {
            try {
                $this->paymentService->recordPayment($invoice, [
                    'amount' => $data['collect']['value'] ?? $invoice->getTotal(),
                    'paymentMethod' => $data['collect']['type'] ?? 'bank_transfer',
                    'paymentDate' => $data['collect']['issueDate'] ?? null,
                    'reference' => $data['collect']['documentNumber'] ?? null,
                    'notes' => $data['collect']['mentions'] ?? null,
                ], $user);
            } catch (\DomainException $e) {
                // Don't fail the entire create for a payment error — invoice was already saved
            }
        }

        $this->notifyInvoiceChange($invoice, 'invoice.created');

        // Run full validation and include results in response
        $validation = $this->ublValidator->validateFull($invoice);

        return $this->json([
            'invoice' => $invoice,
            'validation' => [
                'valid' => $validation->isValid,
                'errors' => array_map(fn ($e) => $e->toArray(), $validation->errors),
                'warnings' => $validation->warnings,
                'schematronAvailable' => $this->ublValidator->isSchematronAvailable(),
            ],
        ], Response::HTTP_CREATED, [], ['groups' => ['invoice:detail']]);
    }

    #[Route('/invoices/{uuid}', methods: ['PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_EDIT', $invoice);

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        try {
            $invoice = $this->invoiceManager->update($invoice, $data, $user);
        } catch (\DomainException $e) {
            $this->logger->warning('Invoice update rejected: {error}', [
                'error' => $e->getMessage(),
                'invoiceId' => (string) $invoice->getId(),
                'invoiceNumber' => $invoice->getNumber(),
                'userId' => (string) $user->getId(),
            ]);
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->notifyInvoiceChange($invoice, 'invoice.updated');

        // Run full validation and include results in response
        $validation = $this->ublValidator->validateFull($invoice);

        return $this->json([
            'invoice' => $invoice,
            'validation' => [
                'valid' => $validation->isValid,
                'errors' => array_map(fn ($e) => $e->toArray(), $validation->errors),
                'warnings' => $validation->warnings,
                'schematronAvailable' => $this->ublValidator->isSchematronAvailable(),
            ],
        ], context: ['groups' => ['invoice:detail']]);
    }

    #[Route('/invoices/{uuid}', methods: ['DELETE'])]
    public function deleteInvoice(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_DELETE', $invoice);

        try {
            $this->invoiceManager->delete($invoice);
        } catch (\DomainException $e) {
            $this->logger->warning('Invoice delete rejected: {error}', [
                'error' => $e->getMessage(),
                'invoiceId' => (string) $invoice->getId(),
                'invoiceNumber' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
            ]);
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/invoices/{uuid}/issue', methods: ['POST'])]
    public function issue(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_ISSUE', $invoice);

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        // Validate before issuing (UblValidator logs failures internally)
        $validationResult = $this->ublValidator->validateFull($invoice);
        if (!$validationResult->isValid) {
            return $this->json([
                'error' => 'Factura contine erori de validare.',
                'valid' => false,
                'errors' => array_map(fn($e) => [
                    'message' => $e->message,
                    'source' => $e->source,
                    'ruleId' => $e->ruleId ?? null,
                    'location' => $e->location ?? null,
                ], $validationResult->errors),
                'warnings' => $validationResult->warnings,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->invoiceManager->issue($invoice, $user);
        } catch (\DomainException $e) {
            $this->logger->warning('Invoice issue rejected: {error}', [
                'error' => $e->getMessage(),
                'invoiceId' => (string) $invoice->getId(),
                'invoiceNumber' => $invoice->getNumber(),
                'userId' => (string) $user->getId(),
            ]);
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Generate and store XML + PDF immediately after issuing
        $this->generateAndStoreFiles($invoice);

        $this->notifyInvoiceChange($invoice, 'invoice.issued');

        return $this->json([
            'status' => $invoice->getStatus()->value,
            'number' => $invoice->getNumber(),
            'efacturaDelayHours' => $invoice->getCompany()?->getEfacturaDelayHours(),
            'scheduledSendAt' => $invoice->getScheduledSendAt()?->format('c'),
        ]);
    }

    #[Route('/invoices/{uuid}/submit', methods: ['POST'])]
    public function submit(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_ISSUE', $invoice);

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->invoiceManager->submitToAnaf($invoice, $user);
        } catch (\DomainException $e) {
            $this->logger->warning('Invoice submit rejected: {error}', [
                'error' => $e->getMessage(),
                'invoiceId' => (string) $invoice->getId(),
                'invoiceNumber' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
                'userId' => (string) $user->getId(),
            ]);
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->notifyInvoiceChange($invoice, 'invoice.submitted');

        return $this->json([
            'message' => 'Factura a fost trimisa la ANAF.',
            'status' => $invoice->getStatus()->value,
        ]);
    }

    #[Route('/invoices/{uuid}/validate', methods: ['POST'])]
    public function validateInvoice(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $data = json_decode($request->getContent(), true);
        $mode = $data['mode'] ?? 'quick';

        $result = $mode === 'full'
            ? $this->ublValidator->validateFull($invoice)
            : $this->ublValidator->validateQuick($invoice);

        return $this->json([
            'valid' => $result->isValid,
            'errors' => array_map(fn ($e) => $e->toArray(), $result->errors),
            'warnings' => $result->warnings,
            'schematronAvailable' => $this->ublValidator->isSchematronAvailable(),
        ]);
    }

    #[Route('/invoices/{uuid}/cancel', methods: ['POST'])]
    public function cancel(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_CANCEL', $invoice);

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? null;

        try {
            $this->invoiceManager->cancel($invoice, $reason, $user);
        } catch (\DomainException $e) {
            $this->logger->warning('Invoice cancel rejected: {error}', [
                'error' => $e->getMessage(),
                'invoiceId' => (string) $invoice->getId(),
                'invoiceNumber' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
                'userId' => (string) $user->getId(),
            ]);
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->notifyInvoiceChange($invoice, 'invoice.cancelled');

        return $this->json($invoice, context: ['groups' => ['invoice:detail']]);
    }

    #[Route('/invoices/{uuid}/restore', methods: ['POST'])]
    public function restore(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_EDIT', $invoice);

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->invoiceManager->restore($invoice, $user);
        } catch (\DomainException $e) {
            $this->logger->warning('Invoice restore rejected: {error}', [
                'error' => $e->getMessage(),
                'invoiceId' => (string) $invoice->getId(),
                'invoiceNumber' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
                'userId' => (string) $user->getId(),
            ]);
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->notifyInvoiceChange($invoice, 'invoice.restored');

        return $this->json($invoice, context: ['groups' => ['invoice:detail']]);
    }

    #[Route('/invoices/{uuid}/xml', methods: ['GET'])]
    public function downloadXml(string $uuid): Response
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $xml = $this->xmlResolver->resolve($invoice);
        if (!$xml) {
            return $this->json(['error' => 'Could not generate XML.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => sprintf('attachment; filename="invoice-%s.xml"', $invoice->getNumber()),
        ]);
    }

    #[Route('/invoices/{uuid}/anaf-response', methods: ['GET'])]
    public function downloadAnafResponse(
        string $uuid,
        \App\Service\Anaf\AnafTokenResolver $tokenResolver,
        \App\Service\Anaf\EFacturaClient $eFacturaClient,
    ): Response {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $downloadId = $invoice->getAnafDownloadId();
        if (!$downloadId) {
            return $this->json(['error' => 'No ANAF response available for this invoice.'], Response::HTTP_NOT_FOUND);
        }

        $company = $invoice->getCompany();
        $token = $tokenResolver->resolve($company);
        if (!$token) {
            return $this->json(['error' => 'No valid ANAF token. Reconnect ANAF from settings.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $content = $eFacturaClient->download($downloadId, $token);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Failed to download from ANAF: ' . $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        // ANAF returns a ZIP — detect content type
        $isZip = str_starts_with($content, "PK");
        $ext = $isZip ? 'zip' : 'xml';
        $mime = $isZip ? 'application/zip' : 'application/xml';

        return new Response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => sprintf('attachment; filename="anaf-response-%s.%s"', $invoice->getNumber(), $ext),
        ]);
    }

    #[Route('/invoices/{uuid}/signature', methods: ['GET'])]
    public function downloadSignature(string $uuid): Response
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $signature = $invoice->getSignatureContent();
        if (!$signature) {
            return $this->json(['error' => 'Signature not available.'], Response::HTTP_NOT_FOUND);
        }

        return new Response($signature, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => sprintf('attachment; filename="signature-%s.xml"', $invoice->getNumber()),
        ]);
    }

    #[Route('/invoices/{uuid}/pdf', methods: ['GET'])]
    public function downloadPdf(string $uuid): Response
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $org = $invoice->getCompany()?->getOrganization();
        if ($org && !$this->licenseManager->canGeneratePdf($org)) {
            return $this->json([
                'error' => 'PDF generation is not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        // Outgoing invoices: always generate fresh via Twig templates (uses latest template settings)
        if ($this->documentPdfService->isOutgoingInvoice($invoice)) {
            try {
                $pdfContent = $this->documentPdfService->generateInvoicePdf($invoice);
            } catch (\Throwable $e) {
                $this->logger->error('PDF generation failed', ['error' => $e->getMessage()]);
                return $this->json(['error' => 'PDF generation failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return new Response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('inline; filename="invoice-%s.pdf"', $invoice->getNumber()),
            ]);
        }

        // Incoming invoices: serve cached PDF if available
        $pdfPath = $invoice->getPdfPath();
        if ($pdfPath && $this->defaultStorage->fileExists($pdfPath)) {
            $pdfContent = $this->defaultStorage->read($pdfPath);

            return new Response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('inline; filename="invoice-%s.pdf"', $invoice->getNumber()),
            ]);
        }

        // Incoming invoices: generate PDF from XML via Java service
        $xml = $this->xmlResolver->resolve($invoice);
        if (!$xml) {
            return $this->json(['error' => 'Could not generate XML for PDF.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $pdfContent = $this->pdfGenerator->generatePdf($xml);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => 'PDF generation failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="invoice-%s.pdf"', $invoice->getNumber()),
        ]);
    }

    #[Route('/invoices/{uuid}/events', methods: ['GET'])]
    public function events(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $events = $invoice->getEvents()->map(fn ($e) => [
            'id' => (string) $e->getId(),
            'previousStatus' => $e->getPreviousStatus()?->value,
            'newStatus' => $e->getNewStatus()->value,
            'metadata' => $e->getMetadata(),
            'createdAt' => $e->getCreatedAt()->format('c'),
            'createdBy' => $e->getCreatedBy()?->getEmail(),
        ])->toArray();

        return $this->json(array_values($events));
    }

    #[Route('/invoices/{uuid}/verify-signature', methods: ['POST'])]
    public function verifySignature(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $org = $invoice->getCompany()?->getOrganization();
        if ($org && !$this->licenseManager->canVerifySignature($org)) {
            return $this->json([
                'error' => 'Verificarea semnaturii necesita plan Pro.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $xml = $this->xmlResolver->resolve($invoice);
        $signature = $invoice->getSignatureContent();

        if (!$xml || !$signature) {
            return $this->json([
                'valid' => false,
                'message' => 'Semnatura indisponibila',
            ], Response::HTTP_NOT_FOUND);
        }

        $result = $this->signatureVerifier->verify($xml, $signature);

        $invoice->setSignatureValid($result['valid']);
        $this->entityManager->flush();

        return $this->json($result);
    }

    #[Route('/invoices/export/csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->query->all();
        $invoices = $this->invoiceRepository->findByCompanyFiltered($company, $filters);

        $csv = $this->csvExportService->generate($invoices);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="facturi-%s.csv"', date('Y-m-d')),
        ]);
    }

    #[Route('/invoices/export/saga-xml', methods: ['GET'])]
    public function exportSagaXml(Request $request): Response
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->query->all();
        $invoices = $this->invoiceRepository->findByCompanyFiltered($company, $filters);

        $xml = $this->sagaXmlExportService->generateInvoicesXml($invoices, $company);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="FCT_%s.xml"', date('Y-m-d')),
        ]);
    }

    #[Route('/invoices/export/receipts-saga-xml', methods: ['GET'])]
    public function exportReceiptsSagaXml(Request $request): Response
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $payments = $this->paymentRepository->findByCompanyAndDirection($company, InvoiceDirection::OUTGOING);
        $xml = $this->sagaXmlExportService->generateReceiptsXml($payments);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="INC_%s.xml"', date('Y-m-d')),
        ]);
    }

    #[Route('/invoices/export/payments-saga-xml', methods: ['GET'])]
    public function exportPaymentsSagaXml(Request $request): Response
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $payments = $this->paymentRepository->findByCompanyAndDirection($company, InvoiceDirection::INCOMING);
        $xml = $this->sagaXmlExportService->generatePaymentsXml($payments);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="PLT_%s.xml"', date('Y-m-d')),
        ]);
    }

    #[Route('/invoices/export/zip', methods: ['POST'])]
    public function exportZip(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No invoice IDs provided.'], Response::HTTP_BAD_REQUEST);
        }

        if (count($ids) > 100) {
            return $this->json(['error' => 'Maximum 100 invoices per export.'], Response::HTTP_BAD_REQUEST);
        }

        $this->messageBus->dispatch(new GenerateZipExportMessage(
            companyId: (string) $company->getId(),
            invoiceIds: $ids,
            userId: (string) $user->getId(),
        ));

        return $this->json([
            'message' => 'Export-ul este in curs de procesare.',
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/invoices/export/efactura-zip', methods: ['POST'])]
    public function exportEfacturaZip(Request $request): Response
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::INVOICE_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $direction = $data['direction'] ?? null;
        $dateFrom = $data['dateFrom'] ?? null;
        $dateTo = $data['dateTo'] ?? null;

        if (!in_array($direction, ['outgoing', 'incoming'], true)) {
            return $this->json(['error' => 'Field "direction" must be "outgoing" or "incoming".'], Response::HTTP_BAD_REQUEST);
        }

        $filters = ['direction' => $direction];
        if ($dateFrom) {
            $filters['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $filters['dateTo'] = $dateTo;
        }

        $invoices = $this->invoiceRepository->findByCompanyFiltered($company, $filters);

        if (empty($invoices)) {
            return $this->json(['error' => 'Nu exista facturi pentru perioada selectata.'], Response::HTTP_NOT_FOUND);
        }

        // For large batches, dispatch async
        if (count($invoices) > 100) {
            $user = $this->getUser();
            if (!$user) {
                return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
            }

            $ids = array_map(fn ($inv) => (string) $inv->getId(), $invoices);

            $this->messageBus->dispatch(new GenerateZipExportMessage(
                companyId: (string) $company->getId(),
                invoiceIds: $ids,
                userId: (string) $user->getId(),
            ));

            return $this->json([
                'message' => 'Export-ul este in curs de procesare. Vei primi o notificare cand este gata.',
                'count' => count($invoices),
            ], Response::HTTP_ACCEPTED);
        }

        // Synchronous ZIP generation
        $zipContent = $this->zipExportService->generate($invoices);
        $label = $direction === 'outgoing' ? 'clienti' : 'furnizori';
        $dateSuffix = date('Y-m-d');

        return new Response($zipContent, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => sprintf('attachment; filename="efactura-%s_%s.zip"', $label, $dateSuffix),
            'Content-Length' => strlen($zipContent),
        ]);
    }

    #[Route('/invoices/{uuid}/email', methods: ['POST'])]
    public function emailInvoice(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_SEND', $invoice);

        // Only outgoing (issued) invoices can be emailed
        if ($invoice->getDirection() !== InvoiceDirection::OUTGOING) {
            return $this->json(['error' => 'Only outgoing invoices can be sent by email.'], Response::HTTP_BAD_REQUEST);
        }

        $blockedStatuses = [DocumentStatus::DRAFT, DocumentStatus::CANCELLED];
        if (in_array($invoice->getStatus(), $blockedStatuses, true)) {
            return $this->json(['error' => 'Draft or cancelled invoices cannot be sent by email.'], Response::HTTP_BAD_REQUEST);
        }

        $org = $invoice->getCompany()?->getOrganization();
        if ($org && !$this->licenseManager->canSendEmails($org)) {
            return $this->json([
                'error' => 'Email sending is not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $data = json_decode($request->getContent(), true);
        $to = $data['to'] ?? null;

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Valid email address required.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate CC/BCC arrays
        $cc = $data['cc'] ?? null;
        $bcc = $data['bcc'] ?? null;

        if ($cc) {
            foreach ($cc as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $this->json(['error' => 'Invalid CC email address: ' . $email], Response::HTTP_BAD_REQUEST);
                }
            }
        }

        if ($bcc) {
            foreach ($bcc as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $this->json(['error' => 'Invalid BCC email address: ' . $email], Response::HTTP_BAD_REQUEST);
                }
            }
        }

        // Load template if provided
        $template = null;
        $templateId = $data['templateId'] ?? null;
        if ($templateId) {
            $template = $this->emailTemplateRepository->find($templateId);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        try {
            $emailLog = $this->invoiceEmailService->send(
                $invoice,
                $to,
                $data['subject'] ?? null,
                $data['body'] ?? null,
                $cc,
                $bcc,
                $template,
                $user,
            );
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Failed to send email: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($emailLog, context: ['groups' => ['email_log:detail']]);
    }

    #[Route('/invoices/{uuid}/email-defaults', methods: ['GET'])]
    public function emailDefaults(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $company = $invoice->getCompany();
        $defaultTemplate = $company ? $this->emailTemplateRepository->findDefaultForCompany($company) : null;

        $to = $this->invoiceEmailService->getDefaultRecipient($invoice);
        $templateId = null;
        $invoiceNumber = $invoice->getNumber() ?? 'N/A';
        $companyName = $company?->getName() ?? '';

        if ($defaultTemplate) {
            $subject = $this->invoiceEmailService->substituteVariables($defaultTemplate->getSubject(), $invoice);
            $body = $this->invoiceEmailService->substituteVariables($defaultTemplate->getBody(), $invoice);
            $templateId = (string) $defaultTemplate->getId();
        } else {
            $subject = sprintf('Factura %s - %s', $invoiceNumber, $companyName);
            $body = sprintf(
                "Buna ziua,\n\nVa trimitem atasat factura %s.\n\nCu stima,\n%s",
                $invoiceNumber,
                $companyName,
            );
        }

        return $this->json([
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'templateId' => $templateId,
        ]);
    }

    #[Route('/invoices/{uuid}/emails', methods: ['GET'])]
    public function invoiceEmails(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $logs = $this->emailLogRepository->findByInvoice($invoice);

        return $this->json($logs, context: ['groups' => ['email_log:list', 'email_log:detail', 'email_event:list']]);
    }

    #[Route('/invoices/{uuid}/attachments/{attachmentId}', methods: ['GET'])]
    public function downloadAttachment(string $uuid, string $attachmentId): Response
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $attachment = null;
        foreach ($invoice->getAttachments() as $att) {
            if ((string) $att->getId() === $attachmentId) {
                $attachment = $att;
                break;
            }
        }

        if (!$attachment) {
            return $this->json(['error' => 'Attachment not found.'], Response::HTTP_NOT_FOUND);
        }

        $content = $attachment->getContent();

        if (!$content) {
            return $this->json(['error' => 'Attachment content not available.'], Response::HTTP_NOT_FOUND);
        }

        // Use StreamedResponse to avoid loading entire BLOB into memory
        $response = new StreamedResponse(function () use ($content) {
            if (is_resource($content)) {
                fpassthru($content);
                fclose($content);
            } else {
                echo $content;
            }
        });

        $response->headers->set('Content-Type', $attachment->getMimeType());
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $attachment->getFilename()));
        if ($attachment->getSize()) {
            $response->headers->set('Content-Length', (string) $attachment->getSize());
        }
        // Cache for 1 hour — attachment content doesn't change
        $response->setMaxAge(3600);
        $response->setPrivate();

        return $response;
    }

    #[Route('/invoices/{uuid}/share-links', methods: ['GET'])]
    public function listShareLinks(string $uuid): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_VIEW', $invoice);

        $tokens = $this->shareTokenRepository->findByInvoice($invoice);

        $data = array_map(fn ($t) => [
            'id' => $t->getId()->toRfc4122(),
            'token' => $t->getToken(),
            'status' => $t->getStatus()->value,
            'url' => $this->shareService->getShareUrl($t),
            'paymentEnabled' => $t->isPaymentEnabled(),
            'createdAt' => $t->getCreatedAt()?->format('c'),
            'expiresAt' => $t->getExpiresAt()?->format('c'),
            'revokedAt' => $t->getRevokedAt()?->format('c'),
            'lastViewedAt' => $t->getLastViewedAt()?->format('c'),
            'viewCount' => $t->getViewCount(),
            'isValid' => $t->isValid(),
            'createdBy' => $t->getCreatedBy()?->getEmail(),
        ], $tokens);

        return $this->json($data);
    }

    #[Route('/invoices/{uuid}/share-links', methods: ['POST'])]
    public function createShareLink(string $uuid, Request $request): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_SEND', $invoice);

        $org = $invoice->getCompany()?->getOrganization();
        if ($org && !$this->licenseManager->canUsePaymentLinks($org)) {
            return $this->json([
                'error' => 'Payment links are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $expiryDays = $data['expiryDays'] ?? 30;

        $token = $this->shareService->createShareToken($invoice, null, $user, (int) $expiryDays);

        return $this->json([
            'id' => $token->getId()->toRfc4122(),
            'token' => $token->getToken(),
            'url' => $this->shareService->getShareUrl($token),
            'expiresAt' => $token->getExpiresAt()?->format('c'),
        ], Response::HTTP_CREATED);
    }

    #[Route('/invoices/{uuid}/share-links/{linkId}', methods: ['DELETE'])]
    public function revokeShareLink(string $uuid, string $linkId): JsonResponse
    {
        $invoice = $this->findInvoice($uuid);
        if (!$invoice) {
            return $this->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('INVOICE_EDIT', $invoice);

        $token = $this->shareTokenRepository->find($linkId);
        if (!$token || $token->getInvoice() !== $invoice) {
            return $this->json(['error' => 'Share link not found.'], Response::HTTP_NOT_FOUND);
        }

        $token->revoke();
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }

    private function findInvoice(string $uuid): ?Invoice
    {
        return $this->invoiceRepository->findWithDetails($uuid);
    }

    private function generateAndStoreFiles(Invoice $invoice): void
    {
        try {
            $xml = $this->xmlResolver->resolve($invoice);
            if (!$xml) {
                return;
            }

            $cif = (string) $invoice->getCompany()?->getCif();
            $issueDate = $invoice->getIssueDate();
            $basePath = sprintf(
                '%s/%s/%s/%s',
                $cif,
                $issueDate?->format('Y') ?? date('Y'),
                $issueDate?->format('m') ?? date('m'),
                $invoice->getId()
            );

            // Store XML
            if (!$invoice->getXmlPath()) {
                $xmlPath = $basePath . '.xml';
                $this->defaultStorage->write($xmlPath, $xml);
                $invoice->setXmlPath($xmlPath);
            }

            // Generate and store PDF
            if (!$invoice->getPdfPath()) {
                $pdfContent = $this->pdfGenerator->generatePdf($xml);
                $pdfPath = $basePath . '.pdf';
                $this->defaultStorage->write($pdfPath, $pdfContent);
                $invoice->setPdfPath($pdfPath);
            }

            $this->entityManager->flush();
        } catch (\Throwable) {
            // PDF/XML generation failure should not block the issue operation
        }
    }

    private function notifyInvoiceChange(Invoice $invoice, string $type): void
    {
        $company = $invoice->getCompany();
        if (!$company) {
            return;
        }

        $this->centrifugo->queue(
            'invoices:company_' . $company->getId()->toRfc4122(),
            [
                'type' => $type,
                'invoice' => [
                    'id' => $invoice->getId()?->toRfc4122(),
                    'number' => $invoice->getNumber(),
                    'status' => $invoice->getStatus()->value,
                    'direction' => $invoice->getDirection()?->value,
                    'total' => $invoice->getTotal(),
                    'currency' => $invoice->getCurrency(),
                ],
            ],
        );
    }
}
