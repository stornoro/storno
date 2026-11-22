<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Automatic overdue reminders: the company's steps, the daily run, the opt-outs.
 */
final class OverdueReminderTest extends ApiTestCase
{
    private ?string $companyId = null;

    protected function tearDown(): void
    {
        // The test database is shared: the company must not keep mailing clients for other tests
        if ($this->companyId !== null) {
            // ... and the overdue invoices of this test must not show up in the next run's count
            static::getContainer()->get('doctrine')->getConnection()->executeStatement(
                "UPDATE invoice i INNER JOIN client c ON i.client_id = c.id SET i.overdue_reminders_disabled = 1
                 WHERE i.company_id = :id AND c.name LIKE 'Client Reamintire %'",
                ['id' => $this->companyId],
            );
            static::getContainer()->get('doctrine')->getConnection()->executeStatement(
                'UPDATE company SET overdue_reminders_enabled = 0, overdue_reminder_steps = NULL WHERE id = :id',
                ['id' => $this->companyId],
            );
        }
        parent::tearDown();
    }

    public function testStepsAreValidatedAndSorted(): void
    {
        $this->login();
        $this->companyId = $this->getFirstCompanyId();
        $uri = '/api/v1/companies/' . $this->companyId;

        $company = $this->apiGet($uri);
        self::assertFalse($company['overdueRemindersEnabled']);
        self::assertSame([3, 7, 14], array_column($company['overdueReminderSteps'], 'days'), 'the default steps');

        $saved = $this->apiPatch($uri, [
            'overdueRemindersEnabled' => true,
            'overdueReminderSteps' => [['days' => 10, 'subject' => 'A doua reamintire [[invoice_number]]'], ['days' => 2]],
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($saved));
        $company = $this->apiGet($uri);
        self::assertTrue($company['overdueRemindersEnabled']);
        self::assertSame([2, 10], array_column($company['overdueReminderSteps'], 'days'));
        self::assertSame('A doua reamintire [[invoice_number]]', $company['overdueReminderSteps'][1]['subject']);

        foreach ([
            [['days' => 0]],
            [['days' => 400]],
            [['days' => 3], ['days' => 3]],
            [['days' => 1], ['days' => 2], ['days' => 3], ['days' => 4], ['days' => 5], ['days' => 6]],
            [['subject' => 'fara zile']],
            [],
            'trei',
        ] as $invalid) {
            $this->apiPatch($uri, ['overdueReminderSteps' => $invalid]);
            self::assertSame(400, $this->client->getResponse()->getStatusCode(), json_encode($invalid));
        }

        $this->apiPatch($uri, ['overdueReminderSteps' => null]);
        self::assertSame([3, 7, 14], array_column($this->apiGet($uri)['overdueReminderSteps'], 'days'));
    }

    public function testDailyRunSendsEachStepOnceAndHonoursTheOptOuts(): void
    {
        $this->login();
        $this->companyId = $this->getFirstCompanyId();
        $headers = ['X-Company' => $this->companyId];

        $this->apiPatch('/api/v1/companies/' . $this->companyId, [
            'overdueRemindersEnabled' => true,
            'overdueReminderSteps' => [['days' => 3], ['days' => 7, 'subject' => 'Ultima reamintire [[invoice_number]]', 'body' => 'Rest de plata [[balance]] [[currency]], [[days_overdue]] zile.']],
        ]);

        $clientId = $this->newClient('Activ');
        $excludedClientId = $this->newClient('Exclus');
        $this->apiPatch('/api/v1/clients/' . $excludedClientId, ['overdueRemindersDisabled' => true], $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        self::assertTrue($this->apiGet('/api/v1/clients/' . $excludedClientId, $headers)['client']['overdueRemindersDisabled'] ?? $this->apiGet('/api/v1/clients/' . $excludedClientId, $headers)['overdueRemindersDisabled']);

        $onStep = $this->createIssuedInvoice($clientId, 3);
        $inGrace = $this->createIssuedInvoice($clientId, 5);
        $pastStep = $this->createIssuedInvoice($clientId, 6);
        $secondStep = $this->createIssuedInvoice($clientId, 7);
        $notDue = $this->createIssuedInvoice($clientId, 2);
        $optedOut = $this->createIssuedInvoice($clientId, 3);
        $paid = $this->createIssuedInvoice($clientId, 3);
        $partlyPaid = $this->createIssuedInvoice($clientId, 3);
        $ofExcludedClient = $this->createIssuedInvoice($excludedClientId, 3);

        $this->apiPost('/api/v1/invoices/' . $optedOut . '/overdue-reminders', ['enabled' => false], $headers);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->apiPost('/api/v1/invoices/' . $optedOut . '/overdue-reminders', ['enabled' => 'no'], $headers);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->apiPost('/api/v1/invoices/' . $paid . '/payments', ['amount' => '1000.00', 'paymentMethod' => 'bank_transfer'], $headers);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->apiPost('/api/v1/invoices/' . $partlyPaid . '/payments', ['amount' => '400.00', 'paymentMethod' => 'bank_transfer'], $headers);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        // a dry run sends nothing and marks nothing
        $output = $this->runCommand(['--dry-run' => true]);
        self::assertStringContainsString('Would send 4 reminder(s)', $output);
        self::assertSame([], $this->remindersOf($onStep));

        $output = $this->runCommand();
        self::assertStringContainsString('Sent 4 reminder(s)', $output, $output);

        self::assertCount(1, $this->remindersOf($onStep));
        self::assertCount(1, $this->remindersOf($inGrace));
        self::assertCount(1, $this->remindersOf($partlyPaid));
        self::assertSame([], $this->remindersOf($pastStep), 'its first step passed more than the grace days ago');
        self::assertSame([], $this->remindersOf($notDue));
        self::assertSame([], $this->remindersOf($optedOut));
        self::assertSame([], $this->remindersOf($paid));
        self::assertSame([], $this->remindersOf($ofExcludedClient));

        $second = $this->remindersOf($secondStep);
        self::assertCount(1, $second);
        self::assertStringStartsWith('Ultima reamintire ', $second[0]['subject']);
        self::assertSame('Reamintire la 7 zile', $second[0]['template_used']);
        self::assertSame('sent', $second[0]['status']);

        $invoice = $this->apiGet('/api/v1/invoices/' . $onStep, $headers);
        self::assertSame(3, $invoice['lastOverdueReminderDays']);
        self::assertNotNull($invoice['lastOverdueReminderAt']);
        self::assertFalse($invoice['overdueRemindersDisabled']);
        self::assertTrue($this->apiGet('/api/v1/invoices/' . $optedOut, $headers)['overdueRemindersDisabled']);

        // the reminder shows in the invoice's email history
        $history = $this->apiGet('/api/v1/invoices/' . $onStep . '/emails', $headers);
        self::assertSame('payment_reminder', ($history['data'] ?? $history)[0]['category'] ?? 'payment_reminder');

        // the next run finds every step already sent
        $output = $this->runCommand();
        self::assertStringContainsString('Sent 0 reminder(s)', $output, $output);
        self::assertCount(1, $this->remindersOf($onStep));

        // switched off: nothing goes out, whatever is overdue
        $this->apiPatch('/api/v1/companies/' . $this->companyId, ['overdueRemindersEnabled' => false]);
        $this->db()->executeStatement('UPDATE invoice SET last_overdue_reminder_days = NULL WHERE id = :id', ['id' => $onStep]);
        $output = $this->runCommand(['--company' => $this->companyId]);
        self::assertStringContainsString('0 companies', $output, $output);
        self::assertCount(1, $this->remindersOf($onStep));
    }

    public function testBacklogIsMailedOncePerClientAndOnlyOnRequest(): void
    {
        $this->login();
        $this->companyId = $this->getFirstCompanyId();
        $headers = ['X-Company' => $this->companyId];
        $uri = '/api/v1/companies/' . $this->companyId . '/overdue-reminders/backlog';

        $this->apiPost($uri, ['dryRun' => true]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'reminders are off');

        $this->apiPatch('/api/v1/companies/' . $this->companyId, ['overdueRemindersEnabled' => true, 'overdueReminderSteps' => null]);

        $clientId = $this->newClient('Restant');
        $excludedClientId = $this->newClient('RestantExclus');
        $this->apiPatch('/api/v1/clients/' . $excludedClientId, ['overdueRemindersDisabled' => true], $headers);

        $old = $this->createIssuedInvoice($clientId, 60);
        $older = $this->createIssuedInvoice($clientId, 115);
        $betweenSteps = $this->createIssuedInvoice($clientId, 10);
        $inGrace = $this->createIssuedInvoice($clientId, 4);
        $optedOut = $this->createIssuedInvoice($clientId, 40);
        $this->apiPost('/api/v1/invoices/' . $optedOut . '/overdue-reminders', ['enabled' => false], $headers);
        $ofExcludedClient = $this->createIssuedInvoice($excludedClientId, 40);

        // the daily run leaves all of them alone, except the one still inside its step
        $this->runCommand();
        self::assertCount(0, $this->remindersOf($old));
        self::assertCount(0, $this->remindersOf($betweenSteps));
        self::assertCount(1, $this->remindersOf($inGrace));

        $this->apiPost($uri, ['dryRun' => 'yes']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $preview = $this->apiPost($uri, ['dryRun' => true]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($preview));
        self::assertTrue($preview['dryRun']);
        $mine = $this->entryFor($preview, $clientId);
        self::assertSame('would_send', $mine['status']);
        self::assertSame(3, $mine['invoices'], 'three invoices passed a step; the opted-out and the in-grace one stay out');
        self::assertSame('3000.00', $mine['balance']);
        self::assertNull($this->entryFor($preview, $excludedClientId));
        self::assertGreaterThanOrEqual(2, ($preview['skipped']['invoice_excluded'] ?? 0) + ($preview['skipped']['client_excluded'] ?? 0));
        self::assertNull($this->lastReminderDays($old), 'a dry run marks nothing');
        self::assertSame(0, $this->statementsTo($clientId));

        $sent = $this->apiPost($uri, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($sent));
        self::assertFalse($sent['dryRun']);
        self::assertSame('sent', $this->entryFor($sent, $clientId)['status'], json_encode($sent));
        self::assertSame(1, $this->statementsTo($clientId), 'one email for the three invoices');
        self::assertSame(14, $this->lastReminderDays($old));
        self::assertSame(14, $this->lastReminderDays($older));
        self::assertSame(7, $this->lastReminderDays($betweenSteps), 'the 14-day step still comes from the daily run');
        self::assertSame(3, $this->lastReminderDays($inGrace));
        self::assertNull($this->lastReminderDays($optedOut));
        self::assertNull($this->lastReminderDays($ofExcludedClient));

        $again = $this->apiPost($uri, []);
        self::assertNull($this->entryFor($again, $clientId), 'a second request sends nothing to the same client');
        self::assertSame(1, $this->statementsTo($clientId));
    }

    /** @return array<string, mixed>|null */
    private function entryFor(array $response, string $clientId): ?array
    {
        foreach ($response['results'] as $entry) {
            if ($entry['clientId'] === $clientId) {
                return $entry;
            }
        }

        return null;
    }

    private function lastReminderDays(string $invoiceId): ?int
    {
        $value = $this->db()->fetchOne('SELECT last_overdue_reminder_days FROM invoice WHERE id = :id', ['id' => $invoiceId]);

        return $value === null ? null : (int) $value;
    }

    private function statementsTo(string $clientId): int
    {
        return (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM email_log l INNER JOIN client c ON c.email = l.to_email WHERE c.id = :id AND l.category = 'statement' AND l.status = 'sent'",
            ['id' => $clientId],
        );
    }

    private function newClient(string $label): string
    {
        $suffix = $label . '-' . uniqid();
        $created = $this->apiPost('/api/v1/clients', [
            'type' => 'individual',
            'name' => 'Client Reamintire ' . $suffix,
            'email' => 'reamintire-' . strtolower($suffix) . '@example.com',
            'country' => 'RO',
            'county' => 'Bucuresti',
            'city' => 'Bucuresti',
            'address' => 'Str. Test 1',
        ], ['X-Company' => $this->companyId]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($created));

        return $created['id'] ?? $created['client']['id'];
    }

    private function createIssuedInvoice(string $clientId, int $daysOverdue): string
    {
        $today = new \DateTimeImmutable('today');
        $inv = $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => $today->modify(sprintf('-%d days', $daysOverdue + 10))->format('Y-m-d'),
            'dueDate' => $today->modify(sprintf('-%d days', $daysOverdue))->format('Y-m-d'),
            'currency' => 'RON',
            'clientId' => $clientId,
            'lines' => [
                ['description' => 'Abonament', 'quantity' => '1.00', 'unitOfMeasure' => 'buc', 'unitPrice' => '1000.00', 'vatRate' => '0.00', 'vatCategoryCode' => 'Z'],
            ],
        ], ['X-Company' => $this->companyId]);
        $invoiceId = $inv['invoice']['id'] ?? $inv['id'] ?? null;
        self::assertNotNull($invoiceId, json_encode($inv));
        $this->apiPost('/api/v1/invoices/' . $invoiceId . '/issue', [], ['X-Company' => $this->companyId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'issue: ' . $this->client->getResponse()->getContent());

        return $invoiceId;
    }

    /** @param array<string, mixed> $input */
    private function runCommand(array $input = []): string
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:invoice:send-overdue-reminders'));
        $tester->execute($input + ['--company' => $this->companyId]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return $tester->getDisplay();
    }

    /** @return array<int, array<string, mixed>> */
    private function remindersOf(string $invoiceId): array
    {
        return $this->db()->fetchAllAssociative(
            "SELECT subject, status, template_used, to_email FROM email_log WHERE invoice_id = :id AND category = 'payment_reminder'",
            ['id' => $invoiceId],
        );
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }
}
