<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Command\Notification\ExpiryReminderCommand;
use App\Entity\Notification;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Parc auto: vehicles with their expiry items, the upcoming list, renewal (history kept),
 * company-level items without a vehicle, and the daily reminder command run on a fixed date.
 */
class FleetTest extends ApiTestCase
{
    /** API tests share one database without rollback: start from an empty fleet. */
    private function deleteAll(array $h): void
    {
        foreach ($this->apiGet('/api/v1/vehicles', $h)['data'] ?? [] as $v) {
            $this->apiDelete('/api/v1/vehicles/' . $v['id'], $h);
        }
        foreach ($this->apiGet('/api/v1/expiries?includeClosed=1', $h)['data'] ?? [] as $e) {
            $this->apiDelete('/api/v1/expiries/' . $e['id'], $h);
        }
    }

    public function testVehiclesExpiriesUpcomingAndRenew(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];
        $this->deleteAll($h);

        $created = $this->apiPost('/api/v1/vehicles', ['plate' => 'b 01 tst', 'make' => 'Dacia', 'model' => 'Logan', 'year' => 2021, 'fuel' => 'motorina', 'ownership' => 'leasing', 'driverName' => 'Popescu Ion'], $h);
        $this->assertResponseStatusCodeSame(201);
        $vehicle = $created['vehicle'];
        $this->assertSame('B 01 TST', $vehicle['plate'], 'plates are normalised to upper case');
        $this->assertSame('B 01 TST · Dacia Logan', $vehicle['displayName']);
        $this->assertNull($created['nextExpiry']);

        $this->apiPost('/api/v1/vehicles', ['plate' => 'B 02 TST', 'ownership' => 'sold'], $h);
        $this->assertResponseStatusCodeSame(422);
        $this->apiPost('/api/v1/vehicles', ['make' => 'Ford'], $h);
        $this->assertResponseStatusCodeSame(422, 'the plate is required');

        $in20 = (new \DateTimeImmutable('today'))->modify('+20 days')->format('Y-m-d');
        $in100 = (new \DateTimeImmutable('today'))->modify('+100 days')->format('Y-m-d');
        $past = (new \DateTimeImmutable('today'))->modify('-3 days')->format('Y-m-d');

        $rca = $this->apiPost('/api/v1/vehicles/' . $vehicle['id'] . '/expiries', ['kind' => 'rca', 'expiresAt' => $in20, 'number' => 'POL-1', 'provider' => 'Asigurator SA'], $h);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('RCA', $rca['label'], 'default label from the kind');
        $this->assertSame(20, $rca['daysLeft']);
        $this->assertSame('due', $rca['status']);
        $this->assertSame($vehicle['id'], $rca['vehicleId']);
        $itp = $this->apiPost('/api/v1/expiries', ['kind' => 'itp', 'vehicleId' => $vehicle['id'], 'expiresAt' => $past], $h);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('expired', $itp['status']);
        $rov = $this->apiPost('/api/v1/expiries', ['kind' => 'rovinieta', 'vehicleId' => $vehicle['id'], 'expiresAt' => $in100, 'remindDaysBefore' => 14], $h);
        $this->assertSame('ok', $rov['status']);
        $this->apiPost('/api/v1/expiries', ['kind' => 'rca', 'vehicleId' => $vehicle['id']], $h);
        $this->assertResponseStatusCodeSame(422, 'expiresAt is required');
        $this->apiPost('/api/v1/expiries', ['kind' => 'rca', 'expiresAt' => $in20, 'vehicleId' => '00000000-0000-0000-0000-000000000000'], $h);
        $this->assertResponseStatusCodeSame(422);

        // company-level item without a vehicle
        $cert = $this->apiPost('/api/v1/expiries', ['kind' => 'certificat_digital', 'label' => 'Certificat semnatura', 'expiresAt' => $in20, 'provider' => 'Furnizor certificat'], $h);
        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($cert['vehicleId']);

        // vehicle list carries the next expiry (the expired ITP first) and the counts
        $list = $this->apiGet('/api/v1/vehicles', $h);
        $this->assertSame(1, $list['total']);
        $ex = $list['expiries'][$vehicle['id']];
        $this->assertSame('itp', $ex['nextExpiry']['kind']);
        $this->assertSame(['expired' => 1, 'due' => 1, 'ok' => 1], $ex['counts']);

