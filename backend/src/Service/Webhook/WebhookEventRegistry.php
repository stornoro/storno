<?php

namespace App\Service\Webhook;

final class WebhookEventRegistry
{
    private const EVENTS = [
        [
            'event' => 'invoice.created',
            'category' => 'invoice',
            'description' => 'Factura noua creata',
        ],
        [
            'event' => 'invoice.updated',
            'category' => 'invoice',
            'description' => 'Factura actualizata',
        ],
        [
            'event' => 'invoice.issued',
            'category' => 'invoice',
            'description' => 'Factura emisa',
        ],
        [
            'event' => 'invoice.validated',
            'category' => 'invoice',
            'description' => 'Factura validata de ANAF',
        ],
        [
            'event' => 'invoice.rejected',
            'category' => 'invoice',
            'description' => 'Factura respinsa de ANAF',
        ],
        [
            'event' => 'invoice.sent_to_provider',
            'category' => 'invoice',
            'description' => 'Factura trimisa catre furnizor e-factura',
        ],
        [
            'event' => 'company.created',
            'category' => 'company',
            'description' => 'Companie noua adaugata',
        ],
        [
            'event' => 'company.updated',
            'category' => 'company',
            'description' => 'Date companie actualizate',
        ],
        [
            'event' => 'company.removed',
            'category' => 'company',
            'description' => 'Companie stearsa',
        ],
        [
            'event' => 'company.restored',
            'category' => 'company',
            'description' => 'Companie restaurata',
        ],
        [
            'event' => 'company.reset',
            'category' => 'company',
            'description' => 'Date companie resetate',
        ],
        [
            'event' => 'sync.started',
            'category' => 'sync',
            'description' => 'Sincronizare ANAF inceputa',
        ],
        [
            'event' => 'sync.completed',
            'category' => 'sync',
            'description' => 'Sincronizare ANAF finalizata',
        ],
        [
            'event' => 'sync.error',
            'category' => 'sync',
            'description' => 'Eroare la sincronizarea ANAF',
        ],
        [
            'event' => 'payment.received',
            'category' => 'payment',
            'description' => 'Plata inregistrata',
        ],
        [
            'event' => 'anaf.token_created',
            'category' => 'anaf',
            'description' => 'Token ANAF creat',
        ],
    ];

    public static function all(): array
    {
        return self::EVENTS;
    }

    public static function eventNames(): array
    {
        return array_column(self::EVENTS, 'event');
    }

    public static function isValidEvent(string $event): bool
    {
        return in_array($event, self::eventNames(), true);
    }

    public static function getByCategory(): array
    {
        $grouped = [];
        foreach (self::EVENTS as $event) {
            $grouped[$event['category']][] = $event;
        }

        return $grouped;
    }
}
