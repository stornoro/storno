<?php

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DocumentSeries;
use App\Entity\Product;
use App\Entity\ProformaInvoice;
use App\Entity\ProformaInvoiceLine;
use App\Enum\ProformaStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ProformaInvoiceFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Proforma 1: company-1 → client-2 Dedeman, SENT
        $pf1 = (new ProformaInvoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-2', Client::class))
            ->setDocumentSeries($this->getReference('series-ueppf', DocumentSeries::class))
            ->setNumber('UEPPF-000001')
            ->setStatus(ProformaStatus::SENT)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-5 days'))
            ->setDueDate(new \DateTime('+25 days'))
            ->setValidUntil(new \DateTime('+30 days'))
            ->setPaymentTerms('30 zile')
            ->setNotes('Oferta echipamente hidraulice')
            ->setSentAt(new \DateTimeImmutable('-4 days'));

        $this->addLine($pf1, 1, 'Echipament hidraulic EH-200', '3', 'buc', '15000.00', '21.00', 'product-1');
        $this->addLine($pf1, 2, 'Servicii montaj echipament', '12', 'ora', '250.00', '21.00', 'product-3');
        $this->calculateTotals($pf1);

        $manager->persist($pf1);
        $this->addReference('proforma-1', $pf1);

        // Proforma 2: company-1 → client-1 Mega Image, ACCEPTED
        $pf2 = (new ProformaInvoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-1', Client::class))
            ->setNumber('UEPPF-000002')
            ->setStatus(ProformaStatus::ACCEPTED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-15 days'))
            ->setDueDate(new \DateTime('+15 days'))
            ->setValidUntil(new \DateTime('+20 days'))
            ->setPaymentTerms('30 zile')
            ->setNotes('Contract anual revizie')
            ->setSentAt(new \DateTimeImmutable('-14 days'))
            ->setAcceptedAt(new \DateTimeImmutable('-10 days'));

        $this->addLine($pf2, 1, 'Revizie tehnica anuala', '4', 'buc', '1200.00', '21.00', 'product-5');
        $this->addLine($pf2, 2, 'Transport utilaje', '300', 'km', '8.50', '21.00', 'product-4');
        $this->calculateTotals($pf2);

        $manager->persist($pf2);
        $this->addReference('proforma-2', $pf2);

        // Proforma 3: company-6 → client-5 WebDev Agency, DRAFT
        $pf3 = (new ProformaInvoice())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setClient($this->getReference('client-5', Client::class))
            ->setDocumentSeries($this->getReference('series-ippf', DocumentSeries::class))
            ->setNumber('IPPF-000001')
            ->setStatus(ProformaStatus::DRAFT)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime());

        $this->addLine($pf3, 1, 'Dezvoltare web - modul rapoarte', '40', 'ora', '200.00', '21.00', 'product-9');
        $this->addLine($pf3, 2, 'Design UI/UX - dashboard', '20', 'ora', '180.00', '21.00', 'product-10');
        $this->calculateTotals($pf3);

        $manager->persist($pf3);
        $this->addReference('proforma-3', $pf3);

        // Proforma 4: company-4 → client-4 Tech Innovations, CONVERTED
        $pf4 = (new ProformaInvoice())
            ->setCompany($this->getReference('company-4', Company::class))
            ->setClient($this->getReference('client-4', Client::class))
            ->setDocumentSeries($this->getReference('series-cepf', DocumentSeries::class))
            ->setNumber('CEPF-000001')
            ->setStatus(ProformaStatus::CONVERTED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-30 days'))
            ->setSentAt(new \DateTimeImmutable('-29 days'))
            ->setAcceptedAt(new \DateTimeImmutable('-25 days'));

        $this->addLine($pf4, 1, 'Servicii contabilitate lunara', '3', 'luna', '800.00', '21.00', 'product-6');
        $this->calculateTotals($pf4);

        $manager->persist($pf4);
        $this->addReference('proforma-4', $pf4);

        // Proforma 5: company-1 → client-9 EMAG, REJECTED
        $pf5 = (new ProformaInvoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-9', Client::class))
            ->setDocumentSeries($this->getReference('series-ueppf', DocumentSeries::class))
            ->setNumber('UEPPF-000003')
            ->setStatus(ProformaStatus::REJECTED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-20 days'))
            ->setDueDate(new \DateTime('+10 days'))
            ->setValidUntil(new \DateTime('+15 days'))
            ->setPaymentTerms('30 zile')
            ->setNotes('Oferta echipamente industriale - respinsa pret prea mare')
            ->setSentAt(new \DateTimeImmutable('-19 days'));

        $this->addLine($pf5, 1, 'Compresor industrial CI-500', '5', 'buc', '12000.00', '21.00', 'product-13');
        $this->addLine($pf5, 2, 'Inspectie echipamente', '5', 'buc', '2000.00', '21.00', 'product-16');
        $this->calculateTotals($pf5);

        $manager->persist($pf5);
        $this->addReference('proforma-5', $pf5);

        // Proforma 6: company-1 → client-7 LIDL, EXPIRED
        $pf6 = (new ProformaInvoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-7', Client::class))
            ->setDocumentSeries($this->getReference('series-ueppf', DocumentSeries::class))
            ->setNumber('UEPPF-000004')
            ->setStatus(ProformaStatus::EXPIRED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-40 days'))
            ->setDueDate(new \DateTime('-10 days'))
            ->setValidUntil(new \DateTime('-5 days'))
            ->setPaymentTerms('30 zile')
            ->setNotes('Oferta pompe hidraulice - expirata')
            ->setSentAt(new \DateTimeImmutable('-39 days'));

        $this->addLine($pf6, 1, 'Pompa hidraulica PH-200', '10', 'buc', '8500.00', '21.00', 'product-12');
        $this->addLine($pf6, 2, 'Transport utilaje', '500', 'km', '8.50', '21.00', 'product-4');
        $this->calculateTotals($pf6);

        $manager->persist($pf6);
        $this->addReference('proforma-6', $pf6);

        // Proforma 7: company-6 → client-13 MEDIPRINT, SENT
        $pf7 = (new ProformaInvoice())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setClient($this->getReference('client-13', Client::class))
            ->setDocumentSeries($this->getReference('series-ippf', DocumentSeries::class))
            ->setNumber('IPPF-000002')
            ->setStatus(ProformaStatus::SENT)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-3 days'))
            ->setDueDate(new \DateTime('+11 days'))
            ->setValidUntil(new \DateTime('+14 days'))
            ->setPaymentTerms('14 zile')
            ->setNotes('Oferta redesign website + SEO')
            ->setSentAt(new \DateTimeImmutable('-2 days'));

        $this->addLine($pf7, 1, 'Dezvoltare web - redesign complet', '100', 'ora', '200.00', '21.00', 'product-9');
        $this->addLine($pf7, 2, 'Design UI/UX', '40', 'ora', '180.00', '21.00', 'product-10');
        $this->addLine($pf7, 3, 'Optimizare SEO - 6 luni', '6', 'luna', '800.00', '21.00', 'product-19');
        $this->calculateTotals($pf7);

        $manager->persist($pf7);
        $this->addReference('proforma-7', $pf7);

        // Proforma 8: company-4 → client-12 CURSOR DIGITAL, CANCELLED
        $pf8 = (new ProformaInvoice())
            ->setCompany($this->getReference('company-4', Company::class))
            ->setClient($this->getReference('client-12', Client::class))
            ->setDocumentSeries($this->getReference('series-cepf', DocumentSeries::class))
            ->setNumber('CEPF-000002')
            ->setStatus(ProformaStatus::CANCELLED)
            ->setCurrency('RON')
            ->setIssueDate(new \DateTime('-25 days'))
            ->setNotes('Anulata - clientul a ales alt furnizor');

        $this->addLine($pf8, 1, 'Audit financiar', '1', 'buc', '3500.00', '21.00', 'product-18');
        $this->addLine($pf8, 2, 'Consultanta fiscala', '20', 'ora', '300.00', '21.00', 'product-8');
        $this->calculateTotals($pf8);

        $manager->persist($pf8);
        $this->addReference('proforma-8', $pf8);

        $manager->flush();
    }

    private function addLine(ProformaInvoice $proforma, int $position, string $description, string $qty, string $unit, string $price, string $vatRate, ?string $productRef): void
    {
        $lineTotal = bcmul($qty, $price, 2);
        $vatAmount = bcdiv(bcmul($lineTotal, $vatRate, 4), '100', 2);

        $line = (new ProformaInvoiceLine())
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

        $proforma->addLine($line);
    }

    private function calculateTotals(ProformaInvoice $proforma): void
    {
        $subtotal = '0.00';
        $vatTotal = '0.00';

        foreach ($proforma->getLines() as $line) {
            $subtotal = bcadd($subtotal, $line->getLineTotal(), 2);
            $vatTotal = bcadd($vatTotal, $line->getVatAmount(), 2);
        }

        $total = bcadd($subtotal, $vatTotal, 2);

        $proforma->setSubtotal($subtotal)
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
