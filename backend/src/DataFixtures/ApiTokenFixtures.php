<?php

namespace App\DataFixtures;

use App\Entity\ApiToken;
use App\Entity\Organization;
use App\Entity\User;
use App\Security\Permission;
use App\Service\ApiTokenService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ApiTokenFixtures extends Fixture implements DependentFixtureInterface
{
    // Known raw tokens for tests
    public const TEST_TOKEN_1 = 'af_test_token_1_abcdef1234567890abcdef1234567890abcdef1234567890abcdef12345';
    public const TEST_TOKEN_2 = 'af_test_token_2_abcdef1234567890abcdef1234567890abcdef1234567890abcdef12345';
    public const TEST_TOKEN_3 = 'af_test_token_3_abcdef1234567890abcdef1234567890abcdef1234567890abcdef12345';
    public const TEST_TOKEN_EXPIRED = 'af_test_token_expired_1234567890abcdef1234567890abcdef1234567890abcdef';

    public function __construct(private readonly ApiTokenService $apiTokenService)
    {
    }

    public function load(ObjectManager $manager): void
    {
        // API token for admin user (org-1) — broad scopes
        $t1 = (new ApiToken())
            ->setUser($this->getReference('user-1', User::class))
            ->setOrganization($this->getReference('org-1', Organization::class))
            ->setName('Integrare ERP')
            ->setTokenHash($this->apiTokenService->hashToken(self::TEST_TOKEN_1))
            ->setTokenPrefix(substr(self::TEST_TOKEN_1, 0, 12))
            ->setScopes([
                Permission::INVOICE_VIEW,
                Permission::INVOICE_CREATE,
                Permission::INVOICE_EDIT,
                Permission::CLIENT_VIEW,
                Permission::PRODUCT_VIEW,
                Permission::SETTINGS_VIEW,
            ])
            ->setExpireAt(new \DateTimeImmutable('+1 year'));
        $manager->persist($t1);

        // API token for admin user (read-only)
        $t2 = (new ApiToken())
            ->setUser($this->getReference('user-1', User::class))
            ->setOrganization($this->getReference('org-1', Organization::class))
            ->setName('Dashboard extern')
            ->setTokenHash($this->apiTokenService->hashToken(self::TEST_TOKEN_2))
            ->setTokenPrefix(substr(self::TEST_TOKEN_2, 0, 12))
            ->setScopes([
                Permission::INVOICE_VIEW,
                Permission::CLIENT_VIEW,
                Permission::PRODUCT_VIEW,
            ])
            ->setExpireAt(new \DateTimeImmutable('+6 months'));
        $manager->persist($t2);

        // API token for org-2 user
        $t3 = (new ApiToken())
            ->setUser($this->getReference('user-2', User::class))
            ->setOrganization($this->getReference('org-2', Organization::class))
            ->setName('Contabilitate sync')
            ->setTokenHash($this->apiTokenService->hashToken(self::TEST_TOKEN_3))
            ->setTokenPrefix(substr(self::TEST_TOKEN_3, 0, 12))
            ->setScopes([
                Permission::INVOICE_VIEW,
                Permission::INVOICE_CREATE,
            ])
            ->setExpireAt(new \DateTimeImmutable('+3 months'));
        $manager->persist($t3);

        // Expired token
        $t4 = (new ApiToken())
            ->setUser($this->getReference('user-1', User::class))
            ->setOrganization($this->getReference('org-1', Organization::class))
            ->setName('Token expirat test')
            ->setTokenHash($this->apiTokenService->hashToken(self::TEST_TOKEN_EXPIRED))
            ->setTokenPrefix(substr(self::TEST_TOKEN_EXPIRED, 0, 12))
            ->setScopes([Permission::INVOICE_VIEW])
            ->setExpireAt(new \DateTimeImmutable('-1 month'));
        $manager->persist($t4);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            OrganizationFixtures::class,
        ];
    }
}
