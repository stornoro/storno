<?php

namespace App\Tests\Unit;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The vehicle expiry alert e-mail: it must name the vehicle, the document, the policy
 * and the date, and link to the vehicle page of the right company.
 */
final class ExpiryEmailTemplateTest extends KernelTestCase
{
    private function render(array $data, string $message = 'RCA expiră pe 12.10.2026.'): string
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get(Environment::class);

        return $twig->render('emails/notification_expiry_due.html.twig', [
            'title' => 'Termen vehicul',
            'message' => $message,
            'data' => $data,
            'frontendUrl' => 'https://app.storno.ro',
            'unsubscribeUrl' => 'https://app.storno.ro/unsubscribe/x',
            'locale' => 'ro',
        ]);
    }

    public function testItShowsTheVehicleDocumentAndPolicy(): void
    {
        $html = $this->render([
            'vehicle' => 'B-00-TST · Dacia Logan',
            'label' => 'RCA',
            'kindLabel' => 'asigurare RCA',
            'number' => 'POL0000001',
            'provider' => 'Asigurator Test S.A.',
            'expiresAt' => '2026-10-12',
            'expiresAtLabel' => '12.10.2026',
            'daysLeft' => 7,
            'companyId' => '00000000-0000-0000-0000-000000000001',
            'companyName' => 'Firma Test SRL',
            'url' => '/vehicles/00000000-0000-0000-0000-000000000002',
        ]);

        self::assertStringContainsString('B-00-TST · Dacia Logan', $html);
        self::assertStringContainsString('POL0000001', $html);
        self::assertStringContainsString('Asigurator Test S.A.', $html);
        self::assertStringContainsString('12.10.2026', $html);
        self::assertStringContainsString('RCA expira in 7 zile', $html, 'the heading names the document');
        self::assertStringContainsString('https://app.storno.ro/vehicles/00000000-0000-0000-0000-000000000002?company=00000000-0000-0000-0000-000000000001', $html);
        self::assertStringNotContainsString('notifications.expiry_due.', $html, 'every label must be translated');
    }

    public function testAnItemWithoutAVehicleOrPolicyStillRenders(): void
    {
        $html = $this->render([
            'label' => 'Certificat digital',
            'kindLabel' => 'certificat digital',
            'expiresAt' => '2026-09-17',
            'expiresAtLabel' => '17.09.2026',
            'daysLeft' => 0,
            'companyId' => '00000000-0000-0000-0000-000000000001',
        ], 'Certificatul digital expiră astăzi.');

        self::assertStringContainsString('Certificat digital', $html);
        self::assertStringContainsString('Certificat digital expira astazi', $html);
        self::assertStringContainsString('https://app.storno.ro/expiries?company=', $html, 'without a vehicle the link goes to the list');
        self::assertStringNotContainsString('notifications.expiry_due.', $html);
    }
}
