<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminAuth;
use App\Services\Lottery;
use App\Support\Api;
use Mini\Csrf;

final class LotteryController
{
    public function day(): never { json_response(Lottery::day(Api::date($_GET['date'] ?? Lottery::now()->format('Y-m-d')))); }
    public function result(string $id): never
    {
        $row = \Mini\Database::fetch('SELECT draw_date FROM lottery_results WHERE id = ?', [Api::id($id)]);
        if (!$row) json_response(['message' => 'ထွက်ဂဏန်း မတွေ့ပါ။'], 404);
        $day = Lottery::day($row['draw_date']);
        foreach ($day['results'] as $result) {
            if ($result['id'] === (int) $id) json_response(['result' => $result, 'day' => $day]);
        }
        json_response(['message' => 'ထွက်ဂဏန်း မတွေ့ပါ။'], 404);
    }
    public function calendar(): never { json_response(Lottery::calendar(is_string($_GET['month'] ?? null) ? $_GET['month'] : Lottery::now()->format('Y-m'))); }
    public function history(): never { json_response(Lottery::history(isset($_GET['before']) ? Api::date($_GET['before']) : null)); }
    public function session(): never { json_response(['admin' => AdminAuth::user(), 'csrfToken' => Csrf::token(), 'today' => Lottery::now()->format('Y-m-d')]); }
    public function login(): never { AdminAuth::login(Api::body()); }
    public function logout(): never { AdminAuth::logout(); }
    public function password(): never { AdminAuth::changePassword(Api::body()); }
    public function schedules(): never { json_response(['schedules' => Lottery::schedules(true)]); }
    public function createSchedule(): never { json_response(['schedule' => Lottery::saveSchedule(Api::body())], 201); }
    public function updateSchedule(string $id): never { json_response(['schedule' => Lottery::saveSchedule(Api::body(), Api::id($id))]); }
    public function deleteSchedule(string $id): never { Lottery::deleteSchedule(Api::id($id)); json_response(['message' => 'အချိန်ဇယား ဖျက်ပြီးပါပြီ။ ယခင်ထွက်ဂဏန်းများ ဆက်ရှိပါမည်။']); }
    public function createResult(): never { json_response(['result' => Lottery::saveResult(Api::body())], 201); }
    public function updateResult(string $id): never { json_response(['result' => Lottery::saveResult(Api::body(), Api::id($id))]); }
    public function deleteResult(string $id): never { Lottery::deleteResult(Api::id($id)); json_response(['message' => 'ထွက်ဂဏန်း ဖျက်ပြီးပါပြီ။']); }
}
