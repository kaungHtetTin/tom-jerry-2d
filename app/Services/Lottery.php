<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Api;
use DateTimeImmutable;
use DateTimeZone;
use Mini\Database as DB;

final class Lottery
{
    public static function now(): DateTimeImmutable
    {
        $clock = env('APP_ENV') === 'testing' ? env('LOTTERY_TEST_NOW', 'now') : 'now';
        return new DateTimeImmutable((string) $clock, new DateTimeZone((string) config('app.timezone', 'Asia/Yangon')));
    }

    public static function schedules(bool $all = false): array
    {
        return array_map(self::scheduleData(...), DB::fetchAll('SELECT * FROM lottery_schedules WHERE deleted_at IS NULL'
            . ($all ? '' : ' AND active = 1') . ' ORDER BY draw_time'));
    }

    public static function scheduleData(array $row): array
    {
        return ['id' => (int) $row['id'], 'time' => substr($row['draw_time'], 0, 5), 'label' => $row['label'], 'active' => (bool) $row['active']];
    }

    public static function resultData(array $row): array
    {
        return ['id' => (int) $row['id'], 'scheduleId' => (int) $row['schedule_id'], 'date' => $row['draw_date'],
            'time' => substr($row['draw_time'], 0, 5), 'label' => $row['draw_label'], 'number' => $row['number'],
            'publishedAt' => str_replace(' ', 'T', $row['published_at']) . '+06:30',
            'updatedAt' => str_replace(' ', 'T', $row['updated_at']) . '+06:30'];
    }

    public static function results(string $date): array
    {
        $now = self::now()->format('Y-m-d H:i:s');
        return array_map(self::resultData(...), DB::fetchAll(
            'SELECT * FROM lottery_results WHERE draw_date = ? AND TIMESTAMP(draw_date, draw_time) <= ? AND published_at <= ? ORDER BY draw_time, id',
            [$date, $now, $now]));
    }

    public static function day(string $date): array
    {
        $now = self::now();
        $today = $now->format('Y-m-d');
        $results = self::results($date);
        $schedules = $date === $today ? self::schedules() : [];
        // Preserve completed occurrences even when their schedule is edited or removed.
        foreach ($results as $result) {
            $schedules = array_values(array_filter($schedules, fn ($item) => $item['id'] !== $result['scheduleId'] && $item['time'] !== $result['time']));
            $schedules[] = ['id' => $result['scheduleId'], 'time' => $result['time'], 'label' => $result['label'], 'active' => true];
        }
        usort($schedules, fn ($a, $b) => strcmp($a['time'], $b['time']));
        $pending = array_values(array_filter($schedules, fn ($item) => !in_array($item['id'], array_column($results, 'scheduleId'), true)));
        $next = $date === $today ? ($pending[0] ?? null) : null;
        return ['date' => $date, 'today' => $today, 'serverNow' => $now->format(DATE_ATOM), 'timezone' => 'Asia/Yangon',
            'results' => $results, 'schedules' => $schedules, 'latest' => $results ? $results[array_key_last($results)] : null,
            'next' => $next, 'pollSeconds' => (int) config('lottery.poll_seconds', 3)];
    }

