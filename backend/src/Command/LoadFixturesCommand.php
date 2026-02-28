<?php

namespace App\Command;

use App\Doctrine\Purger\ResetAutoIncrementORMPurger;
use App\Doctrine\Purger\DoNotUsePurgerFactory;
use App\Doctrine\Purger\ResetAutoIncrementPurgerFactory;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This command does basically the same as doctrine:fixtures:load, but it purges the database before loading the fixtures.
 * It does so in another transaction, so we can modify the purger to reset the autoincrement, which would not be possible
 * because the implicit commit otherwise.
 */
#[AsCommand(name: 'app:fixtures:load', description: 'Load test fixtures into the database and allows to reset the autoincrement before loading the fixtures.', hidden: true)]
class LoadFixturesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = new SymfonyStyle($input, $output);

        $ui->warning('This command is for development and testing purposes only. It will purge the database and load fixtures afterwards. Do not use in production!');

        if (!$ui->confirm(sprintf('Careful, database "%s" will be purged. Do you want to continue?', $this->entityManager->getConnection()->getDatabase()), !$input->isInteractive())) {
            return 0;
        }

        $factory = new ResetAutoIncrementPurgerFactory();
        $purger = $factory->createForEntityManager(null, $this->entityManager);

        $purger->purge();

        //Afterwards run the load fixtures command as normal, but with the --append option
        $new_input = new ArrayInput([
            'command' => 'doctrine:fixtures:load',
            '--append' => true,
        ]);

        $returnCode = $this->getApplication()?->doRun($new_input, $output);

        return $returnCode ?? Command::FAILURE;
    }
}
