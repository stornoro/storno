<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\DocumentEvent;
use App\Entity\DocumentSeries;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\InvoiceDirection;
use App\Repository\DocumentSeriesRepository;
use App\Repository\PdfTemplateConfigRepository;
use App\Util\RomanianAmountInWords;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Snappy\Pdf;
use Twig\Environment;

/**
 * The chitanță for a cash payment that is already recorded on an issued invoice.
 *
 * The receipt documents that payment — it takes a number from the company's receipt
 * series and a date — and never records a second one: the invoice balance, the client
 * statement and the cash register all keep reading the same payment row. Issuing is
 * idempotent: a payment that already has a receipt keeps it.
 */
class PaymentReceiptService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly PdfTemplateConfigRepository $configRepository,
        private readonly Environment $twig,
        private readonly Pdf $snappy,
        private readonly WhiteLabelResolver $whiteLabelResolver,
        private readonly string $projectDir,
    ) {}

    /**
     * @throws \DomainException when the payment cannot carry a receipt (not cash, not on an
     *                          issued invoice, no receipt series) — the message is for the user
     */
    public function issue(Payment $payment, ?DocumentSeries $series = null, ?\DateTimeInterface $date = null, ?User $user = null): Payment
    {
        if ($payment->hasReceipt()) {
            return $payment; // already issued: the same receipt, nothing new recorded
        }
        $invoice = $payment->getInvoice();
        $company = $payment->getCompany() ?? $invoice?->getCompany();
        if ($invoice === null || $company === null) {
            throw new \DomainException('Chitanța se emite doar pentru o plată înregistrată pe o factură.');
        }
        if ($invoice->getDirection() !== InvoiceDirection::OUTGOING) {
            throw new \DomainException('Chitanța se emite doar pentru încasări pe facturi emise, nu pentru plăți către furnizori.');
        }
        if ($payment->getPaymentMethod() !== 'cash') {
            throw new \DomainException('Chitanța se emite doar pentru o încasare în numerar; această plată este ' . $payment->getPaymentMethod() . '.');
        }

        $series ??= $this->documentSeriesRepository->findDefaultByType($company, 'receipt');
        if ($series === null) {
            throw new \DomainException('Nu există o serie de chitanțe configurată. Adaugă una în Setări → Serii documente (tip Chitanță).');
        }
        if ($series->getType() !== 'receipt' || (string) $series->getCompany()?->getId() !== (string) $company->getId()) {
            throw new \DomainException('Seria aleasă nu este o serie de chitanțe a acestei companii.');
        }

        $receiptDate = $date !== null ? \DateTime::createFromInterface($date) : \DateTime::createFromInterface($payment->getPaymentDate() ?? new \DateTime());

        // Next number from the series, under a row lock so two receipts never share a number
        $this->entityManager->wrapInTransaction(function () use ($series, $payment, $receiptDate) {
            $this->entityManager->lock($series, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($series);
            $next = $series->getCurrentNumber() + 1;
            $series->setCurrentNumber($next);
            $payment->setReceiptSeries($series);
            $payment->setReceiptNumber($series->formatNumber($next));
            $payment->setReceiptDate($receiptDate);
            $payment->setReceiptIssuedAt(new \DateTimeImmutable());
        });

        $event = new DocumentEvent();
        $event->setPreviousStatus($invoice->getStatus());
        $event->setNewStatus($invoice->getStatus());
        $event->setCreatedBy($user);
        $event->setMetadata([
            'action' => 'receipt_issued',
            'paymentId' => $payment->getId()?->toRfc4122(),
            'receiptNumber' => $payment->getReceiptNumber(),
            'receiptDate' => $receiptDate->format('Y-m-d'),
            'amount' => $payment->getAmount(),
        ]);
        $invoice->addEvent($event);
        $this->entityManager->flush();

        return $payment;
    }

    public function pdf(Payment $payment): string
    {
        return $this->snappy->getOutputFromHtml($this->html($payment), [
            'encoding' => 'UTF-8',
            'print-media-type' => true,
            'no-outline' => true,
            'disable-javascript' => true,
            'disable-external-links' => true,
            'disable-local-file-access' => true,
            'allow' => [$this->projectDir . '/assets/fonts'],
            'page-size' => 'A5',
            'orientation' => 'Landscape',
            'margin-top' => '8mm',
            'margin-bottom' => '8mm',
            'margin-left' => '10mm',
            'margin-right' => '10mm',
        ]);
    }

    public function html(Payment $payment): string
    {
        if (!$payment->hasReceipt()) {
            throw new \DomainException('Plata nu are chitanță emisă.');
        }
        $invoice = $payment->getInvoice();
        $company = $payment->getCompany() ?? $invoice?->getCompany();
        $config = $company ? $this->configRepository->findByCompany($company) : null;
        $buyer = $invoice?->getBuyerSnapshot() ?? [];
        $client = $invoice?->getClient();

        return $this->twig->render('documents/pdf/chitanta.html.twig', [
            'payment' => $payment,
            'invoice' => $invoice,
            'company' => $company,
            'payer' => [
                'name' => $buyer['name'] ?? $client?->getName() ?? $invoice?->getReceiverName() ?? '—',
                'cui' => $buyer['cui'] ?? $client?->getCui() ?? $invoice?->getReceiverCif(),
                'registrationNumber' => $buyer['registrationNumber'] ?? $client?->getRegistrationNumber(),
                'address' => trim(implode(', ', array_filter([$buyer['address'] ?? $client?->getAddress(), $buyer['city'] ?? $client?->getCity(), $buyer['county'] ?? $client?->getCounty()]))),
            ],
            'amountInWords' => RomanianAmountInWords::amount($payment->getAmount(), $payment->getCurrency()),
            'primaryColor' => $config?->getPrimaryColor() ?? '#2563eb',
            'fontFamily' => $config?->getFontFamily() ?? 'DejaVu Sans',
            'fontsDir' => $this->projectDir . '/assets/fonts',
            'customCss' => null,
            'whiteLabelHideBranding' => ($company instanceof Company && $company->getOrganization())
                ? $this->whiteLabelResolver->shouldHideBranding($company->getOrganization())
                : false,
        ]);
    }
}
