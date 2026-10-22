<?php

declare(strict_types=1);

namespace App\Support;

final class Api
{
    public static function body(): array
    {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            json_response(['message' => 'JSON request လိုအပ်ပါသည်။'], 415);
        }
        try {
            $object = json_decode(file_get_contents('php://input'), false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            json_response(['message' => 'Request data မမှန်ပါ။'], 400);
        }
        if (!is_object($object)) {
            json_response(['message' => 'Request data မမှန်ပါ။'], 400);
        }
        return (array) $object;
    }

    public static function invalid(string $field, string $message): never
    {
        json_response(['message' => $message, 'errors' => [$field => $message]], 422);
    }

    public static function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            self::invalid('date', 'ရက်စွဲ မှန်ကန်စွာ ရွေးပေးပါ။');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '2000-01-01' || $value > '2100-12-31') {
            self::invalid('date', 'ရက်စွဲ မှန်ကန်စွာ ရွေးပေးပါ။');
        }
        return $value;
    }

    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value) || (int) $value < 1) {
            self::invalid('id', 'ရွေးချယ်ထားသည့် အချက်အလက် မမှန်ပါ။');
        }
        return (int) $value;
    }
}
