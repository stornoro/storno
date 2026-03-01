<?php

namespace App\Controller\Api\V1;

use App\Entity\BankAccount;
use App\Entity\BorderouTransaction;
use App\Enum\ProformaStatus;
use App\Repository\BankAccountRepository;
use App\Repository\BorderouTransactionRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ImportJobRepository;
use App\Repository\ProformaInvoiceRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Borderou\BorderouImportService;
use App\Service\Borderou\BorderouMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/borderou')]
class BorderouController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly BorderouImportService $importService,
        private readonly BorderouMatchingService $matchingService,
        private readonly BorderouTransactionRepository $txRepo,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly ImportJobRepository $importJobRepo,
        private readonly BankAccountRepository $bankAccountRepo,
        private readonly ProformaInvoiceRepository $proformaRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission(Permission::BORDEROU_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->importService->getProviders());
    }

    #[Route('/upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::BORDEROU_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $allowedExtensions = ['csv', 'xlsx', 'xls'];
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        if (!in_array($extension, $allowedExtensions, true)) {
            return $this->json(['error' => 'Unsupported file format. Allowed: csv, xlsx, xls'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sourceType = $request->request->get('sourceType');
        if (!in_array($sourceType, ['borderou', 'bank_statement', 'marketplace'], true)) {
            return $this->json(['error' => 'Invalid sourceType. Must be "borderou", "bank_statement", or "marketplace".'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $request->request->get('provider');
        if (empty($provider)) {
            return $this->json(['error' => 'Field "provider" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $currency = $request->request->get('currency', 'RON');
        $bordereauNumber = $request->request->get('bordereauNumber');
        $bankAccountId = $request->request->get('bankAccountId');

        // Validate bank account for bank_statement imports
        $bankAccount = null;
        if ($sourceType === 'bank_statement' && $bankAccountId) {
            $bankAccount = $this->bankAccountRepo->find($bankAccountId);
            if (!$bankAccount || $bankAccount->getCompany()->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
                return $this->json(['error' => 'Bank account not found.'], Response::HTTP_NOT_FOUND);
            }
        }

        $tempPath = $uploadedFile->getRealPath();
        $originalFilename = $uploadedFile->getClientOriginalName();

        try {
            $result = $this->importService->import(
                company: $company,
                filePath: $tempPath,
                fileFormat: $extension,
                originalFilename: $originalFilename,
                sourceType: $sourceType,
                provider: $provider,
                currency: $currency,
                bordereauNumber: $bordereauNumber,
                bankAccount: $bankAccount,
            );

            return $this->json([
                'importJobId' => $result['importJobId'],
                'summary' => $result['summary'],
                'transactions' => $result['transactions'],
            ], Response::HTTP_CREATED, [], ['groups' => ['borderou:list']]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/transactions', methods: ['GET'])]
    public function listTransactions(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::BORDEROU_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->query->all();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));

        $result = $this->txRepo->findByCompanyPaginated($company, $filters, $page, $limit);

        return $this->json($result, context: ['groups' => ['borderou:list']]);
    }

    #[Route('/transactions/{id}', methods: ['GET'])]
    public function getTransaction(string $id, Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::BORDEROU_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $tx = $this->txRepo->find($id);
        if (!$tx || $tx->getCompany()->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'Transaction not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($tx, context: ['groups' => ['borderou:detail']]);
    }

    #[Route('/transactions/{id}', methods: ['PUT'])]
    public function updateTransaction(string $id, Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::BORDEROU_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $tx = $this->txRepo->find($id);
        if (!$tx || $tx->getCompany()->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'Transaction not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($tx->getStatus() !== 'unsaved') {
            return $this->json(['error' => 'Cannot modify a saved transaction.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['clientId'])) {
            if ($data['clientId']) {
                $client = $this->em->find(\App\Entity\Client::class, $data['clientId']);
                $tx->setMatchedClient($client);
            } else {
                $tx->setMatchedClient(null);
            }
        }

        if (isset($data['invoiceId'])) {
            if ($data['invoiceId']) {
                $invoice = $this->invoiceRepo->find($data['invoiceId']);
                $tx->setMatchedInvoice($invoice);
                // Mutual exclusion: clear proforma if invoice set
                $tx->setMatchedProformaInvoice(null);
            } else {
                $tx->setMatchedInvoice(null);
            }
        }

        if (isset($data['proformaInvoiceId'])) {
            if ($data['proformaInvoiceId']) {
                $proforma = $this->proformaRepo->find($data['proformaInvoiceId']);
                $tx->setMatchedProformaInvoice($proforma);
                // Mutual exclusion: clear invoice if proforma set
                $tx->setMatchedInvoice(null);
            } else {
                $tx->setMatchedProformaInvoice(null);
            }
        }

        if (isset($data['amount'])) {
            $tx->setAmount($data['amount']);
        }

        if (isset($data['documentType'])) {
            $tx->setDocumentType($data['documentType']);
        }

        // Recalculate confidence
        $hasManualSelection = isset($data['clientId']) || isset($data['invoiceId']) || isset($data['proformaInvoiceId']);
        if (!$hasManualSelection) {
            $match = $this->matchingService->matchTransaction($tx, $company);
            $tx->setMatchConfidence($match['confidence']);
        } else {
            // User manually set client/invoice/proforma — determine confidence
            if ($tx->getMatchedInvoice()) {
                $balance = $tx->getMatchedInvoice()->getBalance();
                $amount = $tx->getAmount();
                if (bccomp($amount, $balance, 2) === 0) {
                    $tx->setMatchConfidence('certain');
                } else {
                    $tx->setMatchConfidence('attention');
                }
            } elseif ($tx->getMatchedProformaInvoice()) {
                $proformaTotal = $tx->getMatchedProformaInvoice()->getTotal();
                $amount = $tx->getAmount();
                if (bccomp($amount, $proformaTotal, 2) === 0) {
                    $tx->setMatchConfidence('certain');
                } else {
                    $tx->setMatchConfidence('attention');
                }
            } elseif ($tx->getMatchedClient()) {
                $tx->setMatchConfidence('attention');
            } else {
                $tx->setMatchConfidence('no_match');
            }
        }

        $this->em->flush();

        return $this->json($tx, context: ['groups' => ['borderou:detail']]);
    }

    #[Route('/transactions/{id}/available-invoices', methods: ['GET'])]
    public function availableInvoices(string $id, Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::BORDEROU_VIEW)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $tx = $this->txRepo->find($id);
        if (!$tx || $tx->getCompany()->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'Transaction not found.'], Response::HTTP_NOT_FOUND);
        }

        $search = $request->query->get('search');
        $typeFilter = $request->query->get('type'); // 'invoice', 'proforma', or null for all
        $result = [];

        // Build invoice filters
        $invoiceFilters = [
            'direction' => 'outgoing',
            'isPaid' => 'false',
        ];

        // If search provided, search across all clients; otherwise scope to matched client
        if ($search) {
            $invoiceFilters['search'] = $search;
        } else {
            $client = $tx->getMatchedClient();
            if ($client) {
                $invoiceFilters['clientId'] = $client->getId()->toRfc4122();
            }
        }

        // Invoices
        if (!$typeFilter || $typeFilter === 'invoice') {
            $invoices = $this->invoiceRepo->findByCompanyFiltered($company, $invoiceFilters, 50);
            foreach ($invoices as $invoice) {
                $result[] = [
                    'id' => $invoice->getId()->toRfc4122(),
                    'type' => 'invoice',
                    'number' => $invoice->getNumber(),
                    'clientName' => $invoice->getClient()?->getName() ?? $invoice->getReceiverName(),
                    'issueDate' => $invoice->getIssueDate()?->format('Y-m-d'),
                    'dueDate' => $invoice->getDueDate()?->format('Y-m-d'),
                    'total' => $invoice->getTotal(),
                    'amountPaid' => $invoice->getAmountPaid(),
                    'balance' => $invoice->getBalance(),
                    'currency' => $invoice->getCurrency(),
                ];
            }
        }

        // Proformas (sent/accepted only)
        if (!$typeFilter || $typeFilter === 'proforma') {
            $conn = $this->em->getConnection();
            $companyId = $company->getId()->toRfc4122();

            $sql = "SELECT p.id, p.number, p.issue_date, p.due_date, p.total, p.currency, c.name AS client_name
                    FROM proforma_invoice p
                    LEFT JOIN client c ON p.client_id = c.id
                    WHERE p.company_id = ?
                    AND p.deleted_at IS NULL
                    AND p.status IN ('sent', 'accepted')";
            $params = [$companyId];

            if ($search) {
                $sql .= ' AND (p.number LIKE ? OR c.name LIKE ?)';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            } else {
                $client = $tx->getMatchedClient();
                if ($client) {
                    $sql .= ' AND p.client_id = ?';
                    $params[] = $client->getId()->toRfc4122();
                }
            }

            $sql .= ' ORDER BY p.issue_date DESC LIMIT 50';
            $rows = $conn->fetchAllAssociative($sql, $params);

            foreach ($rows as $row) {
                $result[] = [
                    'id' => $row['id'],
                    'type' => 'proforma',
                    'number' => $row['number'],
                    'clientName' => $row['client_name'],
                    'issueDate' => $row['issue_date'],
                    'dueDate' => $row['due_date'],
                    'total' => $row['total'],
                    'amountPaid' => '0.00',
                    'balance' => $row['total'],
                    'currency' => $row['currency'],
                ];
            }
        }

        return $this->json($result);
    }

    #[Route('/transactions/save', methods: ['POST'])]
    public function saveTransactions(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::BORDEROU_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $transactionIds = $data['transactionIds'] ?? [];

        if (empty($transactionIds)) {
            return $this->json(['error' => 'No transactions specified.'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->importService->saveTransactions($transactionIds, $company);

        return $this->json($result);
    }

    #[Route('/transactions/re-match', methods: ['POST'])]
    public function rematch(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->organizationContext->hasPermission(Permission::BORDEROU_MANAGE)) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $transactionIds = $data['transactionIds'] ?? [];

        if (empty($transactionIds)) {
            return $this->json(['error' => 'No transactions specified.'], Response::HTTP_BAD_REQUEST);
        }

        $transactions = $this->txRepo->findByIds($transactionIds);

        // Filter to only this company's transactions
        $transactions = array_filter($transactions, fn (BorderouTransaction $tx) => $tx->getCompany()->getId()->toRfc4122() === $company->getId()->toRfc4122()
        );

        $this->matchingService->rematchTransactions($transactions, $company);

        return $this->json($transactions, context: ['groups' => ['borderou:list']]);
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        return $this->organizationContext->resolveCompany($request);
    }
}
