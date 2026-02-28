<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookDeliveryStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class WebhookFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Endpoint 1: company-1 ERP integration (active)
        $endpoint1 = (new WebhookEndpoint())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setUrl('https://erp.example.com/webhooks/storno')
            ->setDescription('Integrare ERP')
            ->setEvents(['invoice.created', 'invoice.validated', 'invoice.rejected', 'payment.received'])
            ->setIsActive(true);

        $manager->persist($endpoint1);
        $this->addReference('webhook-endpoint-1', $endpoint1);

        // Endpoint 2: company-1 Slack notifications (active)
        $endpoint2 = (new WebhookEndpoint())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setUrl('https://slack-hook.example.com/services/T123/B456')
            ->setDescription('Notificari Slack')
            ->setEvents(['invoice.rejected', 'sync.error'])
            ->setIsActive(true);

        $manager->persist($endpoint2);
        $this->addReference('webhook-endpoint-2', $endpoint2);

        // Endpoint 3: company-1 old system (inactive)
        $endpoint3 = (new WebhookEndpoint())
            ->setCompany($this->getReference('company-1', Company::class))
            ->setUrl('https://old-system.example.com/callback')
            ->setDescription('Sistem vechi (dezactivat)')
            ->setEvents(['invoice.created'])
            ->setIsActive(false);

        $manager->persist($endpoint3);
        $this->addReference('webhook-endpoint-3', $endpoint3);

        // Endpoint 4: company-4 accounting app (active)
        $endpoint4 = (new WebhookEndpoint())
            ->setCompany($this->getReference('company-4', Company::class))
            ->setUrl('https://accounting-app.example.com/hooks')
            ->setDescription('Aplicatie contabilitate')
            ->setEvents(['invoice.created', 'invoice.validated', 'sync.completed'])
            ->setIsActive(true);

        $manager->persist($endpoint4);
        $this->addReference('webhook-endpoint-4', $endpoint4);

        // Delivery 1: invoice.created SUCCESS (endpoint-1)
        $delivery1 = (new WebhookDelivery())
            ->setEndpoint($endpoint1)
            ->setEventType('invoice.created')
            ->setPayload([
                'event' => 'invoice.created',
                'timestamp' => (new \DateTimeImmutable('-2 hours'))->format(\DateTimeInterface::ATOM),
                'data' => [
                    'invoice_id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                    'number' => 'UEP2026000001',
                    'company_cif' => '31385365',
                    'client_name' => 'Mega Image SRL',
                    'total' => '34272.50',
                    'currency' => 'RON',
                    'status' => 'synced',
                ],
            ])
            ->setResponseStatusCode(200)
            ->setResponseBody('{"success":true,"message":"Webhook received"}')
            ->setDurationMs(145)
            ->setAttempt(1)
            ->setStatus(WebhookDeliveryStatus::SUCCESS)
            ->setCompletedAt(new \DateTimeImmutable('-2 hours'));

        $manager->persist($delivery1);

        // Delivery 2: invoice.validated SUCCESS (endpoint-1)
        $delivery2 = (new WebhookDelivery())
            ->setEndpoint($endpoint1)
            ->setEventType('invoice.validated')
            ->setPayload([
                'event' => 'invoice.validated',
                'timestamp' => (new \DateTimeImmutable('-3 days'))->format(\DateTimeInterface::ATOM),
                'data' => [
                    'invoice_id' => 'b2c3d4e5-f6a7-8901-bcde-f12345678901',
                    'number' => 'UEP2026000002',
                    'company_cif' => '31385365',
                    'client_name' => 'Dedeman SRL',
                    'total' => '39270.00',
                    'currency' => 'RON',
                    'status' => 'validated',
                    'anaf_download_id' => '9283746',
                ],
            ])
            ->setResponseStatusCode(200)
            ->setResponseBody('{"success":true,"processed":true}')
            ->setDurationMs(230)
            ->setAttempt(1)
            ->setStatus(WebhookDeliveryStatus::SUCCESS)
            ->setCompletedAt(new \DateTimeImmutable('-3 days'));

        $manager->persist($delivery2);

        // Delivery 3: payment.received FAILED (endpoint-1)
        $delivery3 = (new WebhookDelivery())
            ->setEndpoint($endpoint1)
            ->setEventType('payment.received')
            ->setPayload([
                'event' => 'payment.received',
                'timestamp' => (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM),
                'data' => [
                    'invoice_id' => 'c3d4e5f6-a7b8-9012-cdef-123456789012',
                    'number' => 'UEP2026000006',
                    'amount_paid' => '20000.00',
                    'currency' => 'RON',
                    'payment_reference' => 'OP-2026-099',
                ],
            ])
            ->setResponseStatusCode(500)
            ->setResponseBody('{"error":"Internal Server Error","code":500}')
            ->setDurationMs(5200)
            ->setAttempt(3)
            ->setStatus(WebhookDeliveryStatus::FAILED)
            ->setErrorMessage('HTTP 500')
            ->setCompletedAt(new \DateTimeImmutable('-1 day'));

        $manager->persist($delivery3);

        // Delivery 4: invoice.rejected RETRYING (endpoint-1)
        $delivery4 = (new WebhookDelivery())
            ->setEndpoint($endpoint1)
            ->setEventType('invoice.rejected')
            ->setPayload([
                'event' => 'invoice.rejected',
                'timestamp' => (new \DateTimeImmutable('-30 minutes'))->format(\DateTimeInterface::ATOM),
                'data' => [
                    'invoice_id' => 'd4e5f6a7-b8c9-0123-def0-234567890123',
                    'number' => 'UEP2026000004',
                    'company_cif' => '31385365',
                    'status' => 'rejected',
                    'anaf_error' => 'Eroare validare: CIF cumparator invalid.',
                ],
            ])
            ->setAttempt(2)
            ->setStatus(WebhookDeliveryStatus::RETRYING)
            ->setNextRetryAt(new \DateTimeImmutable('+5 minutes'));

        $manager->persist($delivery4);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CompanyFixtures::class,
        ];
    }
}
