<?php
namespace Hue\Core;
final class Auth {
  public static function hashToken(string $t): string { return hash('sha256', $t); }

  public static function issueToken(int $userId, ?string $device): string {
    $token = bin2hex(random_bytes(32));
    $days = (int) Config::get('session_days', 90);
    Database::insert('INSERT INTO sessions (user_id, token_hash, device_name, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))',
      [$userId, self::hashToken($token), $device ? mb_substr($device, 0, 80) : null, $days]);
    return $token;
  }

  public static function user(Request $r): array {
    $t = $r->bearer();
    if (!$t) throw new ApiException('Authentication required', 401);
    $u = Database::one('SELECT u.*, s.id AS session_id
                        FROM sessions s JOIN users u ON u.id = s.user_id
                        WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()', [self::hashToken($t)]);
    if (!$u) throw new ApiException('Session expired', 401);
    if ($u['is_banned']) throw self::suspended($u);
    Database::run('UPDATE users SET last_seen_at = NOW() WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 60 SECOND)', [$u['id']]);
    Database::run('UPDATE sessions SET last_used_at = NOW(), ip = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 60 SECOND)', [$_SERVER['REMOTE_ADDR'] ?? null, $u['session_id']]);
    return $u;
  }

  public static function suspended(array $u): ApiException {
    $why = $u['suspension_reason'] ?? null;
    return new ApiException('This account has been suspended.' . ($why ? " Reason: $why" : ''), 403, ['code' => 'suspended']);
  }

  /** The signed-in user's own profile, including privacy settings. */
  public static function selfProfile(array $u): array {
    return ['id' => (int)$u['id'], 'code' => $u['code'], 'username' => $u['username'], 'display_name' => $u['display_name'], 'email' => $u['email'] ?? null,
      'bio' => $u['bio'] ?? null, 'personal_color' => $u['personal_color'], 'personal_colors' => self::colors($u), 'has_avatar' => !empty($u['avatar_path']),
      'privacy' => ['who_can_request' => $u['who_can_request'] ?? 'everyone', 'who_can_message' => $u['who_can_message'] ?? 'friends',
        'color_visibility' => $u['color_visibility'] ?? 'everyone', 'profile_visibility' => $u['profile_visibility'] ?? 'everyone',
        'space_visibility' => $u['space_visibility'] ?? 'everyone', 'reveal_chat_color_default' => (bool)($u['reveal_chat_color_default'] ?? 0)]];
  }

  /** What `$viewerId` is allowed to see of user row `$u`, honouring that user's privacy settings. */
  public static function publicProfile(array $u, ?int $viewerId = null): array {
    $self = $viewerId !== null && $viewerId === (int)$u['id'];
    $friends = !$self && $viewerId !== null && \Hue\Controllers\FriendController::statusBetween($viewerId, (int)$u['id']) === 'friends';
    $color = $u['personal_color'] ?? 'white'; $bio = $u['bio'] ?? null; $avatar = !empty($u['avatar_path']);
    if (!$self) {
      $cv = $u['color_visibility'] ?? 'everyone';
      if ($cv === 'nobody' || ($cv === 'friends' && !$friends)) $color = null;
      if (($u['profile_visibility'] ?? 'everyone') === 'friends' && !$friends) { $bio = null; $avatar = false; }
    }
    return ['id' => (int)$u['id'], 'code' => $u['code'], 'username' => $u['username'], 'display_name' => $u['display_name'],
            'bio' => $bio, 'personal_color' => $color, 'personal_colors' => $color === null ? null : self::colors($u), 'has_avatar' => $avatar];
  }

  private static function colors(array $u): array {
    $colors = json_decode((string)($u['personal_colors'] ?? ''), true);
    return is_array($colors) && $colors ? $colors : [(string)($u['personal_color'] ?? 'white')];
  }

  public const PUBLIC_COLS = 'u.id, u.code, u.username, u.display_name, u.bio, u.personal_color, u.personal_colors, u.color_visibility, u.profile_visibility, u.avatar_path, u.who_can_request';

  public static function revokeSessions(int $userId, ?int $exceptSessionId = null): void {
    Database::run('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL AND id <> ?', [$userId, $exceptSessionId ?? 0]);
    Database::run('DELETE FROM device_tokens WHERE user_id = ? AND (session_id IS NULL OR session_id <> ?)', [$userId, $exceptSessionId ?? 0]);
  }

  public static function strongPassword($p, ?string $username = null): string {
    if (!is_string($p) || strlen($p) < 8 || strlen($p) > 200) throw new ApiException('Password must be at least 8 characters', 422);
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) throw new ApiException('Password needs at least one letter and one number', 422);
    if ($username && strtolower($p) === strtolower($username)) throw new ApiException('Password cannot be your username', 422);
    return $p;
  }

  public static function generateCode(string $name): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) . 'HUE', 0, 3));
    for ($i = 0; $i < 10; $i++) {
      $s = ''; for ($j = 0; $j < 5; $j++) $s .= $alphabet[random_int(0, 31)];
      $code = "$prefix-$s";
      if (!Database::one('SELECT 1 FROM users WHERE code = ?', [$code])) return $code;
    }
    throw new ApiException('Could not generate code', 500);
  }
}
