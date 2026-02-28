<?php

namespace App\Command;

use App\Manager\CompanyManager;
use App\Repository\CompanyRepository;
use App\Repository\EmailTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:email-templates:reset',
    description: 'Delete all email templates and re-seed defaults for all companies',
)]
class ResetEmailTemplatesCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly EmailTemplateRepository $emailTemplateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyManager $companyManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without making changes')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $companies = $this->companyRepository->findAll();

        if (empty($companies)) {
            $io->warning('No companies found.');
            return Command::SUCCESS;
        }

        $totalTemplates = 0;
        $rows = [];
        foreach ($companies as $company) {
            $count = count($this->emailTemplateRepository->findByCompany($company));
            $totalTemplates += $count;
            $rows[] = [$company->getName(), $company->getCif(), $count];
        }

        $io->title('Reset email templates for all companies');
        $io->table(['Company', 'CIF', 'Current templates'], $rows);
        $io->note(sprintf(
            'Will delete %d existing templates and create 7 new defaults for each of %d companies.',
            $totalTemplates,
            count($companies),
        ));

        if ($dryRun) {
            $io->note('Dry-run mode — nothing was changed.');
            return Command::SUCCESS;
        }

        if (!$force) {
            if (!$io->confirm('This will DELETE all existing email templates and replace them with defaults. Continue?', false)) {
                $io->warning('Aborted.');
                return Command::SUCCESS;
            }
        }

        $deleted = 0;
        foreach ($companies as $company) {
            $templates = $this->emailTemplateRepository->findByCompany($company);
            foreach ($templates as $template) {
                $this->entityManager->remove($template);
                $deleted++;
            }
        }
        $this->entityManager->flush();

        $seeded = 0;
        foreach ($companies as $company) {
            $this->companyManager->ensureDefaultEmailTemplates($company);
            $seeded++;
        }
        $this->entityManager->flush();

        $io->success(sprintf('Deleted %d templates. Seeded defaults for %d companies.', $deleted, $seeded));

        return Command::SUCCESS;
    }
}
