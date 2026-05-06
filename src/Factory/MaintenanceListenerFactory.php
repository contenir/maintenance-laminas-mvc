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
        $defaults    = (new ConfigProvider())->getMaintenanceDefaults();
        $maintenance = ($config['maintenance'] ?? []) + $defaults;

        $bypass = $maintenance['bypass'];
        if ($bypass !== null && ! is_callable($bypass)) {
            throw new RuntimeException(
                'contenir/maintenance-laminas-mvc: config[maintenance][bypass] must be callable or null.'
            );
        }

        return new MaintenanceListener(
            repository: $this->resolveRepository($container, $maintenance),
            retryAfter: (int) $maintenance['retry_after'],
            bodyTemplate: (string) $maintenance['body_template'],
            bypass: $bypass,
        );
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
