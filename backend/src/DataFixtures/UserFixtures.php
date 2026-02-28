<?php

namespace App\DataFixtures;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Entity\UserBilling;
use App\Enum\OrganizationRole;
use App\Enum\UserRoles;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private UserPasswordHasherInterface $encoder,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // 1. Super Admin (platform admin, all orgs)
        $superAdmin = (new User())
            ->setEmail('superadmin@storno.ro')
            ->setPassword('temp')
            ->setFirstName('Super')
            ->setLastName('Admin')
            ->addRole(UserRoles::ROLE_SUPER_ADMIN)
            ->setLocale('ro')
            ->setTimezone('Europe/Bucharest')
            ->setEmailVerified(true)
            ->activate(true)
            ->setUserBilling((new UserBilling())
                ->setFirstName('Super')
                ->setLastName('Admin')
                ->setCompanyName('Storno.ro')
                ->setVatCode('RO00000000')
                ->setCity('Bucuresti')
                ->setAddress('Str. Admin 1')
            );
        $superAdmin->setPassword($this->encoder->hashPassword($superAdmin, 'password'));
        $manager->persist($superAdmin);
        $this->addReference('user-superadmin', $superAdmin);

        // Super admin membership in org-1 as owner
        $membership = (new OrganizationMembership())
            ->setUser($superAdmin)
            ->setOrganization($this->getReference('org-1', Organization::class))
            ->setRole(OrganizationRole::OWNER)
            ->setPermissions([])
            ->setIsActive(true);
        $manager->persist($membership);

        // 2. Admin user (org-1 owner, main demo user)
        $admin = (new User())
            ->setEmail('admin@localhost.com')
            ->setPassword('temp')
            ->setFirstName('John')
            ->setLastName('Doe')
            ->addRole(UserRoles::ROLE_ADMIN)
            ->setLocale('ro')
            ->setTimezone('Europe/Bucharest')
            ->setEmailVerified(true)
            ->activate(true)
            ->setUserBilling((new UserBilling())
                ->setFirstName('John')
                ->setLastName('Doe')
                ->setCompanyName('Company')
                ->setVatCode('RO999999')
                ->setCity('Bacau')
                ->setAddress('Sos. Oltneitei 19D')
            );
        $admin->setPassword($this->encoder->hashPassword($admin, 'password'));
        $manager->persist($admin);
        $this->addReference('user-1', $admin);

        // Admin membership in org-1 as admin
        $membership = (new OrganizationMembership())
            ->setUser($admin)
            ->setOrganization($this->getReference('org-1', Organization::class))
            ->setRole(OrganizationRole::ADMIN)
            ->setPermissions([])
            ->setIsActive(true);
        $manager->persist($membership);

        // 3. Regular user (org-2 owner)
        $user = (new User())
            ->setEmail('user@localhost.com')
            ->setPassword('temp')
            ->setFirstName('Maria')
            ->setLastName('Ionescu')
            ->addRole(UserRoles::ROLE_USER)
            ->setLocale('ro')
            ->setTimezone('Europe/Bucharest')
            ->setEmailVerified(true)
            ->activate(true)
            ->setUserBilling((new UserBilling())
                ->setFirstName('Maria')
                ->setLastName('Ionescu')
                ->setCompanyName('Contabilitate Expert')
                ->setVatCode('RO11223344')
                ->setCity('Timisoara')
                ->setAddress('Str. Libertatii 10')
            );
        $user->setPassword($this->encoder->hashPassword($user, 'password'));
        $manager->persist($user);
        $this->addReference('user-2', $user);

        // User membership in org-2 as owner
        $membership = (new OrganizationMembership())
            ->setUser($user)
            ->setOrganization($this->getReference('org-2', Organization::class))
            ->setRole(OrganizationRole::OWNER)
            ->setPermissions([])
            ->setIsActive(true);
        $manager->persist($membership);

        // 4. Accountant (org-1 accountant)
        $accountant = (new User())
            ->setEmail('contabil@localhost.com')
            ->setPassword('temp')
            ->setFirstName('Ana')
            ->setLastName('Contabil')
            ->addRole(UserRoles::ROLE_CONTABIL)
            ->setLocale('ro')
            ->setTimezone('Europe/Bucharest')
            ->setEmailVerified(true)
            ->activate(true)
            ->setUserBilling((new UserBilling())
                ->setFirstName('Ana')
                ->setLastName('Contabil')
                ->setCompanyName('Contabilitate')
                ->setVatCode('RO999999')
                ->setCity('Bacau')
                ->setAddress('Str. Contabililor 5')
            );
        $accountant->setPassword($this->encoder->hashPassword($accountant, 'password'));
        $manager->persist($accountant);
        $this->addReference('user-3', $accountant);

        // Accountant membership in org-1
        $membership = (new OrganizationMembership())
            ->setUser($accountant)
            ->setOrganization($this->getReference('org-1', Organization::class))
            ->setRole(OrganizationRole::ACCOUNTANT)
            ->setPermissions([])
            ->setIsActive(true);
        $manager->persist($membership);

        // 5. Employee (org-1 employee, also member of org-2)
        $employee = (new User())
            ->setEmail('angajat@localhost.com')
            ->setPassword('temp')
            ->setFirstName('Mihai')
            ->setLastName('Angajat')
            ->addRole(UserRoles::ROLE_ANGAJAT)
            ->setLocale('ro')
            ->setTimezone('Europe/Bucharest')
            ->setEmailVerified(true)
            ->activate(true)
            ->setUserBilling((new UserBilling())
                ->setFirstName('Mihai')
                ->setLastName('Angajat')
                ->setCompanyName('UEP')
                ->setVatCode('RO31385365')
                ->setCity('Bacau')
                ->setAddress('Str. Angajatilor 3')
            );
        $employee->setPassword($this->encoder->hashPassword($employee, 'password'));
        $manager->persist($employee);
        $this->addReference('user-4', $employee);

        // Employee membership in org-1
        $membership = (new OrganizationMembership())
            ->setUser($employee)
            ->setOrganization($this->getReference('org-1', Organization::class))
            ->setRole(OrganizationRole::EMPLOYEE)
            ->setPermissions([])
            ->setIsActive(true);
        $manager->persist($membership);

        // Employee also in org-2 as accountant
        $membership = (new OrganizationMembership())
            ->setUser($employee)
            ->setOrganization($this->getReference('org-2', Organization::class))
            ->setRole(OrganizationRole::ACCOUNTANT)
            ->setPermissions([])
            ->setIsActive(true);
        $manager->persist($membership);

        // 6. Freelancer (org-3 owner)
        $freelancer = (new User())
            ->setEmail('ion.popescu@gmail.com')
            ->setPassword('temp')
            ->setFirstName('Ion')
            ->setLastName('Popescu')
            ->addRole(UserRoles::ROLE_USER)
            ->setLocale('ro')
            ->setTimezone('Europe/Bucharest')
            ->setEmailVerified(true)
            ->activate(true)
            ->setUserBilling((new UserBilling())
                ->setFirstName('Ion')
                ->setLastName('Popescu')
                ->setCompanyName('Ion Popescu PFA')
                ->setVatCode('RO12345678')
                ->setCity('Iasi')
                ->setAddress('Str. Mihai Viteazu 12')
            );
        $freelancer->setPassword($this->encoder->hashPassword($freelancer, 'password'));
        $manager->persist($freelancer);
        $this->addReference('user-5', $freelancer);

        // Freelancer membership in org-3 as owner
        $membership = (new OrganizationMembership())
            ->setUser($freelancer)
            ->setOrganization($this->getReference('org-3', Organization::class))
            ->setRole(OrganizationRole::OWNER)
            ->setPermissions([])
            ->setIsActive(true);
        $manager->persist($membership);

        // 7. Inactive user
        $inactive = (new User())
            ->setEmail('inactive@localhost.com')
            ->setPassword('temp')
            ->setFirstName('Gheorghe')
            ->setLastName('Inactiv')
            ->addRole(UserRoles::ROLE_USER)
            ->setLocale('ro')
            ->setTimezone('Europe/Bucharest')
            ->setEmailVerified(false)
            ->activate(false)
            ->setUserBilling((new UserBilling())
                ->setFirstName('Gheorghe')
                ->setLastName('Inactiv')
                ->setCompanyName('-')
                ->setVatCode('-')
                ->setCity('Bucuresti')
                ->setAddress('Str. Inactiva 1')
            );
        $inactive->setPassword($this->encoder->hashPassword($inactive, 'password'));
        $manager->persist($inactive);
        $this->addReference('user-6', $inactive);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            OrganizationFixtures::class,
        ];
    }
}
