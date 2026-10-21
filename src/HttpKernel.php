<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Maps an HTTP request onto the receiver.
 *
 * Keeping the routing here (instead of inside public/index.php) means the
 * whole request path - method check, routing, signature check, event claim -
 * can be tested without a web server.
 */
final class HttpKernel
{
    public function __construct(
        private readonly WebhookReceiver $receiver,
        private readonly string $route = '/webhooks/orders',
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     */
    public function handle(array $server, string $rawBody, array $headers = []): Response
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rtrim($path, '/') : '';

        if ($path === '' || $path === '/') {
            $path = '/';
        }

        if ($path !== $this->route) {
            return Response::json(404, ['status' => 'error', 'reason' => 'not_found']);
        }

        if ($method !== 'POST') {
            return Response::json(405, ['status' => 'error', 'reason' => 'method_not_allowed'])
                ->withHeader('Allow', 'POST');
        }

        if ($headers === []) {
            $headers = Headers::fromServer($server);
        }

        return $this->receiver->handle($rawBody, $headers);
    }
}
