<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Api;
use Mini\Csrf;
use Mini\Database as DB;

final class AdminAuth
{
    public static function user(): ?array
    {
        $session = $_SESSION['admin'] ?? null;
        if (!is_array($session) || ($session['expires'] ?? 0) < time()) {
            unset($_SESSION['admin']);
            return null;
        }
        $admin = DB::fetch('SELECT id, username, auth_version FROM lottery_admin WHERE id = 1');
        if (!$admin || (int) $admin['auth_version'] !== ($session['version'] ?? null)) {
            unset($_SESSION['admin']);
            return null;
        }
        return ['id' => 1, 'username' => $admin['username']];
    }

    public static function login(array $data): never
    {
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        if (!is_string($username) || !is_string($password) || strlen($username) > 80 || strlen($password) > 256) {
            Api::invalid('username', 'အကောင့်အမည်နှင့် စကားဝှက်ကို စစ်ဆေးပေးပါ။');
        }
        $bucket = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'local'));
        $now = date('Y-m-d H:i:s');
        $outcome = DB::transaction(function () use ($bucket, $now, $username, $password): array {
            DB::statement('INSERT IGNORE INTO lottery_login_attempts (bucket, attempts, started_at) VALUES (?, 0, ?)', [$bucket, $now]);
            $attempt = DB::fetch('SELECT * FROM lottery_login_attempts WHERE bucket = ? FOR UPDATE', [$bucket]);
            if (strtotime($attempt['started_at']) < time() - 900) {
                DB::statement('UPDATE lottery_login_attempts SET attempts = 0, started_at = ? WHERE bucket = ?', [$now, $bucket]);
                $attempt['attempts'] = 0;
            }
            if ((int) $attempt['attempts'] >= 5) {
                return ['status' => 429];
            }
            $admin = DB::fetch('SELECT * FROM lottery_admin WHERE id = 1');
            // A real hash is always checked, even for an incorrect username.
            $valid = password_verify($password, $admin['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
            if (!$admin || !hash_equals($admin['username'], trim($username)) || !$valid) {
                DB::statement('UPDATE lottery_login_attempts SET attempts = attempts + 1 WHERE bucket = ?', [$bucket]);
                return ['status' => 401];
            }
            DB::statement('DELETE FROM lottery_login_attempts WHERE bucket = ?', [$bucket]);
            return ['status' => 200, 'admin' => $admin];
        });
        if ($outcome['status'] === 429) {
            header('Retry-After: 900');
            json_response(['message' => 'ခဏခဏ မှားယွင်းနေပါသည်။ ၁၅ မိနစ်ကြာလျှင် ပြန်စမ်းပါ။'], 429);
        }
        if ($outcome['status'] !== 200) {
            json_response(['message' => 'အကောင့်အမည် သို့မဟုတ် စကားဝှက် မှားနေပါသည်။'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = ['version' => (int) $outcome['admin']['auth_version'], 'expires' => time() + 43200];
        unset($_SESSION['_token']);
        json_response(['admin' => ['id' => 1, 'username' => $outcome['admin']['username']], 'csrfToken' => Csrf::token()]);
    }

    public static function logout(): never
    {
        unset($_SESSION['admin'], $_SESSION['_token']);
        session_regenerate_id(true);
        json_response(['message' => 'အကောင့်မှ ထွက်ပြီးပါပြီ။', 'csrfToken' => Csrf::token()]);
    }

    public static function changePassword(array $data): never
    {
        $old = $data['currentPassword'] ?? null;
        $new = $data['newPassword'] ?? null;
        if (!is_string($old) || !is_string($new) || strlen($new) < 12 || strlen($new) > 72) {
            Api::invalid('newPassword', 'စကားဝှက်အသစ်ကို အနည်းဆုံး ၁၂ လုံး၊ အများဆုံး ၇၂ bytes ထည့်ပါ။');
        }
        $admin = DB::fetch('SELECT * FROM lottery_admin WHERE id = 1');
        if (!password_verify($old, $admin['password_hash'])) {
            Api::invalid('currentPassword', 'လက်ရှိ စကားဝှက် မမှန်ပါ။');
        }
        if (hash_equals($old, $new)) {
            Api::invalid('newPassword', 'လက်ရှိ စကားဝှက်နှင့် မတူသော စကားဝှက် ရွေးပါ။');
        }
        DB::statement('UPDATE lottery_admin SET password_hash = ?, auth_version = auth_version + 1, updated_at = ? WHERE id = 1',
            [password_hash($new, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        $_SESSION['admin']['version'] = (int) $admin['auth_version'] + 1;
        session_regenerate_id(true);
        unset($_SESSION['_token']);
        // Remove the one-time setup credential after it has been replaced.
        $credentials = BASE_PATH . '/storage/initial-admin.txt';
        if (env('APP_ENV') !== 'testing' && is_file($credentials)) {
            unlink($credentials);
        }
        json_response(['message' => 'စကားဝှက် ပြောင်းပြီးပါပြီ။', 'csrfToken' => Csrf::token()]);
    }
}
