<?php
namespace Hue\Core;
/** Firebase Cloud Messaging HTTP v1 sender. Does nothing until a service-account file is configured. */
final class Fcm {
  public static function configured(): bool { $f = Config::get('fcm_service_account'); return $f && is_file($f) && Config::get('fcm_project_id'); }

  private static function accessToken(): ?string {
    $cache = Config::get('storage') . '/fcm_token.json';
    if (is_file($cache)) { $c = json_decode(file_get_contents($cache), true); if (($c['exp'] ?? 0) > time() + 60) return $c['token']; }
    $sa = json_decode(file_get_contents(Config::get('fcm_service_account')), true);
    $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
      'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    openssl_sign($unsigned, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);
    $res = self::post('https://oauth2.googleapis.com/token', http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$unsigned." . $b64($sig)]), ['Content-Type: application/x-www-form-urlencoded']);
    $j = json_decode($res['body'], true);
    if (empty($j['access_token'])) return null;
    file_put_contents($cache, json_encode(['token' => $j['access_token'], 'exp' => $now + (int)($j['expires_in'] ?? 3000)]), LOCK_EX);
    @chmod($cache, 0600);
    return $j['access_token'];
  }

  private static function post(string $url, string $body, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => (string)$out];
  }

  public static function send(string $token, string $title, string $body, array $data, string $channel): void {
    if (!self::configured()) return;
    $at = self::accessToken();
    if (!$at) { error_log('FCM delivery skipped: OAuth access token unavailable'); return; }
    $payload = ['message' => ['token' => $token, 'notification' => ['title' => $title, 'body' => $body],
      'data' => array_map('strval', $data), 'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => $channel]]]];
    $r = self::post('https://fcm.googleapis.com/v1/projects/' . Config::get('fcm_project_id') . '/messages:send', json_encode($payload),
      ['Authorization: Bearer ' . $at, 'Content-Type: application/json']);
    if (in_array($r['code'], [404, 410], true)) Database::run('DELETE FROM device_tokens WHERE fcm_token = ?', [$token]);   // token no longer valid
    elseif ($r['code'] < 200 || $r['code'] >= 300) error_log('FCM delivery failed with HTTP ' . $r['code']);
  }
}
