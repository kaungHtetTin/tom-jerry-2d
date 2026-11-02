<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use Mini\Database as DB;

$name = (string) env('DB_DATABASE');
if (env('APP_ENV') !== 'testing' || !preg_match('/^lottery_tj_test_[a-z0-9_]+$/D', $name)) {
    throw new RuntimeException('Fixtures may only use an isolated lottery_tj_test_* database.');
}
$action = $argv[1] ?? 'seed';
if ($action === 'verify-release') {
    $row = DB::fetch('SELECT * FROM lottery_results WHERE id = ?', [(int) ($argv[2] ?? 0)]);
    $due = new DateTimeImmutable($row['draw_date'] . ' ' . $row['draw_time']);
    $checks = [];
    foreach ([-1, 0, 1] as $offset) {
        $_ENV['LOTTERY_TEST_NOW'] = $due->modify("{$offset} seconds")->format('Y-m-d H:i:s');
        $visible = $offset >= 0;
        $results = \App\Services\Lottery::results($row['draw_date']);
        $checks[] = in_array((int) $row['id'], array_column($results, 'id'), true) === $visible;
        $calendar = \App\Services\Lottery::calendar(substr($row['draw_date'], 0, 7));
        $checks[] = in_array($row['draw_date'], array_column($calendar['days'], 'date'), true) === $visible;
        $history = \App\Services\Lottery::history(null);
        $checks[] = in_array($row['draw_date'], array_column($history['days'], 'date'), true) === $visible;
    }
    echo json_encode($checks);
    exit;
}
if ($action === 'ui-reset') {
    DB::statement('DELETE FROM lottery_results');
    DB::statement('DELETE FROM lottery_schedules');
    DB::statement('DELETE FROM lottery_login_attempts');
    DB::statement('UPDATE lottery_admin SET password_hash = ?, auth_version = auth_version + 1 WHERE id = 1', [password_hash((string) env('ADMIN_INITIAL_PASSWORD'), PASSWORD_DEFAULT)]);
    foreach (['17:00', '18:00', '19:00', '20:00', '21:00', '22:00'] as $time) {
        DB::insert('lottery_schedules', ['draw_time' => $time . ':00', 'label' => '', 'active' => 1, 'created_at' => '2026-10-22 00:00:00', 'updated_at' => '2026-10-22 00:00:00']);
    }
    $action = 'ui';
}
if ($action === 'cleanup') {
    DB::connection(true)->exec("DROP DATABASE IF EXISTS `{$name}`");
    echo "Removed isolated test database.\n";
    exit;
}
if ($action === 'reset-rate-limit') {
    DB::statement('DELETE FROM lottery_login_attempts');
    exit;
}
// Fixed history belongs only to the isolated test database, never the real app.
$schedules = DB::fetchAll('SELECT * FROM lottery_schedules ORDER BY draw_time');
$values = ['58', '63', '71', '45', '09', '76'];
foreach ($schedules as $index => $schedule) {
    DB::insert('lottery_results', ['schedule_id' => $schedule['id'], 'draw_date' => '2026-10-21', 'draw_time' => $schedule['draw_time'],
        'draw_label' => '', 'number' => $values[$index], 'published_at' => '2026-10-21 ' . $schedule['draw_time'], 'updated_at' => '2026-10-21 ' . $schedule['draw_time']]);
}
if ($action === 'ui') {
    foreach (['60', '72'] as $index => $number) {
        DB::insert('lottery_results', ['schedule_id' => $schedules[$index]['id'], 'draw_date' => '2026-10-22', 'draw_time' => $schedules[$index]['draw_time'],
            'draw_label' => '', 'number' => $number, 'published_at' => '2026-10-22 ' . $schedules[$index]['draw_time'], 'updated_at' => '2026-10-22 ' . $schedules[$index]['draw_time']]);
    }
}
echo "Isolated test fixtures ready.\n";
