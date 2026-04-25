<?php

namespace App\Tests\Unit;

use App\Controller\Api\V1\DashboardConfigController;
use App\Entity\Company;
use App\Entity\User;
use App\Entity\UserDashboardConfig;
use App\Repository\UserDashboardConfigRepository;
use App\Security\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class DashboardConfigControllerTest extends TestCase
{
    private UserDashboardConfigRepository&MockObject $configRepository;
    private OrganizationContext&MockObject $organizationContext;
    private EntityManagerInterface&MockObject $entityManager;
    private DashboardConfigController $controller;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(UserDashboardConfigRepository::class);
        $this->organizationContext = $this->createMock(OrganizationContext::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->company = $this->createMock(Company::class);
        $this->user = $this->createMock(User::class);

        // Build the controller and inject the token storage so getUser() works
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->user);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $this->controller = new DashboardConfigController(
            $this->configRepository,
            $this->organizationContext,
            $this->entityManager,
        );

        // Inject the container with the token storage so AbstractController::getUser() works
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn (string $id) => $id === 'security.token_storage');
        $container->method('get')->with('security.token_storage')->willReturn($tokenStorage);
        $this->controller->setContainer($container);
    }

    // -------------------------------------------------------------------------
    // Catalog endpoint
    // -------------------------------------------------------------------------

    public function testCatalogReturnsFifteenWidgets(): void
    {
        $response = $this->controller->catalog();
        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('widgets', $data);
        $this->assertCount(15, $data['widgets']);
    }

    public function testCatalogContainsExpectedIds(): void
    {
        $response = $this->controller->catalog();
        $data = json_decode($response->getContent(), true);
        $ids = array_column($data['widgets'], 'id');

        foreach ([
            'sales-card', 'expenses-card', 'client-balance-card', 'unpaid-card',
            'amounts-to-pay-card', 'activity-card', 'due-today-card', 'cash-balance-card',
            'recent-invoices-table', 'status-breakdown-chart', 'monthly-charts',
            'sync-status', 'top-clients-revenue', 'top-products-revenue', 'top-outstanding-clients',
        ] as $expectedId) {
            $this->assertContains($expectedId, $ids, "Catalog is missing widget: $expectedId");
        }
    }

    public function testCatalogSetsMaxAgeHeader(): void
    {
        $response = $this->controller->catalog();
        $this->assertTrue($response->headers->hasCacheControlDirective('max-age'));
        $this->assertSame(86400, $response->getMaxAge());
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    public function testCatalogWidgetsHaveRequiredFields(): void
    {
        $response = $this->controller->catalog();
        $data = json_decode($response->getContent(), true);

        foreach ($data['widgets'] as $widget) {
            $this->assertArrayHasKey('id', $widget);
            $this->assertArrayHasKey('name_key', $widget);
            $this->assertArrayHasKey('description_key', $widget);
            $this->assertArrayHasKey('size', $widget);
            $this->assertArrayHasKey('category', $widget);
            $this->assertContains($widget['size'], ['sm', 'md', 'lg', 'xl']);
        }
    }

    // -------------------------------------------------------------------------
    // GET /dashboard/config — default config when none saved
    // -------------------------------------------------------------------------

    public function testGetConfigReturnsDefaultWhenNoneSaved(): void
    {
        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn(null);

        $request = new Request();
        $response = $this->controller->getConfig($request);
        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('widgets', $data);
        $this->assertCount(15, $data['widgets']);
    }

    public function testDefaultConfigNewWidgetsAreHidden(): void
    {
        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn(null);

        $request = new Request();
        $response = $this->controller->getConfig($request);
        $data = json_decode($response->getContent(), true);

        $hiddenIds = ['top-clients-revenue', 'top-products-revenue', 'top-outstanding-clients'];
        foreach ($data['widgets'] as $widget) {
            if (in_array($widget['id'], $hiddenIds, true)) {
                $this->assertFalse($widget['visible'], "New widget {$widget['id']} should default to visible: false");
            } else {
                $this->assertTrue($widget['visible'], "Existing widget {$widget['id']} should default to visible: true");
            }
        }
    }

    public function testDefaultConfigPositionsMatchOrder(): void
    {
        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn(null);

        $request = new Request();
        $response = $this->controller->getConfig($request);
        $data = json_decode($response->getContent(), true);

        foreach ($data['widgets'] as $index => $widget) {
            $this->assertSame($index, $widget['position']);
        }
    }

    public function testGetConfigReturnsSavedConfig(): void
    {
        $savedWidgets = [
            ['id' => 'sales-card', 'position' => 0, 'visible' => true],
            ['id' => 'expenses-card', 'position' => 1, 'visible' => false],
        ];

        $config = new UserDashboardConfig();
        $config->setWidgets($savedWidgets);

        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn($config);

        $request = new Request();
        $response = $this->controller->getConfig($request);
        $data = json_decode($response->getContent(), true);

        $this->assertSame($savedWidgets, $data['widgets']);
    }

    public function testGetConfigSetsNoCacheHeader(): void
    {
        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn(null);

        $request = new Request();
        $response = $this->controller->getConfig($request);

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    // -------------------------------------------------------------------------
    // PUT /dashboard/config
    // -------------------------------------------------------------------------

    public function testPutConfigPersistsAndReturns(): void
    {
        $widgets = [
            ['id' => 'sales-card', 'position' => 0, 'visible' => true],
            ['id' => 'expenses-card', 'position' => 1, 'visible' => false],
        ];

        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn(null);
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $request = new Request(content: json_encode(['widgets' => $widgets]));
        $response = $this->controller->putConfig($request);
        $data = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($widgets, $data['widgets']);
    }

    public function testPutConfigUpdatesExistingRecord(): void
    {
        $existing = new UserDashboardConfig();
        $existing->setWidgets([['id' => 'sales-card', 'position' => 0, 'visible' => true]]);

        $newWidgets = [['id' => 'expenses-card', 'position' => 0, 'visible' => true]];

        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn($existing);
        // persist must NOT be called when updating
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $request = new Request(content: json_encode(['widgets' => $newWidgets]));
        $response = $this->controller->putConfig($request);
        $data = json_decode($response->getContent(), true);

        $this->assertSame($newWidgets, $data['widgets']);
    }

    public function testPutConfigRejectsUnknownWidgetId(): void
    {
        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn(null);

        $request = new Request(content: json_encode([
            'widgets' => [
                ['id' => 'totally-unknown-widget', 'position' => 0, 'visible' => true],
            ],
        ]));

        $response = $this->controller->putConfig($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('totally-unknown-widget', $data['error']);
    }

    public function testPutConfigRejectsNegativePosition(): void
    {
        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn(null);

        $request = new Request(content: json_encode([
            'widgets' => [
                ['id' => 'sales-card', 'position' => -1, 'visible' => true],
            ],
        ]));

        $response = $this->controller->putConfig($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPutConfigRejectsNonBoolVisible(): void
    {
        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn(null);

        $request = new Request(content: json_encode([
            'widgets' => [
                ['id' => 'sales-card', 'position' => 0, 'visible' => 'yes'],
            ],
        ]));

        $response = $this->controller->putConfig($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPutConfigRejectsMissingWidgetsKey(): void
    {
        $this->organizationContext->method('resolveCompany')->willReturn($this->company);

        $request = new Request(content: json_encode(['foo' => 'bar']));
        $response = $this->controller->putConfig($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPutConfigSetsCacheHeader(): void
    {
        $this->organizationContext->method('resolveCompany')->willReturn($this->company);
        $this->configRepository->method('findByUserAndCompany')->willReturn(null);
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $request = new Request(content: json_encode([
            'widgets' => [['id' => 'sales-card', 'position' => 0, 'visible' => true]],
        ]));

        $response = $this->controller->putConfig($request);

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    }
}
