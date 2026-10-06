<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, ApiException, Database, Auth, Validator, RateLimiter, Mailer};

final class AuthController {
  private const DUMMY = '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0ZHVtbXk$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaGhhc2g';

  public function register(Request $r): void {
    RateLimiter::hit('register:' . $r->ip(), 5, 3600);
    $name  = Validator::str($r->input('display_name'), 'Display name', 2, 60);
    $user  = strtolower(Validator::str($r->input('username'), 'Username', 3, 30));
    $email = strtolower(Validator::str($r->input('email'), 'Email', 5, 190));
    $pass  = Auth::strongPassword($r->input('password'), $user);
    if ($pass !== $r->input('confirm_password')) throw new ApiException('Passwords do not match', 422);
    if (!preg_match('/^[a-z0-9_]+$/', $user)) throw new ApiException('Username can use letters, numbers and underscores', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiException('Email is invalid', 422);
    $color = Validator::oneOf($r->input('color', 'white'), Validator::COLORS, 'color');
    if (Database::one('SELECT 1 FROM users WHERE username = ? OR email = ?', [$user, $email])) throw new ApiException('Username or email is already taken', 409);

    $pdo = Database::pdo();
    try {
      $pdo->beginTransaction();
      $id = Database::insert('INSERT INTO users (code, username, display_name, email, password_hash, personal_color) VALUES (?,?,?,?,?,?)',
        [Auth::generateCode($name), $user, $name, $email, password_hash($pass, PASSWORD_ARGON2ID), $color]);
      Database::run('INSERT INTO notification_settings (user_id) VALUES (?)', [$id]);
      Database::run('INSERT INTO user_colors (user_id, color) VALUES (?,?)', [$id, $color]);
      $pdo->commit();
    } catch (\PDOException $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($e->getCode() === '23000') throw new ApiException('Username or email is already taken', 409);   // race on unique key
      throw $e;
    }
    $u = Database::one('SELECT * FROM users WHERE id = ?', [$id]);
    Response::ok(['token' => Auth::issueToken($id, $r->input('device')), 'user' => Auth::selfProfile($u)]);
  }

  public function login(Request $r): void {
    $login = strtolower(trim((string)$r->input('login', '')));
    RateLimiter::hit('login:' . $r->ip(), 10, 600);
    RateLimiter::hit('login-id:' . $login, 10, 600);
    $u = Database::one('SELECT * FROM users WHERE username = ? OR email = ? OR code = ?', [$login, $login, strtoupper($login)]);
    $pass = (string)$r->input('password', '');
    $ok = password_verify($pass, $u['password_hash'] ?? self::DUMMY) && $u;   // always hash-compare: no timing hint about existence
    if (!$ok) throw new ApiException('Incorrect login or password', 401);
    if ($u['is_banned']) throw Auth::suspended($u);
    if (password_needs_rehash($u['password_hash'], PASSWORD_ARGON2ID)) Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_ARGON2ID), $u['id']]);
    Response::ok(['token' => Auth::issueToken((int)$u['id'], $r->input('device')), 'user' => Auth::selfProfile($u)]);
  }

  public function logout(Request $r): void {
    $u = Auth::user($r);
    Database::run('UPDATE sessions SET revoked_at = NOW() WHERE id = ?', [$u['session_id']]);
    Database::run('DELETE FROM device_tokens WHERE session_id = ?', [$u['session_id']]);
    Response::ok();
  }

  // ---- password recovery: emailed single-use code, 30 minutes, generic responses ----
  public function forgot(Request $r): void {
    $email = strtolower(trim((string)$r->input('email', '')));
    RateLimiter::hit('forgot-ip:' . $r->ip(), 5, 3600);
    RateLimiter::hit('forgot:' . $email, 3, 3600);
    $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? Database::one('SELECT id, display_name FROM users WHERE email = ? AND is_banned = 0', [$email]) : null;
    if ($u) {
      $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $code = '';
      for ($i = 0; $i < 10; $i++) $code .= $alphabet[random_int(0, 31)];
      Database::run('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$u['id']]);
      Database::insert('INSERT INTO password_resets (user_id, code_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))', [$u['id'], hash('sha256', $code)]);
      Mailer::send($email, 'Your Hue reset code', "Hi {$u['display_name']},\n\nYour Hue password reset code is: " . substr($code, 0, 5) . '-' . substr($code, 5) .
        "\n\nIt works once and expires in 30 minutes. If you didn't ask for this, ignore this email.\n");
    }
    Response::ok(['message' => 'If an account exists for that email, a reset code is on its way.']);
  }

  public function reset(Request $r): void {
    $email = strtolower(trim((string)$r->input('email', '')));
    RateLimiter::hit('reset-ip:' . $r->ip(), 10, 3600);
    RateLimiter::hit('reset:' . $email, 5, 3600);
    $pass = Auth::strongPassword($r->input('password'));
    if ($pass !== $r->input('confirm_password')) throw new ApiException('Passwords do not match', 422);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$r->input('code', '')));
    $row = Database::one('SELECT pr.id, pr.user_id FROM password_resets pr JOIN users u ON u.id = pr.user_id
        WHERE u.email = ? AND pr.code_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()', [$email, hash('sha256', $code)]);
    if (!$row) throw new ApiException('That code is invalid or has expired', 400);
    if (!Database::run('UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [$row['id']])) throw new ApiException('That code is invalid or has expired', 400);   // single use, race-safe
    Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_ARGON2ID), $row['user_id']]);
    Auth::revokeSessions((int)$row['user_id']);
    Response::ok(['message' => 'Password updated. Please log in.']);
  }

  // ---- security settings ----
  public function changePassword(Request $r): void {
    $u = Auth::user($r);
    RateLimiter::hit('chpw:' . $u['id'], 5, 600);
    if (!password_verify((string)$r->input('current_password', ''), $u['password_hash'])) throw new ApiException('Current password is incorrect', 403);
    $new = Auth::strongPassword($r->input('new_password'), $u['username']);
    if ($new !== $r->input('confirm_password')) throw new ApiException('Passwords do not match', 422);
    Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_ARGON2ID), $u['id']]);
    if ($r->input('revoke_others')) Auth::revokeSessions((int)$u['id'], (int)$u['session_id']);
    Response::ok();
  }

  public function sessions(Request $r): void {
    $u = Auth::user($r);
    $rows = Database::all('SELECT id, device_name, created_at, last_used_at, ip FROM sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW() ORDER BY COALESCE(last_used_at, created_at) DESC', [$u['id']]);
    foreach ($rows as &$s) { $s['current'] = (int)$s['id'] === (int)$u['session_id']; $s['id'] = (int)$s['id']; }
    Response::ok(['sessions' => $rows]);
  }

  public function revokeSession(Request $r): void {
    $u = Auth::user($r);
    $id = Validator::int($r->input('session_id'), 'session_id');
    Database::run('UPDATE sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$id, $u['id']]);
    Database::run('DELETE FROM device_tokens WHERE session_id = ?', [$id]);
    Response::ok();
  }

  public function logoutOthers(Request $r): void {
    $u = Auth::user($r);
    Auth::revokeSessions((int)$u['id'], (int)$u['session_id']);
    Response::ok();
  }
}
