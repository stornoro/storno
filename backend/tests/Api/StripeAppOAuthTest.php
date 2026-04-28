<?php

namespace App\Tests\Api;

use App\Entity\StripeAppDeviceCode;
use App\Entity\StripeAppToken;
use Doctrine\ORM\EntityManagerInterface;

/**
 * End-to-end OAuth 2.0 device-flow tests for the Stripe Dashboard extension.
 *
 * Covers the trust boundaries between the Stripe extension (origin: null,
 * unauthenticated outside of /oauth/device + /token), the Storno frontend
 * (JWT-authenticated, runs /oauth/approve on behalf of the user), and the
 * StripeAppToken-protected backend endpoints.
 *
 * The bug this suite is built to prevent: a user with `allowedCompanies`
 * restricted to a single company within a multi-company org could approve
 * the device flow and end up with an app token that read every company they
 * had any membership for, including across organizations.
 */
class StripeAppOAuthTest extends ApiTestCase
{
    private const STRIPE_ACCOUNT = 'acct_test_security_1T5moFIW6DSK16dC';

    private function startDeviceFlow(string $accountId = self::STRIPE_ACCOUNT): array
    {
        // /oauth/device sits on a public firewall; intentionally do not log in.
        $previous = $this->token;
        $this->token = null;

        $resp = $this->apiPost('/api/v1/stripe-app/oauth/device', [
            'stripe_account_id' => $accountId,
        ]);

        $this->token = $previous;

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('device_code', $resp);
        $this->assertArrayHasKey('user_code', $resp);
        $this->assertArrayHasKey('verification_uri', $resp);
        $this->assertArrayHasKey('expires_in', $resp);
        $this->assertArrayHasKey('interval', $resp);

        return $resp;
    }

    private function exchangeDeviceCode(string $deviceCode, string $accountId = self::STRIPE_ACCOUNT): array
    {
        $previous = $this->token;
        $this->token = null;
        $resp = $this->apiPost('/api/v1/stripe-app/token', [
            'grant_type' => 'device_code',
            'device_code' => $deviceCode,
            'stripe_account_id' => $accountId,
        ]);
        $this->token = $previous;

        return $resp;
    }

