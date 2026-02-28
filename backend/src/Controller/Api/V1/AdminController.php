<?php

namespace App\Controller\Api\V1;

use App\Entity\AuditLog;
use App\Entity\EmailLog;
use App\Entity\Organization;
use App\Entity\User;
use App\Message\SendEmailConfirmationMessage;
use App\Constants\Pagination;
use App\Repository\CompanyRepository;
use App\Repository\EmailLogRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\AdminMetricsService;
use App\Service\LicenseManager;
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
            ->leftJoin('o.memberships', 'm')->addSelect('m')
            ->leftJoin('o.companies', 'c')->addSelect('c')
            ->orderBy('o.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('o.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $total = (int) $this->organizationRepository->createQueryBuilder('o2')
            ->select('COUNT(o2.id)')
            ->getQuery()
            ->getSingleScalarResult();

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
                'maxUsers' => $o->getMaxUsers(),
                'maxCompanies' => $o->getMaxCompanies(),
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
            'maxUsers' => $org->getMaxUsers(),
            'maxCompanies' => $org->getMaxCompanies(),
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

        if (isset($data['maxUsers'])) {
            $maxUsers = (int) $data['maxUsers'];
            if ($maxUsers < 1) {
                return $this->json(['error' => 'maxUsers must be at least 1.'], Response::HTTP_BAD_REQUEST);
            }
            $org->setMaxUsers($maxUsers);
        }

        if (isset($data['maxCompanies'])) {
            $maxCompanies = (int) $data['maxCompanies'];
            if ($maxCompanies < 1) {
                return $this->json(['error' => 'maxCompanies must be at least 1.'], Response::HTTP_BAD_REQUEST);
            }
            $org->setMaxCompanies($maxCompanies);
        }

        if (isset($data['isActive'])) {
            $org->setIsActive((bool) $data['isActive']);
        }

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Organization updated.',
            'plan' => $org->getPlan(),
            'maxUsers' => $org->getMaxUsers(),
            'maxCompanies' => $org->getMaxCompanies(),
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
