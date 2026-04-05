<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
            ->addArgument('retention', InputArgument::OPTIONAL, 'Delete entries older than this (e.g. "-6 months", "-1 year"). Default: -6 months');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retention = $input->getArgument('retention') ?? self::DEFAULT_RETENTION;
        $cutoff = new \DateTimeImmutable($retention);

        $deleted = $this->em->getConnection()->executeStatement(
            'DELETE FROM audit_log WHERE created_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')]
        );

        $output->writeln(sprintf('Purged <comment>%d</comment> audit log entries older than %s.', $deleted, $cutoff->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
