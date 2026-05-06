<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit\Factory;

use Contenir\Maintenance\Laminas\Mvc\Factory\MaintenanceListenerFactory;
use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Contenir\Maintenance\Laminas\Mvc\Tests\Unit\Factory\Stub\ArrayContainer;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\Mvc\MvcEvent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
#[Group('factory')]
final class MaintenanceListenerFactoryTest extends TestCase
{
    private function container(array $maintenanceConfig, ?MaintenanceRepositoryInterface $repo = null): ArrayContainer
    {
        return new ArrayContainer([
            'config' => ['maintenance' => $maintenanceConfig],
            MaintenanceRepositoryInterface::class => $repo ?? new InMemoryRepository(),
        ]);
    }

    public function testBuildsListenerWithRepositoryFromContainer(): void
    {
        $repo      = new InMemoryRepository(MaintenanceState::active('m'));
        $container = $this->container([], $repo);

        $listener = (new MaintenanceListenerFactory())($container);

        self::assertInstanceOf(MaintenanceListener::class, $listener);
    }

    public function testFallsBackToDefaultsWhenConfigOmitsKeys(): void
    {
        $container = $this->container([], new InMemoryRepository(MaintenanceState::active('m')));

        $listener = (new MaintenanceListenerFactory())($container);

        $response = $listener(new MvcEvent());
        self::assertSame(600, $response->getHeaders()->get('Retry-After')->getFieldValue());
    }

    public function testAppliesConfiguredRetryAfter(): void
    {
        $container = $this->container(
            ['retry_after' => 1234],
            new InMemoryRepository(MaintenanceState::active('m')),
        );

        $listener = (new MaintenanceListenerFactory())($container);

        $response = $listener(new MvcEvent());
        self::assertSame(1234, $response->getHeaders()->get('Retry-After')->getFieldValue());
    }

    public function testAppliesConfiguredBypass(): void
    {
        $container = $this->container(
            ['bypass' => static fn (MvcEvent $e): bool => true],
            new InMemoryRepository(MaintenanceState::active('m')),
        );

        $listener = (new MaintenanceListenerFactory())($container);

        self::assertNull($listener(new MvcEvent()));
    }

    public function testAppliesConfiguredBodyTemplate(): void
    {
        $container = $this->container(
            ['body_template' => 'MAINT: %s'],
            new InMemoryRepository(MaintenanceState::active('down')),
        );

        $listener = (new MaintenanceListenerFactory())($container);

        $response = $listener(new MvcEvent());
        self::assertSame('MAINT: down', $response->getContent());
    }

    public function testThrowsWhenBypassIsNotCallable(): void
    {
        $container = $this->container(['bypass' => 'not a callable string xyz']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('config[maintenance][bypass] must be callable');

        (new MaintenanceListenerFactory())($container);
    }

    public function testWorksWhenContainerHasNoConfig(): void
    {
        $container = new ArrayContainer([
            MaintenanceRepositoryInterface::class => new InMemoryRepository(),
        ]);

        $listener = (new MaintenanceListenerFactory())($container);

        self::assertInstanceOf(MaintenanceListener::class, $listener);
    }

    public function testBuildsInMemoryRepositoryFromConfigStateWhenNoServiceRegistered(): void
    {
        // Site doesn't register MaintenanceRepositoryInterface — factory falls
        // back to building one from the merged config (Laminas auto-merges
        // config/autoload/maintenance.local.php into $config['maintenance']['state']).
        $container = new ArrayContainer([
            'config' => [
                'maintenance' => [
                    'state' => [
                        'active'  => true,
                        'message' => 'Down for upgrade',
                        'since'   => '2026-01-02T03:04:05+00:00',
                    ],
                ],
            ],
        ]);

        $listener = (new MaintenanceListenerFactory())($container);
        $response = $listener(new MvcEvent());

        self::assertNotNull($response, 'Active state should produce a 503 response.');
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('Down for upgrade', $response->getContent());
    }

    public function testFallbackRepositoryReturnsInactiveWhenNoStateConfigured(): void
    {
        $container = new ArrayContainer([
            'config' => ['maintenance' => []],
        ]);

        $listener = (new MaintenanceListenerFactory())($container);
        $response = $listener(new MvcEvent());

        self::assertNull($response, 'Inactive state should not intercept dispatch.');
    }

    public function testFallbackRepositoryTreatsExplicitInactiveAsNoIntercept(): void
    {
        $container = new ArrayContainer([
            'config' => [
                'maintenance' => [
                    'state' => ['active' => false, 'message' => 'lingering'],
                ],
            ],
        ]);

        $listener = (new MaintenanceListenerFactory())($container);
        $response = $listener(new MvcEvent());

        self::assertNull($response);
    }

    public function testFallbackRepositoryTreatsBadSinceAsNull(): void
    {
        $container = new ArrayContainer([
            'config' => [
                'maintenance' => [
                    'state' => [
                        'active'  => true,
                        'message' => 'm',
                        'since'   => 'not-a-date',
                    ],
                ],
            ],
        ]);

        // Should still build (just without parsing 'since') — no exception.
        $listener = (new MaintenanceListenerFactory())($container);
        $response = $listener(new MvcEvent());

        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
    }
}
