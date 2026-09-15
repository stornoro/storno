<?php

namespace App\Tests\Unit;

use App\Command\Notification\ExpiryReminderCommand;
use App\Entity\Company;
use App\Entity\ExpiryItem;
use App\Entity\Vehicle;
use App\Repository\ExpiryItemRepository;
use App\Service\Fleet\ExpiryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ExpiryServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private ExpiryItemRepository $repository;
    private ExpiryService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(ExpiryItemRepository::class);
        $this->service = new ExpiryService($this->em, $this->repository);
    }

    private function item(string $kind, string $expiresAt, int $remind = 30): ExpiryItem
    {
        return (new ExpiryItem())->setCompany(new Company())->setKind($kind)->setLabel(ExpiryService::defaultLabel($kind))->setExpiresAt(new \DateTimeImmutable($expiresAt))->setRemindDaysBefore($remind);
    }

    public function testDaysLeftAndStatus(): void
    {
        $today = new \DateTimeImmutable('2026-09-15');
        $this->assertSame(45, $this->item('rca', '2026-10-30')->daysLeftOn($today));
        $this->assertSame(ExpiryItem::STATUS_OK, $this->item('rca', '2026-10-30')->statusOn($today));
        $this->assertSame(ExpiryItem::STATUS_DUE, $this->item('rca', '2026-10-15')->statusOn($today), '30 days ahead is inside the reminder window');
        $this->assertSame(ExpiryItem::STATUS_DUE, $this->item('rca', '2026-09-15')->statusOn($today), 'expires today: still due, not expired');
        $this->assertSame(-1, $this->item('itp', '2026-09-14')->daysLeftOn($today));
        $this->assertSame(ExpiryItem::STATUS_EXPIRED, $this->item('itp', '2026-09-14')->statusOn($today));
        $this->assertSame(ExpiryItem::STATUS_OK, $this->item('itp', '2026-10-15', 7)->statusOn($today), 'a shorter reminder window keeps it ok');
        $closed = $this->item('rca', '2026-09-01')->setClosedAt(new \DateTimeImmutable());
        $this->assertSame(ExpiryItem::STATUS_RENEWED, $closed->statusOn($today));
    }

    public function testChangingTheDateRestartsTheReminders(): void
    {
        $item = $this->item('rca', '2026-10-01')->markNotified(30)->markNotified(7);
        $this->assertSame([30, 7], $item->getNotified());
        $item->setExpiresAt(new \DateTimeImmutable('2026-10-01'));
        $this->assertSame([30, 7], $item->getNotified(), 'same date keeps the history');
        $item->setExpiresAt(new \DateTimeImmutable('2027-10-01'));
        $this->assertSame([], $item->getNotified());
    }

    public function testReminderThresholds(): void
    {
        $item = $this->item('rca', '2026-10-15');
        $this->assertNull(ExpiryReminderCommand::thresholdFor($item, 45), 'outside the window');
        $this->assertSame(30, ExpiryReminderCommand::thresholdFor($item, 30));
        $this->assertSame(30, ExpiryReminderCommand::thresholdFor($item, 20), 'created late: the 30-day reminder still goes out once');
        $item->markNotified(30);
        $this->assertNull(ExpiryReminderCommand::thresholdFor($item, 19), 'no daily nagging between thresholds');
        $this->assertSame(7, ExpiryReminderCommand::thresholdFor($item, 7));
        $this->assertSame(7, ExpiryReminderCommand::thresholdFor($item, 5));
        $item->markNotified(7);
        $this->assertNull(ExpiryReminderCommand::thresholdFor($item, 4));
        $this->assertSame(1, ExpiryReminderCommand::thresholdFor($item, 1));
        $item->markNotified(1);
        $this->assertSame(0, ExpiryReminderCommand::thresholdFor($item, 0));
        $item->markNotified(0);
        $this->assertNull(ExpiryReminderCommand::thresholdFor($item, 0));
        $this->assertNull(ExpiryReminderCommand::thresholdFor($item, -3), 'expired items are not reminded');

        $short = $this->item('rovinieta', '2026-10-15', 3);
        $this->assertNull(ExpiryReminderCommand::thresholdFor($short, 7), 'a 3-day window ignores the 7-day threshold');
        $this->assertSame(3, ExpiryReminderCommand::thresholdFor($short, 3));
        $short->markNotified(3);
        $this->assertSame(1, ExpiryReminderCommand::thresholdFor($short, 1));
    }

    public function testRenewCreatesTheNextItemAndClosesTheOld(): void
    {
        $vehicle = (new Vehicle())->setPlate('B 01 TST')->setMake('Dacia')->setModel('Logan');
        $old = $this->item('rca', '2026-10-01')->setVehicle($vehicle)->setProvider('Asigurator SA')->setNumber('POL-1')->setRemindDaysBefore(14)->markNotified(30);
        $persisted = null;
        $this->em->expects($this->once())->method('persist')->willReturnCallback(function ($e) use (&$persisted) { $persisted = $e; });
        $this->em->expects($this->once())->method('flush');

        $today = new \DateTimeImmutable('2026-09-15');
        $next = $this->service->renew($old, ['number' => 'POL-2'], $today);

        $this->assertSame($next, $persisted);
        $this->assertTrue($old->isClosed());
        $this->assertSame($old, $next->getRenewedFrom());
        $this->assertSame('rca', $next->getKind());
        $this->assertSame('RCA', $next->getLabel());
        $this->assertSame($vehicle, $next->getVehicle());
        $this->assertSame('Asigurator SA', $next->getProvider(), 'the provider carries over');
        $this->assertSame('POL-2', $next->getNumber());
        $this->assertSame(14, $next->getRemindDaysBefore());
        $this->assertSame([], $next->getNotified());
        $this->assertSame('2027-10-01', $next->getExpiresAt()->format('Y-m-d'), 'RCA: 12 months after the old expiry');
        $this->assertSame('2026-10-01', $next->getValidFrom()?->format('Y-m-d'), 'valid from the old expiry, not from today');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->renew($old, [], $today);
    }

    public function testRenewOfAnExpiredItemStartsFromToday(): void
    {
        $old = $this->item('itp', '2026-08-01');
        $today = new \DateTimeImmutable('2026-09-15');
        $next = $this->service->renew($old, ['expiresAt' => '2028-09-15'], $today);
        $this->assertSame('2028-09-15', $next->getExpiresAt()->format('Y-m-d'));
        $this->assertSame('2026-09-15', $next->getValidFrom()?->format('Y-m-d'));
        $this->assertSame('2028-09-15', ExpiryService::proposedNextExpiry($old, $today)->format('Y-m-d'), 'ITP: 24 months from today when already expired');
    }

    public function testUpcomingRowsAreFlatWithStatus(): void
    {
        $company = new Company();
        $today = new \DateTimeImmutable('2026-09-15');
        $expired = $this->item('itp', '2026-09-10');
        $due = $this->item('rca', '2026-09-20');
        $this->repository->method('findUpcoming')->willReturn([$expired, $due]);

        $rows = $this->service->upcoming($company, 60, $today);
        $this->assertCount(2, $rows);
        $this->assertSame('expired', $rows[0]['status']);
        $this->assertSame(-5, $rows[0]['daysLeft']);
        $this->assertSame('due', $rows[1]['status']);
        $this->assertSame('2026-09-20', $rows[1]['expiresAt']);
        $this->assertSame(['total' => 2, 'expired' => 1, 'due' => 1, 'ok' => 0], ExpiryService::counts($rows));
    }

    public function testCreateValidates(): void
    {
        $company = new Company();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($company, ['kind' => 'rca']);
    }

    public function testCreateRejectsUnknownKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create(new Company(), ['kind' => 'permis', 'expiresAt' => '2027-01-01']);
    }

    public function testCreateUsesTheDefaultLabel(): void
    {
        $item = $this->service->create(new Company(), ['kind' => 'trusa_medicala', 'expiresAt' => '2027-01-01', 'validFrom' => '2026-01-01']);
        $this->assertSame('Trusă medicală', $item->getLabel());
        $this->assertSame(30, $item->getRemindDaysBefore());
    }
}
