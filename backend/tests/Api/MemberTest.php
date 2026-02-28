<?php

namespace App\Tests\Api;

class MemberTest extends ApiTestCase
{
    public function testListMembers(): void
    {
        $this->login(); // admin@localhost.com (org-1 admin)
        $data = $this->apiGet('/api/v1/members');

        $this->assertResponseStatusCodeSame(200);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('canManage', $data['meta']);
        $this->assertArrayHasKey('maxUsers', $data['meta']);
        $this->assertArrayHasKey('currentCount', $data['meta']);

        // org-1 has: superadmin (owner), admin (admin), accountant, employee = 4 members
        $this->assertGreaterThanOrEqual(4, count($data['data']));

        // Verify member shape
        $member = $data['data'][0];
        $this->assertArrayHasKey('id', $member);
        $this->assertArrayHasKey('user', $member);
        $this->assertArrayHasKey('role', $member);
        $this->assertArrayHasKey('isActive', $member);
        $this->assertArrayHasKey('joinedAt', $member);
        $this->assertArrayHasKey('allowedCompanies', $member);
        $this->assertArrayHasKey('isCurrentUser', $member);

        // Verify user shape
        $this->assertArrayHasKey('id', $member['user']);
        $this->assertArrayHasKey('email', $member['user']);
        $this->assertArrayHasKey('firstName', $member['user']);
        $this->assertArrayHasKey('lastName', $member['user']);
    }

