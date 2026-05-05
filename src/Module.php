<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc;

use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;

/**
 * Laminas MVC entry point.
 *
 * On bootstrap, attaches the MaintenanceListener to MvcEvent::EVENT_DISPATCH
 * at high priority so the listener can short-circuit dispatch with a 503
 * before any controller runs.
 */
class Module
{
    public const DISPATCH_PRIORITY = 10000;

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return (new ConfigProvider())();
    }

    public function onBootstrap(MvcEvent $event): void
    {
        $application = $event->getApplication();
        $listener    = $application->getServiceManager()->get(MaintenanceListener::class);
        $this->attachListener($application->getEventManager(), $listener);
    }

    public function attachListener(EventManagerInterface $events, MaintenanceListener $listener): void
    {
        $events->attach(MvcEvent::EVENT_DISPATCH, $listener, self::DISPATCH_PRIORITY);
    }
}
