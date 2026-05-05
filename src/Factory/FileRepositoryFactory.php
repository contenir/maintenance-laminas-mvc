<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Factory;

use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\Repository\FileRepository;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Builds a FileRepository pointed at config['maintenance']['file'].
 *
 * The file path must be configured by the consuming Site — there's no sensible
 * default, since the path is the contract between the admin (writer) and the
 * Site (reader).
 */
final class FileRepositoryFactory
{
    public function __invoke(ContainerInterface $container): MaintenanceRepositoryInterface
    {
        $config = $container->has('config') ? $container->get('config') : [];
        $path   = $config['maintenance']['file'] ?? null;

        if (! is_string($path) || $path === '') {
            throw new RuntimeException(
                'contenir/maintenance-laminas-mvc: config[maintenance][file] must be a non-empty string'
                . ' pointing at the shared maintenance state file.'
            );
        }

        return new FileRepository($path);
    }
}
