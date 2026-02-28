<?php

namespace App\Command;

use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\LicenseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:plan:upgrade', 'Upgrade or change an organization\'s plan.')]
class PlanUpgradeCommand extends Command
{
    private const VALID_PLANS = [
        LicenseManager::PLAN_STARTER,
        LicenseManager::PLAN_PROFESSIONAL,
        LicenseManager::PLAN_BUSINESS,
    ];

    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseManager $licenseManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('organization', InputArgument::REQUIRED, 'Organization slug, UUID, or owner email')
            ->addArgument('plan', InputArgument::REQUIRED, 'Target plan: starter, professional, business')
            ->addOption('max-companies', null, InputOption::VALUE_REQUIRED, 'Override max companies limit')
            ->addOption('max-users', null, InputOption::VALUE_REQUIRED, 'Override max users limit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = $input->getArgument('organization');
        $plan = strtolower($input->getArgument('plan'));

        if (!in_array($plan, self::VALID_PLANS, true)) {
            $io->error(sprintf('Invalid plan "%s". Valid plans: %s', $plan, implode(', ', self::VALID_PLANS)));
            return Command::FAILURE;
        }

        $org = $this->organizationRepository->findBySlug($identifier)
            ?? $this->organizationRepository->findOneBy(['name' => $identifier]);

        if (!$org && Uuid::isValid($identifier)) {
            $org = $this->organizationRepository->find(Uuid::fromString($identifier));
        }

        // Lookup by user email — find the first organization the user belongs to
        if (!$org && str_contains($identifier, '@')) {
            $user = $this->userRepository->findOneBy(['email' => $identifier]);
            if ($user) {
                $memberships = $user->getOrganizationMemberships();
                if ($memberships->count() > 0) {
                    $org = $memberships->first()->getOrganization();
                }
            }
        }

        if (!$org) {
            $io->error(sprintf('Organization "%s" not found (tried slug, name, UUID, and email).', $identifier));
            return Command::FAILURE;
        }

        $oldPlan = $org->getPlan();
        $features = $this->licenseManager->getFeatures($org);

        $io->section('Current state');
        $io->table(
            ['Field', 'Value'],
            [
                ['Organization', $org->getName() . ' (' . $org->getSlug() . ')'],
                ['Current plan', $oldPlan],
                ['Max companies', $org->getMaxCompanies()],
                ['Max users', $org->getMaxUsers()],
            ],
        );

        // Apply plan
        $org->setPlan($plan);

        // Get new plan defaults
        $newFeatures = $this->licenseManager->getFeatures($org);
        $maxCompanies = $input->getOption('max-companies') !== null
            ? (int) $input->getOption('max-companies')
            : $newFeatures['maxCompanies'];
        $maxUsers = $input->getOption('max-users') !== null
            ? (int) $input->getOption('max-users')
            : $newFeatures['maxUsersPerOrg'];

        $org->setMaxCompanies($maxCompanies);
        $org->setMaxUsers($maxUsers);

        $io->section('New state');
        $io->table(
            ['Field', 'Value'],
            [
                ['Plan', $plan],
                ['Max companies', $maxCompanies === PHP_INT_MAX ? 'unlimited' : $maxCompanies],
                ['Max users', $maxUsers === PHP_INT_MAX ? 'unlimited' : $maxUsers],
            ],
        );

        if (!$io->confirm('Apply this change?', true)) {
            $io->warning('Aborted.');
            return Command::SUCCESS;
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Organization "%s" upgraded from "%s" to "%s".',
            $org->getName(),
            $oldPlan,
            $plan,
        ));

        return Command::SUCCESS;
    }
}