        $detail = $this->apiGet('/api/v1/vehicles/' . $vehicle['id'], $h);
        $this->assertCount(3, $detail['expiries']);
        $vex = $this->apiGet('/api/v1/vehicles/' . $vehicle['id'] . '/expiries', $h);
        $this->assertSame(3, $vex['total']);

        // all items, expired first; filters
        $all = $this->apiGet('/api/v1/expiries', $h);
        $this->assertSame(4, $all['total']);
        $this->assertSame('itp', $all['data'][0]['kind']);
        $this->assertSame(1, $this->apiGet('/api/v1/expiries?kind=certificat_digital', $h)['total']);
        $this->assertSame(3, $this->apiGet('/api/v1/expiries?vehicleId=' . $vehicle['id'], $h)['total']);
        $this->assertSame(1, $this->apiGet('/api/v1/expiries?companyLevel=1', $h)['total']);

        // upcoming: within 60 days (expired included), the rovinietă at 100 days is out
        $up = $this->apiGet('/api/v1/expiries/upcoming?days=60', $h);
        $this->assertSame(['itp', 'certificat_digital', 'rca'], array_column($up['data'], 'kind'), 'expired first, then by date and label');
        $this->assertSame(['total' => 3, 'expired' => 1, 'due' => 2, 'ok' => 0], $up['counts']);
        $this->assertSame(4, $this->apiGet('/api/v1/expiries/upcoming?days=120', $h)['counts']['total']);

        // update
        $upd = $this->apiPatch('/api/v1/expiries/' . $rov['id'], ['number' => 'ROV-2026', 'notes' => 'platita online'], $h);
        $this->assertSame('ROV-2026', $upd['item']['number']);
        $this->apiPatch('/api/v1/expiries/' . $rov['id'], ['kind' => 'permis'], $h);
        $this->assertResponseStatusCodeSame(422);

        // renew: the next RCA a year after the old expiry, the old one closed and in the history
        $renewed = $this->apiPost('/api/v1/expiries/' . $rca['id'] . '/renew', ['number' => 'POL-2'], $h);
        $this->assertResponseStatusCodeSame(201);
        $next = $renewed['item'];
        $this->assertSame('POL-2', $next['number']);
        $this->assertSame('Asigurator SA', $next['provider']);
        $this->assertSame((new \DateTimeImmutable($in20))->modify('+12 months')->format('Y-m-d'), $next['expiresAt']);
        $this->assertSame($rca['id'], $next['renewedFromId']);
        $this->assertSame('renewed', $renewed['previous']['status']);
        $this->assertNotNull($renewed['previous']['closedAt']);
        $show = $this->apiGet('/api/v1/expiries/' . $next['id'], $h);
        $this->assertSame([$rca['id']], array_column($show['history'], 'id'));
        $this->apiPost('/api/v1/expiries/' . $rca['id'] . '/renew', [], $h);
        $this->assertResponseStatusCodeSame(422, 'a closed item cannot be renewed twice');

        // the closed one leaves the open lists but stays in the history listing
        $this->assertSame(4, $this->apiGet('/api/v1/expiries', $h)['total']);
        $this->assertSame(5, $this->apiGet('/api/v1/expiries?includeClosed=1', $h)['total']);
        $this->assertSame(['itp', 'certificat_digital'], array_column($this->apiGet('/api/v1/expiries/upcoming?days=60', $h)['data'], 'kind'));

        // vehicle update + delete cascades to its items
        $vu = $this->apiPatch('/api/v1/vehicles/' . $vehicle['id'], ['active' => false, 'driverName' => null], $h);
        $this->assertFalse($vu['vehicle']['active']);
        $this->assertNull($vu['vehicle']['driverName']);
        $this->assertSame(0, $this->apiGet('/api/v1/vehicles?active=1', $h)['total']);
        $this->apiDelete('/api/v1/vehicles/' . $vehicle['id'], $h);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(1, $this->apiGet('/api/v1/expiries?includeClosed=1', $h)['total'], 'only the certificate is left');
        $this->apiGet('/api/v1/expiries/' . $next['id'], $h);
        $this->assertResponseStatusCodeSame(404);

