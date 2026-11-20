<?php

namespace App\Tests\Unit;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\Product;
use App\EventListener\RealtimeChangeListener;
use App\Service\Centrifugo\CentrifugoService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RealtimeChangeListenerTest extends TestCase
{
    public function testOneEventPerCompanyAndKind(): void
    {
        $company = new Company();
        $other = new Company();
        $invoice = (new Invoice())->setCompany($company);
        $payment = (new Payment())->setCompany($company)->setInvoice($invoice);
        $client = (new Client())->setCompany($company);
        $foreignInvoice = (new Invoice())->setCompany($other);

        $queued = $this->run_([$invoice, $payment], [$client, new Product()], [$foreignInvoice]);

        $channel = 'invoices:company_' . $company->getId()->toRfc4122();
        self::assertSame([
            [$channel, ['type' => 'invoice.changed']],
            [$channel, ['type' => 'client.changed']],
            ['invoices:company_' . $other->getId()->toRfc4122(), ['type' => 'invoice.changed']],
        ], $queued);
    }

    public function testADocumentWithoutCompanyAndAFailingServerNeverBreakTheWrite(): void
    {
        self::assertSame([], $this->run_([new Invoice()], [], []));

        $centrifugo = $this->createMock(CentrifugoService::class);
        $centrifugo->method('queueOnce')->willThrowException(new \RuntimeException('down'));
        $listener = new RealtimeChangeListener($centrifugo, new NullLogger());
        $listener->onFlush($this->args([(new Invoice())->setCompany(new Company())], [], []));
        $this->addToAssertionCount(1); // no exception reached the flush
    }

    /** @return list<array{0: string, 1: array<string, string>}> */
    private function run_(array $inserts, array $updates, array $deletes): array
    {
        $queued = [];
        $centrifugo = $this->createMock(CentrifugoService::class);
        $centrifugo->method('queueOnce')->willReturnCallback(function (string $channel, array $data) use (&$queued): void {
            $queued[] = [$channel, $data];
        });

        (new RealtimeChangeListener($centrifugo, new NullLogger()))->onFlush($this->args($inserts, $updates, $deletes));

        return $queued;
    }

    private function args(array $inserts, array $updates, array $deletes): OnFlushEventArgs
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($inserts);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates);
        $uow->method('getScheduledEntityDeletions')->willReturn($deletes);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return new OnFlushEventArgs($em);
    }
}
