<?php
namespace Hue\Core;
/** Records an in-app notification and, if allowed, pushes it. Never throws: notifications must not break the action that caused them. */
final class Notifier {
  private const CATEGORY = ['message' => 'messages', 'friend_request' => 'friend_requests', 'friend_accepted' => 'friend_requests',
    'friend_activity' => 'friend_activity', 'space_access' => 'spaces', 'space_expired' => 'spaces', 'impression' => 'reactions'];

  public static function send(int $userId, string $type, string $title, string $body, array $data = []): void {
    try {
      $s = Database::one('SELECT * FROM notification_settings WHERE user_id = ?', [$userId]);
      if (!$s || !$s[self::CATEGORY[$type]]) return;
      if ($type === 'message' && !$s['show_previews']) $body = 'New message';
      Database::insert('INSERT INTO notifications (user_id, type, title, body, payload) VALUES (?,?,?,?,?)',
        [$userId, $type, mb_substr($title, 0, 100), mb_substr($body, 0, 200), json_encode($data + ['type' => $type])]);
      if (!$s['push_enabled'] || !Fcm::configured()) return;
      $channel = 'hue_s' . (int)$s['sound'] . '_v' . (int)$s['vibration'];
      foreach (Database::all('SELECT fcm_token FROM device_tokens WHERE user_id = ?', [$userId]) as $t) {
        try { Fcm::send($t['fcm_token'], $title, $body, $data + ['type' => $type], $channel); }
        catch (\Throwable $e) { error_log('FCM delivery failed: ' . get_class($e)); }
      }
    } catch (\Throwable $e) { error_log('notify failed: ' . get_class($e)); }
  }

  public static function preview(string $text): string { $t = trim(preg_replace('/\s+/', ' ', $text)); return mb_strlen($t) > 80 ? mb_substr($t, 0, 77) . '...' : $t; }
}
