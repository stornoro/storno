<?php

namespace App\Service\Anaf;

use App\Entity\Invoice;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

class InvoiceArchiver
{
    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
    ) {}

    public function archive(Invoice $invoice, string $xml, ?string $signature): void
    {
        $basePath = $this->buildBasePath($invoice);
        $messageId = $invoice->getAnafMessageId();

        $xmlPath = $basePath . '/' . $messageId . '.xml';
        $this->defaultStorage->write($xmlPath, $xml);
        $invoice->setXmlPath($xmlPath);

        $this->logger->info('Archived invoice XML', ['path' => $xmlPath]);

        if ($signature) {
            $sigPath = $basePath . '/' . $messageId . '.sig.xml';
            $this->defaultStorage->write($sigPath, $signature);
            $invoice->setSignaturePath($sigPath);

            $this->logger->info('Archived invoice signature', ['path' => $sigPath]);
        }
    }

    public function delete(Invoice $invoice): void
    {
        if ($invoice->getXmlPath() && $this->defaultStorage->fileExists($invoice->getXmlPath())) {
            $this->defaultStorage->delete($invoice->getXmlPath());
            $this->logger->info('Deleted archived XML', ['path' => $invoice->getXmlPath()]);
        }
        $invoice->setXmlPath(null);

        if ($invoice->getSignaturePath() && $this->defaultStorage->fileExists($invoice->getSignaturePath())) {
            $this->defaultStorage->delete($invoice->getSignaturePath());
            $this->logger->info('Deleted archived signature', ['path' => $invoice->getSignaturePath()]);
        }
        $invoice->setSignaturePath(null);
    }

    private function buildBasePath(Invoice $invoice): string
    {
        $cif = (string) $invoice->getCompany()->getCif();
        $issueDate = $invoice->getIssueDate();
        $year = $issueDate->format('Y');
        $month = $issueDate->format('m');

        return $cif . '/' . $year . '/' . $month;
    }
}
