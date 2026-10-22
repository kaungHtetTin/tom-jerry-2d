<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Services\Lottery;
use App\Services\LotterySeeder;
use Mini\Database as DB;

if (env('APP_ENV') !== 'testing' || !preg_match('/^lottery_tj_test_[a-z0-9_]+$/D', (string) env('DB_DATABASE'))) {
    throw new RuntimeException('Seeder verification requires an isolated test database.');
}

$originalAdmin = DB::fetch('SELECT * FROM lottery_admin WHERE id = 1');
$originalResults = DB::fetchAll('SELECT id, number, draw_time FROM lottery_results ORDER BY id');
$credentialsExist = is_file(BASE_PATH . '/storage/initial-admin.txt');
$first = LotterySeeder::run();
$countAfterFirst = (int) DB::fetchValue('SELECT COUNT(*) FROM lottery_results');
$date = $first['from'];
$sample = DB::fetch("SELECT * FROM lottery_results WHERE draw_date = ? AND draw_time = '08:00:00'", [$date]);
DB::update('lottery_results', (int) $sample['id'], ['number' => '07']);
$second = LotterySeeder::run();
$admin = DB::fetch('SELECT * FROM lottery_admin WHERE id = 1');
$days = DB::fetchAll("SELECT draw_date, COUNT(*) AS count FROM lottery_results
    WHERE draw_date BETWEEN ? AND ? AND draw_time IN ('08:00:00', '10:00:00', '12:00:00', '14:00:00', '16:00:00')
    GROUP BY draw_date ORDER BY draw_date", [$first['from'], $first['to']]);
$originalsPreserved = true;
foreach ($originalResults as $original) {
    $current = DB::fetch('SELECT id, number, draw_time FROM lottery_results WHERE id = ?', [$original['id']]);
    if ($current !== $original) $originalsPreserved = false;
}

$checks = [
    'inserted' => $first['inserted'],
    'repeatInserted' => $second['inserted'],
    'repeatCountUnchanged' => $countAfterFirst === (int) DB::fetchValue('SELECT COUNT(*) FROM lottery_results'),
    'times' => array_column(Lottery::schedules(), 'time'),
    'days' => count($days),
    'fivePerDay' => array_reduce($days, fn ($valid, $day) => $valid && (int) $day['count'] === 5, true),
    'historicalOnly' => $first['to'] < Lottery::now()->format('Y-m-d'),
    'manualCorrectionPreserved' => DB::fetchValue('SELECT number FROM lottery_results WHERE id = ?', [$sample['id']]) === '07',
    'originalResultsPreserved' => $originalsPreserved,
    'existingAdminPreserved' => $admin === $originalAdmin,
];

// Only the isolated test admin is removed to verify initial-account provisioning.
DB::statement('DELETE FROM lottery_admin WHERE id = 1');
$created = LotterySeeder::run();
$newAdmin = DB::fetch('SELECT * FROM lottery_admin WHERE id = 1');
$checks['missingAdminCreated'] = $created['adminCreated'] && $newAdmin['username'] === 'admin'
    && password_verify((string) env('ADMIN_INITIAL_PASSWORD'), $newAdmin['password_hash']);
// Verify the requested default credentials independently of test environment overrides.
DB::statement('DELETE FROM lottery_admin WHERE id = 1');
$_ENV['ADMIN_INITIAL_PASSWORD'] = '';
$defaultCreated = LotterySeeder::run();
$defaultAdmin = DB::fetch('SELECT * FROM lottery_admin WHERE id = 1');
$checks['defaultAdminCredentials'] = $defaultCreated['adminCreated'] && $defaultAdmin['username'] === 'admin'
    && password_verify('password', $defaultAdmin['password_hash']);
$checks['singleAdmin'] = (int) DB::fetchValue('SELECT COUNT(*) FROM lottery_admin') === 1;
$checks['localCredentialsUntouched'] = $credentialsExist === is_file(BASE_PATH . '/storage/initial-admin.txt');
echo json_encode($checks, JSON_THROW_ON_ERROR) . PHP_EOL;
