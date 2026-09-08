<?php

declare(strict_types=1);

namespace App\Service\Related;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Dosar;
use App\Entity\Invoice;
use App\Entity\RecurringInvoice;
use App\Entity\SpvDocument;
use App\Entity\SpvRequest;
use App\Entity\Supplier;
use App\Entity\TaxDeclaration;
use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use App\Security\OrganizationContext;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One answer to "what else in Storno is about this?" for any record: the dosar of an invoice's
 * tenant, the invoices and recurring invoice of a dosar, the dosar behind a declaration or an
 * SPV message, the dosare of a client. Every item carries the page it lives on, so a UI or an
 * MCP client can jump straight there. Groups the caller may not view are left out.
 */
final class RelatedService
{
    public const TYPES = ['client', 'supplier', 'invoice', 'recurring_invoice', 'declaration', 'spv_document', 'spv_request', 'dosar'];
    private const RECENT = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizationContext $context,
    ) {
    }

    /** @return array{source: array<string, mixed>, groups: array<string, list<array<string, mixed>>>}|null null when the record is not in this company */
    public function forRecord(Company $company, string $type, string $id): ?array
    {
        $uuid = Uuid::isValid($id) ? Uuid::fromString($id) : null;
        if ($uuid === null || !in_array($type, self::TYPES, true)) {
            return null;
        }
        $entity = $this->em->getRepository($this->classOf($type))->findOneBy(['id' => $uuid, 'company' => $company]);
        if ($entity === null) {
            return null;
        }
        $groups = match ($type) {
            'client' => $this->forClient($entity),
            'supplier' => $this->forSupplier($entity),
            'invoice' => $this->forInvoice($entity),
            'recurring_invoice' => $this->forRecurring($entity),
            'declaration' => $this->forDeclaration($entity),
            'spv_document' => $this->forSpvDocument($entity),
            'spv_request' => $this->forSpvRequest($entity),
            'dosar' => $this->forDosar($entity),
        };

        return ['source' => $this->item($entity), 'groups' => $this->visible($groups)];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function forClient(Client $client): array
    {
        $dosare = $this->dosareOf(['client' => $client]);

        return [
            'dosare' => $this->items($dosare),
            'recurringInvoices' => $this->items($this->em->getRepository(RecurringInvoice::class)->findBy(['company' => $client->getCompany(), 'client' => $client], ['createdAt' => 'DESC'])),
            'invoices' => $this->items($this->recentInvoices(['client' => $client], $client->getCompany())),
            'declarations' => $this->items($this->declarationsOf($dosare)),
            'spvDocuments' => $this->items($this->spvDocumentsOf($dosare)),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function forSupplier(Supplier $supplier): array
    {
        $dosare = $this->dosareOf(['supplier' => $supplier]);

        return [
            'dosare' => $this->items($dosare),
            'invoices' => $this->items($this->recentInvoices(['supplier' => $supplier], $supplier->getCompany())),
            'declarations' => $this->items($this->declarationsOf($dosare)),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function forInvoice(Invoice $invoice): array
    {
        $dosare = $invoice->getClient() ? $this->dosareOf(['client' => $invoice->getClient()]) : [];
        if ($invoice->getSupplier()) {
            $dosare = [...$dosare, ...$this->dosareOf(['supplier' => $invoice->getSupplier()])];
        }
        $recurring = [];
        foreach ($this->em->getRepository(RecurringInvoice::class)->findBy(['company' => $invoice->getCompany()]) as $r) {
            if ($r->getLastInvoiceId() !== null && $r->getLastInvoiceId()->equals($invoice->getId())) {
                $recurring[] = $r;
            }
        }
        $siblings = $invoice->getClient() ? array_values(array_filter($this->recentInvoices(['client' => $invoice->getClient()], $invoice->getCompany()), fn (Invoice $i) => !$i->getId()->equals($invoice->getId()))) : [];

        return [
            'clients' => $this->items(array_filter([$invoice->getClient()])),
            'suppliers' => $this->items(array_filter([$invoice->getSupplier()])),
            'dosare' => $this->items($dosare),
            'recurringInvoices' => $this->items($recurring),
            'invoices' => $this->items($siblings),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function forRecurring(RecurringInvoice $recurring): array
    {
        $last = $recurring->getLastInvoiceId() ? $this->em->getRepository(Invoice::class)->find($recurring->getLastInvoiceId()) : null;

        return [
            'clients' => $this->items(array_filter([$recurring->getClient()])),
            'dosare' => $this->items($recurring->getClient() ? $this->dosareOf(['client' => $recurring->getClient()]) : []),
            'invoices' => $this->items($recurring->getClient() ? $this->recentInvoices(['client' => $recurring->getClient()], $recurring->getCompany()) : array_filter([$last])),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function forDeclaration(TaxDeclaration $declaration): array
    {
        $dosar = $declaration->getDosar();
        $docs = [];
        if ($declaration->getAnafUploadId()) {
            foreach ($this->em->getRepository(SpvDocument::class)->findBy(['company' => $declaration->getCompany()], ['anafCreatedAt' => 'DESC'], 200) as $doc) {
                if (str_contains((string) $doc->getFileName(), (string) $declaration->getAnafUploadId()) || str_contains((string) $doc->getSummary(), (string) $declaration->getAnafUploadId())) {
                    $docs[] = $doc;
                }
            }
        }

        return [
            'dosare' => $this->items(array_filter([$dosar])),
            'clients' => $this->items(array_filter([$dosar?->getClient()])),
            'spvDocuments' => $this->items($docs),
            'declarations' => $this->items($dosar ? array_values(array_filter($this->declarationsOf([$dosar]), fn (TaxDeclaration $d) => !$d->getId()->equals($declaration->getId()))) : []),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function forSpvDocument(SpvDocument $document): array
    {
        $requests = $this->em->getRepository(SpvRequest::class)->findBy(['company' => $document->getCompany(), 'answerDocument' => $document]);
        $dosar = $document->getDosar();

        return [
            'dosare' => $this->items(array_filter([$dosar])),
            'clients' => $this->items(array_filter([$dosar?->getClient()])),
            'spvRequests' => $this->items($requests),
            'declarations' => $this->items($dosar ? $this->declarationsOf([$dosar]) : []),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function forSpvRequest(SpvRequest $request): array
    {
        $dosar = $request->getDosar();

        return [
            'dosare' => $this->items(array_filter([$dosar])),
            'spvDocuments' => $this->items(array_filter([$request->getAnswerDocument()])),
            'clients' => $this->items(array_filter([$dosar?->getClient()])),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function forDosar(Dosar $dosar): array
    {
        $company = $dosar->getCompany();
        $invoices = [];
        $recurring = [];
        if ($dosar->getClient()) {
            $invoices = $this->recentInvoices(['client' => $dosar->getClient()], $company);
            $recurring = $this->em->getRepository(RecurringInvoice::class)->findBy(['company' => $company, 'client' => $dosar->getClient()], ['createdAt' => 'DESC']);
        }
        if ($dosar->getSupplier()) {
            $invoices = [...$invoices, ...$this->recentInvoices(['supplier' => $dosar->getSupplier()], $company)];
        }

        return [
            'clients' => $this->items(array_filter([$dosar->getClient()])),
            'suppliers' => $this->items(array_filter([$dosar->getSupplier()])),
            'recurringInvoices' => $this->items($recurring),
            'invoices' => $this->items($invoices),
            'declarations' => $this->items($this->declarationsOf([$dosar])),
            'spvRequests' => $this->items($this->em->getRepository(SpvRequest::class)->findBy(['dosar' => $dosar], ['createdAt' => 'DESC'])),
            'spvDocuments' => $this->items($this->spvDocumentsOf([$dosar])),
        ];
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $criteria @return list<Dosar> */
    private function dosareOf(array $criteria): array
    {
        return $this->em->getRepository(Dosar::class)->findBy($criteria, ['status' => 'ASC', 'updatedAt' => 'DESC']);
    }

    /** @param list<Dosar> $dosare @return list<TaxDeclaration> */
    private function declarationsOf(array $dosare): array
    {
        return $dosare === [] ? [] : $this->em->getRepository(TaxDeclaration::class)->findBy(['dosar' => $dosare], ['createdAt' => 'DESC']);
    }

    /** @param list<Dosar> $dosare @return list<SpvDocument> */
    private function spvDocumentsOf(array $dosare): array
    {
        return $dosare === [] ? [] : $this->em->getRepository(SpvDocument::class)->findBy(['dosar' => $dosare], ['anafCreatedAt' => 'DESC']);
    }

    /** @param array<string, mixed> $criteria @return list<Invoice> */
    private function recentInvoices(array $criteria, ?Company $company): array
    {
        $out = [];
        foreach ($this->em->getRepository(Invoice::class)->findBy($criteria + ['company' => $company], ['issueDate' => 'DESC', 'createdAt' => 'DESC'], self::RECENT * 2) as $inv) {
            if ($inv->getStatus() === DocumentStatus::CANCELLED) {
                continue;
            }
            $out[] = $inv;
            if (count($out) >= self::RECENT) {
                break;
            }
        }

        return $out;
    }

    /** @param iterable<object> $entities @return list<array<string, mixed>> */
    private function items(iterable $entities): array
    {
        $out = [];
        foreach ($entities as $e) {
            if ($e !== null) {
                $out[] = $this->item($e);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function item(object $e): array
    {
        $id = (string) $e->getId();

        return match (true) {
            $e instanceof Dosar => ['type' => 'dosar', 'id' => $id, 'title' => $e->getTitle(), 'subtitle' => $e->getType(), 'status' => $e->getStatus(), 'date' => $e->getDeadlineAt()?->format('Y-m-d'), 'href' => '/dosare/' . $id],
            $e instanceof Client => ['type' => 'client', 'id' => $id, 'title' => $e->getName(), 'subtitle' => $e->getCui() ?: $e->getCnp(), 'status' => null, 'date' => null, 'href' => '/clients/' . $id],
            $e instanceof Supplier => ['type' => 'supplier', 'id' => $id, 'title' => $e->getName(), 'subtitle' => $e->getCif(), 'status' => null, 'date' => null, 'href' => '/suppliers/' . $id],
            $e instanceof Invoice => ['type' => 'invoice', 'id' => $id, 'title' => $e->getNumber() ?: 'draft', 'subtitle' => sprintf('%s %s · %s', number_format((float) $e->getTotal(), 2, '.', ' '), $e->getCurrency(), $e->getDirection() === InvoiceDirection::INCOMING ? ($e->getSupplier()?->getName() ?? '') : ($e->getClientName() ?? '')), 'status' => $e->getStatus()->value, 'date' => $e->getIssueDate()?->format('Y-m-d'), 'href' => '/invoices/' . $id, 'direction' => $e->getDirection()?->value, 'balance' => round((float) $e->getBalance(), 2)],
            $e instanceof RecurringInvoice => ['type' => 'recurring_invoice', 'id' => $id, 'title' => $e->getReference() ?: ($e->getClientName() ?? 'recurring'), 'subtitle' => sprintf('%s · %s %s', $e->getFrequency(), number_format((float) $e->getEstimatedTotal(), 2, '.', ' '), $e->getCurrency()), 'status' => $e->isActive() ? 'active' : 'paused', 'date' => $e->getNextIssuanceDate()?->format('Y-m-d'), 'href' => '/recurring-invoices/' . $id],
            $e instanceof TaxDeclaration => ['type' => 'declaration', 'id' => $id, 'title' => strtoupper($e->getType()->value), 'subtitle' => trim($e->getYear() . ($e->getMonth() ? '-' . str_pad((string) $e->getMonth(), 2, '0', STR_PAD_LEFT) : '')), 'status' => $e->getStatus()->value, 'date' => $e->getSubmittedAt()?->format('Y-m-d'), 'href' => '/declarations/' . $id],
            $e instanceof SpvRequest => ['type' => 'spv_request', 'id' => $id, 'title' => $e->getTitle() ?: $e->getRequestType(), 'subtitle' => $e->getRequestType(), 'status' => $e->getStatus(), 'date' => $e->getCreatedAt()?->format('Y-m-d'), 'href' => '/spv'],
            $e instanceof SpvDocument => ['type' => 'spv_document', 'id' => $id, 'title' => $e->getSummary() ?: $e->getMessageType(), 'subtitle' => $e->getCategory()->value, 'status' => $e->getReadAt() ? 'read' : 'unread', 'date' => $e->getAnafCreatedAt()?->format('Y-m-d'), 'href' => '/spv?document=' . $id],
            default => ['type' => 'unknown', 'id' => $id, 'title' => $id, 'subtitle' => null, 'status' => null, 'date' => null, 'href' => null],
        };
    }

    /** @param array<string, list<array<string, mixed>>> $groups @return array<string, list<array<string, mixed>>> */
    private function visible(array $groups): array
    {
        $needs = [
            'dosare' => Permission::DECLARATION_VIEW, 'declarations' => Permission::DECLARATION_VIEW, 'spvRequests' => Permission::DECLARATION_VIEW, 'spvDocuments' => Permission::DECLARATION_VIEW,
            'invoices' => Permission::INVOICE_VIEW, 'recurringInvoices' => Permission::RECURRING_INVOICE_VIEW, 'clients' => Permission::CLIENT_VIEW, 'suppliers' => Permission::CLIENT_VIEW,
        ];
        $out = [];
        foreach ($groups as $name => $items) {
            if ($items !== [] && $this->context->hasPermission($needs[$name] ?? Permission::INVOICE_VIEW)) {
                $out[$name] = $items;
            }
        }

        return $out;
    }

    /** @return class-string */
    private function classOf(string $type): string
    {
        return match ($type) {
            'client' => Client::class, 'supplier' => Supplier::class, 'invoice' => Invoice::class, 'recurring_invoice' => RecurringInvoice::class,
            'declaration' => TaxDeclaration::class, 'spv_document' => SpvDocument::class, 'spv_request' => SpvRequest::class, default => Dosar::class,
        };
    }
}
