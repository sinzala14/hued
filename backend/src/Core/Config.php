<?php
namespace Hue\Core;
/** Values come from environment variables first (production), then config/config.php (local dev). */
final class Config {
  private static ?array $c = null;
  public static function get(string $k, $default = null) {
    if (self::$c === null) {
      $f = __DIR__ . '/../../config/config.php';
      self::$c = is_file($f) ? require $f : [];
    }
    $env = ['db' => null, 'session_days' => 'HUE_SESSION_DAYS', 'force_https' => 'HUE_FORCE_HTTPS', 'max_upload_bytes' => 'HUE_MAX_UPLOAD_BYTES',
            'storage' => 'HUE_STORAGE', 'mail_driver' => 'HUE_MAIL_DRIVER', 'mail_from' => 'HUE_MAIL_FROM',
            'fcm_project_id' => 'HUE_FCM_PROJECT_ID', 'fcm_service_account' => 'HUE_FCM_SERVICE_ACCOUNT'];
    if ($k === 'db') {
      if (getenv('HUE_DB_DSN')) return ['dsn' => getenv('HUE_DB_DSN'), 'user' => getenv('HUE_DB_USER') ?: '', 'pass' => getenv('HUE_DB_PASS') ?: ''];
      return self::$c['db'] ?? throw new \RuntimeException('Database not configured');
    }
    $v = isset($env[$k]) ? getenv($env[$k]) : false;
    if ($v !== false && $v !== '') return in_array($k, ['force_https']) ? filter_var($v, FILTER_VALIDATE_BOOLEAN) : (is_numeric($v) ? (int)$v : $v);
    return self::$c[$k] ?? $default;
  }
}
