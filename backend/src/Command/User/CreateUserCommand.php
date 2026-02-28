<?php

namespace App\Command\User;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Enum\OrganizationRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create a new user account',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SluggerInterface $slugger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'User email address')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'User password')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'First name')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Last name')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getOption('email') ?? $io->ask('Email');
        $password = $input->getOption('password') ?? $io->askHidden('Password');
        $firstName = $input->getOption('first-name') ?? $io->ask('First name', '');
        $lastName = $input->getOption('last-name') ?? $io->ask('Last name', '');
        $isAdmin = $input->getOption('admin');

        if (!$email || !$password) {
            $io->error('Email and password are required.');
            return Command::FAILURE;
        }

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            $io->error(sprintf('A user with email "%s" already exists.', $email));
            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName ?: null);
        $user->setLastName($lastName ?: null);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setActive(true);
        $user->setEmailVerified(true);
        $user->setRoles($isAdmin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER']);

        // Create default organization
        $orgName = trim(($firstName ?: '') . ' ' . ($lastName ?: '')) ?: $email;
        $organization = new Organization();
        $organization->setName($orgName);
        $organization->setSlug($this->slugger->slug($orgName)->lower()->toString() . '-' . substr(md5(uniqid()), 0, 6));

        // Create owner membership
        $membership = new OrganizationMembership();
        $membership->setUser($user);
        $membership->setOrganization($organization);
        $membership->setRole(OrganizationRole::OWNER);
        $membership->setIsActive(true);

        $this->entityManager->persist($user);
        $this->entityManager->persist($organization);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        $io->success(sprintf('User "%s" created successfully (ID: %s).', $email, $user->getId()));

        return Command::SUCCESS;
    }
}
