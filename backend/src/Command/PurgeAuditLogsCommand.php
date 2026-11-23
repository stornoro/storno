<?php

namespace App\Command;

use App\Command\Support\BatchedDelete;
use App\Command\Support\CommandProgress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:audit-log:purge',
    description: 'Purge audit log entries older than a given period',
)]
class PurgeAuditLogsCommand extends Command
{
    private const DEFAULT_RETENTION = '-6 months';

    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('retention', InputArgument::OPTIONAL, 'Delete entries older than this (e.g. "-6 months", "-1 year"). Default: -6 months')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows deleted per statement', (string) BatchedDelete::DEFAULT_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retention = $input->getArgument('retention') ?? self::DEFAULT_RETENTION;
        $cutoff = new \DateTimeImmutable($retention);
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        $params = ['cutoff' => $cutoff->format('Y-m-d H:i:s')];
        $total = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE created_at < :cutoff', $params);
        $progress = new CommandProgress($output, 'audit-log:purge', $total);
        $progress->start(sprintf('%d audit log rows older than %s, deleted %d at a time', $total, $cutoff->format('Y-m-d'), $batchSize));

        $deleted = BatchedDelete::run(
            $this->em->getConnection(),
            'DELETE FROM audit_log WHERE created_at < :cutoff',
            $params,
            $batchSize,
            static fn (int $n) => $progress->advance($n),
        );
        $progress->finish();

        $output->writeln(sprintf('Purged <comment>%d</comment> audit log entries older than %s.', $deleted, $cutoff->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