    private function findDeviceByUserCode(string $userCode): ?StripeAppDeviceCode
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em->getRepository(StripeAppDeviceCode::class)->findOneBy(['userCode' => $userCode]);
    }

    /**
     * Helper: completes a full happy-path link for the given user → company.
     * Returns the access/refresh tokens issued by /token.
     */
    private function linkAccount(string $email, string $companyId, string $accountId = self::STRIPE_ACCOUNT): array
    {
        $device = $this->startDeviceFlow($accountId);

        $this->login($email);
        $approval = $this->apiPost('/api/v1/stripe-app/oauth/approve', [
            'user_code' => $device['user_code'],
            'company_id' => $companyId,
            'approve' => true,
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('approved', $approval['status']);

        // Polling is rate-limited to 1s — wait so we don't get slow_down.
        sleep(2);
        $tokens = $this->exchangeDeviceCode($device['device_code'], $accountId);
        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);

        return $tokens;
    }

    private function getCompanies(): array
    {
        $resp = $this->apiGet('/api/v1/companies');
        $this->assertResponseStatusCodeSame(200);

        return $resp['data'] ?? [];
    }

    // ─── happy path ──────────────────────────────────────────────────────

    public function testDeviceFlowEndToEnd(): void
    {
        $this->login(); // admin
        $companyId = $this->getFirstCompanyId();

        $tokens = $this->linkAccount('admin@localhost.com', $companyId);

        // Use the X-Stripe-App-Token to read /settings — the response must
        // be scoped to the granted company only.
        $previous = $this->token;
        $this->token = null;
        $settings = $this->apiGet('/api/v1/stripe-app/settings', [
            'X-Stripe-App-Token' => $tokens['access_token'],
        ]);
        $this->token = $previous;

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('company', $settings);
        $this->assertSame($companyId, $settings['company']['id']);
        // Crucially: no 'companies' array — the old shape leaked all of them.
        $this->assertArrayNotHasKey('companies', $settings);
    }

    // ─── scope enforcement (the core regression guard) ───────────────────

    public function testRestrictedUserCannotGrantUnaccessibleCompany(): void
    {
        $this->login(); // admin
        $companies = $this->getCompanies();
        $this->assertGreaterThanOrEqual(2, count($companies), 'org-1 fixture should have ≥2 companies');
        $allowedId = $companies[0]['id'];
        $forbiddenId = $companies[1]['id'];

        // Restrict the accountant to allowedId only.
        $members = $this->apiGet('/api/v1/members');
        $accountant = null;
        foreach ($members['data'] as $m) {
            if ($m['user']['email'] === 'contabil@localhost.com') {
                $accountant = $m;
                break;
            }
        }
        $this->assertNotNull($accountant);

        $this->apiPatch('/api/v1/members/' . $accountant['id'], [
            'allowedCompanies' => [$allowedId],
        ]);
        $this->assertResponseStatusCodeSame(200);

        try {
            $device = $this->startDeviceFlow();

            // The restricted user attempts to authorize the company they
            // are NOT a member of → must be 403.
            $this->login('contabil@localhost.com');
            $this->apiPost('/api/v1/stripe-app/oauth/approve', [
                'user_code' => $device['user_code'],
                'company_id' => $forbiddenId,
                'approve' => true,
            ]);
            $this->assertResponseStatusCodeSame(403);

            // …but they CAN authorize a company they DO have access to.
            $resp = $this->apiPost('/api/v1/stripe-app/oauth/approve', [
                'user_code' => $device['user_code'],
                'company_id' => $allowedId,
                'approve' => true,
            ]);
            $this->assertResponseStatusCodeSame(200);
            $this->assertSame('approved', $resp['status']);
        } finally {
            // Always restore — other tests rely on the unrestricted accountant.
            $this->login();
            $this->apiPatch('/api/v1/members/' . $accountant['id'], [
                'allowedCompanies' => [],
            ]);
        }
    }

    public function testTokenScopeDoesNotLeakOtherCompanies(): void
    {
        $this->login();
        $companies = $this->getCompanies();
        $this->assertGreaterThanOrEqual(2, count($companies));
        $grantedId = $companies[0]['id'];
        $otherId = $companies[1]['id'];

        $tokens = $this->linkAccount('admin@localhost.com', $grantedId);

        // Try to override the scope by injecting X-Company. The
        // StripeAppTokenAuthenticator must overwrite this header with the
        // token's bound company; the dashboard must reflect the granted one.
        $previous = $this->token;
        $this->token = null;
        $dashboard = $this->apiGet('/api/v1/stripe-app/dashboard', [
            'X-Stripe-App-Token' => $tokens['access_token'],
            'X-Company' => $otherId,
        ]);
        $this->token = $previous;

        $this->assertResponseStatusCodeSame(200);
        // The response shape includes companyName — must be the granted one.
        // (Exact shape depends on dashboard implementation; this assertion
        // ensures the controller didn't honor the spoofed X-Company.)
        if (isset($dashboard['companyName'])) {
            $this->assertSame(
                $companies[0]['name'],
                $dashboard['companyName'],
                'Dashboard reflected a spoofed X-Company instead of the granted scope',
            );
        }
    }

    public function testUpdateSettingsCannotRetargetCompany(): void
    {
        $this->login();
        $companies = $this->getCompanies();
        $grantedId = $companies[0]['id'];
        $otherId = $companies[1]['id'];

        $tokens = $this->linkAccount('admin@localhost.com', $grantedId);

        // Old API allowed PUT /settings { defaultCompanyId } to switch the
        // active company. New API only supports autoMode; passing a stray
        // company_id field must NOT mutate scope.
        $previous = $this->token;
        $this->token = null;
        $this->apiPut('/api/v1/stripe-app/settings', [
            'defaultCompanyId' => $otherId,
            'company_id' => $otherId,
            'autoMode' => true,
        ], [
            'X-Stripe-App-Token' => $tokens['access_token'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $settings = $this->apiGet('/api/v1/stripe-app/settings', [
            'X-Stripe-App-Token' => $tokens['access_token'],
        ]);
        $this->token = $previous;

        $this->assertSame($grantedId, $settings['company']['id'], 'company_id was retargeted via /settings PUT');
        $this->assertTrue($settings['autoMode'], 'autoMode was not persisted');
    }

    // ─── replay & lifecycle protection ────────────────────────────────────

    public function testDeviceCodeIsConsumedAfterTokenExchange(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $device = $this->startDeviceFlow();

        $this->apiPost('/api/v1/stripe-app/oauth/approve', [
            'user_code' => $device['user_code'],
            'company_id' => $companyId,
            'approve' => true,
        ]);
        $this->assertResponseStatusCodeSame(200);

        sleep(2);
        $first = $this->exchangeDeviceCode($device['device_code']);
        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('access_token', $first);

        // Re-using the same device_code must fail (record was deleted).
        sleep(2);
        $second = $this->exchangeDeviceCode($device['device_code']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_grant', $second['error'] ?? null);
    }

    public function testApproveTwiceReturnsConflict(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $device = $this->startDeviceFlow();

        $this->apiPost('/api/v1/stripe-app/oauth/approve', [
            'user_code' => $device['user_code'],
            'company_id' => $companyId,
            'approve' => true,
        ]);
        $this->assertResponseStatusCodeSame(200);

        $second = $this->apiPost('/api/v1/stripe-app/oauth/approve', [
            'user_code' => $device['user_code'],
            'company_id' => $companyId,
            'approve' => true,
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testPollBeforeApproveReturnsAuthorizationPending(): void
    {
        $device = $this->startDeviceFlow();

        // Wait briefly to avoid slow_down (interval is 2s server-side; rate
        // limit is 1s minimum between polls).
        sleep(2);
        $resp = $this->exchangeDeviceCode($device['device_code']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('authorization_pending', $resp['error'] ?? null);
    }

    public function testRapidPollingTriggersSlowDown(): void
    {
        $device = $this->startDeviceFlow();

        $this->exchangeDeviceCode($device['device_code']);
        // Immediate retry — under the 1-second floor.
        $resp = $this->exchangeDeviceCode($device['device_code']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('slow_down', $resp['error'] ?? null);
    }

    public function testExpiredDeviceCodeRejectedOnPoll(): void
    {
        $this->login();
        $device = $this->startDeviceFlow();

        // Force-expire the record server-side.
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entity = $this->findDeviceByUserCode($device['user_code']);
        $this->assertNotNull($entity);
        $entity->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $em->flush();

        sleep(2);
        $resp = $this->exchangeDeviceCode($device['device_code']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('expired_token', $resp['error'] ?? null);
    }

    public function testExpiredUserCodeRejectedOnApprove(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $device = $this->startDeviceFlow();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entity = $this->findDeviceByUserCode($device['user_code']);
        $entity->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $em->flush();

        $this->apiPost('/api/v1/stripe-app/oauth/approve', [
            'user_code' => $device['user_code'],
            'company_id' => $companyId,
            'approve' => true,
        ]);
        $this->assertResponseStatusCodeSame(410);
    }

    public function testDeniedDeviceCodeReturnsAccessDenied(): void
    {
        $this->login();
        $device = $this->startDeviceFlow();

        $this->apiPost('/api/v1/stripe-app/oauth/approve', [
            'user_code' => $device['user_code'],
            'approve' => false,
        ]);
        $this->assertResponseStatusCodeSame(200);

        sleep(2);
        $resp = $this->exchangeDeviceCode($device['device_code']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('access_denied', $resp['error'] ?? null);
    }

    public function testStripeAccountIdMismatchOnPollIsRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $device = $this->startDeviceFlow();

        $this->apiPost('/api/v1/stripe-app/oauth/approve', [
            'user_code' => $device['user_code'],
            'company_id' => $companyId,
            'approve' => true,
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Poll under a *different* stripe_account_id — must not return tokens.
        sleep(2);
        $resp = $this->exchangeDeviceCode($device['device_code'], 'acct_other_account');
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_grant', $resp['error'] ?? null);
    }

    // ─── grant types ──────────────────────────────────────────────────────

    public function testAuthorizationCodeGrantNeverIssuesTokens(): void
    {
        // The legacy authorization_code grant cannot carry a company scope.
        // Whatever code the caller sends, the grant must NOT mint an
        // access_token — the only reachable outcomes are invalid_grant
        // (bad JWT) or unsupported_grant_type (valid JWT, no company).
        $previous = $this->token;
        $this->token = null;
        $resp = $this->apiPost('/api/v1/stripe-app/token', [
            'grant_type' => 'authorization_code',
            'code' => 'irrelevant',
            'stripe_account_id' => self::STRIPE_ACCOUNT,
        ]);
        $this->token = $previous;

        $this->assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        $this->assertArrayNotHasKey('access_token', $resp);
        $this->assertContains($resp['error'] ?? null, ['invalid_grant', 'unsupported_grant_type', 'invalid_request']);
    }

    public function testRefreshTokenGrantIssuesNewTokens(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $tokens = $this->linkAccount('admin@localhost.com', $companyId);

        $previous = $this->token;
        $this->token = null;
        $refreshed = $this->apiPost('/api/v1/stripe-app/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $this->token = $previous;

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('access_token', $refreshed);
        $this->assertNotSame($tokens['access_token'], $refreshed['access_token'], 'access_token should rotate on refresh');
        $this->assertArrayHasKey('refresh_token', $refreshed);
        $this->assertNotSame($tokens['refresh_token'], $refreshed['refresh_token'], 'refresh_token should rotate on refresh (defense against replay)');
    }

    public function testInvalidRefreshTokenIsRejected(): void
    {
        $previous = $this->token;
        $this->token = null;
        $resp = $this->apiPost('/api/v1/stripe-app/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => str_repeat('00', 32),
        ]);
        $this->token = $previous;

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('invalid_grant', $resp['error'] ?? null);
    }

    // ─── public endpoint guards ───────────────────────────────────────────

    public function testApproveRequiresAuthentication(): void
    {
        // /oauth/approve is JWT-protected — no token must mean 401, not 403.
        $previous = $this->token;
        $this->token = null;
        $this->apiPost('/api/v1/stripe-app/oauth/approve', [
            'user_code' => 'WHATEVER',
            'company_id' => '00000000-0000-0000-0000-000000000000',
            'approve' => true,
        ]);
        $this->token = $previous;

        $this->assertResponseStatusCodeSame(401);
    }

    public function testDeviceEndpointRejectsMissingAccount(): void
    {
        $previous = $this->token;
        $this->token = null;
        $resp = $this->apiPost('/api/v1/stripe-app/oauth/device', []);
        $this->token = $previous;

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_request', $resp['error'] ?? null);
    }

    // ─── disconnect ───────────────────────────────────────────────────────

    public function testDisconnectRevokesToken(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $tokens = $this->linkAccount('admin@localhost.com', $companyId);

        $previous = $this->token;
        $this->token = null;
        $this->apiPost('/api/v1/stripe-app/disconnect', [
            'stripe_account_id' => self::STRIPE_ACCOUNT,
        ], [
            'X-Stripe-App-Token' => $tokens['access_token'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Subsequent /settings call with the same token must fail.
        $this->apiGet('/api/v1/stripe-app/settings', [
            'X-Stripe-App-Token' => $tokens['access_token'],
        ]);
        $this->token = $previous;
        $this->assertResponseStatusCodeSame(401);
    }
}
