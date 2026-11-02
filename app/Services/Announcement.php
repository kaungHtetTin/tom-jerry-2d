<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Api;
use Mini\Database as DB;

final class Announcement
{
    public static function get(): array
    {
        $row = DB::fetch('SELECT message FROM lottery_announcement WHERE id = 1');
        return ['message' => $row['message'] ?? ''];
    }

    public static function save(array $data): array
    {
        $message = $data['message'] ?? null;
        if (!is_string($message) || mb_strlen($message) > 1000) {
            Api::invalid('message', 'ကြေညာချက်ကို စာလုံးရေ ၁၀၀၀ အတွင်း ရေးပေးပါ။');
        }
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
        DB::statement('INSERT INTO lottery_announcement (id, message, updated_at) VALUES (1, ?, ?)
            ON DUPLICATE KEY UPDATE message = VALUES(message), updated_at = VALUES(updated_at)',
            [$message, Lottery::now()->format('Y-m-d H:i:s')]);
        return ['message' => $message];
    }
}
