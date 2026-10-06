<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Calendar subscription: turning it on, the public iCalendar body behind the secret link (vehicle
 * expiries with alarms, fiscal deadlines), options, regenerating the link and turning it off.
 */
class CalendarFeedTest extends ApiTestCase
{
    /** The public link is fetched without the session, as a calendar app would. */
    private function fetchFeed(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->client->request('GET', $path);

        return (string) $this->client->getResponse()->getContent();
    }

    public function testSubscriptionLifecycleAndFeedContents(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $h = ['X-Company' => $companyId];
        $this->apiDelete('/api/v1/calendar-feed');

        $off = $this->apiGet('/api/v1/calendar-feed');
        $this->assertResponseIsSuccessful();
        $this->assertFalse($off['enabled']);
        $this->assertTrue($off['canSeeExpiries']);

        $vehicle = $this->apiPost('/api/v1/vehicles', ['plate' => 'B 77 CAL', 'make' => 'Dacia', 'model' => 'Duster'], $h)['vehicle']
            ?? null;
        if ($vehicle === null) { // left over from an earlier run (plates are unique per company)
            foreach ($this->apiGet('/api/v1/vehicles', $h)['data'] as $v) {
                if ($v['plate'] === 'B 77 CAL') {
                    $vehicle = $v;
                }
            }
        }
        $this->assertNotNull($vehicle);
        $expiresAt = (new \DateTimeImmutable('today'))->modify('+40 days');
        $rca = $this->apiPost('/api/v1/vehicles/' . $vehicle['id'] . '/expiries', ['kind' => 'rca', 'expiresAt' => $expiresAt->format('Y-m-d'), 'number' => 'POL-CAL', 'remindDaysBefore' => 14], $h);
        $this->assertResponseStatusCodeSame(201);

        $feed = $this->apiPost('/api/v1/calendar-feed');
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($feed['enabled']);
        $this->assertMatchesRegularExpression('#/api/v1/calendar/feed/[0-9a-f-]{36}/[0-9a-f]{40}\.ics$#', $feed['url']);
        $this->assertStringStartsWith('webcal://', $feed['webcalUrl']);
        $this->assertStringContainsString(rawurlencode($feed['webcalUrl']), $feed['googleCalendarUrl']);
        $this->assertSame($feed['url'], $this->apiGet('/api/v1/calendar-feed')['url'], 'the same link can be shown again');
        $this->apiPost('/api/v1/calendar-feed');
        $this->assertResponseStatusCodeSame(200, 'turning it on again keeps the link');

        $token = $this->token;
        $this->token = null;
        $body = $this->fetchFeed($feed['url']);
        $this->assertResponseIsSuccessful();
        $this->assertStringStartsWith('text/calendar', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        $unfolded = str_replace("\r\n ", '', $body);
        $this->assertStringContainsString('UID:expiry-' . $rca['id'] . '@storno.ro', $unfolded);
        $this->assertStringContainsString('SUMMARY:Expiră RCA · B 77 CAL · Dacia Duster', $unfolded);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . $expiresAt->format('Ymd'), $unfolded);
        $this->assertStringContainsString('TRIGGER:-P13DT15H', $unfolded, 'alarm at the reminder threshold of the item');
        $this->assertStringContainsString('Număr: POL-CAL', $unfolded);

        // a forged signature and an unknown feed both look like nothing is there
        $this->fetchFeed(preg_replace('#/[0-9a-f]{40}\.ics$#', '/' . str_repeat('a', 40) . '.ics', $feed['url']));
        $this->assertResponseStatusCodeSame(404);
        $this->fetchFeed(preg_replace('#/feed/[0-9a-f-]{36}/#', '/feed/00000000-0000-0000-0000-000000000000/', $feed['url']));
        $this->assertResponseStatusCodeSame(404);

        // options: without expiries the RCA leaves the feed
        $this->token = $token;
        $patched = $this->apiPatch('/api/v1/calendar-feed', ['includeExpiries' => false]);
        $this->assertFalse($patched['includeExpiries']);
        $this->token = null;
        $this->assertStringNotContainsString('expiry-' . $rca['id'], $this->fetchFeed($feed['url']));
        $this->token = $token;
        $this->apiPatch('/api/v1/calendar-feed', ['includeExpiries' => true]);

        // a renewed item moves to its new date: the old UID disappears
        $renewed = $this->apiPost('/api/v1/expiries/' . $rca['id'] . '/renew', [], $h);
        $this->assertResponseIsSuccessful();
        $this->token = null;
        $afterRenew = str_replace("\r\n ", '', $this->fetchFeed($feed['url']));
        $this->assertStringNotContainsString('expiry-' . $rca['id'], $afterRenew);
        $this->assertStringContainsString('expiry-' . $renewed['item']['id'], $afterRenew);

        // regenerating invalidates the old link
        $this->token = $token;
        $regenerated = $this->apiPost('/api/v1/calendar-feed', ['regenerate' => true]);
        $this->assertNotSame($feed['url'], $regenerated['url']);
        $this->token = null;
        $this->fetchFeed($feed['url']);
        $this->assertResponseStatusCodeSame(404);
        $this->fetchFeed($regenerated['url']);
        $this->assertResponseIsSuccessful();

        // turning it off stops every link
        $this->token = $token;
        $this->assertFalse($this->apiDelete('/api/v1/calendar-feed')['enabled']);
        $this->token = null;
        $this->fetchFeed($regenerated['url']);
        $this->assertResponseStatusCodeSame(404);

        $this->token = $token;
        $this->apiDelete('/api/v1/vehicles/' . $vehicle['id'], $h);
    }

    public function testRequiresASession(): void
    {
        $this->apiGet('/api/v1/calendar-feed');
        $this->assertResponseStatusCodeSame(401);
    }
}
