<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, ApiException, Database, Auth, Validator};

final class NotificationController {
  private const KEYS = ['messages', 'friend_requests', 'friend_activity', 'spaces', 'reactions', 'push_enabled', 'sound', 'vibration', 'show_previews'];

  public function getSettings(Request $r): void {
    $u = Auth::user($r);
    Database::run('INSERT IGNORE INTO notification_settings (user_id) VALUES (?)', [$u['id']]);
    $s = Database::one('SELECT * FROM notification_settings WHERE user_id = ?', [$u['id']]); unset($s['user_id']);
    Response::ok(['settings' => array_map('boolval', $s)]);
  }

  public function setSettings(Request $r): void {
    $u = Auth::user($r);
    Database::run('INSERT IGNORE INTO notification_settings (user_id) VALUES (?)', [$u['id']]);
    foreach (self::KEYS as $k) if ($r->input($k) !== null) Database::run("UPDATE notification_settings SET $k = ? WHERE user_id = ?", [$r->input($k) ? 1 : 0, $u['id']]);   // $k whitelisted
    $this->getSettings($r);
  }

  public function registerDevice(Request $r): void {
    $u = Auth::user($r);
    $t = Validator::str($r->input('fcm_token'), 'Token', 20, 512);
    $platform = Validator::oneOf($r->input('platform', 'android'), ['android', 'ios', 'web'], 'Platform');
    Database::run('INSERT INTO device_tokens (user_id, session_id, fcm_token, platform) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), session_id = VALUES(session_id), platform = VALUES(platform)', [$u['id'], $u['session_id'], $t, $platform]);
    Response::ok();
  }

  public function unregisterDevice(Request $r): void {
    $u = Auth::user($r);
    Database::run('DELETE FROM device_tokens WHERE fcm_token = ? AND user_id = ?', [(string)$r->input('fcm_token', ''), $u['id']]);
    Response::ok();
  }
}
