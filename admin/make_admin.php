<?php
// CLI only:  php make_admin.php username 'NewStrongPassword'
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/bootstrap.php';
use Hue\Core\Database as DB;
[$_, $user, $pass] = $argv + [null, null, null];
if (!$user || !$pass) exit("Usage: php make_admin.php username password\n");
$n = DB::run('UPDATE users SET is_admin = 1, password_hash = ? WHERE username = ?', [password_hash($pass, PASSWORD_ARGON2ID), strtolower($user)]);
echo $n ? "Done. $user is now an admin.\n" : "No user named $user. Register in the app first.\n";
