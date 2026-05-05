<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc;

use Contenir\Maintenance\MaintenanceRepositoryInterface;

/**
 * Returns the merged config consumed by Module::getConfig().
 *
 * Kept separate so a Mezzio sibling adapter can later require the same array
 * without reaching into a Laminas MVC Module class.
 */
final class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'service_manager' => $this->getDependencies(),
            'maintenance'     => $this->getMaintenanceDefaults(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDependencies(): array
    {
        return [
            'factories' => [
                MaintenanceRepositoryInterface::class => Factory\FileRepositoryFactory::class,
                Listener\MaintenanceListener::class   => Factory\MaintenanceListenerFactory::class,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMaintenanceDefaults(): array
    {
        return [
            'file'          => null,
            'retry_after'   => 600,
            'bypass'        => null,
            'body_template' => '<!doctype html>'
                . '<html lang="en"><head><meta charset="utf-8">'
                . '<title>503 Service Unavailable</title></head>'
                . '<body><h1>Service Unavailable</h1><p>%s</p></body></html>',
        ];
    }
}
