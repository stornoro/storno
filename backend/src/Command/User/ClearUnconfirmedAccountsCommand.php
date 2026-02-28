<?php

namespace App\Command\User;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:user:clear-unconfirmed',
    description: 'Delete unconfirmed user accounts from the database',
)]
class ClearUnconfirmedAccountsCommand extends Command
{
    private const DEFAULT_DATETIME_VALUE = '-1 week';

    public function __construct(private UserRepository $userRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('datetime', InputArgument::OPTIONAL, 'Delete accounts created before this date (default: -1 week)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $datetime = $input->getArgument('datetime');
        if (is_null($datetime)) {
            $datetime = new \DateTime(self::DEFAULT_DATETIME_VALUE);
        } else {
            $datetime = new \DateTime($datetime);
        }

        $output->writeln('Deleting unconfirmed users...');

        [$isSuccess, $deletedUsers] = $this->userRepository->clearUnconfirmedAccounts($datetime);

        if ($isSuccess) {
            foreach ($deletedUsers as $deletedUser) {
                $output->writeln(
                    sprintf(
                        'Deleted user: <comment>%s</comment>',
                        $deletedUser->getUserIdentifier()
                    )
                );
            }

            return Command::SUCCESS;
        }

        return Command::FAILURE;
    }
}
