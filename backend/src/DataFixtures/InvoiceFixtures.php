<?php

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DocumentEvent;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Product;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class InvoiceFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Invoice 1: Synced incoming invoice (company-1 ← client-1 Mega Image)
        $inv1 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-1', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::SYNCED)
            ->setDirection(InvoiceDirection::INCOMING)
            ->setAnafMessageId('msg-001')
            ->setNumber('UEP2026000001')
            ->setIssueDate(new \DateTimeImmutable('-10 days'))
            ->setDueDate(new \DateTimeImmutable('+20 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('18505846')
            ->setSenderName('Mega Image SRL')
            ->setReceiverCif('12345678')
            ->setReceiverName('Utilaje Echipamente Profesionale SRL')
            ->setSyncedAt(new \DateTimeImmutable('-1 day'))
            ->setPaymentTerms('30 zile');

        $this->addLine($inv1, 1, 'Echipament hidraulic EH-200', '2', 'buc', '15000.00', '21.00', 'S', 'product-1');
        $this->addLine($inv1, 2, 'Servicii montaj echipament', '8', 'ora', '250.00', '21.00', 'S', 'product-3');
        $this->addLine($inv1, 3, 'Transport utilaje', '150', 'km', '8.50', '21.00', 'S', 'product-4');
        $this->calculateTotals($inv1);

        $inv1->addEvent((new DocumentEvent())
            ->setNewStatus(DocumentStatus::SYNCED)
            ->setMetadata(['action' => 'synced_from_anaf', 'anafMessageId' => 'msg-001']));

        $manager->persist($inv1);
        $this->addReference('invoice-1', $inv1);

        // Invoice 2: Validated outgoing (company-1 → client-2 Dedeman)
        $inv2 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-2', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::VALIDATED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setAnafMessageId('msg-002')
            ->setNumber('UEP2026000002')
            ->setIssueDate(new \DateTimeImmutable('-20 days'))
            ->setDueDate(new \DateTimeImmutable('-5 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('12345678')
            ->setSenderName('Utilaje Echipamente Profesionale SRL')
            ->setReceiverCif('14399840')
            ->setReceiverName('Dedeman SRL')
            ->setAnafUploadId('3847562')
            ->setAnafDownloadId('9283746')
            ->setAnafStatus('ok')
            ->setSyncedAt(new \DateTimeImmutable('-3 days'));

        $this->addLine($inv2, 1, 'Piese schimb excavator Cat 320', '10', 'buc', '2500.00', '21.00', 'S', 'product-2');
        $this->addLine($inv2, 2, 'Revizie tehnica excavator', '1', 'buc', '1200.00', '21.00', 'S', 'product-5');
        $this->calculateTotals($inv2);

        $inv2->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata(['action' => 'synced_from_anaf']));
        $inv2->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::VALIDATED)->setMetadata(['download_id' => '9283746']));

        $manager->persist($inv2);
        $this->addReference('invoice-2', $inv2);

        // Invoice 3: Synced incoming (company-1 ← client-3)
        $inv3 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-3', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::SYNCED)
            ->setDirection(InvoiceDirection::INCOMING)
            ->setAnafMessageId('msg-003')
            ->setNumber('UEP2026000003')
            ->setIssueDate(new \DateTimeImmutable())
            ->setDueDate(new \DateTimeImmutable('+15 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('99887766')
            ->setSenderName('Popescu Ion PFA')
            ->setReceiverCif('12345678')
            ->setReceiverName('Utilaje Echipamente Profesionale SRL')
            ->setSyncedAt(new \DateTimeImmutable())
            ->setNotes('Factura servicii.');

        $this->addLine($inv3, 1, 'Servicii montaj', '4', 'ora', '250.00', '21.00', 'S', 'product-3');
        $this->calculateTotals($inv3);
        $manager->persist($inv3);
        $this->addReference('invoice-3', $inv3);

        // Invoice 4: Rejected by ANAF (company-1 → client-1)
        $inv4 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-1', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::REJECTED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setAnafMessageId('msg-004')
            ->setNumber('UEP2026000004')
            ->setIssueDate(new \DateTimeImmutable('-15 days'))
            ->setDueDate(new \DateTimeImmutable('+15 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('12345678')
            ->setSenderName('Utilaje Echipamente Profesionale SRL')
            ->setReceiverCif('18505846')
            ->setReceiverName('Mega Image SRL')
            ->setAnafUploadId('5678901')
            ->setAnafStatus('nok')
            ->setAnafErrorMessage('Eroare validare: CIF cumparator invalid.')
            ->setSyncedAt(new \DateTimeImmutable('-2 days'));

        $this->addLine($inv4, 1, 'Echipament test', '1', 'buc', '5000.00', '21.00', 'S', null);
        $this->calculateTotals($inv4);

        $inv4->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata([]));
        $inv4->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::REJECTED)->setMetadata(['error' => 'CIF cumparator invalid']));

        $manager->persist($inv4);
        $this->addReference('invoice-4', $inv4);

        // Invoice 5: Overdue incoming (company-1 ← client-2) — past due, unpaid
        $inv5 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-2', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::SYNCED)
            ->setDirection(InvoiceDirection::INCOMING)
            ->setAnafMessageId('msg-005')
            ->setNumber('UEP2026000005')
            ->setIssueDate(new \DateTimeImmutable('-45 days'))
            ->setDueDate(new \DateTimeImmutable('-15 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('14399840')
            ->setSenderName('Dedeman SRL')
            ->setReceiverCif('12345678')
            ->setReceiverName('Utilaje Echipamente Profesionale SRL')
            ->setAnafUploadId('1234567')
            ->setAnafDownloadId('7654321')
            ->setAnafStatus('ok')
            ->setSyncedAt(new \DateTimeImmutable('-10 days'));

        $this->addLine($inv5, 1, 'Piese schimb buldozer', '5', 'buc', '3500.00', '21.00', 'S', 'product-2');
        $this->calculateTotals($inv5);
        $manager->persist($inv5);
        $this->addReference('invoice-5', $inv5);

        // Invoice 6: Credit note incoming (company-1 ← client-1)
        $inv6 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-1', Client::class))
            ->setDocumentType(DocumentType::CREDIT_NOTE)
            ->setStatus(DocumentStatus::VALIDATED)
            ->setDirection(InvoiceDirection::INCOMING)
            ->setAnafMessageId('msg-006')
            ->setNumber('UEP2026CN0001')
            ->setIssueDate(new \DateTimeImmutable('-5 days'))
            ->setDueDate(new \DateTimeImmutable('+25 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('18505846')
            ->setSenderName('Mega Image SRL')
            ->setReceiverCif('12345678')
            ->setReceiverName('Utilaje Echipamente Profesionale SRL')
            ->setAnafDownloadId('4567890')
            ->setAnafStatus('ok')
            ->setSyncedAt(new \DateTimeImmutable('-1 day'))
            ->setNotes('Stornare partiala factura UEP2026000001');

        $this->addLine($inv6, 1, 'Stornare echipament hidraulic', '1', 'buc', '15000.00', '21.00', 'S', 'product-1');
        $this->calculateTotals($inv6);

        $inv6->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata(['action' => 'synced_from_anaf']));
        $inv6->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::VALIDATED)->setMetadata([]));

        $manager->persist($inv6);
        $this->addReference('invoice-6', $inv6);

        // Invoice 7: Fully paid outgoing (company-4 Contabilitate → client-4 Tech Innovations)
        $inv7 = (new Invoice())
            ->setCompany($this->getReference('company-4', Company::class))
            ->setClient($this->getReference('client-4', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::VALIDATED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setAnafMessageId('msg-007')
            ->setNumber('CE000001')
            ->setIssueDate(new \DateTimeImmutable('-25 days'))
            ->setDueDate(new \DateTimeImmutable('-10 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('11223344')
            ->setSenderName('Contabilitate Expert SRL')
            ->setReceiverCif('33445566')
            ->setReceiverName('Tech Innovations SRL')
            ->setAnafUploadId('7890123')
            ->setAnafDownloadId('3210987')
            ->setAnafStatus('ok')
            ->setSyncedAt(new \DateTimeImmutable('-5 days'));

        $this->addLine($inv7, 1, 'Servicii contabilitate lunara', '1', 'luna', '800.00', '21.00', 'S', 'product-6');
        $this->addLine($inv7, 2, 'Declaratii fiscale Q4', '3', 'buc', '150.00', '21.00', 'S', 'product-7');
        $this->calculateTotals($inv7);
        $inv7->setPaidAt(new \DateTimeImmutable('-8 days'));
        $inv7->setAmountPaid($inv7->getTotal());
        $inv7->setPaymentMethod('bank_transfer');

        $inv7->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata([]));
        $inv7->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::VALIDATED)->setMetadata([]));
        $inv7->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::VALIDATED)->setMetadata(['action' => 'payment_recorded', 'payment_ref' => 'OP-2026-050']));

        $manager->persist($inv7);
        $this->addReference('invoice-7', $inv7);

        // Invoice 8: Fully paid invoice (company-6 Ion Popescu → client-5 WebDev Agency)
        $inv8 = (new Invoice())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setClient($this->getReference('client-5', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::VALIDATED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setAnafMessageId('msg-008')
            ->setNumber('IP2026000001')
            ->setIssueDate(new \DateTimeImmutable('-30 days'))
            ->setDueDate(new \DateTimeImmutable('-16 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('12345678')
            ->setSenderName('Ion Popescu PFA')
            ->setReceiverCif('44556677')
            ->setReceiverName('WebDev Agency SRL')
            ->setAnafUploadId('2345678')
            ->setAnafDownloadId('8765432')
            ->setAnafStatus('ok')
            ->setSyncedAt(new \DateTimeImmutable('-7 days'));

        $this->addLine($inv8, 1, 'Dezvoltare web - modul autentificare', '80', 'ora', '200.00', '21.00', 'S', 'product-9');
        $this->addLine($inv8, 2, 'Design UI/UX - pagini autentificare', '30', 'ora', '180.00', '21.00', 'S', 'product-10');
        $this->calculateTotals($inv8);
        $inv8->setPaidAt(new \DateTimeImmutable('-14 days'));
        $inv8->setAmountPaid($inv8->getTotal());
        $inv8->setPaymentMethod('bank_transfer');

        $inv8->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata([]));
        $inv8->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::VALIDATED)->setMetadata([]));
        $inv8->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::VALIDATED)->setMetadata(['action' => 'payment_recorded', 'payment_ref' => 'OP-2026-001']));

        $manager->persist($inv8);
        $this->addReference('invoice-8', $inv8);

        // Invoice 9: Partially paid (company-1 → client-2) — status stays VALIDATED
        $inv9 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-2', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::VALIDATED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setAnafMessageId('msg-009')
            ->setNumber('UEP2026000006')
            ->setIssueDate(new \DateTimeImmutable('-35 days'))
            ->setDueDate(new \DateTimeImmutable('-5 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('12345678')
            ->setSenderName('Utilaje Echipamente Profesionale SRL')
            ->setReceiverCif('14399840')
            ->setReceiverName('Dedeman SRL')
            ->setAnafUploadId('6789012')
            ->setAnafDownloadId('2109876')
            ->setAnafStatus('ok')
            ->setSyncedAt(new \DateTimeImmutable('-8 days'));

        $this->addLine($inv9, 1, 'Echipament hidraulic EH-300', '2', 'buc', '15000.00', '21.00', 'S', 'product-1');
        $this->addLine($inv9, 2, 'Transport utilaje', '200', 'km', '8.50', '21.00', 'S', 'product-4');
        $this->addLine($inv9, 3, 'Servicii montaj echipament', '16', 'ora', '250.00', '21.00', 'S', 'product-3');
        $this->calculateTotals($inv9);
        $inv9->setAmountPaid('25000.00');

        $inv9->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata([]));
        $inv9->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::VALIDATED)->setMetadata([]));
        $inv9->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::VALIDATED)->setMetadata(['action' => 'payment_recorded', 'amount_paid' => '25000.00']));

        $manager->persist($inv9);
        $this->addReference('invoice-9', $inv9);

        // Invoice 10: Sent to ANAF, awaiting validation (company-2 → company-1 related)
        $inv10 = (new Invoice())
            ->setCompany($this->getReference('company-2', Company::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::SENT_TO_PROVIDER)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setAnafMessageId('msg-010')
            ->setNumber('RKS2026000001')
            ->setIssueDate(new \DateTimeImmutable('-1 day'))
            ->setDueDate(new \DateTimeImmutable('+29 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('24899169')
            ->setSenderName('Rikko Steel SRL')
            ->setReceiverCif('31385365')
            ->setReceiverName('Universal Equipment Projects SRL')
            ->setAnafUploadId('9876543')
            ->setSyncedAt(new \DateTimeImmutable());

        $this->addLine($inv10, 1, 'Profile otel inoxidabil', '50', 'buc', '350.00', '21.00', 'S', null);
        $this->addLine($inv10, 2, 'Tabla otel 2mm', '100', 'mp', '120.00', '21.00', 'S', null);
        $this->calculateTotals($inv10);

        $inv10->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata([]));
        $inv10->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::SENT_TO_PROVIDER)->setMetadata(['upload_id' => '9876543']));

        $manager->persist($inv10);
        $this->addReference('invoice-10', $inv10);

        // Invoice 11: DRAFT outgoing (company-1 → client-7 LIDL), big multi-line
        $inv11 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-7', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::DRAFT)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setNumber('UEP2026000007')
            ->setIssueDate(new \DateTimeImmutable())
            ->setDueDate(new \DateTimeImmutable('+30 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('31385365')
            ->setSenderName('Universal Equipment Projects SRL')
            ->setReceiverCif('16329050')
            ->setReceiverName('LIDL ROMANIA SCS')
            ->setNotes('Comanda #LDL-2026-0234');

        $this->addLine($inv11, 1, 'Pompa hidraulica PH-200', '4', 'buc', '8500.00', '21.00', 'S', 'product-12');
        $this->addLine($inv11, 2, 'Compresor industrial CI-500', '2', 'buc', '12000.00', '21.00', 'S', 'product-13');
        $this->addLine($inv11, 3, 'Filtre hidraulice set', '10', 'set', '450.00', '21.00', 'S', 'product-14');
        $this->addLine($inv11, 4, 'Consultanta tehnica', '8', 'ora', '350.00', '21.00', 'S', 'product-15');
        $this->addLine($inv11, 5, 'Transport utilaje', '250', 'km', '8.50', '21.00', 'S', 'product-4');
        $this->calculateTotals($inv11);

        $manager->persist($inv11);
        $this->addReference('invoice-11', $inv11);

        // Invoice 12: ISSUED outgoing EUR (company-1 → client-8 HORNBACH)
        $inv12 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-8', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::ISSUED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setNumber('UEP2026000008')
            ->setIssueDate(new \DateTimeImmutable('-8 days'))
            ->setDueDate(new \DateTimeImmutable('+37 days'))
            ->setCurrency('EUR')
            ->setLanguage('ro')
            ->setSenderCif('31385365')
            ->setSenderName('Universal Equipment Projects SRL')
            ->setReceiverCif('18274432')
            ->setReceiverName('HORNBACH BAUMARKT SRL')
            ->setPaymentTerms('45 zile');

        $this->addLine($inv12, 1, 'Hydraulic pump HP-300', '2', 'buc', '1750.00', '21.00', 'S', 'product-12');
        $this->addLine($inv12, 2, 'Industrial compressor IC-200', '1', 'buc', '2450.00', '21.00', 'S', 'product-13');
        $this->addLine($inv12, 3, 'Technical inspection', '1', 'buc', '400.00', '21.00', 'S', 'product-16');
        $this->calculateTotals($inv12);

        $inv12->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::DRAFT)->setMetadata([]));
        $inv12->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::DRAFT)->setNewStatus(DocumentStatus::ISSUED)->setMetadata([]));

        $manager->persist($inv12);
        $this->addReference('invoice-12', $inv12);

        // Invoice 13: CANCELLED outgoing (company-1 → client-9 EMAG)
        $inv13 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-9', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::CANCELLED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setNumber('UEP2026000009')
            ->setIssueDate(new \DateTimeImmutable('-25 days'))
            ->setDueDate(new \DateTimeImmutable('-5 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('31385365')
            ->setSenderName('Universal Equipment Projects SRL')
            ->setReceiverCif('14399840')
            ->setReceiverName('EMAG.RO SRL')
            ->setNotes('Anulata - comanda returnata de client');

        $this->addLine($inv13, 1, 'Echipament hidraulic EH-200', '1', 'buc', '15000.00', '21.00', 'S', 'product-1');
        $this->calculateTotals($inv13);

        $inv13->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::DRAFT)->setMetadata([]));
        $inv13->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::DRAFT)->setNewStatus(DocumentStatus::ISSUED)->setMetadata([]));
        $inv13->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::ISSUED)->setNewStatus(DocumentStatus::CANCELLED)->setMetadata(['reason' => 'Comanda returnata']));

        $manager->persist($inv13);
        $this->addReference('invoice-13', $inv13);

        // Invoice 14: SYNCED incoming (company-1 ← client-10 ALTEX)
        $inv14 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-10', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::SYNCED)
            ->setDirection(InvoiceDirection::INCOMING)
            ->setAnafMessageId('msg-014')
            ->setNumber('ALT2026000345')
            ->setIssueDate(new \DateTimeImmutable('-2 days'))
            ->setDueDate(new \DateTimeImmutable('+18 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('1269480')
            ->setSenderName('ALTEX ROMANIA SRL')
            ->setReceiverCif('31385365')
            ->setReceiverName('Universal Equipment Projects SRL')
            ->setSyncedAt(new \DateTimeImmutable('-1 day'))
            ->setPaymentTerms('20 zile');

        $this->addLine($inv14, 1, 'Monitor industrial 24"', '4', 'buc', '1800.00', '21.00', 'S', null);
        $this->addLine($inv14, 2, 'Tastatura industriala IP65', '4', 'buc', '350.00', '21.00', 'S', null);
        $this->addLine($inv14, 3, 'UPS APC 1500VA', '2', 'buc', '2200.00', '21.00', 'S', null);
        $this->calculateTotals($inv14);

        $inv14->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata(['action' => 'synced_from_anaf', 'anafMessageId' => 'msg-014']));

        $manager->persist($inv14);
        $this->addReference('invoice-14', $inv14);

        // Invoice 15: VALIDATED outgoing with discount (company-1 → client-2 Dedeman)
        $inv15 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-2', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::VALIDATED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setAnafMessageId('msg-015')
            ->setNumber('UEP2026000010')
            ->setIssueDate(new \DateTimeImmutable('-12 days'))
            ->setDueDate(new \DateTimeImmutable('+3 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('31385365')
            ->setSenderName('Universal Equipment Projects SRL')
            ->setReceiverCif('4521785')
            ->setReceiverName('DEDEMAN SRL')
            ->setAnafUploadId('1122334')
            ->setAnafDownloadId('4433221')
            ->setAnafStatus('ok')
            ->setSyncedAt(new \DateTimeImmutable('-3 days'))
            ->setDiscount('2500.00')
            ->setNotes('Discount 5% comanda mare');

        $this->addLine($inv15, 1, 'Echipament hidraulic EH-500', '3', 'buc', '15000.00', '21.00', 'S', 'product-1');
        $this->addLine($inv15, 2, 'Inspectie echipamente', '3', 'buc', '2000.00', '21.00', 'S', 'product-16');
        $this->calculateTotals($inv15);
        $inv15->setDiscount('2500.00');

        $inv15->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata([]));
        $inv15->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::VALIDATED)->setMetadata([]));

        $manager->persist($inv15);
        $this->addReference('invoice-15', $inv15);

        // Invoice 16: DRAFT (company-4 → client-12 CURSOR DIGITAL)
        $inv16 = (new Invoice())
            ->setCompany($this->getReference('company-4', Company::class))
            ->setClient($this->getReference('client-12', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::DRAFT)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setNumber('CE000003')
            ->setIssueDate(new \DateTimeImmutable())
            ->setDueDate(new \DateTimeImmutable('+30 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('11223344')
            ->setSenderName('Contabilitate Expert SRL')
            ->setReceiverCif('42567890')
            ->setReceiverName('CURSOR DIGITAL SRL');

        $this->addLine($inv16, 1, 'Audit financiar anual', '1', 'buc', '3500.00', '21.00', 'S', 'product-18');
        $this->addLine($inv16, 2, 'Consultanta resurse umane', '10', 'ora', '200.00', '21.00', 'S', 'product-17');
        $this->addLine($inv16, 3, 'Declaratii fiscale Q1', '3', 'buc', '150.00', '21.00', 'S', 'product-7');
        $this->calculateTotals($inv16);

        $manager->persist($inv16);
        $this->addReference('invoice-16', $inv16);

        // Invoice 17: VALIDATED outgoing (company-6 → client-13 MEDIPRINT)
        $inv17 = (new Invoice())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setClient($this->getReference('client-13', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::VALIDATED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setAnafMessageId('msg-017')
            ->setNumber('IP2026000002')
            ->setIssueDate(new \DateTimeImmutable('-18 days'))
            ->setDueDate(new \DateTimeImmutable('-4 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('12345678')
            ->setSenderName('Ion Popescu PFA')
            ->setReceiverCif('55778899')
            ->setReceiverName('MEDIPRINT SRL')
            ->setAnafUploadId('5566778')
            ->setAnafDownloadId('8776655')
            ->setAnafStatus('ok')
            ->setSyncedAt(new \DateTimeImmutable('-5 days'));

        $this->addLine($inv17, 1, 'Dezvoltare web - magazin online', '60', 'ora', '200.00', '21.00', 'S', 'product-9');
        $this->addLine($inv17, 2, 'Design UI/UX - pagini produs', '25', 'ora', '180.00', '21.00', 'S', 'product-10');
        $this->addLine($inv17, 3, 'Optimizare SEO - configurare initiala', '1', 'luna', '800.00', '21.00', 'S', 'product-19');
        $this->calculateTotals($inv17);
        $inv17->setPaidAt(new \DateTimeImmutable('-2 days'));
        $inv17->setAmountPaid($inv17->getTotal());
        $inv17->setPaymentMethod('bank_transfer');

        $inv17->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata([]));
        $inv17->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::VALIDATED)->setMetadata([]));

        $manager->persist($inv17);
        $this->addReference('invoice-17', $inv17);

        // Invoice 18: ISSUED (company-6 → client-14 Stanciu Andrei, individual)
        $inv18 = (new Invoice())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setClient($this->getReference('client-14', Client::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::ISSUED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setNumber('IP2026000003')
            ->setIssueDate(new \DateTimeImmutable('-5 days'))
            ->setDueDate(new \DateTimeImmutable('+9 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('12345678')
            ->setSenderName('Ion Popescu PFA')
            ->setReceiverName('Stanciu Andrei');

        $this->addLine($inv18, 1, 'Dezvoltare web - landing page', '20', 'ora', '200.00', '21.00', 'S', 'product-9');
        $this->addLine($inv18, 2, 'Hosting web premium', '12', 'luna', '150.00', '21.00', 'S', 'product-20');
        $this->addLine($inv18, 3, 'Certificat SSL', '1', 'buc', '250.00', '21.00', 'S', 'product-21');
        $this->calculateTotals($inv18);

        $inv18->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::DRAFT)->setMetadata([]));
        $inv18->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::DRAFT)->setNewStatus(DocumentStatus::ISSUED)->setMetadata([]));

        $manager->persist($inv18);
        $this->addReference('invoice-18', $inv18);

        // Invoice 19: SYNCED incoming (company-2 Rikko Steel, from external supplier)
        $inv19 = (new Invoice())
            ->setCompany($this->getReference('company-2', Company::class))
            ->setDocumentType(DocumentType::INVOICE)
            ->setStatus(DocumentStatus::SYNCED)
            ->setDirection(InvoiceDirection::INCOMING)
            ->setAnafMessageId('msg-019')
            ->setNumber('FV2026-0567')
            ->setIssueDate(new \DateTimeImmutable('-3 days'))
            ->setDueDate(new \DateTimeImmutable('+27 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('9398887')
            ->setSenderName('HILTI ROMANIA SRL')
            ->setReceiverCif('24899169')
            ->setReceiverName('RIKKO STEEL SRL')
            ->setSyncedAt(new \DateTimeImmutable('-2 days'));

        $this->addLine($inv19, 1, 'Masina de gaurit Hilti TE-60', '3', 'buc', '4500.00', '21.00', 'S', null);
        $this->addLine($inv19, 2, 'Set burghie SDS-MAX', '5', 'set', '850.00', '21.00', 'S', null);
        $this->calculateTotals($inv19);

        $inv19->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata(['action' => 'synced_from_anaf']));

        $manager->persist($inv19);
        $this->addReference('invoice-19', $inv19);

        // Invoice 20: Credit note outgoing (company-1 → client-9 EMAG)
        $inv20 = (new Invoice())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setClient($this->getReference('client-9', Client::class))
            ->setDocumentType(DocumentType::CREDIT_NOTE)
            ->setStatus(DocumentStatus::VALIDATED)
            ->setDirection(InvoiceDirection::OUTGOING)
            ->setAnafMessageId('msg-020')
            ->setNumber('UEPCN2026000002')
            ->setIssueDate(new \DateTimeImmutable('-3 days'))
            ->setDueDate(new \DateTimeImmutable('+27 days'))
            ->setCurrency('RON')
            ->setLanguage('ro')
            ->setSenderCif('31385365')
            ->setSenderName('Universal Equipment Projects SRL')
            ->setReceiverCif('14399840')
            ->setReceiverName('EMAG.RO SRL')
            ->setAnafUploadId('7788990')
            ->setAnafDownloadId('0998877')
            ->setAnafStatus('ok')
            ->setSyncedAt(new \DateTimeImmutable('-1 day'))
            ->setNotes('Stornare factura UEP2026000009');

        $this->addLine($inv20, 1, 'Stornare echipament hidraulic', '1', 'buc', '15000.00', '21.00', 'S', 'product-1');
        $this->calculateTotals($inv20);

        $inv20->addEvent((new DocumentEvent())->setNewStatus(DocumentStatus::SYNCED)->setMetadata([]));
        $inv20->addEvent((new DocumentEvent())->setPreviousStatus(DocumentStatus::SYNCED)->setNewStatus(DocumentStatus::VALIDATED)->setMetadata([]));

        $manager->persist($inv20);
        $this->addReference('invoice-20', $inv20);

        $manager->flush();
    }

    private function addLine(Invoice $invoice, int $position, string $description, string $qty, string $unit, string $price, string $vatRate, string $vatCode, ?string $productRef): void
    {
        $lineTotal = bcmul($qty, $price, 2);
        $vatAmount = bcdiv(bcmul($lineTotal, $vatRate, 4), '100', 2);

        $line = (new InvoiceLine())
            ->setPosition($position)
            ->setDescription($description)
            ->setQuantity($qty)
            ->setUnitOfMeasure($unit)
            ->setUnitPrice($price)
            ->setVatRate($vatRate)
            ->setVatCategoryCode($vatCode)
            ->setVatAmount($vatAmount)
            ->setLineTotal($lineTotal)
            ->setDiscount('0.00')
            ->setDiscountPercent('0.00');

        if ($productRef) {
            $line->setProduct($this->getReference($productRef, Product::class));
        }

        $invoice->addLine($line);
    }

    private function calculateTotals(Invoice $invoice): void
    {
        $subtotal = '0.00';
        $vatTotal = '0.00';

        foreach ($invoice->getLines() as $line) {
            $subtotal = bcadd($subtotal, $line->getLineTotal(), 2);
            $vatTotal = bcadd($vatTotal, $line->getVatAmount(), 2);
        }

        $total = bcadd($subtotal, $vatTotal, 2);

        $invoice->setSubtotal($subtotal)
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
        ];
    }
}
