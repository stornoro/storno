<?php

namespace App\Tests\Unit;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Every reminder e-mail must name its subject, show the details the reader needs to act
 * and link back to the right page of the right company — no untranslated keys.
 */
final class NotificationEmailTemplatesTest extends KernelTestCase
{
    private function render(string $template, array $data, string $message): string
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get(Environment::class);

        return $twig->render($template, [
            'title' => 'Storno',
            'message' => $message,
            'data' => $data,
            'frontendUrl' => 'https://app.storno.ro',
            'unsubscribeUrl' => 'https://app.storno.ro/unsubscribe/x',
            'locale' => 'ro',
        ]);
    }

    public function testFiscalDeadlineNamesTheDeclarationAndLinksToTheCreateDialog(): void
    {
        $html = $this->render('emails/notification_fiscal_deadline.html.twig', [
            'code' => 'D300',
            'label' => 'Decont de TVA',
            'periodLabel' => 'august 2026',
            'dueDate' => '2026-09-25',
            'dueDateLabel' => '25.09.2026',
            'daysLeft' => 3,
            'declarationType' => 'd300',
            'period' => ['year' => 2026, 'month' => 8],
            'companyId' => '00000000-0000-0000-0000-000000000001',
            'companyName' => 'Firma Test SRL',
        ], 'D300 pentru august 2026 se depune pe 25.09.2026.');

        self::assertStringContainsString('D300 se depune in 3 zile', $html);
        self::assertStringContainsString('august 2026', $html);
        self::assertStringContainsString('25.09.2026', $html);
        self::assertStringContainsString('https://app.storno.ro/declarations?create=d300&amp;year=2026&amp;company=00000000-0000-0000-0000-000000000001', $html);
        self::assertStringNotContainsString('notifications.fiscal_deadline.', $html);
    }

    public function testFiscalDeadlineWithoutADeclarationTypeLinksToTheCalendar(): void
    {
        $html = $this->render('emails/notification_fiscal_deadline.html.twig', [
            'code' => 'BILANT',
            'label' => 'Situații financiare anuale',
            'dueDate' => '2027-05-31',
            'dueDateLabel' => '31.05.2027',
            'daysLeft' => 7,
            'declarationType' => null,
            'companyId' => '00000000-0000-0000-0000-000000000001',
        ], 'Situațiile financiare se depun pe 31.05.2027.');

        self::assertStringContainsString('https://app.storno.ro/fiscal-calendar?company=', $html);
        self::assertStringNotContainsString('notifications.fiscal_deadline.', $html);
    }

    public function testFiscalDeadlineDigestGroupsTheDeadlinesByCompany(): void
    {
        $html = $this->render('emails/notification_fiscal_deadline.html.twig', [
            'key' => 'digest:2026-09-22',
            'count' => 3,
            'companyCount' => 2,
            'companyId' => '00000000-0000-0000-0000-000000000001',
            'companies' => [
                ['companyId' => '00000000-0000-0000-0000-000000000001', 'companyName' => 'Firma Unu SRL', 'items' => [
                    ['code' => 'D300', 'label' => 'Decont de TVA', 'periodLabel' => '08.2026', 'dueDate' => '2026-09-25', 'dueDateLabel' => '25.09.2026', 'daysLeft' => 3, 'declarationType' => 'd300', 'period' => ['year' => 2026, 'month' => 8]],
                    ['code' => 'D112', 'label' => 'Contribuții sociale', 'periodLabel' => '08.2026', 'dueDate' => '2026-09-25', 'dueDateLabel' => '25.09.2026', 'daysLeft' => 3, 'declarationType' => 'd112', 'period' => ['year' => 2026, 'month' => 8]],
                ]],
                ['companyId' => '00000000-0000-0000-0000-000000000002', 'companyName' => 'Firma Doi SRL', 'items' => [
                    ['code' => 'D394', 'label' => 'Declarație informativă', 'periodLabel' => '08.2026', 'dueDate' => '2026-09-30', 'dueDateLabel' => '30.09.2026', 'daysLeft' => 8, 'declarationType' => 'd394', 'period' => ['year' => 2026, 'month' => 8]],
                ]],
            ],
        ], 'Firma Unu SRL: D300 (25.09.2026), D112 (25.09.2026) · Firma Doi SRL: D394 (30.09.2026)');

        self::assertStringContainsString('Firma Unu SRL', $html);
        self::assertStringContainsString('Firma Doi SRL', $html);
        self::assertStringContainsString('3 declaratii de depus', $html);
        self::assertStringContainsString('https://app.storno.ro/declarations?create=d300&amp;year=2026&amp;company=00000000-0000-0000-0000-000000000001', $html);
        self::assertStringContainsString('https://app.storno.ro/declarations?create=d394&amp;year=2026&amp;company=00000000-0000-0000-0000-000000000002', $html);
        self::assertStringContainsString('https://app.storno.ro/fiscal-calendar?company=00000000-0000-0000-0000-000000000001', $html);
        self::assertStringContainsString('30.09.2026', $html);
        self::assertStringNotContainsString('notifications.fiscal_deadline.', $html);
    }

    public function testDosarDeadlineShowsTheCaseFileAndItsStep(): void
    {
        $html = $this->render('emails/notification_dosar_deadline.html.twig', [
            'dosarId' => '00000000-0000-0000-0000-000000000009',
            'dosarTitle' => 'Contract de închiriere Str. Exemplu nr. 1',
            'label' => 'C168 înregistrare: 30 de zile de la semnarea contractului',
            'days' => 0,
            'deadlineAtLabel' => '17.09.2026',
            'companyId' => '00000000-0000-0000-0000-000000000001',
            'companyName' => 'Firma Test SRL',
            'url' => '/dosare/00000000-0000-0000-0000-000000000009',
        ], 'Termenul este astăzi.');

        self::assertStringContainsString('Contract de închiriere Str. Exemplu nr. 1', $html);
        self::assertStringContainsString('C168 înregistrare', $html);
        self::assertStringContainsString('Termen intr-un dosar astazi', $html);
        self::assertStringContainsString('https://app.storno.ro/dosare/00000000-0000-0000-0000-000000000009?company=', $html);
        self::assertStringNotContainsString('notifications.dosar_deadline.', $html);
    }

    public function testPartnerStatusListsTheChangesAndLinksToThePartner(): void
    {
        $html = $this->render('emails/notification_partner_status_changed.html.twig', [
            'partnerType' => 'supplier',
            'partnerId' => '00000000-0000-0000-0000-00000000000a',
            'partnerName' => 'Furnizor Test SRL',
            'identifier' => '12345678',
            'changeLabels' => ['A devenit inactiv la ANAF', 'A trecut la TVA la incasare'],
            'checkedAtLabel' => '17.09.2026',
            'companyId' => '00000000-0000-0000-0000-000000000001',
            'companyName' => 'Firma Test SRL',
        ], 'Furnizorul Furnizor Test SRL (12345678) a devenit inactiv.');

        self::assertStringContainsString('Furnizor Test SRL', $html);
        self::assertStringContainsString('A devenit inactiv la ANAF', $html);
        self::assertStringContainsString('A trecut la TVA la incasare', $html);
        self::assertStringContainsString('https://app.storno.ro/suppliers/00000000-0000-0000-0000-00000000000a?company=', $html);
        self::assertStringContainsString('Vezi furnizorul', $html);
        self::assertStringNotContainsString('notifications.partner_status_changed.', $html);
    }

    public function testEveryReminderTypeHasATemplate(): void
    {
        $reflection = new \ReflectionClass(\App\MessageHandler\SendExternalNotificationHandler::class);
        $templates = $reflection->getConstant('EMAIL_TEMPLATES');

        foreach (['expiry.due', 'fiscal.deadline', 'dosar.deadline', 'partner.status_changed'] as $type) {
            self::assertArrayHasKey($type, $templates, $type . ' sends a plain-text e-mail');
        }
    }
}
