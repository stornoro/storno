<?php

namespace App\Tests\Unit;

use App\Controller\Api\V1\TelegramController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Telegram webhook is public; only calls carrying the secret Telegram was
 * configured with may touch account linking.
 */
class TelegramWebhookAuthTest extends TestCase
{
    private function makeController(?string $secret, string $env): TelegramController
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $controller = new TelegramController($em, new NullLogger(), $env, 'storno_bot', $secret);
        $controller->setContainer(new Container());

        return $controller;
    }

    private function call(TelegramController $controller, ?string $header): int
    {
        $request = Request::create('/api/v1/telegram/webhook', 'POST', [], [], [], [], '{}');
        if ($header !== null) {
            $request->headers->set('X-Telegram-Bot-Api-Secret-Token', $header);
        }

        return $controller->webhook($request)->getStatusCode();
    }

    public function testMissingOrWrongSecretHeaderIsRejected(): void
    {
        $controller = $this->makeController('s3cret', 'prod');

        self::assertSame(403, $this->call($controller, null));
        self::assertSame(403, $this->call($controller, 'wrong'));
        self::assertSame(403, $this->call($controller, ''));
    }

    public function testCorrectSecretIsAccepted(): void
    {
        self::assertSame(200, $this->call($this->makeController('s3cret', 'prod'), 's3cret'));
    }

    public function testUnconfiguredSecretRejectsEverythingInProdButNotInDev(): void
    {
        self::assertSame(403, $this->call($this->makeController(null, 'prod'), 'anything'));
        self::assertSame(403, $this->call($this->makeController('', 'prod'), null));
        self::assertSame(200, $this->call($this->makeController(null, 'dev'), null));
    }
}
