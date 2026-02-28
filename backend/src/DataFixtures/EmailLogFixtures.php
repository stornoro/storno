<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\EmailLog;
use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\EmailStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class EmailLogFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Email 1: invoice-2, company-1, DELIVERED
        $email1 = (new EmailLog())
            ->setInvoice($this->getReference('invoice-2', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setToEmail('achizitii@dedeman.ro')
            ->setSubject('Factura UEP2026000002 - Utilaje Echipamente Profesionale SRL')
            ->setStatus(EmailStatus::DELIVERED)
            ->setSentAt(new \DateTimeImmutable('-19 days'))
            ->setSentBy($this->getReference('user-1', User::class))
            ->setTemplateUsed('Factura noua')
            ->setFromEmail('facturi@uep.ro')
            ->setFromName('UNIVERSAL EQUIPMENT PROJECTS SRL')
            ->setSesMessageId('ses-msg-001');
        $manager->persist($email1);

        // Email 2: invoice-7, company-4, DELIVERED
        $email2 = (new EmailLog())
            ->setInvoice($this->getReference('invoice-7', Invoice::class))
            ->setCompany($this->getReference('company-4', Company::class))
            ->setToEmail('office@techinnovations.ro')
            ->setSubject('Factura CE000001 - Contabilitate Expert SRL')
            ->setStatus(EmailStatus::DELIVERED)
            ->setSentAt(new \DateTimeImmutable('-24 days'))
            ->setSentBy($this->getReference('user-2', User::class))
            ->setTemplateUsed('Factura servicii contabilitate')
            ->setFromEmail('facturi@contabilitate.ro')
            ->setFromName('CONTABILITATE EXPERT SRL')
            ->setSesMessageId('ses-msg-002');
        $manager->persist($email2);

        // Email 3: invoice-8, company-6, SENT
        $email3 = (new EmailLog())
            ->setInvoice($this->getReference('invoice-8', Invoice::class))
            ->setCompany($this->getReference('company-6', Company::class))
            ->setToEmail('office@webdev.ro')
            ->setSubject('Factura IP2026000001 - Ion Popescu PFA')
            ->setStatus(EmailStatus::SENT)
            ->setSentAt(new \DateTimeImmutable('-29 days'))
            ->setSentBy($this->getReference('user-5', User::class))
            ->setFromEmail('ion.popescu@gmail.com')
            ->setFromName('ION POPESCU PFA')
            ->setSesMessageId('ses-msg-003');
        $manager->persist($email3);

        // Email 4: invoice-9 reminder, company-1, BOUNCED
        $email4 = (new EmailLog())
            ->setInvoice($this->getReference('invoice-9', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setToEmail('achizitii-old@dedeman.ro')
            ->setSubject('Memento: Factura UEP2026000006 scadenta')
            ->setStatus(EmailStatus::BOUNCED)
            ->setSentAt(new \DateTimeImmutable('-4 days'))
            ->setSentBy($this->getReference('user-1', User::class))
            ->setTemplateUsed('Memento plata')
            ->setFromEmail('facturi@uep.ro')
            ->setFromName('UNIVERSAL EQUIPMENT PROJECTS SRL')
            ->setErrorMessage('Address not found: achizitii-old@dedeman.ro')
            ->setSesMessageId('ses-msg-004');
        $manager->persist($email4);

        // Email 5: invoice-4, company-1, FAILED
        $email5 = (new EmailLog())
            ->setInvoice($this->getReference('invoice-4', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setToEmail('comenzi@mega-image.ro')
            ->setSubject('Factura UEP2026000004')
            ->setStatus(EmailStatus::FAILED)
            ->setSentAt(new \DateTimeImmutable('-14 days'))
            ->setSentBy($this->getReference('user-1', User::class))
            ->setFromEmail('facturi@uep.ro')
            ->setErrorMessage('SES quota exceeded');
        $manager->persist($email5);

        // Email 6: invoice-12 EUR, company-1, DELIVERED
        $email6 = (new EmailLog())
            ->setInvoice($this->getReference('invoice-12', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setToEmail('aprovizionare@hornbach.ro')
            ->setSubject('Factura UEP2026000008 - Universal Equipment Projects SRL')
            ->setStatus(EmailStatus::DELIVERED)
            ->setSentAt(new \DateTimeImmutable('-7 days'))
            ->setSentBy($this->getReference('user-1', User::class))
            ->setTemplateUsed('Factura noua')
            ->setFromEmail('facturi@uep.ro')
            ->setFromName('UNIVERSAL EQUIPMENT PROJECTS SRL')
            ->setSesMessageId('ses-msg-006');
        $manager->persist($email6);

        // Email 7: invoice-17, company-6, DELIVERED
        $email7 = (new EmailLog())
            ->setInvoice($this->getReference('invoice-17', Invoice::class))
            ->setCompany($this->getReference('company-6', Company::class))
            ->setToEmail('contact@mediprint.ro')
            ->setSubject('Factura IP2026000002 - Ion Popescu PFA')
            ->setStatus(EmailStatus::DELIVERED)
            ->setSentAt(new \DateTimeImmutable('-17 days'))
            ->setSentBy($this->getReference('user-5', User::class))
            ->setTemplateUsed('Factura noua')
            ->setFromEmail('ion.popescu@gmail.com')
            ->setFromName('ION POPESCU PFA')
            ->setSesMessageId('ses-msg-007');
        $manager->persist($email7);

        // Email 8: invoice-15 reminder, company-1, DELIVERED
        $email8 = (new EmailLog())
            ->setInvoice($this->getReference('invoice-15', Invoice::class))
            ->setCompany($this->getReference('company-1', Company::class))
            ->setToEmail('achizitii@dedeman.ro')
            ->setSubject('Memento: Factura UEP2026000010 - rest de plata')
            ->setStatus(EmailStatus::DELIVERED)
            ->setSentAt(new \DateTimeImmutable('-2 days'))
            ->setSentBy($this->getReference('user-1', User::class))
            ->setTemplateUsed('Memento plata')
            ->setFromEmail('facturi@uep.ro')
            ->setFromName('UNIVERSAL EQUIPMENT PROJECTS SRL')
            ->setSesMessageId('ses-msg-008');
        $manager->persist($email8);

        // Email 9: invoice-18, company-6, SENT (individual client)
        $email9 = (new EmailLog())
            ->setInvoice($this->getReference('invoice-18', Invoice::class))
            ->setCompany($this->getReference('company-6', Company::class))
            ->setToEmail('andrei.stanciu@gmail.com')
            ->setSubject('Factura IP2026000003 - Ion Popescu PFA')
            ->setStatus(EmailStatus::SENT)
            ->setSentAt(new \DateTimeImmutable('-4 days'))
            ->setSentBy($this->getReference('user-5', User::class))
            ->setFromEmail('ion.popescu@gmail.com')
            ->setFromName('ION POPESCU PFA')
            ->setSesMessageId('ses-msg-009');
        $manager->persist($email9);

        // Email 10: proforma reminder, company-6, DELIVERED
        $email10 = (new EmailLog())
            ->setCompany($this->getReference('company-6', Company::class))
            ->setToEmail('contact@mediprint.ro')
            ->setSubject('Oferta IPPF-000002 - Redesign website + SEO')
            ->setStatus(EmailStatus::DELIVERED)
            ->setSentAt(new \DateTimeImmutable('-2 days'))
            ->setSentBy($this->getReference('user-5', User::class))
            ->setTemplateUsed('Proforma noua')
            ->setFromEmail('ion.popescu@gmail.com')
            ->setFromName('ION POPESCU PFA')
            ->setSesMessageId('ses-msg-010');
        $manager->persist($email10);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CompanyFixtures::class,
            InvoiceFixtures::class,
            UserFixtures::class,
        ];
    }
}
