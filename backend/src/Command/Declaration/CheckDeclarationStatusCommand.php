<?php

namespace App\Command\Declaration;

use App\Entity\TaxDeclaration;
use App\Enum\DeclarationStatus;
use App\Message\Declaration\CheckDeclarationStatusMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Re-queues the ANAF status check for declarations that carry an upload index but
 * are still marked as processing (for example after the check was not dispatched).
 */
#[AsCommand(
    name: 'app:declarations:check-status',
    description: 'Queue an ANAF status check for processing declarations with an upload index',
)]
class CheckDeclarationStatusCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('ids', InputArgument::IS_ARRAY, 'Declaration UUIDs (default: every processing declaration with an index)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repository = $this->entityManager->getRepository(TaxDeclaration::class);
        $ids = $input->getArgument('ids');

        $declarations = $ids !== []
            ? array_filter(array_map(static fn (string $id) => $repository->find($id), $ids))
            : $repository->findBy(['status' => [DeclarationStatus::SUBMITTED, DeclarationStatus::PROCESSING]]);

        $queued = 0;
        foreach ($declarations as $declaration) {
            if ($declaration->getAnafUploadId() === null) {
                $io->warning(sprintf('%s has no upload index, skipped.', $declaration->getId()));
                continue;
            }
            $this->messageBus->dispatch(new CheckDeclarationStatusMessage(declarationId: (string) $declaration->getId()));
            ++$queued;
        }

        $io->success(sprintf('%d status check(s) queued.', $queued));

        return Command::SUCCESS;
    }
}
