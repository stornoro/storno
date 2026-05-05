<?php

namespace App\Controller\Api\V1;

use App\Entity\AppVersionOverride;
use App\Entity\AuditLog;
use App\Message\BroadcastVersionGateMessage;
use App\Entity\EmailLog;
use App\Entity\Organization;
use App\Entity\User;
use App\Message\SendEmailConfirmationMessage;
use App\Constants\Pagination;
use App\Repository\AppVersionOverrideRepository;
use App\Repository\CompanyRepository;
use App\Repository\EmailLogRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\AdminMetricsService;
use App\Service\LicenseManager;
use App\Service\VersionGateService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly EmailLogRepository $emailLogRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LicenseManager $licenseManager,
        private readonly AdminMetricsService $adminMetricsService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly SerializerInterface $serializer,
    ) {}

    #[Route('/stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $users = (int) $this->entityManager->createQuery('SELECT COUNT(u.id) FROM App\Entity\User u')->getSingleScalarResult();
        $organizations = (int) $this->entityManager->createQuery('SELECT COUNT(o.id) FROM App\Entity\Organization o')->getSingleScalarResult();
        $companies = (int) $this->entityManager->createQuery('SELECT COUNT(c.id) FROM App\Entity\Company c')->getSingleScalarResult();

        return $this->json([
            'users' => $users,
            'organizations' => $organizations,
            'companies' => $companies,
        ]);
    }

    #[Route('/users', methods: ['GET'])]
    public function users(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
        $search = $request->query->get('search');

        $qb = $this->userRepository->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('u.email LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        $users = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->json([
            'data' => array_map(fn (User $u) => $this->serializeUser($u), $users),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/users/{id}', methods: ['GET'])]
    public function userDetail(string $id): JsonResponse
    {
        $user = $this->userRepository->find(Uuid::fromString($id));
        if (!$user) {
            return $this->json(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeUser($user));
    }

    #[Route('/users/{id}/toggle-active', methods: ['POST'])]
    public function toggleActive(string $id): JsonResponse
    {
        $user = $this->userRepository->find(Uuid::fromString($id));
        if (!$user) {
            return $this->json(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $user->setActive(!$user->isActive());
        $this->entityManager->flush();

        return $this->json([
            'active' => $user->isActive(),
            'message' => $user->isActive() ? 'User activated.' : 'User deactivated.',
        ]);
    }

    #[Route('/users/{id}/verify-email', methods: ['POST'])]
    public function verifyEmail(string $id): JsonResponse
    {
        $user = $this->userRepository->find(Uuid::fromString($id));
        if (!$user) {
            return $this->json(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $user->setEmailVerified(true);
        $this->entityManager->flush();

        return $this->json(['message' => 'Email verified.']);
    }

    #[Route('/users/{id}/resend-confirmation', methods: ['POST'])]
    public function resendConfirmation(string $id): JsonResponse
    {
        $user = $this->userRepository->find(Uuid::fromString($id));
        if (!$user) {
            return $this->json(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($user->isEmailVerified()) {
            return $this->json(['error' => 'Email already verified.'], Response::HTTP_BAD_REQUEST);
        }

        $this->messageBus->dispatch(new SendEmailConfirmationMessage((string) $user->getId()));

        return $this->json(['message' => 'Confirmation email sent.']);
    }

    #[Route('/organizations', methods: ['GET'])]
    public function organizations(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
        $search = $request->query->get('search');

        $qb = $this->organizationRepository->createQueryBuilder('o')
            ->orderBy('o.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('o.name LIKE :search OR o.slug LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Count must reuse the filtered builder, otherwise pagination shows
        // the wrong total when the user is searching. Clone before
        // setFirstResult so the count query stays unpaginated; reset orderBy
        // because COUNT(...) doesn't allow ORDER BY against an aggregate.
        $countQb = (clone $qb)
            ->select('COUNT(o.id)')
            ->resetDQLPart('orderBy');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // No fetch-join here on purpose: combining setFirstResult/setMaxResults
        // with collection joins makes Doctrine paginate by SQL row, not by
        // root entity, which silently truncates pages. The serializer below
        // only reads ->count() on each collection — Doctrine lazy-loads those
        // counts as cheap COUNT(*) queries, not full hydrations.
        $organizations = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->json([
            'data' => array_map(fn (Organization $o) => [
                'id' => (string) $o->getId(),
                'name' => $o->getName(),
                'slug' => $o->getSlug(),
                'plan' => $o->getPlan(),
                'isActive' => $o->isActive(),
                'memberCount' => $o->getMemberships()->count(),
                'companyCount' => $o->getCompanies()->count(),
                'maxUsers' => $this->licenseManager->getMaxUsers($o),
                'maxCompanies' => $this->licenseManager->getMaxCompanies($o),
                'trialEndsAt' => $o->getTrialEndsAt()?->format('c'),
                'createdAt' => $o->getCreatedAt()?->format('c'),
            ], $organizations),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/organizations/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function organizationDetail(string $id): JsonResponse
    {
        $org = $this->organizationRepository->find(Uuid::fromString($id));
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $members = [];
        foreach ($org->getMemberships() as $m) {
            $u = $m->getUser();
            $members[] = [
                'id' => (string) $m->getId(),
                'email' => $u?->getEmail(),
                'fullName' => $u?->getFullName(),
                'role' => $m->getRole()->value,
                'isActive' => $m->isActive(),
                'joinedAt' => $m->getJoinedAt()->format('c'),
            ];
        }

        $companies = [];
        foreach ($org->getCompanies() as $c) {
            $companies[] = [
                'id' => (string) $c->getId(),
                'name' => $c->getName(),
                'cif' => $c->getCif(),
                'city' => $c->getCity(),
                'createdAt' => $c->getCreatedAt()?->format('c'),
            ];
        }

        return $this->json([
            'id' => (string) $org->getId(),
            'name' => $org->getName(),
            'slug' => $org->getSlug(),
            'plan' => $org->getPlan(),
            'isActive' => $org->isActive(),
            'maxUsers' => $this->licenseManager->getMaxUsers($org),
            'maxCompanies' => $this->licenseManager->getMaxCompanies($org),
            'stripeCustomerId' => $org->getStripeCustomerId(),
            'stripeSubscriptionId' => $org->getStripeSubscriptionId(),
            'stripePriceId' => $org->getStripePriceId(),
            'subscriptionStatus' => $org->getSubscriptionStatus(),
            'currentPeriodEnd' => $org->getCurrentPeriodEnd()?->format('c'),
            'cancelAtPeriodEnd' => $org->isCancelAtPeriodEnd(),
            'trialEndsAt' => $org->getTrialEndsAt()?->format('c'),
            'createdAt' => $org->getCreatedAt()?->format('c'),
            'members' => $members,
            'companies' => $companies,
        ]);
    }

    #[Route('/organizations/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function updateOrganization(string $id, Request $request): JsonResponse
    {
        $org = $this->organizationRepository->find(Uuid::fromString($id));
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['plan'])) {
            if (!\in_array($data['plan'], LicenseManager::ALL_PLANS, true)) {
                return $this->json(['error' => 'Invalid plan. Allowed: ' . implode(', ', LicenseManager::ALL_PLANS)], Response::HTTP_BAD_REQUEST);
            }
            $org->setPlan($data['plan']);
        }

        if (isset($data['isActive'])) {
            $org->setIsActive((bool) $data['isActive']);
        }

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Organization updated.',
            'plan' => $org->getPlan(),
            'maxUsers' => $this->licenseManager->getMaxUsers($org),
            'maxCompanies' => $this->licenseManager->getMaxCompanies($org),
            'isActive' => $org->isActive(),
        ]);
    }

    #[Route('/organizations/{id}/toggle-active', methods: ['POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function toggleOrganizationActive(string $id): JsonResponse
    {
        $org = $this->organizationRepository->find(Uuid::fromString($id));
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $org->setIsActive(!$org->isActive());
        $this->entityManager->flush();

        return $this->json([
            'isActive' => $org->isActive(),
            'message' => $org->isActive() ? 'Organization reactivated.' : 'Organization suspended.',
        ]);
    }

    #[Route('/metrics', methods: ['GET'])]
    public function metrics(): JsonResponse
    {
        return $this->json($this->adminMetricsService->getAllMetrics());
    }

    #[Route('/revenue', methods: ['GET'])]
    public function revenue(): JsonResponse
    {
        $totalOrgs = (int) $this->entityManager->createQuery('SELECT COUNT(o.id) FROM App\Entity\Organization o')->getSingleScalarResult();

        $activeSubscriptions = (int) $this->entityManager->createQuery(
            "SELECT COUNT(o.id) FROM App\Entity\Organization o WHERE o.subscriptionStatus IN ('active', 'trialing')"
        )->getSingleScalarResult();

        $trialCount = (int) $this->entityManager->createQuery(
            "SELECT COUNT(o.id) FROM App\Entity\Organization o WHERE o.trialEndsAt IS NOT NULL AND o.trialEndsAt > :now"
        )->setParameter('now', new \DateTimeImmutable())->getSingleScalarResult();

        // Plan distribution
        $planRows = $this->entityManager->createQuery(
            'SELECT o.plan, COUNT(o.id) AS cnt FROM App\Entity\Organization o GROUP BY o.plan'
        )->getResult();

        $planDistribution = [];
        foreach ($planRows as $row) {
            $planDistribution[$row['plan']] = (int) $row['cnt'];
        }

        // MRR calculation: sum monthly prices for orgs with active/trialing subscriptions
        $pricing = LicenseManager::getPlanPricing();
        $paidOrgs = $this->entityManager->createQuery(
            "SELECT o.plan FROM App\Entity\Organization o WHERE o.subscriptionStatus IN ('active', 'trialing') AND o.plan IN (:plans)"
        )->setParameter('plans', LicenseManager::ALL_PLANS)->getResult();

        $mrr = 0;
        foreach ($paidOrgs as $row) {
            $plan = $row['plan'];
            $mrr += $pricing[$plan]['monthlyPrice'] ?? 0;
        }

        // Recent subscriptions (last 10 orgs with a subscription)
        $recentOrgs = $this->entityManager->createQuery(
            "SELECT o FROM App\Entity\Organization o WHERE o.subscriptionStatus IS NOT NULL ORDER BY o.createdAt DESC"
        )->setMaxResults(10)->getResult();

        $recentSubscriptions = array_map(fn (Organization $o) => [
            'id' => (string) $o->getId(),
            'name' => $o->getName(),
            'plan' => $o->getPlan(),
            'subscriptionStatus' => $o->getSubscriptionStatus(),
            'createdAt' => $o->getCreatedAt()?->format('c'),
        ], $recentOrgs);

        return $this->json([
            'totalOrgs' => $totalOrgs,
            'activeSubscriptions' => $activeSubscriptions,
            'trialCount' => $trialCount,
            'planDistribution' => $planDistribution,
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'currency' => 'RON',
            'recentSubscriptions' => $recentSubscriptions,
        ]);
    }

    #[Route('/audit-logs', methods: ['GET'])]
    public function auditLogs(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
        $search = $request->query->get('search');
        $action = $request->query->get('action');

        $qb = $this->entityManager->createQueryBuilder()
            ->select('a', 'u')
            ->from(AuditLog::class, 'a')
            ->leftJoin('a.user', 'u')
            ->orderBy('a.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('a.entityType LIKE :search OR a.entityId LIKE :search OR u.email LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($action) {
            $qb->andWhere('a.action = :action')
                ->setParameter('action', $action);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $logs = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->json([
            'data' => array_map(fn (AuditLog $log) => [
                'id' => (string) $log->getId(),
                'action' => $log->getAction(),
                'entityType' => $log->getEntityType(),
                'entityId' => $log->getEntityId(),
                'changes' => $log->getChanges(),
                'ipAddress' => $log->getIpAddress(),
                'userAgent' => $log->getUserAgent(),
                'user' => $log->getUser() ? [
                    'id' => (string) $log->getUser()->getId(),
                    'email' => $log->getUser()->getEmail(),
                    'fullName' => $log->getUser()->getFullName(),
                ] : null,
                'createdAt' => $log->getCreatedAt()->format('c'),
            ], $logs),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/email-logs', methods: ['GET'])]
    public function emailLogs(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
        $search = $request->query->get('search');
        $category = $request->query->get('category');
        $status = $request->query->get('status');

        $result = $this->emailLogRepository->findAllPaginated($page, $limit, $search, $category, $status);

        $data = json_decode($this->serializer->serialize($result['data'], 'json', ['groups' => ['email_log:list']]), true);

        return $this->json([
            'data' => $data,
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/email-logs/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function emailLogDetail(string $id): JsonResponse
    {
        $emailLog = $this->emailLogRepository->find(Uuid::fromString($id));
        if (!$emailLog) {
            return $this->json(['error' => 'Email log not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($this->serializer->serialize($emailLog, 'json', ['groups' => ['email_log:detail', 'email_event:list']]), true);

        return $this->json($data);
    }

    #[Route('/users/{id}/impersonate', methods: ['POST'])]
    public function impersonate(string $id, Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        $targetUser = $this->userRepository->find(Uuid::fromString($id));
        if (!$targetUser) {
            return $this->json(['error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($targetUser->getId()->equals($admin->getId())) {
            return $this->json(['error' => 'Cannot impersonate yourself.'], Response::HTTP_BAD_REQUEST);
        }

        if (\in_array('ROLE_SUPER_ADMIN', $targetUser->getRoles(), true)) {
            return $this->json(['error' => 'Cannot impersonate another super admin.'], Response::HTTP_FORBIDDEN);
        }

        $token = $this->jwtManager->createFromPayload($targetUser, [
            'impersonator' => (string) $admin->getId(),
        ]);

        // Audit log
        $auditLog = new AuditLog();
        $auditLog->setAction('impersonate');
        $auditLog->setEntityType('User');
        $auditLog->setEntityId((string) $targetUser->getId());
        $auditLog->setChanges([
            'adminId' => (string) $admin->getId(),
            'adminEmail' => $admin->getEmail(),
            'targetEmail' => $targetUser->getEmail(),
        ]);
        $auditLog->setUser($admin);
        $auditLog->setIpAddress($request->getClientIp());
        $auditLog->setUserAgent(substr((string) $request->headers->get('User-Agent'), 0, 500));
        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();

        return $this->json([
            'token' => $token,
            'user' => $this->serializeUser($targetUser),
        ]);
    }

    #[Route('/telemetry/stats', methods: ['GET'])]
    public function telemetryStats(Request $request, Connection $connection): JsonResponse
    {
        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');

        $totalEvents = (int) $connection->fetchOne('SELECT COUNT(*) FROM telemetry_event');

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $todayEvents = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM telemetry_event WHERE created_at >= :today',
            ['today' => $today . ' 00:00:00']
        );

        $weekAgo = (new \DateTimeImmutable('-7 days'))->format('Y-m-d');
        $weekEvents = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM telemetry_event WHERE created_at >= :weekAgo',
            ['weekAgo' => $weekAgo . ' 00:00:00']
        );

        $thirtyDaysAgo = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');
        $uniqueUsers = (int) $connection->fetchOne(
            'SELECT COUNT(DISTINCT user_id) FROM telemetry_event WHERE created_at >= :since',
            ['since' => $thirtyDaysAgo . ' 00:00:00']
        );

        $topEventsFrom = $dateFrom ?? $thirtyDaysAgo;
        $topEventsTo = $dateTo ?? (new \DateTimeImmutable('+1 day'))->format('Y-m-d');

        $topEvents = $connection->fetchAllAssociative(
            'SELECT event, COUNT(*) AS count FROM telemetry_event WHERE created_at >= :from AND created_at < :to GROUP BY event ORDER BY count DESC LIMIT 10',
            ['from' => $topEventsFrom . ' 00:00:00', 'to' => $topEventsTo . ' 00:00:00']
        );
        $topEvents = array_map(fn (array $row) => ['event' => $row['event'], 'count' => (int) $row['count']], $topEvents);

        $platformBreakdown = $connection->fetchAllAssociative(
            'SELECT platform, COUNT(*) AS count FROM telemetry_event WHERE created_at >= :from AND created_at < :to GROUP BY platform ORDER BY count DESC',
            ['from' => $topEventsFrom . ' 00:00:00', 'to' => $topEventsTo . ' 00:00:00']
        );
        $platformBreakdown = array_map(fn (array $row) => ['platform' => $row['platform'], 'count' => (int) $row['count']], $platformBreakdown);

        $dailyTrend = $connection->fetchAllAssociative(
            'SELECT DATE(created_at) AS date, COUNT(*) AS count FROM telemetry_event WHERE created_at >= :since GROUP BY DATE(created_at) ORDER BY date ASC',
            ['since' => $thirtyDaysAgo . ' 00:00:00']
        );
        $dailyTrend = array_map(fn (array $row) => ['date' => $row['date'], 'count' => (int) $row['count']], $dailyTrend);

        return $this->json([
            'totalEvents' => $totalEvents,
            'todayEvents' => $todayEvents,
            'weekEvents' => $weekEvents,
            'uniqueUsers' => $uniqueUsers,
            'topEvents' => $topEvents,
            'platformBreakdown' => $platformBreakdown,
            'dailyTrend' => $dailyTrend,
        ]);
    }

    #[Route('/telemetry/events', methods: ['GET'])]
    public function telemetryEvents(Request $request, Connection $connection): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = Pagination::clamp($request->query->getInt('limit', Pagination::DEFAULT_LIMIT));
        $event = $request->query->get('event');
        $platform = $request->query->get('platform');

        $where = [];
        $params = [];

        if ($event) {
            $where[] = 't.event = :event';
            $params['event'] = $event;
        }
        if ($platform) {
            $where[] = 't.platform = :platform';
            $params['platform'] = $platform;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM telemetry_event t {$whereClause}",
            $params
        );

        $offset = ($page - 1) * $limit;
        // `user` is reserved across SQL dialects, so let DBAL quote it the
        // way the active platform expects (backticks on MySQL, double quotes
        // on Postgres). Hard-coding either form 500's on the other engine.
        $userTable = $connection->getDatabasePlatform()->quoteSingleIdentifier('user');
        $rows = $connection->fetchAllAssociative(
            "SELECT t.id, t.user_id, t.company_id, t.event, t.properties, t.platform, t.app_version, t.created_at,
                    u.email AS user_email, u.first_name AS user_first_name, u.last_name AS user_last_name
             FROM telemetry_event t
             LEFT JOIN {$userTable} u ON u.id = t.user_id
             {$whereClause}
             ORDER BY t.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        $items = array_map(function (array $row): array {
            $fullName = trim(($row['user_first_name'] ?? '') . ' ' . ($row['user_last_name'] ?? ''));
            return [
                'id' => $row['id'],
                'userId' => $row['user_id'],
                'userEmail' => $row['user_email'],
                'userName' => $fullName !== '' ? $fullName : null,
                'companyId' => $row['company_id'],
                'event' => $row['event'],
                'properties' => json_decode($row['properties'], true),
                'platform' => $row['platform'],
                'appVersion' => $row['app_version'],
                'createdAt' => $row['created_at'],
            ];
        }, $rows);

        return $this->json([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * List the version-gate metadata for every supported platform: the
     * deployed YAML defaults, the DB override (if any), and the merged
     * effective values that drive /api/v1/version. Used by the admin
     * "version gate" page to render the kill-switch table.
     */
    #[Route('/version-overrides', methods: ['GET'])]
    public function listVersionOverrides(VersionGateService $gate, AppVersionOverrideRepository $repo): JsonResponse
    {
        $overrides = $repo->findAllIndexed();
        $platforms = ['ios', 'android', 'huawei'];

        $out = [];
        foreach ($platforms as $platform) {
            $defaults = $gate->defaultConfigFor($platform);
            if ($defaults === null) {
                continue;
            }
            $effective = $gate->effectiveConfigFor($platform);
            $override = $overrides[$platform] ?? null;

            $out[] = [
                'platform' => $platform,
                'defaults' => $defaults,
                'effective' => $effective,
                'override' => $override === null ? null : [
                    'minOverride' => $override->getMinOverride(),
                    'latestOverride' => $override->getLatestOverride(),
                    'storeUrlOverride' => $override->getStoreUrlOverride(),
                    'releaseNotesUrlOverride' => $override->getReleaseNotesUrlOverride(),
                    'messageOverride' => $override->getMessageOverride(),
                    'updatedAt' => $override->getUpdatedAt()->format('c'),
                    'updatedBy' => $override->getUpdatedBy()?->getEmail(),
                    'hasOverride' => $override->hasAnyOverride(),
                ],
            ];
        }

        return $this->json(['platforms' => $out]);
    }

    /**
     * Upsert the override row for a platform. Each field in the request
     * body is treated independently: present-and-non-null sets the
     * override, present-and-null clears it, absent leaves it as-is.
     * Audit-logged so we can reconstruct who flipped the kill switch
     * and when.
     */
    #[Route('/version-overrides/{platform}', methods: ['PUT'], requirements: ['platform' => 'ios|android|huawei'])]
    public function updateVersionOverride(
        string $platform,
        Request $request,
        AppVersionOverrideRepository $repo,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON body.'], 400);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $override = $repo->findByPlatform($platform);
        if ($override === null) {
            $override = new AppVersionOverride($platform);
        }

        $previous = [
            'min' => $override->getMinOverride(),
            'latest' => $override->getLatestOverride(),
            'storeUrl' => $override->getStoreUrlOverride(),
            'releaseNotesUrl' => $override->getReleaseNotesUrlOverride(),
            'message' => $override->getMessageOverride(),
        ];

        if (array_key_exists('minOverride', $payload)) {
            $override->setMinOverride(is_string($payload['minOverride']) ? $payload['minOverride'] : null);
        }
        if (array_key_exists('latestOverride', $payload)) {
            $override->setLatestOverride(is_string($payload['latestOverride']) ? $payload['latestOverride'] : null);
        }
        if (array_key_exists('storeUrlOverride', $payload)) {
            $override->setStoreUrlOverride(is_string($payload['storeUrlOverride']) ? $payload['storeUrlOverride'] : null);
        }
        if (array_key_exists('releaseNotesUrlOverride', $payload)) {
            $override->setReleaseNotesUrlOverride(
                is_string($payload['releaseNotesUrlOverride']) ? $payload['releaseNotesUrlOverride'] : null,
            );
        }
        if (array_key_exists('messageOverride', $payload)) {
            $value = $payload['messageOverride'];
            if (!is_array($value)) {
                $value = null;
            } else {
                // Filter out non-string values; keep only locale → string pairs.
                $value = array_filter(
                    $value,
                    static fn ($v, $k) => is_string($k) && is_string($v) && $v !== '',
                    ARRAY_FILTER_USE_BOTH,
                );
                if ($value === []) {
                    $value = null;
                }
            }
            $override->setMessageOverride($value);
        }

        $override->setUpdatedBy($admin);
        $override->touch();

        $this->entityManager->persist($override);

        // Optional fan-out — admin can untick the checkbox on the version-gate
        // page to silently fix a typo or roll back without renotifying. The
        // dispatch happens after flush() below so the handler reads the
        // freshly-persisted override values.
        $shouldNotify = isset($payload['notify']) && $payload['notify'] === true;

        $auditLog = new AuditLog();
        $auditLog->setAction('update');
        $auditLog->setEntityType('AppVersionOverride');
        $auditLog->setEntityId($platform);
        $auditLog->setChanges([
            'before' => $previous,
            'after' => [
                'min' => $override->getMinOverride(),
                'latest' => $override->getLatestOverride(),
                'storeUrl' => $override->getStoreUrlOverride(),
                'releaseNotesUrl' => $override->getReleaseNotesUrlOverride(),
                'message' => $override->getMessageOverride(),
            ],
        ]);
        $auditLog->setUser($admin);
        $auditLog->setIpAddress($request->getClientIp());
        $auditLog->setUserAgent(substr((string) $request->headers->get('User-Agent'), 0, 500));
        $this->entityManager->persist($auditLog);

        $this->entityManager->flush();

        if ($shouldNotify) {
            $this->messageBus->dispatch(new BroadcastVersionGateMessage(
                platform: $platform,
                triggeredByUserId: (string) $admin->getId(),
            ));
        }

        return $this->json([
            'platform' => $platform,
            'notified' => $shouldNotify,
            'override' => [
                'minOverride' => $override->getMinOverride(),
                'latestOverride' => $override->getLatestOverride(),
                'storeUrlOverride' => $override->getStoreUrlOverride(),
                'releaseNotesUrlOverride' => $override->getReleaseNotesUrlOverride(),
                'messageOverride' => $override->getMessageOverride(),
                'updatedAt' => $override->getUpdatedAt()->format('c'),
                'updatedBy' => $admin->getEmail(),
                'hasOverride' => $override->hasAnyOverride(),
            ],
        ]);
    }

    private function serializeUser(User $u): array
    {
        return [
            'id' => (string) $u->getId(),
            'email' => $u->getEmail(),
            'firstName' => $u->getFirstName(),
            'lastName' => $u->getLastName(),
            'fullName' => $u->getFullName(),
            'phone' => $u->getPhone(),
            'roles' => $u->getRoles(),
            'active' => $u->isActive(),
            'emailVerified' => $u->isEmailVerified(),
            'lastConnectedAt' => $u->getLastConnectedAt()?->format('c'),
            'createdAt' => $u->getCreatedAt()?->format('c'),
        ];
    }
}
