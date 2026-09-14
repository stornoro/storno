<?php

namespace App\Tests\Api;

use App\Entity\Company;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\SupplierProductMapping;
use App\Service\Product\SupplierProductMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class SupplierProductMatcherTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SupplierProductMatcher $matcher;
    private Company $company;
    private Supplier $supplier;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->matcher = self::getContainer()->get(SupplierProductMatcher::class);

        $this->company = $this->em->getRepository(Company::class)->findOneBy([]);
        self::assertNotNull($this->company, 'fixtures must provide a company');

        $this->supplier = new Supplier();
        $this->supplier->setCompany($this->company);
        $this->supplier->setName('Furnizor Test ' . Uuid::v4()->toRfc4122());
        $this->supplier->setCif('RO' . random_int(10000000, 99999999));
        $this->em->persist($this->supplier);
        $this->em->flush();
    }

    private function product(string $name, ?string $code = null): Product
    {
        $p = new Product();
        $p->setCompany($this->company);
        $p->setName($name);
        $p->setCode($code);
        $this->em->persist($p);
        $this->em->flush();
        return $p;
    }

    public function testBarcodeWinsOverDescriptionAndIsLearned(): void
    {
        $hartie = $this->product('Hartie A4 80g');
        $toner = $this->product('Toner negru');

        // Nothing known yet
        self::assertNull($this->matcher->match($this->company, $this->supplier, ['barcode' => '5941000000001'], 'HARTIE A4 80G'));

        $this->matcher->learn($this->company, $this->supplier, $hartie, ['barcode' => '5941000000001', 'sellerCode' => 'HA4'], 'Hartie  A4 80g');
        $this->em->flush();

        // Same barcode, different wording → same product
        self::assertSame($hartie->getId()->toRfc4122(), $this->matcher->match($this->company, $this->supplier, ['barcode' => '5941000000001'], 'Hartie A4 (top)')?->getId()?->toRfc4122());
        // Only the supplier's code
        self::assertSame($hartie->getId()->toRfc4122(), $this->matcher->match($this->company, $this->supplier, ['sellerCode' => 'HA4'], null)?->getId()?->toRfc4122());
        // Only the description, normalised (case, spaces)
        self::assertSame($hartie->getId()->toRfc4122(), $this->matcher->match($this->company, $this->supplier, [], 'hartie a4 80g')?->getId()?->toRfc4122());

        // A later import pointing the same barcode elsewhere moves the mapping…
        $this->matcher->learn($this->company, $this->supplier, $toner, ['barcode' => '5941000000001'], 'Toner');
        $this->em->flush();
        self::assertSame($toner->getId()->toRfc4122(), $this->matcher->match($this->company, $this->supplier, ['barcode' => '5941000000001'], null)?->getId()?->toRfc4122());

        // …but a product confirmed by the user is never overridden by an import
        $this->matcher->learn($this->company, $this->supplier, $hartie, ['barcode' => '5941000000001'], null, confirmed: true);
        $this->em->flush();
        $this->matcher->learn($this->company, $this->supplier, $toner, ['barcode' => '5941000000001'], null);
        $this->em->flush();
        self::assertSame($hartie->getId()->toRfc4122(), $this->matcher->match($this->company, $this->supplier, ['barcode' => '5941000000001'], null)?->getId()?->toRfc4122());

        $mapping = $this->em->getRepository(SupplierProductMapping::class)->findOneBy(['supplier' => $this->supplier, 'barcode' => '5941000000001']);
        self::assertTrue($mapping->isConfirmed());
        self::assertGreaterThanOrEqual(3, $mapping->getHits());
    }

    public function testOurOwnCodeFromBuyersItemIdentificationMatchesTheProduct(): void
    {
        $code = 'CAB-' . substr(Uuid::v4()->toBase32(), 0, 8);
        $ours = $this->product('Cablu UTP', $code);
        self::assertSame($ours->getId()->toRfc4122(), $this->matcher->match($this->company, $this->supplier, ['buyerCode' => $code], 'Cablu retea')?->getId()?->toRfc4122());
    }

    public function testMappingsBelongToTheirSupplier(): void
    {
        $p = $this->product('Apa plata 2L');
        $this->matcher->learn($this->company, $this->supplier, $p, ['barcode' => '5940000000002'], 'Apa plata 2L');
        $this->em->flush();

        $other = new Supplier();
        $other->setCompany($this->company);
        $other->setName('Alt furnizor');
        $other->setCif('RO' . random_int(10000000, 99999999));
        $this->em->persist($other);
        $this->em->flush();

        self::assertNull($this->matcher->match($this->company, $other, ['barcode' => '5940000000002'], 'Apa plata 2L'));
    }
}
