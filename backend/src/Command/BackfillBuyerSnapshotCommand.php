<?php

namespace App\Command;

use App\Repository\InvoiceRepository;
use App\Service\Anaf\EFacturaXmlParser;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill:buyer-snapshot',
    description: 'Backfill buyerSnapshot from stored UBL XML for existing invoices',
)]
class BackfillBuyerSnapshotCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $defaultStorage,
        private readonly EFacturaXmlParser $xmlParser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of invoices to process', '0')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Only backfill invoices for clients with this email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');
        $email = $input->getOption('email');

        if ($dryRun) {
            $io->note('Dry-run mode — no changes will be saved.');
        }

        $qb = $this->invoiceRepository->createQueryBuilder('i')
            ->where('i.xmlPath IS NOT NULL')
            ->andWhere('i.buyerSnapshot IS NULL')
            ->orderBy('i.issueDate', 'ASC');

        if ($email) {
            $qb->join('i.client', 'c')
                ->andWhere('c.email = :email')
                ->setParameter('email', $email);
            $io->info(sprintf('Filtering invoices for client email "%s".', $email));
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        $invoices = $qb->getQuery()->toIterable();

        $processed = 0;
        $backfilled = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($invoices as $invoice) {
            $processed++;
            $xmlPath = $invoice->getXmlPath();

            try {
                if (!$this->defaultStorage->fileExists($xmlPath)) {
                    $skipped++;
                    $io->warning(sprintf('XML file missing for invoice %s: %s', $invoice->getNumber(), $xmlPath));
                    continue;
                }

                $xml = $this->defaultStorage->read($xmlPath);
                $parsed = $this->xmlParser->parse($xml);

                if (!$parsed->buyer) {
                    $skipped++;
                    continue;
                }

                $buyer = $parsed->buyer;

                // Determine type: if CIF looks like a CNP (13 digits, no letters) and no VAT code, it's individual
                $type = 'company';
                $cui = $buyer->cif;
                $cnp = null;

                if ($cui && preg_match('/^\d{13}$/', $cui)) {
                    // 13-digit numeric = CNP (individual)
                    $type = 'individual';
                    $cnp = $cui;
                    $cui = null;
                } elseif ($cui === '0000000000000') {
                    // ANAF placeholder for individuals without CUI/CNP
                    $type = 'individual';
                    $cui = null;
                }

                $snapshot = [
                    'type' => $type,
                    'name' => $buyer->name,
                    'cui' => $cui,
                    'cnp' => $cnp,
                    'vatCode' => $buyer->vatCode,
                    'isVatPayer' => $buyer->isVatPayer(),
                    'registrationNumber' => $buyer->registrationNumber,
                    'address' => $buyer->address,
                    'city' => $buyer->city,
                    'county' => $buyer->county,
                    'country' => $buyer->country,
                    'postalCode' => $buyer->postalCode,
                    'email' => $buyer->email,
                    'phone' => $buyer->phone,
                    'bankName' => $buyer->bankName,
                    'bankAccount' => $buyer->bankAccount,
                    'clientCode' => null,
                    'einvoiceIdentifiers' => null,
                ];

                if (!$dryRun) {
                    $invoice->setBuyerSnapshot($snapshot);
                }

                $backfilled++;

                if ($backfilled % self::BATCH_SIZE === 0) {
                    if (!$dryRun) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }
                    $io->text(sprintf('Processed %d invoices, backfilled %d...', $processed, $backfilled));
                }
            } catch (\Throwable $e) {
                $errors++;
                $io->error(sprintf('Error processing invoice %s: %s', $invoice->getNumber() ?? $invoice->getId(), $e->getMessage()));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Done. Processed: %d, Backfilled: %d, Skipped: %d, Errors: %d',
            $processed,
            $backfilled,
            $skipped,
            $errors,
        ));

        return Command::SUCCESS;
    }
}