    public static function calendar(string $month): array
    {
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month)) {
            Api::invalid('month', 'လနှင့် နှစ်ကို မှန်ကန်စွာ ရွေးပါ။');
        }
        $start = $month . '-01';
        $end = (new DateTimeImmutable($start))->modify('first day of next month')->format('Y-m-d');
        $now = self::now()->format('Y-m-d H:i:s');
        $rows = DB::fetchAll('SELECT draw_date, COUNT(*) AS count FROM lottery_results WHERE draw_date >= ? AND draw_date < ?
            AND TIMESTAMP(draw_date, draw_time) <= ? AND published_at <= ? GROUP BY draw_date', [$start, $end, $now, $now]);
        return ['month' => $month, 'days' => array_map(fn ($row) => ['date' => $row['draw_date'], 'count' => (int) $row['count']], $rows)];
    }

    public static function history(?string $before): array
    {
        $now = self::now()->format('Y-m-d H:i:s');
        $where = $before ? ' AND draw_date < ?' : '';
        $bindings = [$now, $now];
        if ($before) $bindings[] = $before;
        $dates = DB::fetchAll('SELECT DISTINCT draw_date FROM lottery_results WHERE TIMESTAMP(draw_date, draw_time) <= ? AND published_at <= ?'
            . $where . ' ORDER BY draw_date DESC LIMIT 15', $bindings);
        $hasMore = count($dates) > 14;
        $dates = array_slice($dates, 0, 14);
        return ['days' => array_map(fn ($row) => ['date' => $row['draw_date'], 'results' => self::results($row['draw_date'])], $dates),
            'nextCursor' => $hasMore ? $dates[array_key_last($dates)]['draw_date'] : null, 'today' => self::now()->format('Y-m-d')];
    }

    public static function saveSchedule(array $data, ?int $id = null): array
    {
        $time = $data['time'] ?? null;
        $label = $data['label'] ?? '';
        $active = $data['active'] ?? true;
        if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            Api::invalid('time', 'အချိန် မှန်ကန်စွာ ရွေးပေးပါ။');
        }
        if (!is_string($label) || mb_strlen(trim($label)) > 80) Api::invalid('label', 'ခေါင်းစဉ်ကို စာလုံး ၈၀ အတွင်း ထည့်ပါ။');
        if (!is_bool($active)) Api::invalid('active', 'အချိန်ဇယား အခြေအနေ မမှန်ပါ။');
        $existing = DB::fetch('SELECT * FROM lottery_schedules WHERE draw_time = ?', [$time . ':00']);
        if ($existing && (int) $existing['id'] !== $id && $existing['deleted_at'] === null) {
            json_response(['message' => 'ဒီအချိန်ကို ထည့်ထားပြီးဖြစ်ပါသည်။'], 409);
        }
        $now = self::now()->format('Y-m-d H:i:s');
        try {
            if ($id) {
                $row = DB::fetch('SELECT * FROM lottery_schedules WHERE id = ? AND deleted_at IS NULL', [$id]);
                if (!$row) json_response(['message' => 'အချိန်ဇယား မတွေ့ပါ။'], 404);
                // A previously removed time remains reserved for its archived schedule.
                if ($existing && (int) $existing['id'] !== $id) json_response(['message' => 'ဒီအချိန်ကို ဖျက်ထားသော အချိန်ဇယားတွင် အသုံးပြုထားပါသည်။ အချိန်အသစ်အဖြစ် ပြန်ထည့်ပါ။'], 409);
                DB::update('lottery_schedules', $id, ['draw_time' => $time . ':00', 'label' => trim($label), 'active' => (int) $active, 'updated_at' => $now]);
            } elseif ($existing) {
                $id = (int) $existing['id'];
                DB::update('lottery_schedules', $id, ['deleted_at' => null, 'label' => trim($label), 'active' => (int) $active, 'updated_at' => $now]);
            } else {
                $id = DB::insert('lottery_schedules', ['draw_time' => $time . ':00', 'label' => trim($label), 'active' => (int) $active, 'created_at' => $now, 'updated_at' => $now]);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') json_response(['message' => 'ဒီအချိန်ကို ထည့်ထားပြီးဖြစ်ပါသည်။'], 409);
            throw $e;
        }
        return self::scheduleData(DB::fetch('SELECT * FROM lottery_schedules WHERE id = ?', [$id]));
    }

    public static function deleteSchedule(int $id): void
    {
        $row = DB::fetch('SELECT id FROM lottery_schedules WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$row) json_response(['message' => 'အချိန်ဇယား မတွေ့ပါ။'], 404);
        DB::update('lottery_schedules', $id, ['deleted_at' => self::now()->format('Y-m-d H:i:s'), 'active' => 0]);
    }

    public static function saveResult(array $data, ?int $id = null): array
    {
        $number = $data['number'] ?? null;
        if (!is_string($number) || !preg_match('/^[0-9]{2}$/D', $number)) Api::invalid('number', '00 မှ 99 အတွင်း ဂဏန်း ၂ လုံး ထည့်ပေးပါ။');
        $now = self::now();
        try {
            if ($id) {
                $row = DB::fetch('SELECT * FROM lottery_results WHERE id = ?', [$id]);
                if (!$row) json_response(['message' => 'ထွက်ဂဏန်း မတွေ့ပါ။'], 404);
                DB::update('lottery_results', $id, ['number' => $number, 'updated_at' => $now->format('Y-m-d H:i:s')]);
            } else {
                $date = Api::date($data['date'] ?? null);
                $schedule = DB::fetch('SELECT * FROM lottery_schedules WHERE id = ? AND active = 1 AND deleted_at IS NULL', [Api::id($data['scheduleId'] ?? null)]);
                if (!$schedule) Api::invalid('scheduleId', 'အသုံးပြုနိုင်သော အချိန်ဇယား ရွေးပေးပါ။');
                if ($date . ' ' . $schedule['draw_time'] > $now->format('Y-m-d H:i:s')) {
                    Api::invalid('date', 'ထီထွက်ချိန် မရောက်သေးပါ။ အချိန်ရောက်မှ ထုတ်ပြန်နိုင်ပါမည်။');
                }
                $id = DB::insert('lottery_results', ['schedule_id' => (int) $schedule['id'], 'draw_date' => $date,
                    'draw_time' => $schedule['draw_time'], 'draw_label' => $schedule['label'], 'number' => $number,
                    'published_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') json_response(['message' => 'ဒီအချိန်အတွက် ထွက်ဂဏန်း ထည့်ထားပြီးပါပြီ။ ပြင်ဆင်ရန်ကို အသုံးပြုပါ။'], 409);
            throw $e;
        }
        return self::resultData(DB::fetch('SELECT * FROM lottery_results WHERE id = ?', [$id]));
    }

    public static function deleteResult(int $id): void
    {
        if (!DB::fetch('SELECT id FROM lottery_results WHERE id = ?', [$id])) json_response(['message' => 'ထွက်ဂဏန်း မတွေ့ပါ။'], 404);
        DB::statement('DELETE FROM lottery_results WHERE id = ?', [$id]);
    }
}
