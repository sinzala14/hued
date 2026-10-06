<?php
// Run every minute:  * * * * * php /path/to/backend/cron/cleanup.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../src/Core/Response.php';
spl_autoload_register(function ($c) { if (str_starts_with($c, 'Hue\\')) { $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (is_file($f)) require $f; } });
use Hue\Core\{Database as DB, Notifier};
use Hue\Controllers\SpaceController;

SpaceController::cleanupExpiredRelay();
// Tell owners when a key that someone actually used has expired.
foreach (DB::all("SELECT k.id, s.owner_id, s.id AS space_id FROM space_access_keys k JOIN spaces s ON s.id = k.space_id
    WHERE k.notified_at IS NULL AND k.revoked_at IS NULL AND k.expires_at < NOW() AND EXISTS (SELECT 1 FROM space_grants g WHERE g.key_id = k.id)") as $k) {
  Notifier::send((int)$k['owner_id'], 'space_expired', 'Space Key expired', 'Access to your private Space has ended', ['space_id' => (int)$k['space_id']]);
  DB::run('UPDATE space_access_keys SET notified_at = NOW() WHERE id = ?', [$k['id']]);
}
DB::run('UPDATE space_access_keys SET notified_at = NOW() WHERE notified_at IS NULL AND expires_at < NOW()');
DB::run('DELETE FROM sessions WHERE expires_at < NOW() - INTERVAL 30 DAY OR revoked_at < NOW() - INTERVAL 30 DAY');
DB::run('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 1 DAY');
DB::run('DELETE FROM rate_limits WHERE window_start < UNIX_TIMESTAMP() - 7200');
DB::run('DELETE FROM notifications WHERE created_at < NOW() - INTERVAL 60 DAY');
