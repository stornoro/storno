<?php

namespace App\Command;

use App\Service\Borderou\Pdf\PdfStatementDispatcher;
use App\Service\Borderou\Pdf\PdfWordExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:borderou:pdf-inspect', description: 'Detect the bank of a statement PDF and print the parsed transactions (debug aid for PDF imports)')]
class BorderouPdfInspectCommand extends Command
{
    public function __construct(
        private readonly PdfWordExtractor $extractor,
        private readonly PdfStatementDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the PDF statement')
            ->addOption('mask', null, InputOption::VALUE_NONE, 'Mask IBANs, names and fiscal codes in the output')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        $mask = (bool) $input->getOption('mask');
        $pages = $this->extractor->extract($file);
        $output->writeln(sprintf('<info>Extractor:</info> %s, %d page(s), %d words on page 1', $this->extractor->hasPdftotext() ? 'pdftotext' : 'smalot', count($pages), count($pages[0]->words ?? [])));

        $scores = [];
        foreach ($this->dispatcher->supportedBanks() as $key => $label) {
            $scores[$key] = $label;
        }
        $selected = $this->dispatcher->select($pages);
        if (!$selected) {
            $output->writeln('<error>No parser reached the threshold.</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('<info>Bank:</info> %s (%s) score %d', $selected['parser']->getBankLabel(), $selected['parser']->getBankKey(), $selected['score']));

        if (method_exists($selected['parser'], 'setSourcePath')) {
            $selected['parser']->setSourcePath($file);
        }
        $statements = $selected['parser']->parse($pages);
        $m = static fn (?string $s) => $mask && $s !== null ? preg_replace('/[A-Z0-9]/', '*', $s) : $s;
        foreach ($statements as $s) {
            $output->writeln(sprintf('IBAN %s | %s | holder %s | CUI %s | opening %s | closing %s | %d transactions',
                $m($s->iban), $s->currency, $m($s->accountHolder), $m($s->fiscalCode), $s->openingBalance ?? '-', $s->closingBalance ?? '-', count($s->transactions)));
            foreach ($s->warnings as $w) {
                $output->writeln('<comment>WARNING: ' . $w . '</comment>');
            }
            if ($input->getOption('json')) {
                $output->writeln(json_encode(array_map(static fn ($t) => [
                    'date' => $t->date->format('Y-m-d'), 'debit' => $t->debit, 'credit' => $t->credit, 'ref' => $t->reference,
                    'counterparty' => $m($t->counterpartyName), 'iban' => $m($t->counterpartyIban), 'balance' => $t->balance, 'description' => $mask ? mb_substr($t->description, 0, 60) . '…' : $t->description,
                ], $s->transactions), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                continue;
            }
            foreach ($s->transactions as $t) {
                $output->writeln(sprintf('%s  D %12s  C %12s  bal %14s  ref %-18s  %s | %s',
                    $t->date->format('Y-m-d'), $t->debit, $t->credit, $t->balance ?? '', $t->reference ?? '', $m($t->counterpartyName) ?? '', mb_substr($t->description, 0, $mask ? 50 : 140)));
            }
        }
        $computed = null;
        foreach ($statements as $s) {
            $txs = $s->transactions;
            $computed = $txs !== [] ? end($txs)->balance : $s->openingBalance;
            $output->writeln(sprintf('<info>Validation:</info> printed closing %s vs computed %s => %s',
                $s->closingBalance ?? 'n/a', $computed ?? 'n/a',
                ($s->closingBalance !== null && $computed !== null && bccomp($s->closingBalance, $computed, 2) === 0) ? '<info>OK</info>' : '<error>MISMATCH</error>'));
        }

        return Command::SUCCESS;
    }
}
