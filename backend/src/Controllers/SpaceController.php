<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, ApiException, Database, Auth, Validator, RateLimiter, Config, Notifier};

final class SpaceController {
  // ---------------- Public Spaces (server-hosted) ----------------
  public function listPublic(Request $r): void {
    $u = Auth::user($r);
    $rows = Database::all("SELECT s.id, s.owner_id, s.title, s.description, s.created_at, o.display_name AS owner_name, o.code AS owner_code, o.personal_color AS owner_color,
        (SELECT id FROM space_media WHERE space_id = s.id ORDER BY position LIMIT 1) AS cover_media_id,
        (SELECT mime FROM space_media WHERE space_id = s.id ORDER BY position LIMIT 1) AS cover_mime,
        (SELECT COUNT(*) FROM space_media WHERE space_id = s.id) AS media_count,
        (SELECT COUNT(*) FROM space_impressions WHERE space_id = s.id) AS impressions,
        (SELECT COUNT(*) FROM space_views WHERE space_id = s.id) AS views
      FROM spaces s JOIN users o ON o.id = s.owner_id
      WHERE s.type = 'public' AND s.deleted_at IS NULL AND s.is_hidden = 0 AND o.is_banned = 0
        AND s.owner_id NOT IN (SELECT blocked_id FROM blocked_users WHERE user_id = ?)
        AND s.owner_id NOT IN (SELECT user_id FROM blocked_users WHERE blocked_id = ?)
        AND (o.space_visibility = 'everyone' OR o.id = ? OR EXISTS (SELECT 1 FROM friendships f WHERE f.status = 'accepted' AND ((f.requester_id = o.id AND f.addressee_id = ?) OR (f.addressee_id = o.id AND f.requester_id = ?))))
      ORDER BY s.id DESC LIMIT 50", [$u['id'], $u['id'], $u['id'], $u['id'], $u['id']]);
    foreach ($rows as &$s) {
      $isOwner = (int)$s['owner_id'] === (int)$u['id'];
      $s['media'] = Database::all('SELECT id, mime FROM space_media WHERE space_id = ? ORDER BY position', [$s['id']]);
      if ($isOwner) {
        $s['is_owner'] = true;
        $s['impression_summary'] = Database::all('SELECT kind, COUNT(*) AS total FROM space_impressions WHERE space_id = ? GROUP BY kind ORDER BY total DESC', [$s['id']]);
      } else { unset($s['owner_id'], $s['views']); }
    }
    Response::ok(['spaces' => $rows]);
  }

  /** Can $viewer see public Space $s (owner row has space_visibility)? */
  private function canView(int $viewer, array $space): bool {
    if ((int)$space['owner_id'] === $viewer) return true;
    if (UserController::blocked($viewer, (int)$space['owner_id'])) return false;
    return $space['space_visibility'] === 'everyone' || FriendController::statusBetween($viewer, (int)$space['owner_id']) === 'friends';
  }

  public function show(Request $r, array $p): void {
    $u = Auth::user($r);
    $s = Database::one("SELECT s.id, s.owner_id, s.title, s.description, s.created_at, o.space_visibility, o.display_name AS owner_name, o.code AS owner_code, o.personal_color AS owner_color
        FROM spaces s JOIN users o ON o.id = s.owner_id WHERE s.id = ? AND s.type = 'public' AND s.deleted_at IS NULL AND s.is_hidden = 0 AND o.is_banned = 0", [(int)$p['id']]);
    if (!$s || !$this->canView((int)$u['id'], $s)) throw new ApiException('Space not found', 404);
    $isOwner = (int)$s['owner_id'] === (int)$u['id'];
    if (!$isOwner) Database::run('INSERT IGNORE INTO space_views (space_id, user_id) VALUES (?,?)', [$s['id'], $u['id']]);
    $s['media'] = Database::all('SELECT id, mime FROM space_media WHERE space_id = ? ORDER BY position', [$s['id']]);
    $mine = Database::one('SELECT kind FROM space_impressions WHERE space_id = ? AND user_id = ?', [$s['id'], $u['id']]);
    $s['my_impression'] = $mine['kind'] ?? null;
    if ($isOwner) {
      $s['is_owner'] = true;
      $s['views'] = (int)(Database::one('SELECT COUNT(*) AS total FROM space_views WHERE space_id = ?', [$s['id']])['total'] ?? 0);
      $s['impressions'] = (int)(Database::one('SELECT COUNT(*) AS total FROM space_impressions WHERE space_id = ?', [$s['id']])['total'] ?? 0);
      $s['impression_summary'] = Database::all('SELECT kind, COUNT(*) AS total FROM space_impressions WHERE space_id = ? GROUP BY kind ORDER BY total DESC', [$s['id']]);
    } else unset($s['owner_id']);
    unset($s['space_visibility']);
    Response::ok(['space' => $s]);
  }

  public function mine(Request $r): void {
    $u = Auth::user($r);
    $spaces = Database::all("SELECT s.id, s.type, s.title, s.description, s.item_count, s.created_at,
        (SELECT COUNT(*) FROM space_views v WHERE v.space_id = s.id) AS views,
        (SELECT COUNT(*) FROM space_impressions i WHERE i.space_id = s.id) AS impressions
        FROM spaces s WHERE s.owner_id = ? AND s.deleted_at IS NULL ORDER BY s.id DESC", [$u['id']]);
    foreach ($spaces as &$s) {
      $s['views'] = (int)$s['views']; $s['impressions'] = (int)$s['impressions'];
      $s['impression_summary'] = Database::all('SELECT kind, COUNT(*) AS total FROM space_impressions WHERE space_id = ? GROUP BY kind ORDER BY total DESC', [$s['id']]);
    }
    Response::ok(['spaces' => $spaces]);
  }

  public function createPublic(Request $r): void {
    $u = Auth::user($r);
    RateLimiter::hit('upload:' . $u['id'], 10, 3600);
    $title = Validator::str($r->input('title'), 'Title', 1, 80);
    $files = $_FILES['images'] ?? null;
    $isVideo = false;
    if (!$files && isset($_FILES['video'])) {
      $video = $_FILES['video']; $isVideo = true;
      $files = ['name' => [$video['name']], 'type' => [$video['type']], 'tmp_name' => [$video['tmp_name']], 'error' => [$video['error']], 'size' => [$video['size']]];
    }
    if (!$files || !is_array($files['name'])) throw new ApiException('Add pictures or choose a video', 422);
    if (count($files['name']) > 12) throw new ApiException('A Space holds up to 12 media items', 422);

    $sid = Database::insert("INSERT INTO spaces (owner_id, type, title, description) VALUES (?, 'public', ?, ?)",
      [$u['id'], $title, mb_substr((string)$r->input('description', ''), 0, 300)]);
    $dir = Config::get('storage') . '/public_media';
    $allowedImages = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $allowedVideos = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
    foreach ($files['tmp_name'] as $i => $tmp) {
      if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
      $limit = $isVideo ? 100 * 1024 * 1024 : Config::get('max_upload_bytes');
      if ($files['size'][$i] > $limit) continue;
      $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
      $extension = $isVideo ? ($allowedVideos[$mime] ?? null) : ($allowedImages[$mime] ?? null);
      if (!$extension || (!$isVideo && !@getimagesize($tmp))) continue;       // verify media type from file contents
      if (!move_uploaded_file($tmp, $dir . '/' . ($name = bin2hex(random_bytes(16)) . '.' . $extension))) continue;
      Database::insert('INSERT INTO space_media (space_id, path, mime, size, position) VALUES (?,?,?,?,?)', [$sid, $name, $mime, $files['size'][$i], $i]);
    }
    if ((int)(Database::one('SELECT COUNT(*) AS total FROM space_media WHERE space_id = ?', [$sid])['total'] ?? 0) === 0) {
      Database::run('DELETE FROM spaces WHERE id = ?', [$sid]);
      throw new ApiException($isVideo ? 'Choose a supported video up to 100 MB (MP4, WebM or MOV)' : 'Choose supported images up to the upload size limit', 422);
    }
    Response::ok(['space_id' => $sid]);
  }

  public function delete(Request $r): void {
    $u = Auth::user($r);
    $sid = Validator::int($r->input('space_id'), 'space_id');
    $s = Database::one('SELECT id, type FROM spaces WHERE id = ? AND owner_id = ? AND deleted_at IS NULL', [$sid, $u['id']]);
    if (!$s) throw new ApiException('Space not found', 404);
    foreach (Database::all('SELECT path FROM space_media WHERE space_id = ?', [$sid]) as $m) @unlink(Config::get('storage') . '/public_media/' . $m['path']);
    Database::run('DELETE FROM space_media WHERE space_id = ?', [$sid]);
    Database::run('UPDATE spaces SET deleted_at = NOW() WHERE id = ?', [$sid]);
    Database::run('UPDATE space_access_keys SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$sid]);
    Database::run('UPDATE space_grants SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$sid]);
    $this->purgeRelay($sid);
    Response::ok();
  }

  /** Media is streamed through PHP so every request is authorised; no permanent public URLs. */
  public function media(Request $r, array $p): void {
    $u = Auth::user($r);
    $m = Database::one("SELECT m.path, m.mime, s.owner_id, o.space_visibility FROM space_media m JOIN spaces s ON s.id = m.space_id JOIN users o ON o.id = s.owner_id
        WHERE m.id = ? AND s.deleted_at IS NULL AND s.is_hidden = 0 AND s.type = 'public' AND o.is_banned = 0", [(int)$p['id']]);
    if (!$m || !$this->canView((int)$u['id'], $m)) throw new ApiException('Not found', 404);
    $path = Config::get('storage') . '/public_media/' . basename($m['path']);
    $size = filesize($path);
    header('Content-Type: ' . $m['mime']); header('Content-Disposition: inline'); header('Accept-Ranges: bytes'); header('Cache-Control: private, max-age=300');
    $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $range)) {
      if ($range[1] === '' && $range[2] !== '') $start = max(0, $size - (int)$range[2]);
      else { $start = (int)$range[1]; if ($range[2] !== '') $end = min($end, (int)$range[2]); }
      if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
      http_response_code(206); header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $handle = fopen($path, 'rb'); fseek($handle, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) { $chunk = fread($handle, min(8192, $remaining)); echo $chunk; $remaining -= strlen($chunk); }
    fclose($handle); exit;
  }

  public function impress(Request $r): void {
    $u = Auth::user($r);
    RateLimiter::hit('impress:' . $u['id'], 60, 60);
    $sid = Validator::int($r->input('space_id'), 'space_id');
    $sp = Database::one("SELECT s.id, s.owner_id, s.title, o.space_visibility FROM spaces s JOIN users o ON o.id = s.owner_id WHERE s.id = ? AND s.type = 'public' AND s.deleted_at IS NULL AND s.is_hidden = 0", [$sid]);
    if (!$sp || !$this->canView((int)$u['id'], $sp)) throw new ApiException('Space not found', 404);
    $had = Database::one('SELECT 1 FROM space_impressions WHERE space_id = ? AND user_id = ?', [$sid, $u['id']]);
    $kind = $r->input('kind');
    if ($kind === null) Database::run('DELETE FROM space_impressions WHERE space_id = ? AND user_id = ?', [$sid, $u['id']]);
    else Database::run('INSERT INTO space_impressions (space_id, user_id, kind) VALUES (?,?,?) ON DUPLICATE KEY UPDATE kind = VALUES(kind)', [$sid, $u['id'], Validator::oneOf($kind, Validator::IMPRESSIONS, 'kind')]);
    if (!$had && (int)$sp['owner_id'] !== (int)$u['id']) Notifier::send((int)$sp['owner_id'], 'impression', 'New impression', $u['display_name'] . ' reacted to "' . $sp['title'] . '"', ['space_id' => $sid]);
    Response::ok();
  }

  // ---------------- Private Spaces (media stays on the owner's device) ----------------
  /** Registers metadata only (title + item count). No pictures are accepted here. */
  public function registerPrivate(Request $r): void {
    $u = Auth::user($r);
    $title = Validator::str($r->input('title', 'My private Space'), 'Title', 1, 80);
    $count = max(0, min(200, (int)$r->input('item_count', 0)));
    $s = Database::one("SELECT id FROM spaces WHERE owner_id = ? AND type = 'private' AND deleted_at IS NULL", [$u['id']]);
    if ($s) { Database::run('UPDATE spaces SET title = ?, item_count = ? WHERE id = ?', [$title, $count, $s['id']]); Response::ok(['space_id' => (int)$s['id']]); }
    Response::ok(['space_id' => Database::insert("INSERT INTO spaces (owner_id, type, title, item_count) VALUES (?, 'private', ?, ?)", [$u['id'], $title, $count])]);
  }

  private function ownedPrivate(int $uid, int $sid): array {
    $s = Database::one("SELECT * FROM spaces WHERE id = ? AND owner_id = ? AND type = 'private' AND deleted_at IS NULL", [$sid, $uid]);
    if (!$s) throw new ApiException('Space not found', 404);
    return $s;
  }

  public function createKey(Request $r): void {
    $u = Auth::user($r);
    RateLimiter::hit('key:' . $u['id'], 20, 3600);
    $s = $this->ownedPrivate((int)$u['id'], Validator::int($r->input('space_id'), 'space_id'));
    $minutes = (int)$r->input('minutes');
    if (!in_array($minutes, [5, 10, 30], true)) throw new ApiException('Choose 5, 10 or 30 minutes', 422);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $key = '';
    for ($i = 0; $i < 8; $i++) $key .= $alphabet[random_int(0, 31)];
    $kid = Database::insert('INSERT INTO space_access_keys (space_id, key_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE))', [$s['id'], hash('sha256', $key), $minutes]);
    $row = Database::one('SELECT expires_at, UNIX_TIMESTAMP(expires_at) - UNIX_TIMESTAMP(NOW()) AS seconds_left FROM space_access_keys WHERE id = ?', [$kid]);
    Response::ok(['key' => substr($key, 0, 4) . '-' . substr($key, 4), 'expires_at' => $row['expires_at'], 'seconds_left' => (int)$row['seconds_left']]);
  }

  public function redeemKey(Request $r): void {
    $u = Auth::user($r);
    RateLimiter::hit('redeem:' . $u['id'], 8, 600);          // brute-force protection
    RateLimiter::hit('redeem-ip:' . $r->ip(), 20, 600);
    $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$r->input('key', '')));
    $k = Database::one("SELECT k.id, k.space_id, k.expires_at, s.title, s.item_count, s.owner_id, UNIX_TIMESTAMP(k.expires_at) - UNIX_TIMESTAMP(NOW()) AS seconds_left
        FROM space_access_keys k JOIN spaces s ON s.id = k.space_id
        WHERE k.key_hash = ? AND k.revoked_at IS NULL AND k.expires_at > NOW() AND s.deleted_at IS NULL", [hash('sha256', $key)]);
    if (!$k) throw new ApiException('That key is invalid or has expired', 404);
    if ((int)$k['owner_id'] === (int)$u['id'] || UserController::blocked((int)$u['id'], (int)$k['owner_id'])) throw new ApiException('That key is invalid or has expired', 404);
    Database::run('INSERT INTO space_grants (space_id, key_id, viewer_id, expires_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE revoked_at = NULL', [$k['space_id'], $k['id'], $u['id'], $k['expires_at']]);
    $g = Database::one('SELECT id FROM space_grants WHERE key_id = ? AND viewer_id = ?', [$k['id'], $u['id']]);
    Notifier::send((int)$k['owner_id'], 'space_access', 'Space opened', $u['display_name'] . ' opened your private Space', ['space_id' => (int)$k['space_id']]);
    Response::ok(['grant_id' => (int)$g['id'], 'title' => $k['title'], 'item_count' => (int)$k['item_count'], 'seconds_left' => (int)$k['seconds_left']]);
  }

  public function revoke(Request $r): void {
    $u = Auth::user($r);
    $s = $this->ownedPrivate((int)$u['id'], Validator::int($r->input('space_id'), 'space_id'));
    Database::run('UPDATE space_access_keys SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$s['id']]);
    Database::run('UPDATE space_grants SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$s['id']]);
    $this->purgeRelay((int)$s['id']);
    Response::ok();
  }

  /** Server-side expiry check. Every viewer/owner action goes through this. */
  private function liveGrant(int $viewerId, int $grantId): array {
    $g = Database::one('SELECT g.*, s.owner_id, s.item_count FROM space_grants g JOIN space_access_keys k ON k.id = g.key_id JOIN spaces s ON s.id = g.space_id JOIN users o ON o.id = s.owner_id
                        WHERE g.id = ? AND g.viewer_id = ? AND g.revoked_at IS NULL AND k.revoked_at IS NULL AND g.expires_at > NOW() AND k.expires_at > NOW()
                          AND s.deleted_at IS NULL AND o.is_banned = 0', [$grantId, $viewerId]);
    if (!$g || UserController::blocked($viewerId, (int)$g['owner_id'])) throw new ApiException('Access has expired', 410);
    return $g;
  }

  public function requestItem(Request $r): void {
    $u = Auth::user($r);
    RateLimiter::hit('relay:' . $u['id'], 60, 60);
    $g = $this->liveGrant((int)$u['id'], Validator::int($r->input('grant_id'), 'grant_id'));
    $this->cleanupRelay();
    $idx = (int)$r->input('item_index', 0);
    if ($idx < 0 || $idx >= (int)$g['item_count']) throw new ApiException('No such picture', 422);
    $id = Database::insert("INSERT INTO space_relay (grant_id, item_index, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 60 SECOND))", [$g['id'], $idx]);
    Response::ok(['request_id' => $id]);
  }

  /** Owner's device polls for viewer requests it should fulfil. */
  public function pending(Request $r): void {
    $u = Auth::user($r);
    $rows = Database::all("SELECT rl.id AS request_id, rl.item_index FROM space_relay rl JOIN space_grants g ON g.id = rl.grant_id JOIN spaces s ON s.id = g.space_id
        WHERE s.owner_id = ? AND rl.status = 'requested' AND rl.expires_at > NOW() AND g.revoked_at IS NULL AND g.expires_at > NOW() LIMIT 20", [$u['id']]);
    Response::ok(['requests' => $rows]);
  }

  public function fulfil(Request $r): void {
    $u = Auth::user($r);
    $req = Database::one("SELECT rl.id FROM space_relay rl JOIN space_grants g ON g.id = rl.grant_id JOIN spaces s ON s.id = g.space_id
        WHERE rl.id = ? AND s.owner_id = ? AND rl.status = 'requested' AND rl.expires_at > NOW() AND g.revoked_at IS NULL AND g.expires_at > NOW()",
        [Validator::int($r->input('request_id'), 'request_id'), $u['id']]);
    $f = $_FILES['image'] ?? null;
    if (!$req || !$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > Config::get('max_upload_bytes')) throw new ApiException('Request not available', 404);
    $name = bin2hex(random_bytes(16)) . '.bin';
    move_uploaded_file($f['tmp_name'], Config::get('storage') . '/relay/' . $name);
    Database::run("UPDATE space_relay SET status = 'ready', blob_path = ? WHERE id = ?", [$name, $req['id']]);
    Response::ok();
  }

  /** Viewer fetches once; the blob is deleted immediately after it is sent. */
  public function fetch(Request $r, array $p): void {
    $u = Auth::user($r);
    $row = Database::one("SELECT rl.id, rl.blob_path, rl.grant_id FROM space_relay rl WHERE rl.id = ?", [(int)$p['id']]);
    if (!$row) throw new ApiException('Not found', 404);
    $this->liveGrant((int)$u['id'], (int)$row['grant_id']);
    $row = Database::one("SELECT blob_path FROM space_relay WHERE id = ? AND status = 'ready' AND expires_at > NOW()", [$row['id']]);
    if (!$row) { http_response_code(202); Response::json(['ok' => true, 'ready' => false], 202); }
    $path = Config::get('storage') . '/relay/' . basename($row['blob_path']);
    Database::run("UPDATE space_relay SET status = 'delivered', blob_path = NULL WHERE id = ?", [(int)$p['id']]);
    header('Content-Type: application/octet-stream'); header('Content-Disposition: inline'); header('Cache-Control: no-store');
    readfile($path); @unlink($path); exit;
  }

  /** Deletes relay blobs that were never collected before they expired. */
  private function cleanupRelay(): void { self::cleanupExpiredRelay(); }
  public static function cleanupExpiredRelay(): void {
    foreach (Database::all("SELECT id, blob_path FROM space_relay WHERE status IN ('requested','ready') AND expires_at < NOW()") as $b) {
      if ($b['blob_path']) @unlink(Config::get('storage') . '/relay/' . basename($b['blob_path']));
      Database::run("UPDATE space_relay SET status = 'expired', blob_path = NULL WHERE id = ?", [$b['id']]);
    }
  }
  public static function purgeRelayForPair(int $a, int $b): void {
    foreach (Database::all("SELECT rl.id, rl.blob_path FROM space_relay rl JOIN space_grants g ON g.id = rl.grant_id JOIN spaces s ON s.id = g.space_id
        WHERE ((s.owner_id = ? AND g.viewer_id = ?) OR (s.owner_id = ? AND g.viewer_id = ?)) AND rl.status IN ('requested','ready')", [$a, $b, $b, $a]) as $x) {
      if ($x['blob_path']) @unlink(Config::get('storage') . '/relay/' . basename($x['blob_path']));
      Database::run("UPDATE space_relay SET status = 'expired', blob_path = NULL WHERE id = ?", [$x['id']]);
    }
  }

  private function purgeRelay(int $spaceId): void {
    foreach (Database::all("SELECT rl.id, rl.blob_path FROM space_relay rl JOIN space_grants g ON g.id = rl.grant_id WHERE g.space_id = ? AND rl.blob_path IS NOT NULL", [$spaceId]) as $b)
      @unlink(Config::get('storage') . '/relay/' . basename($b['blob_path']));
    Database::run("UPDATE space_relay rl JOIN space_grants g ON g.id = rl.grant_id SET rl.status = 'expired', rl.blob_path = NULL WHERE g.space_id = ?", [$spaceId]);
  }
}
