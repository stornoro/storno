<?php

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DeliveryNote;
use App\Entity\DeliveryNoteLine;
use App\Entity\DocumentSeries;
use App\Entity\Product;
use App\Enum\DeliveryNoteStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DeliveryNoteFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // DN 1: company-1 → client-2 Dedeman, ISSUED
        $dn1 = (new DeliveryNote())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-2', Client::class))
            ->setDocumentSeries($this->getReference('series-uepav', DocumentSeries::class))
            ->setNumber('UEPAV-000001')
            ->setStatus(DeliveryNoteStatus::ISSUED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-7 days'))
            ->setDeliveryLocation('Depozit Bacau, Str. Industriei 15')
            ->setDeputyName('Vasile Popescu')
            ->setDeputyIdentityCard('BC123456')
            ->setDeputyAuto('BC-01-ABC')
            ->setIssuerName('John Doe')
            ->setIssuerId('1234567890123')
            ->setIssuedAt(new \DateTimeImmutable('-7 days'));

        $this->addLine($dn1, 1, 'Echipament hidraulic EH-200', '2', 'buc', '15000.00', '21.00', 'product-1');
        $this->addLine($dn1, 2, 'Piese schimb excavator Cat 320', '5', 'buc', '2500.00', '21.00', 'product-2');
        $this->calculateTotals($dn1);

        $manager->persist($dn1);
        $this->addReference('delivery-note-1', $dn1);

        // DN 2: company-1 → client-1 Mega Image, DRAFT
        $dn2 = (new DeliveryNote())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-1', Client::class))
            ->setNumber('UEPAV-000002')
            ->setStatus(DeliveryNoteStatus::DRAFT)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime())
            ->setDeliveryLocation('Mega Image Bucuresti, Bd. Timisoara 26');

        $this->addLine($dn2, 1, 'Echipament hidraulic EH-300', '1', 'buc', '15000.00', '21.00', 'product-1');
        $this->calculateTotals($dn2);

        $manager->persist($dn2);
        $this->addReference('delivery-note-2', $dn2);

        // DN 3: company-1 → client-2 Dedeman, CANCELLED
        $dn3 = (new DeliveryNote())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-2', Client::class))
            ->setNumber('UEPAV-000003')
            ->setStatus(DeliveryNoteStatus::CANCELLED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-20 days'))
            ->setCancelledAt(new \DateTimeImmutable('-18 days'))
            ->setNotes('Anulat la cererea clientului');

        $this->addLine($dn3, 1, 'Transport utilaje', '100', 'km', '8.50', '21.00', 'product-4');
        $this->calculateTotals($dn3);

        $manager->persist($dn3);
        $this->addReference('delivery-note-3', $dn3);

        // DN 4: company-1 → client-7 LIDL, ISSUED
        $dn4 = (new DeliveryNote())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-7', Client::class))
            ->setDocumentSeries($this->getReference('series-uepav', DocumentSeries::class))
            ->setNumber('UEPAV-000004')
            ->setStatus(DeliveryNoteStatus::ISSUED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-4 days'))
            ->setDeliveryLocation('Depozit LIDL Chiajna, Str. Industriilor 2')
            ->setDeputyName('Marian Ionescu')
            ->setDeputyIdentityCard('IF234567')
            ->setDeputyAuto('IF-10-XYZ')
            ->setIssuerName('John Doe')
            ->setIssuerId('1234567890123')
            ->setIssuedAt(new \DateTimeImmutable('-4 days'));

        $this->addLine($dn4, 1, 'Pompa hidraulica PH-200', '4', 'buc', '8500.00', '21.00', 'product-12');
        $this->addLine($dn4, 2, 'Filtre hidraulice set', '10', 'set', '450.00', '21.00', 'product-14');
        $this->addLine($dn4, 3, 'Compresor industrial CI-500', '2', 'buc', '12000.00', '21.00', 'product-13');
        $this->calculateTotals($dn4);

        $manager->persist($dn4);
        $this->addReference('delivery-note-4', $dn4);

        // DN 5: company-1 → client-9 EMAG, ISSUED
        $dn5 = (new DeliveryNote())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-9', Client::class))
            ->setDocumentSeries($this->getReference('series-uepav', DocumentSeries::class))
            ->setNumber('UEPAV-000005')
            ->setStatus(DeliveryNoteStatus::ISSUED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-12 days'))
            ->setDeliveryLocation('Depozit eMAG Bucuresti, Sos. Virtutii 148')
            ->setDeputyName('Cosmin Barbu')
            ->setDeputyIdentityCard('B987654')
            ->setDeputyAuto('B-99-EMG')
            ->setIssuerName('Jane Smith')
            ->setIssuerId('2345678901234')
            ->setIssuedAt(new \DateTimeImmutable('-12 days'));

        $this->addLine($dn5, 1, 'Echipament hidraulic EH-200', '1', 'buc', '15000.00', '21.00', 'product-1');
        $this->addLine($dn5, 2, 'Piese schimb excavator Cat 320', '8', 'buc', '2500.00', '21.00', 'product-2');
        $this->calculateTotals($dn5);

        $manager->persist($dn5);
        $this->addReference('delivery-note-5', $dn5);

        // DN 6: company-1 → client-10 ALTEX, DRAFT
        $dn6 = (new DeliveryNote())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-10', Client::class))
            ->setNumber('UEPAV-000006')
            ->setStatus(DeliveryNoteStatus::DRAFT)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime())
            ->setDeliveryLocation('Magazin ALTEX Piatra Neamt, Str. Garii 2');

        $this->addLine($dn6, 1, 'Inspectie echipamente', '2', 'buc', '2000.00', '21.00', 'product-16');
        $this->addLine($dn6, 2, 'Consultanta tehnica', '4', 'ora', '350.00', '21.00', 'product-15');
        $this->calculateTotals($dn6);

        $manager->persist($dn6);
        $this->addReference('delivery-note-6', $dn6);

        $manager->flush();
    }

    private function addLine(DeliveryNote $deliveryNote, int $position, string $description, string $qty, string $unit, string $price, string $vatRate, ?string $productRef): void
    {
        $lineTotal = bcmul($qty, $price, 2);
        $vatAmount = bcdiv(bcmul($lineTotal, $vatRate, 4), '100', 2);

        $line = (new DeliveryNoteLine())
            ->setPosition($position)
            ->setDescription($description)
            ->setQuantity($qty)
            ->setUnitOfMeasure($unit)
            ->setUnitPrice($price)
            ->setVatRate($vatRate)
            ->setVatCategoryCode('S')
            ->setVatAmount($vatAmount)
            ->setLineTotal($lineTotal)
            ->setDiscount('0.00')
            ->setDiscountPercent('0.00');

        if ($productRef) {
            $line->setProduct($this->getReference($productRef, Product::class));
        }

        $deliveryNote->addLine($line);
    }

    private function calculateTotals(DeliveryNote $deliveryNote): void
    {
        $subtotal = '0.00';
        $vatTotal = '0.00';

        foreach ($deliveryNote->getLines() as $line) {
            $subtotal = bcadd($subtotal, $line->getLineTotal(), 2);
            $vatTotal = bcadd($vatTotal, $line->getVatAmount(), 2);
        }

        $total = bcadd($subtotal, $vatTotal, 2);

        $deliveryNote->setSubtotal($subtotal)
            ->setVatTotal($vatTotal)
            ->setTotal($total)
            ->setDiscount('0.00');
    }

    public function getDependencies(): array
    {
        return [
            CompanyFixtures::class,
            ClientFixtures::class,
            ProductFixtures::class,
            DocumentSeriesFixtures::class,
        ];
    }
}
