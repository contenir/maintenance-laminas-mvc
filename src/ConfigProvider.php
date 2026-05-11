<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc;

/**
 * Returns the merged config consumed by Module::getConfig().
 *
 * Kept separate so a Mezzio sibling adapter can later require the same array
 * without reaching into a Laminas MVC Module class.
 */
final class ConfigProvider
{
    public const DEFAULT_BODY_TEMPLATE = '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<title>503 Service Unavailable</title></head>'
        . '<body><h1>Service Unavailable</h1><p>%s</p></body></html>';

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
                Listener\MaintenanceListener::class => Factory\MaintenanceListenerFactory::class,
            ],
        ];
    }

    /**
     * Absolute path to the package's bundled default 503 body template.
     *
     * Loaded by the factory via include + output-buffering at config-load,
     * so PHP inside the file is evaluated once per boot. Consumers override
     * by setting `maintenance.body_template_path` (any extension) or
     * `maintenance.body_template` (inline string) in their own config.
     */
    public static function defaultBodyTemplatePath(): string
    {
        return __DIR__ . '/../view/contenir/maintenance/index.phtml';
    }

    /**
     * @return array<string, mixed>
     */
    public function getMaintenanceDefaults(): array
    {
        return [
            'retry_after'        => 600,
            'bypass'             => null,
            'body_template'      => self::DEFAULT_BODY_TEMPLATE,
            'body_template_path' => self::defaultBodyTemplatePath(),
        ];
    }
}
