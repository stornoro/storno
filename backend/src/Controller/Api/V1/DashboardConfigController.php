<?php

namespace App\Controller\Api\V1;

use App\Entity\UserDashboardConfig;
use App\Repository\UserDashboardConfigRepository;
use App\Security\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/dashboard')]
class DashboardConfigController extends AbstractController
{
    /**
     * Full catalog of available dashboard widgets.
     * Order here defines the default position (index = position).
     * The 3 "new" widgets at the end default to visible: false.
     */
    public const WIDGET_CATALOG = [
        [
            'id' => 'sales-card',
            'name_key' => 'dashboard.widgets.sales.name',
            'description_key' => 'dashboard.widgets.sales.description',
            'size' => 'md',
            'category' => 'sales',
        ],
        [
            'id' => 'expenses-card',
            'name_key' => 'dashboard.widgets.expenses.name',
            'description_key' => 'dashboard.widgets.expenses.description',
            'size' => 'md',
            'category' => 'expenses',
        ],
        [
            'id' => 'client-balance-card',
            'name_key' => 'dashboard.widgets.clientBalance.name',
            'description_key' => 'dashboard.widgets.clientBalance.description',
            'size' => 'md',
            'category' => 'clients',
        ],
        [
            'id' => 'unpaid-card',
            'name_key' => 'dashboard.widgets.unpaid.name',
            'description_key' => 'dashboard.widgets.unpaid.description',
            'size' => 'md',
            'category' => 'invoices',
        ],
        [
            'id' => 'amounts-to-pay-card',
            'name_key' => 'dashboard.widgets.amountsToPay.name',
            'description_key' => 'dashboard.widgets.amountsToPay.description',
            'size' => 'md',
            'category' => 'expenses',
        ],
        [
            'id' => 'activity-card',
            'name_key' => 'dashboard.widgets.activity.name',
            'description_key' => 'dashboard.widgets.activity.description',
            'size' => 'md',
            'category' => 'activity',
        ],
        [
            'id' => 'due-today-card',
            'name_key' => 'dashboard.widgets.dueToday.name',
            'description_key' => 'dashboard.widgets.dueToday.description',
            'size' => 'md',
            'category' => 'invoices',
        ],
        [
            'id' => 'cash-balance-card',
            'name_key' => 'dashboard.widgets.cashBalance.name',
            'description_key' => 'dashboard.widgets.cashBalance.description',
            'size' => 'md',
            'category' => 'sales',
        ],
        [
            'id' => 'recent-invoices-table',
            'name_key' => 'dashboard.widgets.recentInvoices.name',
            'description_key' => 'dashboard.widgets.recentInvoices.description',
            'size' => 'xl',
            'category' => 'activity',
        ],
        [
            'id' => 'status-breakdown-chart',
            'name_key' => 'dashboard.widgets.statusBreakdown.name',
            'description_key' => 'dashboard.widgets.statusBreakdown.description',
            'size' => 'lg',
            'category' => 'invoices',
        ],
        [
            'id' => 'monthly-charts',
            'name_key' => 'dashboard.widgets.monthlyCharts.name',
            'description_key' => 'dashboard.widgets.monthlyCharts.description',
            'size' => 'xl',
            'category' => 'sales',
        ],
        [
            'id' => 'sync-status',
            'name_key' => 'dashboard.widgets.syncStatus.name',
            'description_key' => 'dashboard.widgets.syncStatus.description',
            'size' => 'sm',
            'category' => 'system',
        ],
        [
            'id' => 'top-clients-revenue',
            'name_key' => 'dashboard.widgets.topClientsRevenue.name',
            'description_key' => 'dashboard.widgets.topClientsRevenue.description',
            'size' => 'lg',
            'category' => 'clients',
        ],
        [
            'id' => 'top-products-revenue',
            'name_key' => 'dashboard.widgets.topProductsRevenue.name',
            'description_key' => 'dashboard.widgets.topProductsRevenue.description',
            'size' => 'lg',
            'category' => 'products',
        ],
        [
            'id' => 'top-outstanding-clients',
            'name_key' => 'dashboard.widgets.topOutstandingClients.name',
            'description_key' => 'dashboard.widgets.topOutstandingClients.description',
            'size' => 'lg',
            'category' => 'clients',
        ],
        [
            'id' => 'dosare-actions',
            'name_key' => 'dashboard.widgets.dosareActions.name',
            'description_key' => 'dashboard.widgets.dosareActions.description',
            'size' => 'md',
            'category' => 'activity',
        ],
    ];

