<?php

namespace App\Tests\Api;

use App\Entity\Company;
use App\Entity\OrganizationMembership;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationMembershipRepository;

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