        $kinds = $this->apiGet('/api/v1/expiries/kinds', $h);
        $this->assertContains('rca', array_column($kinds['data'], 'kind'));
        $this->apiDelete('/api/v1/expiries/' . $cert['id'], $h);
    }

    public function testReminderCommandNotifiesOncePerThreshold(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];
        $this->deleteAll($h);

        $vehicle = $this->apiPost('/api/v1/vehicles', ['plate' => 'B 09 RMD', 'make' => 'Ford', 'model' => 'Transit'], $h)['vehicle'];
        $rca = $this->apiPost('/api/v1/vehicles/' . $vehicle['id'] . '/expiries', ['kind' => 'rca', 'expiresAt' => '2026-10-15'], $h);
        $far = $this->apiPost('/api/v1/expiries', ['kind' => 'autorizatie', 'label' => 'Autorizatie mediu', 'expiresAt' => '2027-06-30'], $h);
        $this->assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get('doctrine')->getManager();
        $before = $em->getRepository(Notification::class)->count(['type' => ExpiryReminderCommand::TYPE]);

        $run = function (string $date): string {
            $application = new Application(static::$kernel);
            $tester = new CommandTester($application->find('app:notifications:expiries'));
            $tester->execute(['--date' => $date]);
            $this->assertSame(0, $tester->getStatusCode());

            return $tester->getDisplay();
        };

        $this->assertStringContainsString('Sent 0 expiry reminders', $run('2026-08-15'), '61 days ahead: nothing yet');
        $out = $run('2026-09-20');
        $this->assertMatchesRegularExpression('/Sent [1-9]\d* expiry reminders/', $out, '25 days ahead: the 30-day reminder goes out');
        $this->assertStringContainsString('Sent 0 expiry reminders', $run('2026-09-21'), 'not repeated the next day');
        $this->assertMatchesRegularExpression('/Sent [1-9]\d* expiry reminders/', $run('2026-10-10'), '5 days ahead: the 7-day reminder');
        $this->assertMatchesRegularExpression('/Sent [1-9]\d* expiry reminders/', $run('2026-10-14'), 'the day before');
        $this->assertMatchesRegularExpression('/Sent [1-9]\d* expiry reminders/', $run('2026-10-15'), 'the day itself');
        $this->assertStringContainsString('Sent 0 expiry reminders', $run('2026-10-20'), 'expired items are not nagged');

        $em->clear();
        $notifications = $em->getRepository(Notification::class)->findBy(['type' => ExpiryReminderCommand::TYPE], ['sentAt' => 'DESC']);
        $this->assertGreaterThanOrEqual($before + 4, count($notifications));
        $mine = array_values(array_filter($notifications, fn (Notification $n) => ($n->getData()['expiryId'] ?? null) === $rca['id']));
        $this->assertNotEmpty($mine);
        $this->assertSame($vehicle['id'], $mine[0]->getData()['vehicleId']);
        $this->assertSame('/vehicles/' . $vehicle['id'], $mine[0]->getData()['url']);
        $thresholds = array_values(array_unique(array_map(fn (Notification $n) => $n->getData()['threshold'], $mine)));
        sort($thresholds);
        $this->assertSame([0, 1, 7, 30], $thresholds, 'each threshold exactly once');
        $this->assertStringContainsString('B 09 RMD', $mine[0]->getTitle());

        $stored = $this->apiGet('/api/v1/expiries/' . $rca['id'], $h);
        $this->assertSame('B 09 RMD · Ford Transit', $stored['item']['vehicle']['displayName']);

        $this->apiDelete('/api/v1/vehicles/' . $vehicle['id'], $h);
        $this->apiDelete('/api/v1/expiries/' . $far['id'], $h);
    }
    public function testASecondVehicleWithTheSamePlateIsRefused(): void
    {
        $this->login();
        $h = ['X-Company' => $this->getFirstCompanyId()];
        $this->apiPost('/api/v1/vehicles', ['plate' => 'B-77-DUP', 'make' => 'Dacia'], $h);
        $this->assertResponseStatusCodeSame(201);

        $again = $this->apiPost('/api/v1/vehicles', ['plate' => 'b-77-dup', 'make' => 'Dacia'], $h);
        $this->assertResponseStatusCodeSame(422, json_encode($again));
        $this->assertSame('VALIDATION_FAILED', $again['code']);
        $this->assertStringContainsString('B-77-DUP', $again['error']);
    }
}
