<?php

namespace App\Tests\Unit;

use App\Controller\Webhook\SesWebhookController;
use App\Service\SesEventProcessor;
use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The SES/SNS webhook is unauthenticated: every payload must carry a valid SNS
 * signature, match the configured topic, and only ever trigger fetches to SNS itself.
 */
class SesWebhookControllerTest extends TestCase
{
    private const TOPIC = 'arn:aws:sns:eu-central-1:123456789012:ses-events';

    private function snsPayload(array $overrides = []): array
    {
        return array_merge([
            'Type' => 'Notification',
            'MessageId' => 'abc',
            'TopicArn' => self::TOPIC,
            'Message' => '{}',
            'Timestamp' => '2026-09-04T00:00:00.000Z',
            'SignatureVersion' => '1',
            'Signature' => 'sig',
            'SigningCertURL' => 'https://sns.eu-central-1.amazonaws.com/cert.pem',
        ], $overrides);
    }

    private function makeController(
        bool $signatureValid,
        ?string $expectedTopicArn,
        ?HttpClientInterface $httpClient = null,
        ?SesEventProcessor $processor = null,
    ): SesWebhookController {
        $validator = $this->createMock(MessageValidator::class);
        if ($signatureValid) {
            $validator->method('validate')->with($this->isInstanceOf(Message::class));
        } else {
            $validator->method('validate')->willThrowException(new InvalidSnsMessageException('bad signature'));
        }

        return new SesWebhookController(
            $processor ?? $this->createMock(SesEventProcessor::class),
            new NullLogger(),
            $httpClient ?? $this->createMock(HttpClientInterface::class),
            $expectedTopicArn,
            $validator,
        );
    }

    private function post(SesWebhookController $controller, array $payload): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/webhook/ses', 'POST', [], [], [], [], json_encode($payload));

        return $controller->handleSesWebhook($request);
    }

    public function testUnsignedPayloadIsRejected(): void
    {
        $processor = $this->createMock(SesEventProcessor::class);
        $processor->expects($this->never())->method('process');

        $response = $this->post($this->makeController(false, self::TOPIC, null, $processor), $this->snsPayload());

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPayloadMissingSnsFieldsIsRejected(): void
    {
        // Missing Signature / SigningCertURL: Message::fromJsonString throws before validation
        $response = $this->post($this->makeController(true, self::TOPIC), ['Type' => 'Notification', 'TopicArn' => self::TOPIC]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testTopicArnMustMatchConfiguredArn(): void
    {
        $processor = $this->createMock(SesEventProcessor::class);
        $processor->expects($this->never())->method('process');

        $response = $this->post(
            $this->makeController(true, self::TOPIC, null, $processor),
            $this->snsPayload(['TopicArn' => 'arn:aws:sns:eu-central-1:999999999999:someone-else']),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testValidNotificationIsProcessed(): void
    {
        $processor = $this->createMock(SesEventProcessor::class);
        $processor->expects($this->once())->method('process');

        $response = $this->post($this->makeController(true, self::TOPIC, null, $processor), $this->snsPayload());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPrefixCheckStillAppliesWhenNoArnConfigured(): void
    {
        $response = $this->post($this->makeController(true, null), $this->snsPayload(['TopicArn' => 'not-an-arn']));
        self::assertSame(403, $response->getStatusCode());

        $response = $this->post($this->makeController(true, null), $this->snsPayload());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testSubscribeUrlIsOnlyFetchedFromSns(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('request');

        $response = $this->post($this->makeController(true, self::TOPIC, $http), $this->snsPayload([
            'Type' => 'SubscriptionConfirmation',
            'Token' => 't',
            'SubscribeURL' => 'https://169.254.169.254/latest/meta-data/',
        ]));
        self::assertSame(400, $response->getStatusCode());

        $response = $this->post($this->makeController(true, self::TOPIC, $http), $this->snsPayload([
            'Type' => 'SubscriptionConfirmation',
            'Token' => 't',
            'SubscribeURL' => 'https://sns.eu-central-1.amazonaws.com.evil.example/confirm',
        ]));
        self::assertSame(400, $response->getStatusCode());

        $response = $this->post($this->makeController(true, self::TOPIC, $http), $this->snsPayload([
            'Type' => 'SubscriptionConfirmation',
            'Token' => 't',
            'SubscribeURL' => 'http://sns.eu-central-1.amazonaws.com/confirm',
        ]));
        self::assertSame(400, $response->getStatusCode());
    }

    public function testGenuineSubscribeUrlIsFetched(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())->method('request')
            ->with('GET', 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc');

        $response = $this->post($this->makeController(true, self::TOPIC, $http), $this->snsPayload([
            'Type' => 'SubscriptionConfirmation',
            'Token' => 'abc',
            'SubscribeURL' => 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc',
        ]));

        self::assertSame(200, $response->getStatusCode());
    }
}
