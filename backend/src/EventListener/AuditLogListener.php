<?php

namespace App\EventListener;

use App\Entity\ApiToken;
use App\Entity\AuditLog;
use App\Entity\BankAccount;
use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DeliveryNote;
use App\Entity\DocumentSeries;
use App\Entity\EmailTemplate;
use App\Entity\Invoice;
use App\Entity\LicenseKey;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\Product;
use App\Entity\ProformaInvoice;
use App\Entity\Receipt;
use App\Entity\RecurringInvoice;
use App\Entity\Supplier;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::onFlush)]
class AuditLogListener
{
    /** Entities worth tracking — everything else is noise. */
    private const AUDITED_ENTITIES = [
        User::class,
        Organization::class,
        OrganizationMembership::class,
        Company::class,
        Invoice::class,
        ProformaInvoice::class,
        DeliveryNote::class,
        Receipt::class,
        RecurringInvoice::class,
        Client::class,
        Supplier::class,
        Product::class,
        BankAccount::class,
        DocumentSeries::class,
        EmailTemplate::class,
        ApiToken::class,
        LicenseKey::class,
    ];

    /** Fields to strip from changesets — sensitive or noisy. */
    private const IGNORED_FIELDS = [
        'password',
        'token',
        'secret',
        'refreshToken',
        'totpSecret',
        'backupCodes',
        'updatedAt',
        'lastConnectedAt',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $auditLogs = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$this->isAudited($entity)) {
                continue;
            }
            $auditLogs[] = $this->createAuditLog($em, 'create', $entity, []);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->isAudited($entity)) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            $changes = $this->formatChangeSet($changeSet);
            if (empty($changes)) {
                continue;
            }
            $auditLogs[] = $this->createAuditLog($em, 'update', $entity, $changes);
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$this->isAudited($entity)) {
                continue;
            }
            $auditLogs[] = $this->createAuditLog($em, 'delete', $entity, []);
        }

        foreach ($auditLogs as $auditLog) {
            $em->persist($auditLog);
            $classMetadata = $em->getClassMetadata(AuditLog::class);
            $uow->computeChangeSet($classMetadata, $auditLog);
        }
    }

    private function isAudited(object $entity): bool
    {
        foreach (self::AUDITED_ENTITIES as $class) {
            if ($entity instanceof $class) {
                return true;
            }
        }
        return false;
    }

    private function createAuditLog(EntityManagerInterface $em, string $action, object $entity, array $changes): AuditLog
    {
        $auditLog = new AuditLog();
        $auditLog->setAction($action);
        $auditLog->setEntityType($this->shortClassName($entity));
        $auditLog->setEntityId($this->resolveEntityId($em, $entity));
        $auditLog->setChanges($changes);

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $auditLog->setUser($user);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $auditLog->setIpAddress($request->getClientIp());
            $auditLog->setUserAgent(substr((string) $request->headers->get('User-Agent'), 0, 500));
        }

        // Tag the source when no authenticated user is present
        if (!$user instanceof User) {
            $auditLog->setUserAgent($this->detectSource($request));
        }

        return $auditLog;
    }

    private function detectSource(?\Symfony\Component\HttpFoundation\Request $request): string
    {
        if ($request === null) {
            // No HTTP request — CLI command or async message handler
            if (\PHP_SAPI === 'cli') {
                $args = $_SERVER['argv'] ?? [];
                // Extract the Symfony command name (e.g. "app:process-recurring")
                $command = $args[1] ?? 'cli';
                return 'system:cli:' . $command;
            }
            return 'system:worker';
        }

        // HTTP request but no user — webhook or public endpoint
        $route = $request->attributes->get('_route', '');
        if (str_contains($route, 'webhook') || str_contains($route, 'stripe')) {
            return 'system:webhook:' . $route;
        }

        return 'system:http';
    }

    private function shortClassName(object $entity): string
    {
        return (new \ReflectionClass($entity))->getShortName();
    }

    private function resolveEntityId(EntityManagerInterface $em, object $entity): string
    {
        $identifiers = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);

        if (empty($identifiers)) {
            return '';
        }

        $id = reset($identifiers);

        return (string) $id;
    }

    private function formatChangeSet(array $changeSet): array
    {
        $formatted = [];

        foreach ($changeSet as $field => [$oldValue, $newValue]) {
            if (\in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }
            $formatted[$field] = [
                'old' => $this->normalizeValue($oldValue),
                'new' => $this->normalizeValue($newValue),
            ];
        }

        return $formatted;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            return (new \ReflectionClass($value))->getShortName();
        }

        return $value;
    }
}
