<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Repository\EmailUnsubscribeRepository;
use App\Service\LifecycleEmailGate;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LifecycleEmailGateTest extends TestCase
{
    private function makeGate(): LifecycleEmailGate
    {
        $unsubscribes = $this->createMock(EmailUnsubscribeRepository::class);
        $unsubscribes->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        return new LifecycleEmailGate($unsubscribes, $em);
    }

    public function testUnverifiedUsersNeverReceiveLifecycleEmails(): void
    {
        $user = (new User())
            ->setEmail('nobody@example.com')
            ->setActive(true)
            ->setEmailVerified(false);

        self::assertFalse($this->makeGate()->canSend('nobody@example.com', 'trial_reminder', $user));
    }

    public function testAnonymousRecipientsAreOnlyGatedByUnsubscribes(): void
    {
        self::assertTrue($this->makeGate()->canSend('nobody@example.com', 'trial_reminder'));
    }
}
