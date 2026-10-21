<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Header helpers.
 *
 * Web servers expose request headers differently:
 *
 *   - Apache / nginx + PHP-FPM  -> $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
 *   - PHP built-in server        -> $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
 *   - Some SAPIs                 -> getallheaders()
 *
 * Normalising to "X-Webhook-Signature" style names means the rest of the code
 * does not have to care which one you are running under.
 */
final class Headers
{
    /**
     * Build a normalised header array from a $_SERVER array.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    public static function fromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string) $value;
            }
        }

        // CONTENT_TYPE and CONTENT_LENGTH are not prefixed with HTTP_.
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($server[$key]) && is_scalar($server[$key])) {
                $headers[$name] = (string) $server[$key];
            }
        }

        return $headers;
    }

    /**
     * Case-insensitive header lookup.
     *
     * @param array<string, mixed> $headers
     */
    public static function get(array $headers, string $name): ?string
    {
        $wanted = strtolower($name);

        foreach ($headers as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (strtolower($key) === $wanted) {
                return (string) $value;
            }
        }

        return null;
    }
}
