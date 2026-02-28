<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\Payment;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class PaymentFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Full payment for invoice-8 (freelancer, paid)
        $p1 = (new Payment())
            ->setInvoice($this->getReference('invoice-8', Invoice::class))
            ->setCompany($this->getReference('company-6', Company::class))
            ->setAmount('25466.00')
            ->setCurrency('RON')
            ->setPaymentDate(new \DateTimeImmutable('-18 days'))
            ->setPaymentMethod('bank_transfer')
            ->setReference('OP-2026-001')
            ->setIsReconciled(true);
        $manager->persist($p1);

        // Partial payment for invoice-9 (partially paid)
        $p2 = (new Payment())
            ->setInvoice($this->getReference('invoice-9', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAmount('20000.00')
            ->setCurrency('RON')
            ->setPaymentDate(new \DateTimeImmutable('-10 days'))
            ->setPaymentMethod('bank_transfer')
            ->setReference('OP-2026-100')
            ->setNotes('Prima transa din totalul de 42,483 RON')
            ->setIsReconciled(true);
        $manager->persist($p2);

        // Cash payment for same invoice
        $p3 = (new Payment())
            ->setInvoice($this->getReference('invoice-9', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAmount('5000.00')
            ->setCurrency('RON')
            ->setPaymentDate(new \DateTimeImmutable('-5 days'))
            ->setPaymentMethod('cash')
            ->setReference('CH-2026-050')
            ->setIsReconciled(true);
        $manager->persist($p3);

        // Full payment for contabilitate invoice
        $p4 = (new Payment())
            ->setInvoice($this->getReference('invoice-7', Invoice::class))
            ->setCompany($this->getReference('company-4', Company::class))
            ->setAmount('1487.50')
            ->setCurrency('RON')
            ->setPaymentDate(new \DateTimeImmutable('-1 day'))
            ->setPaymentMethod('bank_transfer')
            ->setReference('OP-2026-050')
            ->setIsReconciled(true);
        $manager->persist($p4);

        // Full payment for invoice-17 (Ion Popescu → MEDIPRINT)
        $p5 = (new Payment())
            ->setInvoice($this->getReference('invoice-17', Invoice::class))
            ->setCompany($this->getReference('company-6', Company::class))
            ->setAmount('18564.00')
            ->setCurrency('RON')
            ->setPaymentDate(new \DateTimeImmutable('-2 days'))
            ->setPaymentMethod('bank_transfer')
            ->setReference('OP-2026-015')
            ->setNotes('Plata integrala dezvoltare web + SEO')
            ->setIsReconciled(true);
        $manager->persist($p5);

        // Payment for invoice-12 (EUR, HORNBACH)
        $p6 = (new Payment())
            ->setInvoice($this->getReference('invoice-12', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAmount('7497.00')
            ->setCurrency('EUR')
            ->setPaymentDate(new \DateTimeImmutable('-3 days'))
            ->setPaymentMethod('bank_transfer')
            ->setReference('OP-EUR-2026-001')
            ->setNotes('Plata integrala echipamente EUR')
            ->setIsReconciled(true);
        $manager->persist($p6);

        // Partial payment for invoice-15 (Dedeman, with discount)
        $p7 = (new Payment())
            ->setInvoice($this->getReference('invoice-15', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAmount('30000.00')
            ->setCurrency('RON')
            ->setPaymentDate(new \DateTimeImmutable('-5 days'))
            ->setPaymentMethod('bank_transfer')
            ->setReference('OP-2026-200')
            ->setNotes('Prima transa - avans 50%')
            ->setIsReconciled(true);
        $manager->persist($p7);

        // Cash payment for invoice-18 (Stanciu Andrei, individual)
        $p8 = (new Payment())
            ->setInvoice($this->getReference('invoice-18', Invoice::class))
            ->setCompany($this->getReference('company-6', Company::class))
            ->setAmount('6247.00')
            ->setCurrency('RON')
            ->setPaymentDate(new \DateTimeImmutable('-3 days'))
            ->setPaymentMethod('cash')
            ->setReference('CH-2026-010')
            ->setIsReconciled(false);
        $manager->persist($p8);

        // Unreconciled bank payment (invoice-14 incoming from ALTEX)
        $p9 = (new Payment())
            ->setInvoice($this->getReference('invoice-14', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAmount('13566.00')
            ->setCurrency('RON')
            ->setPaymentDate(new \DateTimeImmutable('-1 day'))
            ->setPaymentMethod('bank_transfer')
            ->setReference('OP-2026-ALT-001')
            ->setNotes('Plata factura ALTEX - de reconciliat')
            ->setIsReconciled(false);
        $manager->persist($p9);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            InvoiceFixtures::class,
        ];
    }
}
