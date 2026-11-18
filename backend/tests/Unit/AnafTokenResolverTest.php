<?php

namespace App\Tests\Unit;

use App\Entity\AnafToken;
use App\Entity\Company;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\AnafTokenRepository;
use App\Service\Anaf\AnafTokenBinder;
use App\Service\Anaf\AnafTokenResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Two modes of picking the ANAF token for a company.
 *
 * Shared (default): one certificate with rights on every company of the account serves all of
 * them, including a company added after the authorization. This is how accountants work and it
 * must not change.
 *
 * Exclusive: a company bound to its own token by an exclusive link uses that token and nothing
 * else, and its token is never offered to another company.
 */
class AnafTokenResolverTest extends TestCase
{
    private Organization $org;

    protected function setUp(): void
    {
        $this->org = new Organization();
    }

    public function testSharedTokenServesEveryCompanyOfTheOrganization(): void
    {
        $token = $this->makeToken([111]);
        $resolver = $this->makeResolver([$token]);

        $first = $this->makeCompany(111);
        $addedLater = $this->makeCompany(222);

        self::assertSame($token, $resolver->resolveEntity($first));
        self::assertSame($token, $resolver->resolveEntity($addedLater));
        // The CIF cache the tokens screen shows keeps filling as before.
        self::assertTrue($token->hasValidatedCif(222));
    }

    public function testSharedModePrefersTheTokenValidatedForTheCif(): void
    {
        $forA = $this->makeToken([111]);
        $forB = $this->makeToken([222]);
        $resolver = $this->makeResolver([$forA, $forB]);

        self::assertSame($forB, $resolver->resolveEntity($this->makeCompany(222)));
        self::assertSame($forA, $resolver->resolveEntity($this->makeCompany(111)));
    }

    public function testExclusiveCompanyUsesItsBoundTokenOnly(): void
    {
        $tokenA = $this->makeToken([111]);
        $tokenB = $this->makeToken([222]);
        $companyA = $this->makeCompany(111);
        $companyB = $this->makeCompany(222);
        $binder = new AnafTokenBinder($this->createStub(EntityManagerInterface::class));
        $binder->bind($companyA, $tokenA);
        $binder->bind($companyB, $tokenB);

        // tokenA is listed first: without the binding it would win for company B too.
        $resolver = $this->makeResolver([$tokenA, $tokenB]);

        self::assertSame($tokenB, $resolver->resolveEntity($companyB));
        self::assertSame($tokenA, $resolver->resolveEntity($companyA));
        self::assertFalse($tokenA->hasValidatedCif(222), 'An exclusive lookup must not mark another token as valid for the CIF');
    }

    public function testExclusiveCompanyWithoutItsTokenHasNoAccess(): void
    {
        $tokenA = $this->makeToken([111]);
        $companyB = $this->makeCompany(222);
        // The bound token of B was deleted: the FK is nulled, the company stays exclusive.
        $companyB->setAnafTokenExclusive(true);
        $companyB->setAnafToken(null);

        $resolver = $this->makeResolver([$tokenA]);

        self::assertNull($resolver->resolveEntity($companyB));
        self::assertNull($resolver->resolve($companyB));
        self::assertFalse($tokenA->hasValidatedCif(222));
    }

    public function testExclusiveTokenIsNeverOfferedToAnotherCompany(): void
    {
        $tokenA = $this->makeToken([111]);
        $companyA = $this->makeCompany(111);
        (new AnafTokenBinder($this->createStub(EntityManagerInterface::class)))->bind($companyA, $tokenA);

        $shared = $this->makeCompany(333);
        $resolver = $this->makeResolver([$tokenA]);

        self::assertNull($resolver->resolveEntity($shared));
        self::assertFalse($tokenA->hasValidatedCif(333));
    }

    public function testRebindingRemovesTheReplacedExclusiveToken(): void
    {
        $old = $this->makeToken([111]);
        $new = $this->makeToken([111]);
        $company = $this->makeCompany(111);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($old);
        $binder = new AnafTokenBinder($em);

        $binder->bind($company, $old);
        $binder->bind($company, $new);

        self::assertSame($new, $company->getAnafToken());
        self::assertTrue($company->isAnafTokenExclusive());
        self::assertTrue($new->isExclusive());
    }

    /** @param int[] $validatedCifs */
    private function makeToken(array $validatedCifs): AnafToken
    {
        $token = new AnafToken();
        $token->setToken('jwt-' . bin2hex(random_bytes(4)));
        $token->setExpireAt(new \DateTimeImmutable('+30 days'));
        $token->setUser(new User());
        foreach ($validatedCifs as $cif) {
            $token->addValidatedCif($cif);
        }

        return $token;
    }

    private function makeCompany(int $cif): Company
    {
        $company = new Company();
        $company->setName('Firma ' . $cif);
        $company->setCif($cif);
        $company->setOrganization($this->org);

        return $company;
    }

    /** @param AnafToken[] $orgTokens */
    private function makeResolver(array $orgTokens): AnafTokenResolver
    {
        $repository = $this->createStub(AnafTokenRepository::class);
        $repository->method('findByOrganization')->willReturn($orgTokens);

        return new AnafTokenResolver(
            $repository,
            $this->createStub(EntityManagerInterface::class),
            new MockHttpClient(),
            new NullLogger(),
            'client-id',
            'client-secret',
        );
    }
}
