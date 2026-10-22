<?php

declare(strict_types=1);

namespace App\Controllers;

use Mini\Controller;
use Mini\Csrf;

final class ApiController extends Controller
{
    public function health(): never
    {
        json_response([
            'status' => 'ok',
            'app' => config('app.name'),
            'serverTime' => date(DATE_ATOM),
        ]);
    }

    public function session(): never
    {
        json_response(['csrfToken' => Csrf::token()]);
    }

    public function welcome(): never
    {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            json_response(['message' => 'Content-Type must be application/json.'], 415);
        }

        try {
            $data = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            json_response(['message' => 'Invalid JSON body.'], 400);
        }
        if (!is_object($data)) {
            json_response(['message' => 'JSON body must be an object.'], 400);
        }

        $name = $data->name ?? null;
        if (!is_string($name) || trim($name) === '' || preg_match_all('/./us', trim($name)) > 80) {
            json_response([
                'message' => 'Validation failed.',
                'errors' => ['name' => 'နာမည်ကို စာလုံး ၁ လုံးမှ ၈၀ အတွင်း ရေးထည့်ပေးပါ။'],
            ], 422);
        }

        json_response(['message' => 'မင်္ဂလာပါ၊ ' . trim($name) . '။ PHP backend နဲ့ ချိတ်ဆက်မှု အောင်မြင်ပါတယ်။']);
    }
}