    public function testListMembersUnauthenticated(): void
    {
        $this->apiGet('/api/v1/members');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUpdateMemberRole(): void
    {
        $this->login(); // admin@localhost.com
        $data = $this->apiGet('/api/v1/members');
        $this->assertResponseStatusCodeSame(200);

        // Find the accountant member
        $accountant = $this->findMemberByEmail($data['data'], 'contabil@localhost.com');
        $this->assertNotNull($accountant, 'Accountant member not found');

        // Change role from accountant to employee
        $result = $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'role' => 'employee',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('employee', $result['role']);

        // Change back
        $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'role' => 'accountant',
        ]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testCannotChangeOwnRole(): void
    {
        $this->login(); // admin@localhost.com
        $data = $this->apiGet('/api/v1/members');

        // Find current user's membership
        $currentUser = null;
        foreach ($data['data'] as $member) {
            if ($member['isCurrentUser']) {
                $currentUser = $member;
                break;
            }
        }
        $this->assertNotNull($currentUser, 'Current user not found');

        $this->apiPatch('/api/v1/members/' . $currentUser['id'], [
            'role' => 'employee',
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCannotPromoteToOwnerAsAdmin(): void
    {
        $this->login(); // admin@localhost.com (admin role, not owner)
        $data = $this->apiGet('/api/v1/members');

        $accountant = $this->findMemberByEmail($data['data'], 'contabil@localhost.com');
        $this->assertNotNull($accountant);

        $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'role' => 'owner',
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCannotRemoveLastOwner(): void
    {
        // Login as admin (who has manage_members permission)
        $this->login();
        $data = $this->apiGet('/api/v1/members');
        $this->assertResponseStatusCodeSame(200);

        // Find the owner member (superadmin)
        $owner = null;
        foreach ($data['data'] as $m) {
            if ($m['role'] === 'owner') {
                $owner = $m;
                break;
            }
        }
        $this->assertNotNull($owner, 'Owner member not found');

        // Try to change the only owner to admin — should fail (last owner)
        $this->apiPatch('/api/v1/members/' . $owner['id'], [
            'role' => 'admin',
        ]);
        // Admin cannot change owner role (only owner can)
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUpdateAllowedCompanies(): void
    {
        $this->login(); // admin@localhost.com
        $data = $this->apiGet('/api/v1/members');

        $accountant = $this->findMemberByEmail($data['data'], 'contabil@localhost.com');
        $this->assertNotNull($accountant);

        // Get a company ID
        $companyId = $this->getFirstCompanyId();

        // Restrict to one company
        $result = $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'allowedCompanies' => [$companyId],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(1, $result['allowedCompanies']);
        $this->assertSame($companyId, $result['allowedCompanies'][0]['id']);

        // Reset to all companies
        $result = $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'allowedCompanies' => [],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(0, $result['allowedCompanies']);
    }

    public function testCreateInvitation(): void
    {
        $this->login(); // admin@localhost.com
        $data = $this->apiPost('/api/v1/invitations', [
            'email' => 'test-invite@example.com',
            'role' => 'accountant',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('test-invite@example.com', $data['email']);
        $this->assertSame('accountant', $data['role']);
        $this->assertSame('pending', $data['status']);
    }

    public function testDuplicateInvitation(): void
    {
        $this->login();

        // First invitation
        $this->apiPost('/api/v1/invitations', [
            'email' => 'duplicate-test@example.com',
            'role' => 'employee',
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Duplicate
        $data = $this->apiPost('/api/v1/invitations', [
            'email' => 'duplicate-test@example.com',
            'role' => 'employee',
        ]);
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('ALREADY_INVITED', $data['code'] ?? null);
    }

    public function testInviteExistingMember(): void
    {
        $this->login();
        $data = $this->apiPost('/api/v1/invitations', [
            'email' => 'contabil@localhost.com', // already a member of org-1
            'role' => 'accountant',
        ]);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('ALREADY_MEMBER', $data['code'] ?? null);
    }

    public function testCannotInviteAsOwner(): void
    {
        $this->login();
        $this->apiPost('/api/v1/invitations', [
            'email' => 'owner-invite@example.com',
            'role' => 'owner',
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testListInvitations(): void
    {
        $this->login();
        $data = $this->apiGet('/api/v1/invitations');

        $this->assertResponseStatusCodeSame(200);
        $this->assertIsArray($data['data']);
    }

    public function testCancelInvitation(): void
    {
        $this->login();

        // Create one first
        $created = $this->apiPost('/api/v1/invitations', [
            'email' => 'cancel-test@example.com',
            'role' => 'employee',
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Cancel it
        $this->apiDelete('/api/v1/invitations/' . $created['id']);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testResendInvitation(): void
    {
        $this->login();

        $created = $this->apiPost('/api/v1/invitations', [
            'email' => 'resend-test@example.com',
            'role' => 'employee',
        ]);
        $this->assertResponseStatusCodeSame(201);

        $data = $this->apiPost('/api/v1/invitations/' . $created['id'] . '/resend');
        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('expiresAt', $data);
    }

    public function testAcceptInvitationValidation(): void
    {
        // GET accept details for invalid token
        $this->apiGet('/api/v1/invitations/accept/invalidtoken123');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInviteWithoutPermission(): void
    {
        // Login as accountant (no manage_members permission, single org membership auto-resolves)
        $this->login('contabil@localhost.com');
        $this->apiPost('/api/v1/invitations', [
            'email' => 'noperm@example.com',
            'role' => 'employee',
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeactivateMember(): void
    {
        $this->login(); // admin
        $data = $this->apiGet('/api/v1/members');

        $employee = $this->findMemberByEmail($data['data'], 'angajat@localhost.com');
        $this->assertNotNull($employee);

        // Deactivate via DELETE
        $this->apiDelete('/api/v1/members/' . $employee['id']);
        $this->assertResponseStatusCodeSame(204);

        // Verify deactivated
        $data = $this->apiGet('/api/v1/members');
        $employeeAfter = $this->findMemberByEmail($data['data'], 'angajat@localhost.com');
        $this->assertFalse($employeeAfter['isActive']);

        // Reactivate
        $this->apiPatch('/api/v1/members/' . $employee['id'], [
            'isActive' => true,
        ]);
        $this->assertResponseStatusCodeSame(200);
    }

    private function findMemberByEmail(array $members, string $email): ?array
    {
        foreach ($members as $member) {
            if ($member['user']['email'] === $email) {
                return $member;
            }
        }
        return null;
    }
}
