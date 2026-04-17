<?php

namespace App\Tests\Api;

use App\Entity\Company;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\UserRepository;

class CompanyAccessTest extends ApiTestCase
{
    public function testAccountantSeesAllCompaniesByDefault(): void
    {
        $this->login('contabil@localhost.com'); // accountant in org-1, no company restrictions
        $data = $this->apiGet('/api/v1/companies');

        $this->assertResponseStatusCodeSame(200);
        // org-1 has 3 companies
        $this->assertCount(3, $data['data']);
    }

    public function testAccountantWithRestrictedAccess(): void
    {
        // First, restrict the accountant to company-1 only
        $this->login(); // admin
        $members = $this->apiGet('/api/v1/members');
        $this->assertResponseStatusCodeSame(200);

        $accountant = null;
        foreach ($members['data'] as $m) {
            if ($m['user']['email'] === 'contabil@localhost.com') {
                $accountant = $m;
                break;
            }
        }
        $this->assertNotNull($accountant);

        $companyId = $this->getFirstCompanyId();

        // Restrict to first company only
        $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'allowedCompanies' => [$companyId],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Now login as accountant and verify restricted view
        $this->login('contabil@localhost.com');
        $data = $this->apiGet('/api/v1/companies');
        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(1, $data['data']);
        $this->assertSame($companyId, $data['data'][0]['id']);

        // Clean up: remove restrictions
        $this->login(); // admin
        $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'allowedCompanies' => [],
        ]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testAdminAlwaysSeesAllCompanies(): void
    {
        $this->login(); // admin@localhost.com (admin role in org-1)
        $data = $this->apiGet('/api/v1/companies');

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(3, $data['data']);
    }

    public function testFindActiveUsersByCompanyRespectsAllowedCompanies(): void
    {
        // Setup: restrict the accountant to the first company only
        $this->login(); // admin
        $members = $this->apiGet('/api/v1/members');
        $accountant = null;
        foreach ($members['data'] as $m) {
            if ($m['user']['email'] === 'contabil@localhost.com') {
                $accountant = $m;
                break;
            }
        }
        $this->assertNotNull($accountant);

        $companies = $this->apiGet('/api/v1/companies');
        $this->assertGreaterThanOrEqual(2, count($companies['data']));
        $firstCompanyId = $companies['data'][0]['id'];
        $secondCompanyId = $companies['data'][1]['id'];

        $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'allowedCompanies' => [$firstCompanyId],
        ]);
        $this->assertResponseStatusCodeSame(200);

        /** @var CompanyRepository $companyRepo */
        $companyRepo = self::getContainer()->get(CompanyRepository::class);
        /** @var OrganizationMembershipRepository $membershipRepo */
        $membershipRepo = self::getContainer()->get(OrganizationMembershipRepository::class);

        $firstCompany = $companyRepo->find($firstCompanyId);
        $secondCompany = $companyRepo->find($secondCompanyId);
        $this->assertNotNull($firstCompany);
        $this->assertNotNull($secondCompany);

        // Accountant IS allowed on first company → must be in notification recipients
        $usersForFirst = array_map(fn (User $u) => $u->getEmail(), $membershipRepo->findActiveUsersByCompany($firstCompany));
        $this->assertContains('contabil@localhost.com', $usersForFirst, 'Accountant should receive notifications for their allowed company');

        // Accountant is NOT allowed on second company → must NOT be in recipients
        $usersForSecond = array_map(fn (User $u) => $u->getEmail(), $membershipRepo->findActiveUsersByCompany($secondCompany));
        $this->assertNotContains('contabil@localhost.com', $usersForSecond, 'Accountant must NOT receive notifications for companies they are not allowed on');

        // Owner/admin must always be included regardless of the company
        $this->assertContains('admin@localhost.com', $usersForFirst);
        $this->assertContains('admin@localhost.com', $usersForSecond);

        // Clean up: restore access
        $this->login();
        $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'allowedCompanies' => [],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // With no restrictions, accountant is back on both lists
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $usersForSecondAfter = array_map(
            fn (User $u) => $u->getEmail(),
            $membershipRepo->findActiveUsersByCompany($companyRepo->find($secondCompanyId))
        );
        $this->assertContains('contabil@localhost.com', $usersForSecondAfter, 'Unrestricted accountant should be on every company notification list');
    }

    public function testFindActiveUsersByCompanySkipsInactiveUsers(): void
    {
        $this->login(); // admin
        $companies = $this->apiGet('/api/v1/companies');
        $companyId = $companies['data'][0]['id'];

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        /** @var CompanyRepository $companyRepo */
        $companyRepo = self::getContainer()->get(CompanyRepository::class);
        /** @var OrganizationMembershipRepository $membershipRepo */
        $membershipRepo = self::getContainer()->get(OrganizationMembershipRepository::class);
        /** @var UserRepository $userRepo */
        $userRepo = self::getContainer()->get(UserRepository::class);

        $company = $companyRepo->find($companyId);

        // Employee starts out active and on the notification list
        $users = array_map(fn (User $u) => $u->getEmail(), $membershipRepo->findActiveUsersByCompany($company));
        $this->assertContains('angajat@localhost.com', $users);

        // Deactivate the employee user
        $employee = $userRepo->findOneBy(['email' => 'angajat@localhost.com']);
        $this->assertNotNull($employee);
        $employee->setActive(false);
        $em->flush();

        try {
            $usersAfter = array_map(fn (User $u) => $u->getEmail(), $membershipRepo->findActiveUsersByCompany($company));
            $this->assertNotContains('angajat@localhost.com', $usersAfter, 'Inactive users must not appear in notification recipients');
        } finally {
            $employee->setActive(true);
            $em->flush();
        }
    }

    public function testRestrictedUserCannotAccessOtherCompany(): void
    {
        // Setup: restrict accountant to company-1
        $this->login(); // admin
        $members = $this->apiGet('/api/v1/members');
        $accountant = null;
        foreach ($members['data'] as $m) {
            if ($m['user']['email'] === 'contabil@localhost.com') {
                $accountant = $m;
                break;
            }
        }
        $this->assertNotNull($accountant);

        $companies = $this->apiGet('/api/v1/companies');
        $this->assertCount(3, $companies['data']);

        $firstCompanyId = $companies['data'][0]['id'];
        $secondCompanyId = $companies['data'][1]['id'];

        // Restrict to first company only
        $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'allowedCompanies' => [$firstCompanyId],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Login as accountant and try to access restricted company
        $this->login('contabil@localhost.com');

        // Accessing the allowed company should work
        $this->apiGet('/api/v1/companies/' . $firstCompanyId);
        $this->assertResponseStatusCodeSame(200);

        // Accessing the non-allowed company should fail
        $this->apiGet('/api/v1/companies/' . $secondCompanyId);
        $this->assertResponseStatusCodeSame(403);

        // Clean up
        $this->login(); // admin
        $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'allowedCompanies' => [],
        ]);
    }
}
