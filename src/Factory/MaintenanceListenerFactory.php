<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Factory;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

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
            repository: $container->get(MaintenanceRepositoryInterface::class),
            retryAfter: (int) $maintenance['retry_after'],
            bodyTemplate: (string) $maintenance['body_template'],
            bypass: $bypass,
        );
    }
}
