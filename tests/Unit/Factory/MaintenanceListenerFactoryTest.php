<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit\Factory;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
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
    /**
     * @var list<string> Temporary file paths written by tests; removed in tearDown.
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function writeTempTemplate(string $extension, string $contents): string
    {
        $path = sys_get_temp_dir() . '/contenir-maint-' . bin2hex(random_bytes(8)) . '.' . $extension;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

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

    public function testLoadsBodyTemplateFromRawHtmlPath(): void
    {
        $path = $this->writeTempTemplate('html', '<p>HTML wrapper: %s</p>');

        $container = $this->container(
            ['body_template_path' => $path],
            new InMemoryRepository(MaintenanceState::active('down for upgrade')),
        );

        $listener = (new MaintenanceListenerFactory())($container);
        $response = $listener(new MvcEvent());

        self::assertSame('<p>HTML wrapper: down for upgrade</p>', $response->getContent());
    }

    public function testEvaluatesPhpInsideBodyTemplatePhtmlPath(): void
    {
        // Demonstrates that .phtml is `include`d with output buffering rather
        // than read raw — the PHP expression is evaluated at config-load time.
        $path = $this->writeTempTemplate('phtml', '<p><?= strtoupper("hello") ?>: %s</p>');

        $container = $this->container(
            ['body_template_path' => $path],
            new InMemoryRepository(MaintenanceState::active('soon')),
        );

        $listener = (new MaintenanceListenerFactory())($container);
        $response = $listener(new MvcEvent());

        self::assertSame('<p>HELLO: soon</p>', $response->getContent());
    }

    public function testExplicitBodyTemplateOverridesBodyTemplatePath(): void
    {
        $path = $this->writeTempTemplate('html', 'NEVER USED: %s');

        $container = $this->container(
            [
                'body_template'      => 'INLINE: %s',
                'body_template_path' => $path,
            ],
            new InMemoryRepository(MaintenanceState::active('msg')),
        );

        $listener = (new MaintenanceListenerFactory())($container);
        $response = $listener(new MvcEvent());

        self::assertSame('INLINE: msg', $response->getContent());
    }

    public function testThrowsWhenBodyTemplatePathIsUnreadable(): void
    {
        $container = $this->container(
            ['body_template_path' => '/this/path/does/not/exist.phtml'],
            new InMemoryRepository(MaintenanceState::active('m')),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('body_template_path');

        (new MaintenanceListenerFactory())($container);
    }

    public function testDefaultBodyTemplatePathRendersBundledTemplate(): void
    {
        // No config at all — factory should load the bundled
        // view/contenir/maintenance/index.phtml via the default
        // body_template_path and sprintf the admin message in.
        $container = new ArrayContainer([
            MaintenanceRepositoryInterface::class
                => new InMemoryRepository(MaintenanceState::active('Down for upgrade')),
        ]);

        $listener = (new MaintenanceListenerFactory())($container);
        $response = $listener(new MvcEvent());

        $body = $response->getContent();
        self::assertStringContainsString('Down for upgrade', $body, 'admin message reaches body');
        self::assertStringContainsString('<!doctype html>', $body, 'bundled doc is rendered');
        self::assertStringContainsString('maintenance__title', $body, 'bundled CSS class is present');
    }

    public function testFallsBackToInlineBodyTemplateWhenPathIsExplicitlyNull(): void
    {
        $container = $this->container(
            ['body_template_path' => null],
            new InMemoryRepository(MaintenanceState::active('msg')),
        );

        $listener = (new MaintenanceListenerFactory())($container);
        $response = $listener(new MvcEvent());

        self::assertStringContainsString('msg', $response->getContent());
        self::assertStringContainsString('Service Unavailable', $response->getContent());
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
