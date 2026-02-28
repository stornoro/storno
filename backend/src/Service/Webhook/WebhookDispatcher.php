<?php

namespace App\Service\Webhook;

use App\Entity\Company;
use App\Message\DispatchWebhookMessage;
use App\Repository\CompanyRepository;
use App\Repository\WebhookEndpointRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookEndpointRepository $endpointRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatch(string $companyId, string $eventType, array $data): void
    {
        $company = $this->companyRepository->find(Uuid::fromString($companyId));
        if (!$company) {
            return;
        }

        $this->dispatchForCompany($company, $eventType, $data);
    }

    public function dispatchForCompany(Company $company, string $eventType, array $data): void
    {
        $endpoints = $this->endpointRepository->findActiveByCompanyAndEvent($company, $eventType);

        foreach ($endpoints as $endpoint) {
            if (!$endpoint->supportsEvent($eventType)) {
                continue;
            }

            $payload = [
                'id' => Uuid::v7()->toRfc4122(),
                'event' => $eventType,
                'created_at' => (new \DateTimeImmutable())->format('c'),
                'data' => $data,
            ];

            $this->messageBus->dispatch(new DispatchWebhookMessage(
                endpointId: $endpoint->getId()->toRfc4122(),
                eventType: $eventType,
                payload: $payload,
            ));

            $this->logger->debug('Webhook dispatched', [
                'endpointId' => $endpoint->getId()->toRfc4122(),
                'eventType' => $eventType,
            ]);
        }
    }
}
