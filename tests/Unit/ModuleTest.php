<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit;

use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Contenir\Maintenance\Laminas\Mvc\Module;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\EventManager\EventManager;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ModuleTest extends TestCase
{
    public function testGetConfigReturnsConfigProviderArray(): void
    {
        $config = (new Module())->getConfig();

        self::assertArrayHasKey('service_manager', $config);
        // 'maintenance' is intentionally absent — see ConfigProvider::__invoke.
        self::assertArrayNotHasKey('maintenance', $config);
    }

    public function testAttachListenerRegistersListenerOnDispatchEvent(): void
    {
        $listener = new MaintenanceListener(new InMemoryRepository(MaintenanceState::active('m')));
        $events   = new EventManager();

        (new Module())->attachListener($events, $listener);

        $event = new MvcEvent();
        $event->setName(MvcEvent::EVENT_DISPATCH);
        $events->triggerEvent($event);

        self::assertSame(503, $event->getResponse()->getStatusCode());
    }

    public function testAttachListenerUsesHighPriorityOverDefault(): void
    {
        $listener = new MaintenanceListener(new InMemoryRepository(MaintenanceState::active('m')));
        $events   = new EventManager();

        $defaultRan = false;
        $events->attach(
            MvcEvent::EVENT_DISPATCH,
            static function () use (&$defaultRan): void {
                $defaultRan = true;
            },
            1
        );

        (new Module())->attachListener($events, $listener);

        $event = new MvcEvent();
        $event->setName(MvcEvent::EVENT_DISPATCH);
        $events->triggerEvent($event);

        self::assertFalse($defaultRan, 'Maintenance listener must stop propagation before default listeners run');
    }

    public function testDispatchPriorityConstantIsExposed(): void
    {
        self::assertSame(10000, Module::DISPATCH_PRIORITY);
    }

    public function testOnBootstrapResolvesListenerAndAttachesIt(): void
    {
        $listener = new MaintenanceListener(new InMemoryRepository(MaintenanceState::active('m')));
        $services = new ServiceManager(['services' => [MaintenanceListener::class => $listener]]);
        $events   = new EventManager();

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('getServiceManager')->willReturn($services);
        $application->method('getEventManager')->willReturn($events);

        $bootstrapEvent = new MvcEvent();
        $bootstrapEvent->setApplication($application);

        (new Module())->onBootstrap($bootstrapEvent);

        $dispatchEvent = new MvcEvent();
        $dispatchEvent->setName(MvcEvent::EVENT_DISPATCH);
        $events->triggerEvent($dispatchEvent);

        self::assertSame(503, $dispatchEvent->getResponse()->getStatusCode());
    }
}
