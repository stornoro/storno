<?php

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DocumentSeries;
use App\Entity\Product;
use App\Entity\Receipt;
use App\Entity\ReceiptLine;
use App\Enum\ReceiptStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ReceiptFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Receipt 1: company-1, ISSUED, cash payment
        $r1 = (new Receipt())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-2', Client::class))
            ->setDocumentSeries($this->getReference('series-uepbon', DocumentSeries::class))
            ->setNumber('UEPBON000001')
            ->setStatus(ReceiptStatus::ISSUED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-3 days'))
            ->setPaymentMethod('cash')
            ->setCashRegisterName('Casa 1 - Front Desk')
            ->setFiscalNumber('AAAA123456')
            ->setIssuedAt(new \DateTimeImmutable('-3 days'));

        $this->addLine($r1, 1, 'Echipament hidraulic EH-100', '2', 'buc', '500.00', '21.00', 'product-1');
        $this->addLine($r1, 2, 'Piese schimb', '10', 'buc', '50.00', '21.00', 'product-2');
        $this->calculateTotals($r1);
        $r1->setCashPayment($r1->getTotal());

        $manager->persist($r1);
        $this->addReference('receipt-1', $r1);

        // Receipt 2: company-1, DRAFT, card payment, B2C (no client)
        $r2 = (new Receipt())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setNumber('BON-draft-001')
            ->setStatus(ReceiptStatus::DRAFT)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime())
            ->setPaymentMethod('card')
            ->setCashRegisterName('Casa 2 - Drive')
            ->setCustomerName('Ion Popescu')
            ->setCustomerCif('1234567890123');

        $this->addLine($r2, 1, 'Cafea espresso', '3', 'buc', '12.00', '9.00', null);
        $this->addLine($r2, 2, 'Sandwich club', '2', 'buc', '25.00', '9.00', null);
        $this->calculateTotals($r2);
        $r2->setCardPayment($r2->getTotal());

        $manager->persist($r2);
        $this->addReference('receipt-2', $r2);

        // Receipt 3: company-1, CANCELLED
        $r3 = (new Receipt())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-1', Client::class))
            ->setNumber('UEPBON000002')
            ->setStatus(ReceiptStatus::CANCELLED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-10 days'))
            ->setPaymentMethod('mixed')
            ->setCashRegisterName('Casa 1 - Front Desk')
            ->setCancelledAt(new \DateTimeImmutable('-9 days'))
            ->setNotes('Anulat - produs returnat');

        $this->addLine($r3, 1, 'Notebook A5', '5', 'buc', '40.00', '21.00', null);
        $this->calculateTotals($r3);
        $r3->setCashPayment('100.00');
        $r3->setCardPayment('138.00');

        $manager->persist($r3);
        $this->addReference('receipt-3', $r3);

        // Receipt 4: company-1, ISSUED, B2C card (no client)
        $r4 = (new Receipt())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setDocumentSeries($this->getReference('series-uepbon', DocumentSeries::class))
            ->setNumber('UEPBON000003')
            ->setStatus(ReceiptStatus::ISSUED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-1 day'))
            ->setPaymentMethod('card')
            ->setCashRegisterName('Casa 2 - Drive')
            ->setFiscalNumber('BBBB789012')
            ->setCustomerName('Gheorghe Maria')
            ->setIssuedAt(new \DateTimeImmutable('-1 day'));

        $this->addLine($r4, 1, 'Filtre hidraulice set', '2', 'set', '450.00', '21.00', 'product-14');
        $this->addLine($r4, 2, 'Piese schimb', '5', 'buc', '50.00', '21.00', 'product-2');
        $this->calculateTotals($r4);
        $r4->setCardPayment($r4->getTotal());

        $manager->persist($r4);
        $this->addReference('receipt-4', $r4);

        // Receipt 5: company-1, ISSUED, bank transfer B2B (client-7 LIDL)
        $r5 = (new Receipt())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-7', Client::class))
            ->setDocumentSeries($this->getReference('series-uepbon', DocumentSeries::class))
            ->setNumber('UEPBON000004')
            ->setStatus(ReceiptStatus::ISSUED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-6 days'))
            ->setPaymentMethod('bank_transfer')
            ->setFiscalNumber('CCCC345678')
            ->setIssuedAt(new \DateTimeImmutable('-6 days'));

        $this->addLine($r5, 1, 'Pompa hidraulica PH-200', '1', 'buc', '8500.00', '21.00', 'product-12');
        $this->calculateTotals($r5);

        $manager->persist($r5);
        $this->addReference('receipt-5', $r5);

        // Receipt 6: company-1, ISSUED, mixed (client-8 HORNBACH)
        $r6 = (new Receipt())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-8', Client::class))
            ->setDocumentSeries($this->getReference('series-uepbon', DocumentSeries::class))
            ->setNumber('UEPBON000005')
            ->setStatus(ReceiptStatus::ISSUED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-2 days'))
            ->setPaymentMethod('mixed')
            ->setCashRegisterName('Casa 1 - Front Desk')
            ->setFiscalNumber('DDDD901234')
            ->setIssuedAt(new \DateTimeImmutable('-2 days'));

        $this->addLine($r6, 1, 'Compresor industrial CI-500', '1', 'buc', '12000.00', '21.00', 'product-13');
        $this->addLine($r6, 2, 'Filtre hidraulice set', '3', 'set', '450.00', '21.00', 'product-14');
        $this->calculateTotals($r6);
        $r6->setCashPayment('5000.00');
        $r6->setCardPayment(bcsub($r6->getTotal(), '5000.00', 2));

        $manager->persist($r6);
        $this->addReference('receipt-6', $r6);

        // Receipt 7: company-6 Ion Popescu, DRAFT (client-13 MEDIPRINT)
        $r7 = (new Receipt())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setClient($this->getReference('client-13', Client::class))
            ->setNumber('BON-ip-001')
            ->setStatus(ReceiptStatus::DRAFT)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime())
            ->setPaymentMethod('bank_transfer');

        $this->addLine($r7, 1, 'Hosting web premium', '6', 'luna', '150.00', '21.00', 'product-20');
        $this->addLine($r7, 2, 'Certificat SSL', '1', 'buc', '250.00', '21.00', 'product-21');
        $this->calculateTotals($r7);

        $manager->persist($r7);
        $this->addReference('receipt-7', $r7);

        // Receipt 8: company-4, ISSUED, cash (client-12 CURSOR DIGITAL)
        $r8 = (new Receipt())
            ->setCompany($this->getReference('company-4', Company::class))
            ->setClient($this->getReference('client-12', Client::class))
            ->setNumber('CE-BON-001')
            ->setStatus(ReceiptStatus::ISSUED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-8 days'))
            ->setPaymentMethod('cash')
            ->setCashRegisterName('Casierie Sediu')
            ->setFiscalNumber('EEEE567890')
            ->setIssuedAt(new \DateTimeImmutable('-8 days'));

        $this->addLine($r8, 1, 'Consultanta fiscala', '2', 'ora', '300.00', '21.00', 'product-8');
        $this->calculateTotals($r8);
        $r8->setCashPayment($r8->getTotal());

        $manager->persist($r8);
        $this->addReference('receipt-8', $r8);

        $manager->flush();
    }

    private function addLine(Receipt $receipt, int $position, string $description, string $qty, string $unit, string $price, string $vatRate, ?string $productRef): void
    {
        $lineTotal = bcmul($qty, $price, 2);
        $vatAmount = bcdiv(bcmul($lineTotal, $vatRate, 4), '100', 2);

        $line = (new ReceiptLine())
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

        $receipt->addLine($line);
    }

    private function calculateTotals(Receipt $receipt): void
    {
        $subtotal = '0.00';
        $vatTotal = '0.00';

        foreach ($receipt->getLines() as $line) {
            $subtotal = bcadd($subtotal, $line->getLineTotal(), 2);
            $vatTotal = bcadd($vatTotal, $line->getVatAmount(), 2);
        }

        $total = bcadd($subtotal, $vatTotal, 2);

        $receipt->setSubtotal($subtotal)
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
