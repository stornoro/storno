<?php

namespace App\Command\Anaf;

use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Shows or sets how many active ANAF token links a user of an organization may hold.
 *
 * The default is 5. An integrator that enrols many companies at once needs more, one link per
 * company waiting for its certificate holder. The value lives in Organization::settings under
 * `anafTokenLinkLimit` and is read by AnafController::createTokenLink().
 *
 *   app:anaf:link-limit acme            show the limit
 *   app:anaf:link-limit acme 100        set it to 100
 *   app:anaf:link-limit acme default    back to 5
 */
#[AsCommand('app:anaf:link-limit', 'Show or set the active ANAF token link limit of an organization.')]
class AnafLinkLimitCommand extends Command
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 500;

    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('organization', InputArgument::REQUIRED, 'Organization slug, name, UUID, or owner email')
            ->addArgument('limit', InputArgument::OPTIONAL, sprintf('New limit (%d to %d), or "default". Omit to show the current one.', self::DEFAULT_LIMIT, self::MAX_LIMIT));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('organization');

        $org = $this->organizationRepository->findBySlug($identifier)
            ?? $this->organizationRepository->findOneBy(['name' => $identifier]);
        if (!$org && Uuid::isValid($identifier)) {
            $org = $this->organizationRepository->find(Uuid::fromString($identifier));
        }
        if (!$org && str_contains($identifier, '@')) {
            $memberships = $this->userRepository->findOneBy(['email' => $identifier])?->getOrganizationMemberships();
            if ($memberships && $memberships->count() > 0) {
                $org = $memberships->first()->getOrganization();
            }
        }
        if (!$org) {
            $io->error(sprintf('Organization "%s" not found (tried slug, name, UUID, and email).', $identifier));

            return Command::FAILURE;
        }

        $settings = $org->getSettings();
        $current = isset($settings['anafTokenLinkLimit']) ? (int) $settings['anafTokenLinkLimit'] : null;
        $limit = $input->getArgument('limit');

        if ($limit === null) {
            $io->writeln(sprintf(
                '%s: %d active links per user%s',
                $org->getName(),
                $current ?? self::DEFAULT_LIMIT,
                $current === null ? ' (default)' : '',
            ));

            return Command::SUCCESS;
        }

        if (strtolower((string) $limit) === 'default') {
            unset($settings['anafTokenLinkLimit']);
        } elseif (ctype_digit((string) $limit) && (int) $limit >= self::DEFAULT_LIMIT && (int) $limit <= self::MAX_LIMIT) {
            $settings['anafTokenLinkLimit'] = (int) $limit;
        } else {
            $io->error(sprintf('The limit must be a number from %d to %d, or "default".', self::DEFAULT_LIMIT, self::MAX_LIMIT));

            return Command::FAILURE;
        }

        $org->setSettings($settings);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s: %d active links per user (was %d).',
            $org->getName(),
            $settings['anafTokenLinkLimit'] ?? self::DEFAULT_LIMIT,
            $current ?? self::DEFAULT_LIMIT,
        ));

        return Command::SUCCESS;
    }
}
