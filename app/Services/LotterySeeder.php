<?php

declare(strict_types=1);

namespace App\Services;

use Mini\Database as DB;

final class LotterySeeder
{
    private const TIMES = ['08:00:00', '10:00:00', '12:00:00', '14:00:00', '16:00:00'];

    public static function run(): array
    {
        $today = Lottery::now()->setTime(0, 0);
        $start = $today->modify('-7 days');
        $end = $today->modify('-1 day');
        $now = Lottery::now()->format('Y-m-d H:i:s');
        $initialPassword = null;

        $summary = DB::transaction(function () use ($today, $start, $end, $now, &$initialPassword): array {
            $placeholders = implode(', ', array_fill(0, count(self::TIMES), '?'));
            // Keep old schedules and their history, but use exactly the requested daily times.
            DB::statement("UPDATE lottery_schedules SET active = 0, updated_at = ? WHERE deleted_at IS NULL AND active = 1 AND draw_time NOT IN ({$placeholders})", [$now, ...self::TIMES]);
            $schedules = [];
            foreach (self::TIMES as $time) {
                $schedule = DB::fetch('SELECT * FROM lottery_schedules WHERE draw_time = ?', [$time]);
                if ($schedule) {
                    DB::update('lottery_schedules', (int) $schedule['id'], ['active' => 1, 'deleted_at' => null, 'updated_at' => $now]);
                    $schedules[] = $schedule;
                } else {
                    $id = DB::insert('lottery_schedules', ['draw_time' => $time, 'label' => '', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
                    $schedules[] = ['id' => $id, 'draw_time' => $time, 'label' => ''];
                }
            }

            $inserted = 0;
            for ($day = $start; $day < $today; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                foreach ($schedules as $index => $schedule) {
                    // Stable sample values, formatted as strings so 00–09 retain their zero.
                    $number = sprintf('%02d', ((int) $day->format('z') * 17 + $index * 23) % 100);
                    $timestamp = $date . ' ' . $schedule['draw_time'];
                    $statement = DB::statement('INSERT INTO lottery_results
                        (schedule_id, draw_date, draw_time, draw_label, number, published_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                        [(int) $schedule['id'], $date, $schedule['draw_time'], $schedule['label'], $number, $timestamp, $timestamp]);
                    $inserted += $statement->rowCount();
                }
            }

            $adminCreated = false;
            if (!DB::fetch('SELECT id FROM lottery_admin WHERE id = 1')) {
                $initialPassword = (string) env('ADMIN_INITIAL_PASSWORD', '');
                if ($initialPassword === '') $initialPassword = bin2hex(random_bytes(12));
                if (strlen($initialPassword) < 12 || strlen($initialPassword) > 72) {
                    throw new \RuntimeException('The initial admin password must contain 12 to 72 bytes.');
                }
                DB::insert('lottery_admin', ['id' => 1, 'username' => 'admin', 'password_hash' => password_hash($initialPassword, PASSWORD_DEFAULT), 'updated_at' => $now]);
                $adminCreated = true;
            }

            return ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'inserted' => $inserted,
                'preserved' => 35 - $inserted, 'adminCreated' => $adminCreated, 'times' => self::TIMES];
        });

        if ($summary['adminCreated'] && env('APP_ENV') !== 'testing') {
            $loginUrl = env('APP_URL') ? rtrim((string) env('APP_URL'), '/') . '/admin/login' : '/admin/login (relative to the application URL)';
            $path = BASE_PATH . '/storage/initial-admin.txt';
            $written = file_put_contents($path, "Myanmar 2D administrator\nUsername: admin\nPassword: {$initialPassword}\nLogin: {$loginUrl}\n\nChange this password in Admin > Settings after signing in.\n", LOCK_EX);
            if ($written === false) {
                throw new \RuntimeException('Admin created, but credentials could not be saved. Use admin:reset-password to generate a new password.');
            }
            @chmod($path, 0600);
        }

        return $summary;
    }
}
