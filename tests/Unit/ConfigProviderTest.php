<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use Contenir\Maintenance\Laminas\Mvc\Factory\FileRepositoryFactory;
use Contenir\Maintenance\Laminas\Mvc\Factory\MaintenanceListenerFactory;
use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    public function testInvokeReturnsServiceManagerAndMaintenanceKeys(): void
    {
        $config = (new ConfigProvider())();

        self::assertArrayHasKey('service_manager', $config);
        self::assertArrayHasKey('maintenance', $config);
    }

    public function testRegistersRepositoryFactory(): void
    {
        $deps = (new ConfigProvider())->getDependencies();

        self::assertSame(
            FileRepositoryFactory::class,
            $deps['factories'][MaintenanceRepositoryInterface::class]
        );
    }

    public function testRegistersListenerFactory(): void
    {
        $deps = (new ConfigProvider())->getDependencies();

        self::assertSame(
            MaintenanceListenerFactory::class,
            $deps['factories'][MaintenanceListener::class]
        );
    }

    public function testMaintenanceDefaultsHaveExpectedKeys(): void
    {
        $defaults = (new ConfigProvider())->getMaintenanceDefaults();

        self::assertArrayHasKey('file', $defaults);
        self::assertArrayHasKey('retry_after', $defaults);
        self::assertArrayHasKey('bypass', $defaults);
        self::assertArrayHasKey('body_template', $defaults);
    }

    public function testMaintenanceDefaultsAreUnconfigured(): void
    {
        $defaults = (new ConfigProvider())->getMaintenanceDefaults();

        self::assertNull($defaults['file']);
        self::assertNull($defaults['bypass']);
        self::assertSame(600, $defaults['retry_after']);
    }
}
