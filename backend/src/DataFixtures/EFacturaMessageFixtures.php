<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\EFacturaMessage;
use App\Entity\Invoice;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class EFacturaMessageFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // msg-001: company-1, incoming invoice from Mega Image, linked to invoice-1
        $msg1 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAnafMessageId('msg-001')
            ->setMessageType('FACTURA PRIMITA')
            ->setCif('31385365')
            ->setDetails('Factura primita de la Mega Image SRL')
            ->setUploadId(null)
            ->setStatus('processed')
            ->setInvoice($this->getReference('invoice-1', Invoice::class));

        $manager->persist($msg1);
        $this->addReference('efactura-message-1', $msg1);

        // msg-002: company-1, outgoing invoice to Dedeman, linked to invoice-2
        $msg2 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAnafMessageId('msg-002')
            ->setMessageType('FACTURA TRIMISA')
            ->setCif('31385365')
            ->setUploadId('3847562')
            ->setStatus('processed')
            ->setInvoice($this->getReference('invoice-2', Invoice::class));

        $manager->persist($msg2);
        $this->addReference('efactura-message-2', $msg2);

        // msg-004: company-1, rejected invoice, linked to invoice-4
        $msg3 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAnafMessageId('msg-004')
            ->setMessageType('ERORI FACTURA')
            ->setCif('31385365')
            ->setUploadId('5678901')
            ->setStatus('error')
            ->setErrorMessage('Eroare validare: CIF cumparator invalid.')
            ->setInvoice($this->getReference('invoice-4', Invoice::class));

        $manager->persist($msg3);
        $this->addReference('efactura-message-3', $msg3);

        // msg-010: company-2, outgoing invoice from Rikko Steel, linked to invoice-10
        $msg4 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-2', Company::class))
            ->setAnafMessageId('msg-010')
            ->setMessageType('FACTURA TRIMISA')
            ->setCif('24899169')
            ->setUploadId('9876543')
            ->setStatus('processed')
            ->setInvoice($this->getReference('invoice-10', Invoice::class));

        $manager->persist($msg4);
        $this->addReference('efactura-message-4', $msg4);

        // msg-unlinked: company-1, unmatched incoming invoice, no invoice link
        $msg5 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAnafMessageId('msg-unlinked-001')
            ->setMessageType('FACTURA PRIMITA')
            ->setCif('31385365')
            ->setDetails('Factura primita neconcordata')
            ->setUploadId(null)
            ->setStatus('received');

        $manager->persist($msg5);
        $this->addReference('efactura-message-5', $msg5);

        // msg-014: company-1, incoming invoice from ALTEX, linked to invoice-14
        $msg6 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAnafMessageId('msg-014')
            ->setMessageType('FACTURA PRIMITA')
            ->setCif('31385365')
            ->setDetails('Factura primita de la ALTEX ROMANIA SRL')
            ->setUploadId(null)
            ->setStatus('processed')
            ->setInvoice($this->getReference('invoice-14', Invoice::class));

        $manager->persist($msg6);
        $this->addReference('efactura-message-6', $msg6);

        // msg-015: company-1, outgoing invoice to Dedeman, linked to invoice-15
        $msg7 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAnafMessageId('msg-015')
            ->setMessageType('FACTURA TRIMISA')
            ->setCif('31385365')
            ->setUploadId('1122334')
            ->setStatus('processed')
            ->setInvoice($this->getReference('invoice-15', Invoice::class));

        $manager->persist($msg7);
        $this->addReference('efactura-message-7', $msg7);

        // msg-017: company-6, outgoing invoice to MEDIPRINT, linked to invoice-17
        $msg8 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setAnafMessageId('msg-017')
            ->setMessageType('FACTURA TRIMISA')
            ->setCif('12345678')
            ->setUploadId('5566778')
            ->setStatus('processed')
            ->setInvoice($this->getReference('invoice-17', Invoice::class));

        $manager->persist($msg8);
        $this->addReference('efactura-message-8', $msg8);

        // msg-019: company-2, incoming invoice from HILTI, linked to invoice-19
        $msg9 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-2', Company::class))
            ->setAnafMessageId('msg-019')
            ->setMessageType('FACTURA PRIMITA')
            ->setCif('24899169')
            ->setDetails('Factura primita de la HILTI ROMANIA SRL')
            ->setUploadId(null)
            ->setStatus('processed')
            ->setInvoice($this->getReference('invoice-19', Invoice::class));

        $manager->persist($msg9);
        $this->addReference('efactura-message-9', $msg9);

        // msg-020: company-1, outgoing credit note to EMAG, linked to invoice-20
        $msg10 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAnafMessageId('msg-020')
            ->setMessageType('FACTURA TRIMISA')
            ->setCif('31385365')
            ->setUploadId('7788990')
            ->setStatus('processed')
            ->setInvoice($this->getReference('invoice-20', Invoice::class));

        $manager->persist($msg10);
        $this->addReference('efactura-message-10', $msg10);

        // Additional unlinked messages for more SPV activity
        $msg11 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAnafMessageId('msg-unlinked-002')
            ->setMessageType('FACTURA PRIMITA')
            ->setCif('31385365')
            ->setDetails('Factura primita de la SCHNEIDER ELECTRIC SRL')
            ->setUploadId(null)
            ->setStatus('received');

        $manager->persist($msg11);
        $this->addReference('efactura-message-11', $msg11);

        $msg12 = (new EFacturaMessage())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setAnafMessageId('msg-unlinked-003')
            ->setMessageType('FACTURA PRIMITA')
            ->setCif('31385365')
            ->setDetails('Factura primita de la BOSCH REXROTH SRL')
            ->setUploadId(null)
            ->setStatus('received');

        $manager->persist($msg12);
        $this->addReference('efactura-message-12', $msg12);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CompanyFixtures::class,
            InvoiceFixtures::class,
        ];
    }
}
