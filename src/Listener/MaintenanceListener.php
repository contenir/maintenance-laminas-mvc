<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Listener;

use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Laminas\Http\Response;
use Laminas\Mvc\MvcEvent;

/**
 * Short-circuits dispatch with a 503 response when maintenance mode is active.
 *
 * Resolution order:
 *   1. Repository reports inactive → return null, request continues.
 *   2. Bypass callable returns true → return null, request continues.
 *   3. Otherwise → build a 503 Response with Retry-After header and the
 *      configured body template (sprintf-style, single %s for message),
 *      attach it to the event, and stop propagation.
 */
final class MaintenanceListener
{
    public const DEFAULT_BODY_TEMPLATE
        = '<!doctype html><title>503</title><h1>Service Unavailable</h1><p>%s</p>';

    /**
     * Event name used to signal page-cache opt-out. Matches the
     * EVENT_DISABLE constant published by contenir/cache-laminas-mvc.
     * Hardcoded here so this package doesn't take a hard dependency on
     * the cache adapter; if cache-laminas-mvc isn't installed, firing
     * the event is a harmless no-op.
     */
    private const PAGECACHE_DISABLE_EVENT = 'pagecache.disable';

    /**
     * @param (callable(MvcEvent): bool)|null $bypass
     */
    public function __construct(
        private readonly MaintenanceRepositoryInterface $repository,
        private readonly int $retryAfter = 600,
        private readonly string $bodyTemplate = self::DEFAULT_BODY_TEMPLATE,
        private $bypass = null,
    ) {
    }

    public function __invoke(MvcEvent $event): ?Response
    {
        $state = $this->repository->get();

        if (! $state->active) {
            return null;
        }

        if ($this->bypass !== null && ($this->bypass)($event) === true) {
            return null;
        }

        $this->disablePageCache($event);

        $response = new Response();
        $response->setStatusCode(503);
        $response->getHeaders()->addHeaderLine('Retry-After', (string) $this->retryAfter);
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/html; charset=utf-8');
        $response->setContent(sprintf($this->bodyTemplate, htmlspecialchars($state->message, ENT_QUOTES, 'UTF-8')));

        $event->setResponse($response);
        $event->stopPropagation(true);

        return $response;
    }

    /**
     * Tell any listening page-cache that this 503 must not be stored.
     * cache-laminas-mvc's CacheStrategy listens to this event on the
     * same identifier(s) it uses for dispatch/finish; on receipt it
     * flips its disabled flag and onFinish skips storage.
     */
    private function disablePageCache(MvcEvent $event): void
    {
        $event->getApplication()?->getEventManager()->trigger(self::PAGECACHE_DISABLE_EVENT);
    }
}
