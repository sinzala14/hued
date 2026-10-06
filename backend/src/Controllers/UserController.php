<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, ApiException, Database, Auth, Validator, RateLimiter, Config};

final class UserController {
  public function me(Request $r): void { Response::ok(['user' => Auth::selfProfile(Auth::user($r))]); }

  public function update(Request $r): void {
    $u = Auth::user($r);
    $name = Validator::str($r->input('display_name', $u['display_name']), 'Display name', 2, 60);
    $bio = $r->input('bio') === null ? (string)$u['bio'] : mb_substr(trim((string)$r->input('bio')), 0, 200);
    Database::run('UPDATE users SET display_name = ?, bio = ? WHERE id = ?', [$name, $bio, $u['id']]);
    Response::ok(['user' => Auth::selfProfile(Database::one('SELECT * FROM users WHERE id = ?', [$u['id']]))]);
  }

  public function privacy(Request $r): void {
    $u = Auth::user($r);
    $rules = ['who_can_request' => ['everyone', 'nobody'], 'who_can_message' => ['friends', 'nobody'], 'color_visibility' => ['everyone', 'friends', 'nobody'],
              'profile_visibility' => ['everyone', 'friends'], 'space_visibility' => ['everyone', 'friends']];
    foreach ($rules as $k => $allowed) {
      if ($r->input($k) !== null) Database::run("UPDATE users SET $k = ? WHERE id = ?", [Validator::oneOf($r->input($k), $allowed, $k), $u['id']]);   // $k is from the whitelist above
    }
    if ($r->input('reveal_chat_color_default') !== null) Database::run('UPDATE users SET reveal_chat_color_default = ? WHERE id = ?', [$r->input('reveal_chat_color_default') ? 1 : 0, $u['id']]);
    Response::ok(['user' => Auth::selfProfile(Database::one('SELECT * FROM users WHERE id = ?', [$u['id']]))]);
  }

  public function search(Request $r): void {
    $u = Auth::user($r);
    RateLimiter::hit('search:' . $u['id'], 30, 60);
    $code = strtoupper(Validator::str($r->input('code', ''), 'Code', 5, 12));
    $row = Database::one('SELECT ' . Auth::PUBLIC_COLS . ' FROM users u WHERE u.code = ? AND u.is_banned = 0', [$code]);
    if (!$row || self::blocked((int)$row['id'], (int)$u['id'])) throw new ApiException('No one found with that code', 404);
    Response::ok(['user' => Auth::publicProfile($row, (int)$u['id']), 'friendship' => FriendController::statusBetween((int)$u['id'], (int)$row['id'])]);
  }

  public function byCode(Request $r, array $p): void {
    $u = Auth::user($r);
    $row = Database::one('SELECT ' . Auth::PUBLIC_COLS . ' FROM users u WHERE u.code = ? AND u.is_banned = 0', [strtoupper($p['code'])]);
    if (!$row || self::blocked((int)$row['id'], (int)$u['id'])) throw new ApiException('User not found', 404);
    $me = (int)$u['id'];
    Response::ok(['user' => Auth::publicProfile($row, $me), 'friendship' => FriendController::statusBetween($me, (int)$row['id']),
                  'blocked_by_me' => false]);
  }

  public function block(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    $other = $r->input('user_id') ? Validator::int($r->input('user_id'), 'user_id') : (int)(Database::one('SELECT id FROM users WHERE code = ?', [strtoupper((string)$r->input('code', ''))])['id'] ?? 0);
    if (!$other || !Database::one('SELECT 1 FROM users WHERE id = ?', [$other])) throw new ApiException('User not found', 404);
    if ($other === $me) throw new ApiException('You cannot block yourself', 422);
    if ($r->input('unblock')) { Database::run('DELETE FROM blocked_users WHERE user_id = ? AND blocked_id = ?', [$me, $other]); Response::ok(); }

    Database::run('INSERT IGNORE INTO blocked_users (user_id, blocked_id) VALUES (?,?)', [$me, $other]);
    Database::run("DELETE FROM friendships WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)", [$me, $other, $other, $me]);
    // Cut off private Space access in both directions, including anything already in flight.
    Database::run("UPDATE space_grants g JOIN spaces s ON s.id = g.space_id SET g.revoked_at = NOW()
                   WHERE g.revoked_at IS NULL AND ((s.owner_id = ? AND g.viewer_id = ?) OR (s.owner_id = ? AND g.viewer_id = ?))", [$me, $other, $other, $me]);
    SpaceController::purgeRelayForPair($me, $other);
    Response::ok();
  }

  public function blockedList(Request $r): void {
    $u = Auth::user($r);
    $rows = Database::all('SELECT u.id, u.code, u.display_name, b.created_at FROM blocked_users b JOIN users u ON u.id = b.blocked_id WHERE b.user_id = ? ORDER BY b.created_at DESC', [$u['id']]);
    Response::ok(['blocked' => array_map(fn($x) => ['id' => (int)$x['id'], 'code' => $x['code'], 'display_name' => $x['display_name'], 'blocked_at' => $x['created_at']], $rows)]);
  }

  public static function blocked(int $a, int $b): bool {
    return (bool) Database::one('SELECT 1 FROM blocked_users WHERE (user_id = ? AND blocked_id = ?) OR (user_id = ? AND blocked_id = ?)', [$a, $b, $b, $a]);
  }

  // ---- profile picture ----
  public function uploadAvatar(Request $r): void {
    $u = Auth::user($r);
    RateLimiter::hit('avatar:' . $u['id'], 10, 3600);
    $f = $_FILES['image'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new ApiException('Choose a picture to upload', 422);
    if ($f['size'] > 2 * 1024 * 1024) throw new ApiException('Picture must be under 2 MB', 422);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])] ?? null;
    $dim = @getimagesize($f['tmp_name']);
    if (!$ext || !$dim || $dim[0] > 4096 || $dim[1] > 4096) throw new ApiException('Use a JPEG, PNG or WebP picture', 422);
    $dir = Config::get('storage') . '/avatars'; if (!is_dir($dir)) mkdir($dir, 0750, true);
    $name = bin2hex(random_bytes(16)) . ".$ext";
    move_uploaded_file($f['tmp_name'], "$dir/$name");
    if ($u['avatar_path']) @unlink("$dir/" . basename($u['avatar_path']));
    Database::run('UPDATE users SET avatar_path = ? WHERE id = ?', [$name, $u['id']]);
    Response::ok(['user' => Auth::selfProfile(Database::one('SELECT * FROM users WHERE id = ?', [$u['id']]))]);
  }

  public function avatar(Request $r, array $p): void {
    $u = Auth::user($r);
    $row = Database::one('SELECT ' . Auth::PUBLIC_COLS . ' FROM users u WHERE u.id = ? AND u.is_banned = 0', [(int)$p['id']]);
    if (!$row || self::blocked((int)$u['id'], (int)$row['id'])) throw new ApiException('Not found', 404);
    $pub = Auth::publicProfile($row, (int)$u['id']);
    $path = Config::get('storage') . '/avatars/' . basename((string)$row['avatar_path']);
    if (!$pub['has_avatar'] || !is_file($path)) throw new ApiException('Not found', 404);
    header('Content-Type: ' . (new \finfo(FILEINFO_MIME_TYPE))->file($path)); header('Cache-Control: private, max-age=300');
    readfile($path); exit;
  }
}
