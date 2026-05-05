<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit\Listener;

use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\Http\Response;
use Laminas\Mvc\MvcEvent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('listener')]
final class MaintenanceListenerTest extends TestCase
{
    public function testReturnsNullWhenStateIsInactive(): void
    {
        $listener = new MaintenanceListener(new InMemoryRepository(MaintenanceState::inactive()));
        $event    = new MvcEvent();

        $result = $listener($event);

        self::assertNull($result);
        self::assertNull($event->getResponse());
        self::assertFalse($event->propagationIsStopped());
    }

    public function testReturnsNullWhenBypassReturnsTrue(): void
    {
        $repo     = new InMemoryRepository(MaintenanceState::active('Down'));
        $listener = new MaintenanceListener(
            repository: $repo,
            bypass: static fn (MvcEvent $e): bool => true,
        );
        $event = new MvcEvent();

        $result = $listener($event);

        self::assertNull($result);
        self::assertFalse($event->propagationIsStopped());
    }

    public function testProceedsToShortCircuitWhenBypassReturnsFalse(): void
    {
        $repo     = new InMemoryRepository(MaintenanceState::active('Down'));
        $listener = new MaintenanceListener(
            repository: $repo,
            bypass: static fn (MvcEvent $e): bool => false,
        );
        $event = new MvcEvent();

        $result = $listener($event);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(503, $result->getStatusCode());
    }

    public function testReturns503ResponseWhenStateIsActive(): void
    {
        $repo     = new InMemoryRepository(MaintenanceState::active('Back at 5pm'));
        $listener = new MaintenanceListener($repo);
        $event    = new MvcEvent();

        $response = $listener($event);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame($response, $event->getResponse());
        self::assertTrue($event->propagationIsStopped());
    }

    public function testSetsRetryAfterHeader(): void
    {
        $listener = new MaintenanceListener(
            repository: new InMemoryRepository(MaintenanceState::active('m')),
            retryAfter: 1800,
        );

        $response = $listener(new MvcEvent());

        self::assertSame(1800, $response->getHeaders()->get('Retry-After')->getFieldValue());
    }

    public function testSetsHtmlContentType(): void
    {
        $listener = new MaintenanceListener(new InMemoryRepository(MaintenanceState::active('m')));

        $response = $listener(new MvcEvent());

        self::assertSame(
            'text/html; charset=utf-8',
            $response->getHeaders()->get('Content-Type')->getFieldValue()
        );
    }

    public function testRendersMessageInBodyTemplate(): void
    {
        $listener = new MaintenanceListener(
            repository: new InMemoryRepository(MaintenanceState::active('Back soon')),
            bodyTemplate: '<p>%s</p>',
        );

        $response = $listener(new MvcEvent());

        self::assertSame('<p>Back soon</p>', $response->getContent());
    }

    public function testEscapesMessageHtml(): void
    {
        $listener = new MaintenanceListener(
            repository: new InMemoryRepository(MaintenanceState::active('<script>x</script>')),
            bodyTemplate: '<p>%s</p>',
        );

        $response = $listener(new MvcEvent());

        self::assertStringNotContainsString('<script>', $response->getContent());
        self::assertStringContainsString('&lt;script&gt;', $response->getContent());
    }

    public function testPassesEventToBypassCallable(): void
    {
        $received = null;
        $event    = new MvcEvent();
        $listener = new MaintenanceListener(
            repository: new InMemoryRepository(MaintenanceState::active('m')),
            bypass: static function (MvcEvent $e) use (&$received): bool {
                $received = $e;
                return false;
            },
        );

        $listener($event);

        self::assertSame($event, $received);
    }
}
