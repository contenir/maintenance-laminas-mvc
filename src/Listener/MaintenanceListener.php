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

        $response = new Response();
        $response->setStatusCode(503);
        $response->getHeaders()->addHeaderLine('Retry-After', (string) $this->retryAfter);
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/html; charset=utf-8');
        $response->setContent(sprintf($this->bodyTemplate, htmlspecialchars($state->message, ENT_QUOTES, 'UTF-8')));

        $event->setResponse($response);
        $event->stopPropagation(true);

        return $response;
    }
}
