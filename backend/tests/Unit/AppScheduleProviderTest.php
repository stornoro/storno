<?php

namespace App\Tests\Unit;

use App\Scheduler\AppScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageContext;

class AppScheduleProviderTest extends TestCase
{
    public function testScheduleIsLockedAndFansOutTheEFacturaSync(): void
    {
        $schedule = (new AppScheduleProvider(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();

        self::assertNotNull($schedule->getLock(), 'a second scheduler replica must wait on the shared lock');

        $inputs = [];
        $now = new \DateTimeImmutable();
        foreach ($schedule->getRecurringMessages() as $recurring) {
            $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), $now);
            foreach ($recurring->getMessages($context) as $message) {
                $inputs[] = $message->input;
            }
        }
        $all = implode("\n", $inputs);
        self::assertStringContainsString('app:efactura:sync --async', $all);
        self::assertStringNotContainsString('app:logs:purge', $all, 'the log purge deletes rows for good and is run by hand until scheduled on purpose');
        self::assertStringContainsString('app:oauth2:cleanup', $all);
    }
}
