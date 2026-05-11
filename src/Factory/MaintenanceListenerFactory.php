<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Factory;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

final class MaintenanceListenerFactory
{
    public function __invoke(ContainerInterface $container): MaintenanceListener
    {
        $config      = $container->has('config') ? $container->get('config') : [];
        $userConfig  = $config['maintenance'] ?? [];
        $defaults    = (new ConfigProvider())->getMaintenanceDefaults();
        $maintenance = $userConfig + $defaults;

        $bypass = $maintenance['bypass'];
        if ($bypass !== null && ! is_callable($bypass)) {
            throw new RuntimeException(
                'contenir/maintenance-laminas-mvc: config[maintenance][bypass] must be callable or null.'
            );
        }

        return new MaintenanceListener(
            repository: $this->resolveRepository($container, $maintenance),
            retryAfter: (int) $maintenance['retry_after'],
            bodyTemplate: $this->resolveBodyTemplate($userConfig, $maintenance),
            bypass: $bypass,
        );
    }

    /**
     * Resolve the sprintf body template the listener feeds the 503 response.
     *
     * Precedence:
     *   1. An explicit `body_template` in user config wins (legacy inline-string
     *      contract, untouched).
     *   2. Otherwise `body_template_path` is loaded — user-set or the package
     *      default (bundled view/contenir/maintenance/index.phtml). For .phtml
     *      and .php paths the file is `include`d under output buffering inside
     *      an isolated closure, so PHP runs once at config-load time without
     *      leaking factory-scope variables; for any other extension the file
     *      contents are read raw via file_get_contents.
     *   3. Final fallback is the inline default body_template string.
     *
     * Either way the result is a sprintf template — exactly one %s for the
     * admin message, no other unescaped percent signs.
     *
     * @param array<string, mixed> $userConfig
     * @param array<string, mixed> $maintenance
     */
    private function resolveBodyTemplate(array $userConfig, array $maintenance): string
    {
        if (array_key_exists('body_template', $userConfig)) {
            return (string) $userConfig['body_template'];
        }

        $path = $maintenance['body_template_path'] ?? null;
        if (is_string($path) && $path !== '') {
            return self::loadBodyTemplateFromPath($path);
        }

        return (string) $maintenance['body_template'];
    }

    private static function loadBodyTemplateFromPath(string $path): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException(sprintf(
                'contenir/maintenance-laminas-mvc: body_template_path "%s" is not readable.',
                $path,
            ));
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'phtml' || $extension === 'php') {
            return (static function () use ($path): string {
                ob_start();
                include $path;
                return (string) ob_get_clean();
            })();
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(sprintf(
                'contenir/maintenance-laminas-mvc: failed reading body_template_path "%s".',
                $path,
            ));
        }
        return $content;
    }

    /**
     * Build an in-memory repository from `config[maintenance][state]` (the
     * merged Laminas config). The data file written by admin lives in
     * config/autoload/*.local.php and is auto-merged into `$config` at boot,
     * so there's no need to re-read it from disk on every request.
     *
     * If a service is registered for MaintenanceRepositoryInterface, that wins.
     *
     * @param array<string, mixed> $maintenance
     */
    private function resolveRepository(
        ContainerInterface $container,
        array $maintenance,
    ): MaintenanceRepositoryInterface {
        if ($container->has(MaintenanceRepositoryInterface::class)) {
            return $container->get(MaintenanceRepositoryInterface::class);
        }

        return new InMemoryRepository(self::stateFromConfig($maintenance['state'] ?? null));
    }

    private static function stateFromConfig(mixed $stateData): ?MaintenanceState
    {
        if (! is_array($stateData)) {
            return null;
        }

        $active = (bool) ($stateData['active'] ?? false);
        if (! $active) {
            return MaintenanceState::inactive();
        }

        return new MaintenanceState(
            true,
            (string) ($stateData['message'] ?? ''),
            self::parseSince($stateData['since'] ?? null),
        );
    }

    private static function parseSince(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
