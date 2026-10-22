<?php

declare(strict_types=1);

namespace Mini;

final class Cors
{
    public static function handle(string $uri, string $method): bool
    {
        if ($uri !== '/api' && !str_starts_with($uri, '/api/')) {
            return false;
        }

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Content-Type, X-CSRF-Token');
        header('Access-Control-Max-Age: 600');

        // Preflight requests do not require authentication or a CSRF token.
        if ($method === 'OPTIONS') {
            http_response_code(204);
            return true;
        }

        return false;
    }
}
