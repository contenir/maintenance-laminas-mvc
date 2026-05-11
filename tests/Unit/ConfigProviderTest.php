<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use Contenir\Maintenance\Laminas\Mvc\Factory\MaintenanceListenerFactory;
use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    public function testInvokeReturnsServiceManagerKey(): void
    {
        $config = (new ConfigProvider())();

        self::assertArrayHasKey('service_manager', $config);
    }

    public function testInvokeDoesNotContributeMaintenanceKeyToMergedConfig(): void
    {
        // The factory is the canonical source of maintenance defaults so it
        // can tell user-supplied body_template/body_template_path values
        // apart from package defaults after Laminas merge. If this method
        // ever starts returning a 'maintenance' key, the factory's
        // precedence logic breaks (body_template appears always set even
        // when the site only set body_template_path).
        $config = (new ConfigProvider())();

        self::assertArrayNotHasKey('maintenance', $config);
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

        self::assertArrayHasKey('retry_after', $defaults);
        self::assertArrayHasKey('bypass', $defaults);
        self::assertArrayHasKey('body_template', $defaults);
        self::assertArrayHasKey('body_template_path', $defaults);
    }

    public function testMaintenanceDefaultsAreUnconfigured(): void
    {
        $defaults = (new ConfigProvider())->getMaintenanceDefaults();

        self::assertNull($defaults['bypass']);
        self::assertSame(600, $defaults['retry_after']);
    }

    public function testDefaultBodyTemplatePathPointsAtBundledFile(): void
    {
        $path = ConfigProvider::defaultBodyTemplatePath();

        self::assertFileExists($path);
        self::assertStringEndsWith('view/contenir/maintenance/index.phtml', $path);
    }

    public function testBundledBodyTemplateContainsExactlyOneSprintfPlaceholder(): void
    {
        // Mirror the factory's include + output-buffering load so PHP comments
        // (which legitimately mention `%s` in docs) are excluded — only `%s`
        // tokens in the rendered HTML count.
        $path     = ConfigProvider::defaultBodyTemplatePath();
        $rendered = (static function () use ($path): string {
            ob_start();
            include $path;
            return (string) ob_get_clean();
        })();

        self::assertSame(
            1,
            substr_count($rendered, '%s'),
            'rendered bundled template must have exactly one %s placeholder',
        );
    }
}
