<?php

namespace App\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('default')]
class AppScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            // TEST — every minute (remove after testing)
            ->add(RecurringMessage::cron('* * * * *', new RunCommandMessage('app:license:sync')))
            // e-Factura — every 5 minutes
            ->add(RecurringMessage::cron('*/5 * * * *', new RunCommandMessage('app:efactura:check-status')))
            ->add(RecurringMessage::cron('*/5 * * * *', new RunCommandMessage('app:efactura:submit-scheduled')))
            ->add(RecurringMessage::cron('*/30 * * * *', new RunCommandMessage('app:efactura:sync')))
            // ANAF token refresh — every hour
            ->add(RecurringMessage::cron('0 * * * *', new RunCommandMessage('app:anaf:refresh-tokens')))
            // License sync — every 6 hours
            ->add(RecurringMessage::cron('0 */6 * * *', new RunCommandMessage('app:license:sync')))
            // Recurring invoices — daily 1 AM
            ->add(RecurringMessage::cron('0 1 * * *', new RunCommandMessage('app:invoice:process-recurring')))
            // Notifications — daily
            ->add(RecurringMessage::cron('0 8 * * *', new RunCommandMessage('app:notifications:token-expiry')))
            ->add(RecurringMessage::cron('0 9 * * *', new RunCommandMessage('app:notifications:due-invoices')))
            ->add(RecurringMessage::cron('0 10 * * *', new RunCommandMessage('app:proforma:process-expiry')))
            // Weekly cleanup — Sunday
            ->add(RecurringMessage::cron('0 2 * * 0', new RunCommandMessage('app:user:clear-unconfirmed')))
            ->add(RecurringMessage::cron('0 3 * * 0', new RunCommandMessage('app:archive:cleanup')));
    }
}
