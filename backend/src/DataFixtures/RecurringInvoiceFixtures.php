<?php

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DocumentSeries;
use App\Entity\Product;
use App\Entity\RecurringInvoice;
use App\Entity\RecurringInvoiceLine;
use App\Enum\DocumentType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class RecurringInvoiceFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Recurring 1: company-4 → client-4, monthly accounting
        $firstDayNextMonth = new \DateTime('first day of next month');
        $firstDayCurrentMonth = new \DateTimeImmutable('first day of this month');

        $ri1 = (new RecurringInvoice())
            ->setCompany($this->getReference('company-4', Company::class))
            ->setClient($this->getReference('client-4', Client::class))
            ->setDocumentSeries($this->getReference('series-ce', DocumentSeries::class))
            ->setReference('Servicii contabilitate lunare')
            ->setIsActive(true)
            ->setDocumentType(DocumentType::INVOICE)
            ->setCurrency('RON')
            ->setFrequency('monthly')
            ->setFrequencyDay(1)
            ->setNextIssuanceDate($firstDayNextMonth)
            ->setDueDateType('days')
            ->setDueDateDays(30)
            ->setLastIssuedAt($firstDayCurrentMonth)
            ->setLastInvoiceNumber('CE000002')
            ->setNotes('Facturare automata servicii contabilitate');

        $this->addLine($ri1, 1, 'Servicii contabilitate lunara', '1', 'luna', '800.00', '21.00', 'product-6');
        $this->addLine($ri1, 2, 'Declaratii fiscale', '2', 'buc', '150.00', '21.00', 'product-7');

        $manager->persist($ri1);
        $this->addReference('recurring-invoice-1', $ri1);

        // Recurring 2: company-6 → client-5, monthly web maintenance
        $fifteenthNextMonth = new \DateTime('first day of next month');
        $fifteenthNextMonth->setDate((int) $fifteenthNextMonth->format('Y'), (int) $fifteenthNextMonth->format('m'), 15);

        $ri2 = (new RecurringInvoice())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setClient($this->getReference('client-5', Client::class))
            ->setDocumentSeries($this->getReference('series-ip', DocumentSeries::class))
            ->setReference('Mentenanta site lunara')
            ->setIsActive(true)
            ->setDocumentType(DocumentType::INVOICE)
            ->setCurrency('RON')
            ->setFrequency('monthly')
            ->setFrequencyDay(15)
            ->setNextIssuanceDate($fifteenthNextMonth)
            ->setDueDateType('days')
            ->setDueDateDays(14)
            ->setNotes('Mentenanta si suport tehnic site');

        $this->addLine($ri2, 1, 'Mentenanta site', '1', 'luna', '500.00', '21.00', 'product-11');

        $manager->persist($ri2);
        $this->addReference('recurring-invoice-2', $ri2);

        // Recurring 3: company-1 → client-2, quarterly (stopped)
        $ri3 = (new RecurringInvoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-2', Client::class))
            ->setReference('Revizie trimestriala echipamente')
            ->setIsActive(false)
            ->setDocumentType(DocumentType::INVOICE)
            ->setCurrency('RON')
            ->setFrequency('quarterly')
            ->setFrequencyDay(1)
            ->setFrequencyMonth(1)
            ->setNextIssuanceDate(new \DateTime('first day of next month'))
            ->setStopDate(new \DateTime('-1 month'))
            ->setLastIssuedAt(new \DateTimeImmutable('-3 months'))
            ->setLastInvoiceNumber('UEP2026000010');

        $this->addLine($ri3, 1, 'Revizie tehnica', '2', 'buc', '1200.00', '21.00', 'product-5');
        $this->addLine($ri3, 2, 'Transport utilaje', '100', 'km', '8.50', '21.00', 'product-4');

        $manager->persist($ri3);
        $this->addReference('recurring-invoice-3', $ri3);

        // Recurring 4: company-6 → client-13 MEDIPRINT, monthly SEO
        $ri4 = (new RecurringInvoice())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setClient($this->getReference('client-13', Client::class))
            ->setDocumentSeries($this->getReference('series-ip', DocumentSeries::class))
            ->setReference('Optimizare SEO lunara')
            ->setIsActive(true)
            ->setDocumentType(DocumentType::INVOICE)
            ->setCurrency('RON')
            ->setFrequency('monthly')
            ->setFrequencyDay(1)
            ->setNextIssuanceDate(new \DateTime('first day of next month'))
            ->setDueDateType('days')
            ->setDueDateDays(14)
            ->setLastIssuedAt(new \DateTimeImmutable('first day of this month'))
            ->setLastInvoiceNumber('IP2026000004')
            ->setNotes('Servicii SEO - contract 12 luni');

        $this->addLine($ri4, 1, 'Optimizare SEO', '1', 'luna', '800.00', '21.00', 'product-19');
        $this->addLine($ri4, 2, 'Hosting web premium', '1', 'luna', '150.00', '21.00', 'product-20');

        $manager->persist($ri4);
        $this->addReference('recurring-invoice-4', $ri4);

        // Recurring 5: company-1 → client-7 LIDL, yearly maintenance contract
        $ri5 = (new RecurringInvoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-7', Client::class))
            ->setReference('Contract anual mentenanta echipamente')
            ->setIsActive(true)
            ->setDocumentType(DocumentType::INVOICE)
            ->setCurrency('RON')
            ->setFrequency('yearly')
            ->setFrequencyDay(1)
            ->setFrequencyMonth(1)
            ->setNextIssuanceDate(new \DateTime('first day of January next year'))
            ->setDueDateType('days')
            ->setDueDateDays(30)
            ->setLastIssuedAt(new \DateTimeImmutable('first day of January this year'))
            ->setLastInvoiceNumber('UEP2026000015')
            ->setAutoEmailEnabled(true)
            ->setAutoEmailDayOffset(0)
            ->setNotes('Contract anual mentenanta - plata la emitere');

        $this->addLine($ri5, 1, 'Revizie tehnica anuala', '4', 'buc', '1200.00', '21.00', 'product-5');
        $this->addLine($ri5, 2, 'Inspectie echipamente', '4', 'buc', '2000.00', '21.00', 'product-16');
        $this->addLine($ri5, 3, 'Transport utilaje', '800', 'km', '8.50', '21.00', 'product-4');

        $manager->persist($ri5);
        $this->addReference('recurring-invoice-5', $ri5);

        $manager->flush();
    }

    private function addLine(RecurringInvoice $recurringInvoice, int $position, string $description, string $qty, string $unit, string $price, string $vatRate, ?string $productRef): void
    {
        $lineTotal = bcmul($qty, $price, 2);
        $vatAmount = bcdiv(bcmul($lineTotal, $vatRate, 4), '100', 2);

        $line = (new RecurringInvoiceLine())
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

        $recurringInvoice->addLine($line);
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
