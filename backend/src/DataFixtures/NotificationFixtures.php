<?php

namespace App\DataFixtures;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class NotificationFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $notifications = [
            [
                'user' => 'user-1',
                'type' => 'invoice.validated',
                'channel' => 'in_app',
                'title' => 'Factura validata ANAF',
                'message' => 'Factura UEP2026000002 a fost validata cu succes de ANAF.',
                'link' => '/invoices/2',
                'isRead' => true,
                'data' => ['invoice_number' => 'UEP2026000002'],
            ],
            [
                'user' => 'user-1',
                'type' => 'invoice.rejected',
                'channel' => 'in_app',
                'title' => 'Factura respinsa ANAF',
                'message' => 'Factura UEP2026000004 a fost respinsa de ANAF: CIF cumparator invalid.',
                'link' => '/invoices/4/efactura',
                'isRead' => false,
                'data' => ['invoice_number' => 'UEP2026000004', 'error' => 'CIF cumparator invalid'],
            ],
            [
                'user' => 'user-1',
                'type' => 'invoice.overdue',
                'channel' => 'in_app',
                'title' => 'Factura scadenta',
                'message' => 'Factura UEP2026000005 catre DEDEMAN SRL este scadenta de 15 zile.',
                'link' => '/invoices/5',
                'isRead' => false,
                'data' => ['invoice_number' => 'UEP2026000005', 'days_overdue' => 15],
            ],
            [
                'user' => 'user-1',
                'type' => 'payment.received',
                'channel' => 'in_app',
                'title' => 'Plata inregistrata',
                'message' => 'S-a inregistrat o plata de 20,000 RON pentru factura UEP2026000006.',
                'link' => '/collections',
                'isRead' => false,
                'data' => ['amount' => '20000.00', 'invoice_number' => 'UEP2026000006'],
            ],
            [
                'user' => 'user-2',
                'type' => 'invoice.issued',
                'channel' => 'in_app',
                'title' => 'Factura emisa',
                'message' => 'Factura CE000001 a fost emisa cu succes.',
                'link' => '/invoices/7',
                'isRead' => true,
                'data' => ['invoice_number' => 'CE000001'],
            ],
            [
                'user' => 'user-5',
                'type' => 'invoice.paid',
                'channel' => 'in_app',
                'title' => 'Factura platita integral',
                'message' => 'Factura IP2026000001 a fost platita integral.',
                'link' => '/invoices/8',
                'isRead' => true,
                'data' => ['invoice_number' => 'IP2026000001'],
            ],
            // Additional notifications
            [
                'user' => 'user-1',
                'type' => 'proforma.accepted',
                'channel' => 'in_app',
                'title' => 'Proforma acceptata',
                'message' => 'Proforma UEPPF-000002 a fost acceptata de MEGA IMAGE SRL.',
                'link' => '/proforma-invoices/2',
                'isRead' => true,
                'data' => ['proforma_number' => 'UEPPF-000002'],
            ],
            [
                'user' => 'user-1',
                'type' => 'proforma.expired',
                'channel' => 'in_app',
                'title' => 'Proforma expirata',
                'message' => 'Proforma UEPPF-000004 pentru LIDL ROMANIA SCS a expirat.',
                'link' => '/proforma-invoices/6',
                'isRead' => false,
                'data' => ['proforma_number' => 'UEPPF-000004'],
            ],
            [
                'user' => 'user-1',
                'type' => 'delivery_note.issued',
                'channel' => 'in_app',
                'title' => 'Aviz de livrare emis',
                'message' => 'Avizul UEPAV-000004 a fost emis pentru LIDL ROMANIA SCS.',
                'link' => '/delivery-notes/4',
                'isRead' => false,
                'data' => ['delivery_note_number' => 'UEPAV-000004'],
            ],
            [
                'user' => 'user-1',
                'type' => 'recurring_invoice.issued',
                'channel' => 'in_app',
                'title' => 'Factura recurenta emisa',
                'message' => 'Factura recurenta UEP2026000015 a fost emisa automat pentru LIDL ROMANIA SCS.',
                'link' => '/invoices/15',
                'isRead' => false,
                'data' => ['invoice_number' => 'UEP2026000015'],
            ],
            [
                'user' => 'user-1',
                'type' => 'invoice.synced',
                'channel' => 'in_app',
                'title' => 'Factura noua sincronizata',
                'message' => 'Factura ALT2026000345 de la ALTEX ROMANIA SRL a fost sincronizata din SPV.',
                'link' => '/invoices/14',
                'isRead' => false,
                'data' => ['invoice_number' => 'ALT2026000345', 'sender' => 'ALTEX ROMANIA SRL'],
            ],
            [
                'user' => 'user-5',
                'type' => 'invoice.paid',
                'channel' => 'in_app',
                'title' => 'Factura platita integral',
                'message' => 'Factura IP2026000002 catre MEDIPRINT SRL a fost platita integral.',
                'link' => '/invoices/17',
                'isRead' => false,
                'data' => ['invoice_number' => 'IP2026000002'],
            ],
            [
                'user' => 'user-2',
                'type' => 'team.member_joined',
                'channel' => 'in_app',
                'title' => 'Membru nou in echipa',
                'message' => 'Maria Stoica s-a alaturat organizatiei cu rolul Contabil.',
                'link' => '/settings/team',
                'isRead' => true,
                'data' => ['member_name' => 'Maria Stoica', 'role' => 'ACCOUNTANT'],
            ],
            [
                'user' => 'user-1',
                'type' => 'system.backup_completed',
                'channel' => 'in_app',
                'title' => 'Backup finalizat',
                'message' => 'Backup-ul automat al datelor a fost realizat cu succes.',
                'link' => '/settings/backups',
                'isRead' => true,
                'data' => ['backup_size' => '245MB'],
            ],
        ];

        foreach ($notifications as $data) {
            $notification = (new Notification())
                ->setUser($this->getReference($data['user'], User::class))
                ->setType($data['type'])
                ->setChannel($data['channel'])
                ->setTitle($data['title'])
                ->setMessage($data['message'])
                ->setLink($data['link'])
                ->setSentAt(new \DateTimeImmutable('-' . rand(1, 48) . ' hours'))
                ->setIsRead($data['isRead'])
                ->setData($data['data'])
                ->setEmailSent(true);

            $manager->persist($notification);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