    /**
     * An individual person (persoană fizică) neither sells nor has clients, products or VAT: the
     * dashboard keeps only what concerns them — the dosare to act on, the invoices received in
     * SPV, activity and the sync state — in this order.
     */
    private const INDIVIDUAL_WIDGET_IDS = [
        'dosare-actions', 'sync-status', 'amounts-to-pay-card', 'expenses-card', 'activity-card', 'recent-invoices-table',
    ];

    /** Widget IDs that default to visible: false for existing users. */
    private const NEW_WIDGET_IDS = [
        'top-clients-revenue',
        'top-products-revenue',
        'top-outstanding-clients',
        'dosare-actions',
    ];

    public function __construct(
        private readonly UserDashboardConfigRepository $configRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/config', methods: ['GET'])]
    public function getConfig(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $config = $this->configRepository->findByUserAndCompany($user, $company);

        if ($config !== null) {
            $widgets = $config->getWidgets();
            if ($company->isIndividual()) {
                $allowed = self::INDIVIDUAL_WIDGET_IDS;
                $widgets = array_values(array_filter($widgets, fn (array $w) => in_array($w['id'] ?? '', $allowed, true)));
            }
        } else {
            $widgets = $company->isIndividual() ? $this->buildIndividualDefaultConfig() : $this->buildDefaultConfig();
        }

        $response = $this->json(['widgets' => $widgets]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[Route('/config', methods: ['PUT'])]
    public function putConfig(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);

        if (!isset($data['widgets']) || !is_array($data['widgets'])) {
            return $this->json(['error' => 'Field "widgets" must be an array.'], Response::HTTP_BAD_REQUEST);
        }

        $knownIds = $company->isIndividual() ? self::INDIVIDUAL_WIDGET_IDS : array_column(self::WIDGET_CATALOG, 'id');
        $validationError = $this->validateWidgets($data['widgets'], $knownIds);
        if ($validationError !== null) {
            return $this->json(['error' => $validationError], Response::HTTP_BAD_REQUEST);
        }

        $config = $this->configRepository->findByUserAndCompany($user, $company);
        if ($config === null) {
            $config = new UserDashboardConfig();
            $config->setUser($user);
            $config->setCompany($company);
            $this->entityManager->persist($config);
        }

        $config->setWidgets($data['widgets']);
        $this->entityManager->flush();

        $response = $this->json(['widgets' => $config->getWidgets()]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[Route('/widgets/catalog', methods: ['GET'])]
    public function catalog(Request $request): JsonResponse
    {
        // with a company header the catalog is the one for that company (a person sees fewer widgets)
        $company = $this->organizationContext->resolveCompany($request);
        if ($company !== null && $company->isIndividual()) {
            $allowed = self::INDIVIDUAL_WIDGET_IDS;
            $widgets = array_values(array_filter(self::WIDGET_CATALOG, fn (array $w) => in_array($w['id'], $allowed, true)));
            $response = $this->json(['widgets' => $widgets, 'audience' => 'individual']);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');

            return $response;
        }

        $response = $this->json(['widgets' => self::WIDGET_CATALOG, 'audience' => 'company']);
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }

    /** @return list<array{id: string, position: int, visible: bool}> */
    private function buildIndividualDefaultConfig(): array
    {
        $widgets = [];
        foreach (self::INDIVIDUAL_WIDGET_IDS as $position => $id) {
            $widgets[] = ['id' => $id, 'position' => $position, 'visible' => true];
        }

        return $widgets;
    }

    private function buildDefaultConfig(): array
    {
        $widgets = [];
        foreach (self::WIDGET_CATALOG as $position => $entry) {
            $widgets[] = [
                'id' => $entry['id'],
                'position' => $position,
                'visible' => !in_array($entry['id'], self::NEW_WIDGET_IDS, true),
            ];
        }

        return $widgets;
    }

    private function validateWidgets(array $widgets, array $knownIds): ?string
    {
        foreach ($widgets as $index => $widget) {
            if (!isset($widget['id']) || !is_string($widget['id'])) {
                return sprintf('Widget at index %d is missing a string "id".', $index);
            }
            if (!in_array($widget['id'], $knownIds, true)) {
                return sprintf('Unknown widget id "%s".', $widget['id']);
            }
            if (!isset($widget['position']) || !is_int($widget['position']) || $widget['position'] < 0) {
                return sprintf('Widget "%s" must have an integer "position" >= 0.', $widget['id']);
            }
            if (!isset($widget['visible']) || !is_bool($widget['visible'])) {
                return sprintf('Widget "%s" must have a boolean "visible".', $widget['id']);
            }
        }

        return null;
    }
}
