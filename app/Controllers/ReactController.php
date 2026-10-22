<?php

declare(strict_types=1);

namespace App\Controllers;

use Mini\Controller;

final class ReactController extends Controller
{
    public function index(): void
    {
        $entry = BASE_PATH . '/public/build/index.html';
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');

        if (!is_file($entry)) {
            http_response_code(503);
            header('Retry-After: 60');
            echo '<!doctype html><html lang="en"><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Frontend setup</title><h1>React frontend needs a build</h1>'
                . '<p>Run <code>npm ci</code> then <code>npm run build</code> in the project directory.</p>'
                . '<p>For development, run <code>npm run dev</code> and open '
                . '<a href="http://127.0.0.1:5173">http://127.0.0.1:5173</a>.</p></html>';
            return;
        }

        // The same bundle works at a domain root or /2d-tj/public, including deep links.
        $appBase = rtrim(parse_url(url(), PHP_URL_PATH) ?: '', '/') . '/';
        $html = file_get_contents($entry);
        if ($html === false) {
            throw new \RuntimeException('Unable to read the frontend build.');
        }
        echo str_replace('<head>', '<head><base href="' . e(url('build/')) . '">'
            . '<meta name="app-base" content="' . e($appBase) . '">', $html);
    }
}
