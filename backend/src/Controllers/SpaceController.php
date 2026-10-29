<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, ApiException, Database, Auth, Validator, RateLimiter, Config, Notifier};

final class SpaceController {
  private const MAX_GALLERIES_PER_USER = 5;
  private const MAX_MEDIA_PER_GALLERY  = 12;
  private static bool $schemaChecked   = false;

  private static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    try {
      $hasAccessKey = Database::one(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spaces' AND COLUMN_NAME = 'access_key'"
      );
      if (!$hasAccessKey) {
        Database::run("ALTER TABLE spaces ADD COLUMN access_key VARCHAR(20) NULL AFTER item_count");
      }
      $hasExpiresAt = Database::one(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spaces' AND COLUMN_NAME = 'expires_at'"
      );
      if (!$hasExpiresAt) {
        Database::run("ALTER TABLE spaces ADD COLUMN expires_at DATETIME NULL AFTER access_key");
      }
      $hasUpdatedAt = Database::one(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spaces' AND COLUMN_NAME = 'updated_at'"
      );
      if (!$hasUpdatedAt) {
        Database::run("ALTER TABLE spaces ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
      }
      Database::run("CREATE TABLE IF NOT EXISTS space_members (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        space_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        approved_at DATETIME NULL,
        expires_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_space_member (space_id, user_id),
        FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_space_status (space_id, status),
        INDEX idx_user_status (user_id, status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
      $hasMemberExpires = Database::one(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'space_members' AND COLUMN_NAME = 'expires_at'"
      );
      if (!$hasMemberExpires) {
        Database::run("ALTER TABLE space_members ADD COLUMN expires_at DATETIME NULL AFTER approved_at");
      }
      Database::run("CREATE TABLE IF NOT EXISTS space_comments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        space_id BIGINT UNSIGNED NOT NULL,
        media_id BIGINT UNSIGNED NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        body VARCHAR(1000) NOT NULL,
        owner_reply VARCHAR(1000) NULL,
        replied_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_space_user (space_id, user_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
      Database::run("CREATE TABLE IF NOT EXISTS space_media_views (
        media_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (media_id, user_id),
        FOREIGN KEY (media_id) REFERENCES space_media(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_viewed (user_id, viewed_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) {
      error_log('SpaceController::ensureSchema warning: ' . $e->getMessage());
    }
  }

  private static function generateAccessKey(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 10; $attempt++) {
      $raw = '';
      for ($i = 0; $i < 8; $i++) $raw .= $alphabet[random_int(0, 31)];
      $formatted = substr($raw, 0, 4) . '-' . substr($raw, 4);
      if (!Database::one('SELECT 1 FROM spaces WHERE access_key = ? AND deleted_at IS NULL', [$formatted])) {
        return $formatted;
      }
    }
    return substr(strtoupper(bin2hex(random_bytes(4))), 0, 4) . '-' . substr(strtoupper(bin2hex(random_bytes(4))), 0, 4);
  }

  /** Can $viewer see gallery $space? */
  private function canView(int $viewer, array $space): bool {
    $ownerId = (int)$space['owner_id'];
    if ($ownerId === $viewer) return true;
    if (UserController::blocked($viewer, $ownerId)) return false;
    if (!empty($space['expires_at']) && strtotime((string)$space['expires_at']) <= time()) {
      return false;
    }
    $type = $space['type'] ?? 'public';
    if ($type === 'private') {
      $member = Database::one(
        "SELECT 1 FROM space_members WHERE space_id = ? AND user_id = ? AND status = 'approved' AND (expires_at IS NULL OR expires_at > NOW())",
        [(int)$space['id'], $viewer]
      );
      if ($member) return true;
      $legacy = Database::one(
        "SELECT 1 FROM space_grants WHERE space_id = ? AND viewer_id = ? AND revoked_at IS NULL AND expires_at > NOW()",
        [(int)$space['id'], $viewer]
      );
      return (bool)$legacy;
    }
    $vis = $space['space_visibility'] ?? 'everyone';
    return $vis === 'everyone' || FriendController::statusBetween($viewer, $ownerId) === 'friends';
  }

  private function fetchMediaWithViewStatus(int $spaceId, int $viewerId): array {
    $media = Database::all(
      'SELECT m.id, m.mime, m.created_at,
              EXISTS(SELECT 1 FROM space_media_views v WHERE v.media_id = m.id AND v.user_id = ?) AS viewed
       FROM space_media m WHERE m.space_id = ? ORDER BY m.position, m.id',
      [$viewerId, $spaceId]
    );
    $newCount = 0;
    $viewedCount = 0;
    $latestAt = null;
    foreach ($media as &$m) {
      $m['id'] = (int)$m['id'];
      $m['viewed'] = (bool)$m['viewed'];
      if ($m['viewed']) {
        $viewedCount++;
      } else {
        $newCount++;
      }
      if ($latestAt === null || strcmp((string)$m['created_at'], (string)$latestAt) > 0) {
        $latestAt = $m['created_at'];
      }
    }
    return [
      'media'        => $media,
      'new_count'    => $newCount,
      'viewed_count' => $viewedCount,
      'latest_at'    => $latestAt,
    ];
  }

  // ---------------- Friends' Galleries & Newly Added Pictures (Discover) ----------------
  public function listPublic(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    $rows = Database::all("SELECT s.id, s.owner_id, s.type, s.title, s.description, s.expires_at, s.created_at, s.updated_at,
        GREATEST(0, IFNULL(UNIX_TIMESTAMP(s.expires_at) - UNIX_TIMESTAMP(NOW()), 0)) AS seconds_left,
        o.display_name AS owner_name, o.code AS owner_code, o.personal_color AS owner_color,
        (SELECT id FROM space_media WHERE space_id = s.id ORDER BY position, id LIMIT 1) AS cover_media_id,
        (SELECT mime FROM space_media WHERE space_id = s.id ORDER BY position, id LIMIT 1) AS cover_mime,
        (SELECT COUNT(*) FROM space_media WHERE space_id = s.id) AS media_count,
        (SELECT COUNT(*) FROM space_impressions WHERE space_id = s.id) AS impressions,
        (SELECT COUNT(*) FROM space_views WHERE space_id = s.id) AS views
      FROM spaces s JOIN users o ON o.id = s.owner_id
      WHERE s.deleted_at IS NULL AND s.is_hidden = 0 AND o.is_banned = 0
        AND (s.expires_at IS NULL OR s.expires_at > NOW())
        AND s.owner_id NOT IN (SELECT blocked_id FROM blocked_users WHERE user_id = ?)
        AND s.owner_id NOT IN (SELECT user_id FROM blocked_users WHERE blocked_id = ?)
        AND (
          (s.type = 'public' AND EXISTS (
            SELECT 1 FROM friendships f WHERE f.status = 'accepted'
              AND ((f.requester_id = o.id AND f.addressee_id = ?) OR (f.addressee_id = o.id AND f.requester_id = ?))
          ))
          OR
          (s.type = 'private' AND EXISTS (
            SELECT 1 FROM space_members sm WHERE sm.space_id = s.id AND sm.user_id = ? AND sm.status = 'approved'
              AND (sm.expires_at IS NULL OR sm.expires_at > NOW())
          ))
        )
        AND EXISTS (SELECT 1 FROM space_media smed WHERE smed.space_id = s.id)
      ORDER BY s.updated_at DESC, s.id DESC LIMIT 60", [$uid, $uid, $uid, $uid, $uid]);

    $recentPics = [];
    foreach ($rows as &$s) {
      $isOwner = (int)$s['owner_id'] === $uid;
      $s['id'] = (int)$s['id'];
      $s['owner_id'] = (int)$s['owner_id'];
      $s['media_count'] = (int)$s['media_count'];
      $s['seconds_left'] = $s['expires_at'] ? (int)$s['seconds_left'] : null;

      $meta = $this->fetchMediaWithViewStatus($s['id'], $uid);
      $s['media'] = $meta['media'];
      $s['new_count'] = $meta['new_count'];
      $s['viewed_count'] = $meta['viewed_count'];
      $s['has_new'] = $meta['new_count'] > 0;
      $s['latest_media_at'] = $meta['latest_at'] ?? $s['created_at'];

      foreach ($s['media'] as $idx => $mItem) {
        if (str_starts_with((string)$mItem['mime'], 'video/')) continue;
        $recentPics[] = [
          'media_id'    => $mItem['id'],
          'media_index' => $idx,
          'space_id'    => $s['id'],
          'space_title' => $s['title'],
          'space_type'  => $s['type'],
          'owner_id'    => $s['owner_id'],
          'owner_name'  => $s['owner_name'],
          'owner_color' => $s['owner_color'],
          'viewed'      => $mItem['viewed'],
          'created_at'  => $mItem['created_at'],
        ];
      }

      if ($isOwner) {
        $s['is_owner'] = true;
        $s['views'] = (int)$s['views'];
        $s['impressions'] = (int)$s['impressions'];
        $s['impression_summary'] = Database::all('SELECT kind, COUNT(*) AS total FROM space_impressions WHERE space_id = ? GROUP BY kind ORDER BY total DESC', [$s['id']]);
      } else {
        $s['is_owner'] = false;
        unset($s['views'], $s['impressions']);
      }
    }
    unset($s);

    // Sort galleries: friends' galleries with NEW (unviewed) pictures first, then newest activity
    usort($rows, function (array $a, array $b): int {
      $aNew = ($a['new_count'] ?? 0) > 0 ? 1 : 0;
      $bNew = ($b['new_count'] ?? 0) > 0 ? 1 : 0;
      if ($aNew !== $bNew) return $bNew <=> $aNew;
      return strcmp((string)($b['latest_media_at'] ?? ''), (string)($a['latest_media_at'] ?? ''));
    });

    // Sort recent pictures: NEW (unviewed) first, then newest created_at
    usort($recentPics, function (array $a, array $b): int {
      $aUnviewed = empty($a['viewed']) ? 1 : 0;
      $bUnviewed = empty($b['viewed']) ? 1 : 0;
      if ($aUnviewed !== $bUnviewed) return $bUnviewed <=> $aUnviewed;
      return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
    $recentPics = array_slice($recentPics, 0, 24);

    Response::ok(['spaces' => $rows, 'recent_pics' => $recentPics]);
  }

  public function show(Request $r, array $p): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    $s = Database::one("SELECT s.id, s.owner_id, s.type, s.title, s.description, s.access_key, s.expires_at, s.created_at,
        GREATEST(0, IFNULL(UNIX_TIMESTAMP(s.expires_at) - UNIX_TIMESTAMP(NOW()), 0)) AS seconds_left,
        o.space_visibility, o.display_name AS owner_name, o.code AS owner_code, o.personal_color AS owner_color
        FROM spaces s JOIN users o ON o.id = s.owner_id
        WHERE s.id = ? AND s.deleted_at IS NULL AND s.is_hidden = 0 AND o.is_banned = 0", [(int)$p['id']]);
    if (!$s || !$this->canView($uid, $s)) throw new ApiException('Gallery not found or access has expired', 404);

    $sid = (int)$s['id'];
    $s['id'] = $sid;
    $s['owner_id'] = (int)$s['owner_id'];
    $s['seconds_left'] = $s['expires_at'] ? (int)$s['seconds_left'] : null;
    $isOwner = $s['owner_id'] === $uid;
    if (!$isOwner) {
      Database::run('INSERT IGNORE INTO space_views (space_id, user_id) VALUES (?,?)', [$sid, $uid]);
    }

    $meta = $this->fetchMediaWithViewStatus($sid, $uid);
    $s['media'] = $meta['media'];
    $s['new_count'] = $meta['new_count'];
    $s['viewed_count'] = $meta['viewed_count'];
    $s['has_new'] = $meta['new_count'] > 0;

    $mine = Database::one('SELECT kind FROM space_impressions WHERE space_id = ? AND user_id = ?', [$sid, $uid]);
    $s['my_impression'] = $mine['kind'] ?? null;

    if ($isOwner) {
      $s['is_owner'] = true;
      if ($s['type'] === 'private' && empty($s['access_key'])) {
        $s['access_key'] = self::generateAccessKey();
        Database::run('UPDATE spaces SET access_key = ? WHERE id = ?', [$s['access_key'], $sid]);
      }
      $s['views'] = (int)(Database::one('SELECT COUNT(*) AS total FROM space_views WHERE space_id = ?', [$sid])['total'] ?? 0);
      $s['impressions'] = (int)(Database::one('SELECT COUNT(*) AS total FROM space_impressions WHERE space_id = ?', [$sid])['total'] ?? 0);
      $s['impression_summary'] = Database::all('SELECT kind, COUNT(*) AS total FROM space_impressions WHERE space_id = ? GROUP BY kind ORDER BY total DESC', [$sid]);
      $s['likers'] = Database::all(
        'SELECT i.kind, i.created_at, u.id AS user_id, u.display_name, u.code, u.personal_color
         FROM space_impressions i JOIN users u ON u.id = i.user_id
         WHERE i.space_id = ? ORDER BY i.created_at DESC',
        [$sid]
      );
      // Owner sees ALL comments and can reply to any of them
      $s['comments'] = Database::all(
        'SELECT c.id, c.media_id, c.user_id, c.body, c.owner_reply, c.replied_at, c.created_at,
                u.display_name AS user_name, u.code AS user_code, u.personal_color AS user_color
         FROM space_comments c JOIN users u ON u.id = c.user_id
         WHERE c.space_id = ? ORDER BY c.created_at DESC',
        [$sid]
      );
      if ($s['type'] === 'private') {
        $s['members'] = Database::all(
          "SELECT m.id AS member_id, m.user_id, m.status, m.requested_at, m.approved_at, m.expires_at,
                  u.display_name, u.code, u.personal_color
           FROM space_members m JOIN users u ON u.id = m.user_id
           WHERE m.space_id = ?
           ORDER BY FIELD(m.status, 'pending', 'approved', 'rejected', 'revoked'), m.requested_at DESC",
          [$sid]
        );
      }
    } else {
      $s['is_owner'] = false;
      unset($s['access_key']);
      // Viewers ONLY see their own comments & owner replies (comments are strictly private to the owner!)
      $s['comments'] = Database::all(
        'SELECT c.id, c.media_id, c.user_id, c.body, c.owner_reply, c.replied_at, c.created_at,
                u.display_name AS user_name, u.personal_color AS user_color
         FROM space_comments c JOIN users u ON u.id = c.user_id
         WHERE c.space_id = ? AND c.user_id = ? ORDER BY c.created_at DESC',
        [$sid, $uid]
      );
    }
    unset($s['space_visibility']);
    Response::ok(['space' => $s]);
  }

  public function markMediaViewed(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    $mid = Validator::int($r->input('media_id'), 'media_id');
    $m = Database::one(
      "SELECT m.id, m.space_id, s.type, s.owner_id, s.expires_at, o.space_visibility
       FROM space_media m JOIN spaces s ON s.id = m.space_id JOIN users o ON o.id = s.owner_id
       WHERE m.id = ? AND s.deleted_at IS NULL AND s.is_hidden = 0 AND o.is_banned = 0",
      [$mid]
    );
    if (!$m) throw new ApiException('Picture not found', 404);
    $spaceCheck = [
      'id'               => $m['space_id'],
      'type'             => $m['type'],
      'owner_id'         => $m['owner_id'],
      'expires_at'       => $m['expires_at'],
      'space_visibility' => $m['space_visibility'],
    ];
    if (!$this->canView($uid, $spaceCheck)) throw new ApiException('Access denied', 403);
    Database::run('INSERT IGNORE INTO space_media_views (media_id, user_id) VALUES (?, ?)', [$mid, $uid]);
    if ((int)$m['owner_id'] !== $uid) {
      Database::run('INSERT IGNORE INTO space_views (space_id, user_id) VALUES (?, ?)', [(int)$m['space_id'], $uid]);
    }
    Response::ok(['media_id' => $mid, 'viewed' => true]);
  }

  public function mine(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    // Active galleries that have media (or created galleries)
    $spaces = Database::all("SELECT s.id, s.type, s.title, s.description, s.item_count, s.access_key, s.expires_at, s.created_at,
        GREATEST(0, IFNULL(UNIX_TIMESTAMP(s.expires_at) - UNIX_TIMESTAMP(NOW()), 0)) AS seconds_left,
        (SELECT id FROM space_media WHERE space_id = s.id ORDER BY position, id LIMIT 1) AS cover_media_id,
        (SELECT mime FROM space_media WHERE space_id = s.id ORDER BY position, id LIMIT 1) AS cover_mime,
        (SELECT COUNT(*) FROM space_media m WHERE m.space_id = s.id) AS media_count,
        (SELECT COUNT(*) FROM space_views v WHERE v.space_id = s.id) AS views,
        (SELECT COUNT(*) FROM space_impressions i WHERE i.space_id = s.id) AS impressions,
        (SELECT COUNT(*) FROM space_comments c WHERE c.space_id = s.id) AS comment_count,
        (SELECT COUNT(*) FROM space_members sm WHERE sm.space_id = s.id AND sm.status = 'pending') AS pending_requests,
        (SELECT COUNT(*) FROM space_members sm WHERE sm.space_id = s.id AND sm.status = 'approved') AS approved_members
        FROM spaces s
        WHERE s.owner_id = ? AND s.deleted_at IS NULL
          AND (s.type = 'public' OR EXISTS (SELECT 1 FROM space_media smed WHERE smed.space_id = s.id) OR s.access_key IS NOT NULL)
        ORDER BY s.id DESC", [$uid]);

    foreach ($spaces as &$s) {
      $sid = (int)$s['id'];
      $s['id'] = $sid;
      if ($s['type'] === 'private' && empty($s['access_key'])) {
        $s['access_key'] = self::generateAccessKey();
        Database::run('UPDATE spaces SET access_key = ? WHERE id = ?', [$s['access_key'], $sid]);
      }
      $s['seconds_left'] = $s['expires_at'] ? (int)$s['seconds_left'] : null;
      $s['is_expired'] = !empty($s['expires_at']) && (int)$s['seconds_left'] <= 0;
      $s['media_count'] = (int)$s['media_count'];
      $s['views'] = (int)$s['views'];
      $s['impressions'] = (int)$s['impressions'];
      $s['comment_count'] = (int)$s['comment_count'];
      $s['pending_requests'] = (int)$s['pending_requests'];
      $s['approved_members'] = (int)$s['approved_members'];
      $s['impression_summary'] = Database::all('SELECT kind, COUNT(*) AS total FROM space_impressions WHERE space_id = ? GROUP BY kind ORDER BY total DESC', [$sid]);
      $s['media'] = Database::all('SELECT id, mime, created_at FROM space_media WHERE space_id = ? ORDER BY position, id', [$sid]);
    }

    // Galleries from others where this user has requested or been granted access
    $joined = Database::all("SELECT s.id, s.type, s.title, s.description, sm.status AS member_status, sm.requested_at, sm.approved_at,
        o.display_name AS owner_name, o.code AS owner_code, o.personal_color AS owner_color,
        (SELECT id FROM space_media WHERE space_id = s.id ORDER BY position, id LIMIT 1) AS cover_media_id,
        (SELECT COUNT(*) FROM space_media WHERE space_id = s.id) AS media_count
      FROM space_members sm
      JOIN spaces s ON s.id = sm.space_id
      JOIN users o ON o.id = s.owner_id
      WHERE sm.user_id = ? AND s.deleted_at IS NULL AND s.is_hidden = 0 AND o.is_banned = 0
      ORDER BY sm.updated_at DESC", [$uid]);

    foreach ($joined as &$j) {
      $j['id'] = (int)$j['id'];
      $j['media_count'] = (int)$j['media_count'];
      if ($j['member_status'] !== 'approved') {
        $j['cover_media_id'] = null;
      }
    }

    // Incoming requests across all of the user's private galleries
    $incoming = Database::all("SELECT sm.id AS request_id, sm.space_id, sm.user_id, sm.status, sm.requested_at, sm.approved_at,
        s.title AS space_title, u.display_name AS user_name, u.code AS user_code, u.personal_color AS user_color
      FROM space_members sm
      JOIN spaces s ON s.id = sm.space_id
      JOIN users u ON u.id = sm.user_id
      WHERE s.owner_id = ? AND s.deleted_at IS NULL
      ORDER BY FIELD(sm.status, 'pending', 'approved', 'rejected', 'revoked'), sm.requested_at DESC", [$uid]);

    Response::ok([
      'spaces'            => $spaces,
      'max_galleries'     => self::MAX_GALLERIES_PER_USER,
      'joined_galleries'  => $joined,
      'incoming_requests' => $incoming,
    ]);
  }

  public function createPublic(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    RateLimiter::hit('upload:' . $uid, 20, 3600);

    // Clean up legacy empty metadata-only private space rows that had no media or key
    Database::run(
      "UPDATE spaces s SET s.deleted_at = NOW()
       WHERE s.owner_id = ? AND s.type = 'private' AND s.access_key IS NULL AND s.deleted_at IS NULL
         AND NOT EXISTS (SELECT 1 FROM space_media m WHERE m.space_id = s.id)",
      [$uid]
    );

    $activeCount = (int)(Database::one(
      "SELECT COUNT(*) AS c FROM spaces s
       WHERE s.owner_id = ? AND s.deleted_at IS NULL
         AND (s.type = 'public' OR s.access_key IS NOT NULL OR EXISTS (SELECT 1 FROM space_media m WHERE m.space_id = s.id))",
      [$uid]
    )['c'] ?? 0);

    if ($activeCount >= self::MAX_GALLERIES_PER_USER) {
      throw new ApiException('You can have up to ' . self::MAX_GALLERIES_PER_USER . ' Hue Galleries. Delete one to create a new gallery.', 422);
    }

    $title = Validator::str($r->input('title'), 'Title', 1, 80);
    $typeInput = strtolower(trim((string)$r->input('type', 'public')));
    $type = in_array($typeInput, ['public', 'private'], true) ? $typeInput : 'public';
    $desc = mb_substr(trim((string)$r->input('description', '')), 0, 300);

    $files = $_FILES['images'] ?? null;
    $isVideo = false;
    if (!$files && isset($_FILES['video'])) {
      $video = $_FILES['video']; $isVideo = true;
      $files = ['name' => [$video['name']], 'type' => [$video['type']], 'tmp_name' => [$video['tmp_name']], 'error' => [$video['error']], 'size' => [$video['size']]];
    }
    if (!$files || !is_array($files['name'])) throw new ApiException('Add your favourite pictures to create the gallery', 422);
    if (count($files['name']) > self::MAX_MEDIA_PER_GALLERY) {
      throw new ApiException('A Hue Gallery holds up to ' . self::MAX_MEDIA_PER_GALLERY . ' items', 422);
    }

    $accessKey = $type === 'private' ? self::generateAccessKey() : null;
    $durationMinutes = (int)$r->input('duration_minutes', $type === 'private' ? 4320 : 0);
    if ($durationMinutes > 0) {
      $durationMinutes = max(5, min(43200, $durationMinutes)); // 5 min to 30 days
      $sid = Database::insert(
        "INSERT INTO spaces (owner_id, type, title, description, access_key, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))",
        [$uid, $type, $title, $desc, $accessKey, $durationMinutes]
      );
    } else {
      $sid = Database::insert(
        "INSERT INTO spaces (owner_id, type, title, description, access_key) VALUES (?, ?, ?, ?, ?)",
        [$uid, $type, $title, $desc, $accessKey]
      );
    }

    $saved = $this->saveMediaBatch((int)$sid, $files, $isVideo, 0);
    if ($saved === 0) {
      Database::run('DELETE FROM spaces WHERE id = ?', [$sid]);
      throw new ApiException($isVideo ? 'Choose a supported video up to 100 MB (MP4, WebM or MOV)' : 'Choose supported images (JPEG, PNG, WebP)', 422);
    }
    Database::run('UPDATE spaces SET item_count = ? WHERE id = ?', [$saved, $sid]);
    Response::ok([
      'space_id'         => (int)$sid,
      'type'             => $type,
      'access_key'       => $accessKey,
      'duration_minutes' => $durationMinutes,
    ]);
  }

  public function addMedia(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    RateLimiter::hit('upload:' . $uid, 30, 3600);
    $sid = Validator::int($r->input('space_id'), 'space_id');
    $s = Database::one('SELECT id, type FROM spaces WHERE id = ? AND owner_id = ? AND deleted_at IS NULL', [$sid, $uid]);
    if (!$s) throw new ApiException('Gallery not found', 404);

    $existingCount = (int)(Database::one('SELECT COUNT(*) AS c FROM space_media WHERE space_id = ?', [$sid])['c'] ?? 0);
    $files = $_FILES['images'] ?? null;
    if (!$files || !is_array($files['name'])) throw new ApiException('Choose pictures to add', 422);
    if ($existingCount + count($files['name']) > self::MAX_MEDIA_PER_GALLERY) {
      throw new ApiException('A Hue Gallery can hold up to ' . self::MAX_MEDIA_PER_GALLERY . ' pictures (currently ' . $existingCount . ')', 422);
    }

    $maxPos = (int)(Database::one('SELECT COALESCE(MAX(position), -1) AS p FROM space_media WHERE space_id = ?', [$sid])['p'] ?? -1);
    $saved = $this->saveMediaBatch($sid, $files, false, $maxPos + 1);
    if ($saved === 0) throw new ApiException('Could not save selected pictures', 422);

    $newCount = (int)(Database::one('SELECT COUNT(*) AS c FROM space_media WHERE space_id = ?', [$sid])['c'] ?? 0);
    Database::run('UPDATE spaces SET item_count = ?, updated_at = NOW() WHERE id = ?', [$newCount, $sid]);
    Response::ok(['added' => $saved, 'media_count' => $newCount]);
  }

  public function deleteMedia(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    $mid = Validator::int($r->input('media_id'), 'media_id');
    $m = Database::one(
      'SELECT m.id, m.space_id, m.path FROM space_media m JOIN spaces s ON s.id = m.space_id WHERE m.id = ? AND s.owner_id = ? AND s.deleted_at IS NULL',
      [$mid, $uid]
    );
    if (!$m) throw new ApiException('Picture not found', 404);

    @unlink(Config::get('storage') . '/public_media/' . basename($m['path']));
    Database::run('DELETE FROM space_media WHERE id = ?', [$mid]);
    Database::run('DELETE FROM space_comments WHERE media_id = ?', [$mid]);

    $sid = (int)$m['space_id'];
    $rem = (int)(Database::one('SELECT COUNT(*) AS c FROM space_media WHERE space_id = ?', [$sid])['c'] ?? 0);
    Database::run('UPDATE spaces SET item_count = ?, updated_at = NOW() WHERE id = ?', [$rem, $sid]);
    Response::ok(['remaining' => $rem, 'deleted_media_id' => $mid]);
  }

  private function saveMediaBatch(int $sid, array $files, bool $isVideo, int $startPos): int {
    $dir = Config::get('storage') . '/public_media';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
      throw new ApiException('Could not create media storage directory', 500);
    }
    $allowedImages = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/heic' => 'heic', 'image/heif' => 'heif'];
    $allowedVideos = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
    $saved = 0;
    foreach ($files['tmp_name'] as $i => $tmp) {
      if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
      $limit = $isVideo ? 100 * 1024 * 1024 : max(15 * 1024 * 1024, (int)Config::get('max_upload_bytes', 10 * 1024 * 1024));
      if ($files['size'][$i] < 1 || $files['size'][$i] > $limit) continue;
      $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
      $extension = $isVideo ? ($allowedVideos[$mime] ?? null) : ($allowedImages[$mime] ?? null);
      if (!$extension && !$isVideo) {
        $extClient = strtolower(pathinfo($files['name'][$i] ?? '', PATHINFO_EXTENSION));
        $extMap = ['jpg' => ' image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        if (isset($extMap[$extClient]) && @getimagesize($tmp)) {
          $extension = $extClient === 'jpeg' ? 'jpg' : $extClient;
          $mime = trim($extMap[$extClient]);
        }
      }
      if (!$extension) continue;
      $name = bin2hex(random_bytes(16)) . '.' . $extension;
      if (!move_uploaded_file($tmp, "$dir/$name")) continue;
      Database::insert(
        'INSERT INTO space_media (space_id, path, mime, size, position) VALUES (?,?,?,?,?)',
        [$sid, $name, $mime, $files['size'][$i], $startPos + $saved]
      );
      $saved++;
    }
    return $saved;
  }

  public function delete(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $sid = Validator::int($r->input('space_id'), 'space_id');
    $s = Database::one('SELECT id, type FROM spaces WHERE id = ? AND owner_id = ? AND deleted_at IS NULL', [$sid, $u['id']]);
    if (!$s) throw new ApiException('Gallery not found', 404);
    foreach (Database::all('SELECT path FROM space_media WHERE space_id = ?', [$sid]) as $m) {
      @unlink(Config::get('storage') . '/public_media/' . basename($m['path']));
    }
    Database::run('DELETE FROM space_media WHERE space_id = ?', [$sid]);
    Database::run('UPDATE spaces SET deleted_at = NOW(), access_key = NULL WHERE id = ?', [$sid]);
    Database::run("UPDATE space_members SET status = 'revoked' WHERE space_id = ?", [$sid]);
    Database::run('UPDATE space_access_keys SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$sid]);
    Database::run('UPDATE space_grants SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$sid]);
    $this->purgeRelay($sid);
    Response::ok(['deleted_space_id' => $sid]);
  }

  /** Media is streamed through PHP so every request is authorised (both public and approved private galleries). */
  public function media(Request $r, array $p): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $m = Database::one(
      "SELECT m.id, m.path, m.mime, s.id AS space_id, s.type, s.owner_id, s.expires_at, o.space_visibility
       FROM space_media m JOIN spaces s ON s.id = m.space_id JOIN users o ON o.id = s.owner_id
       WHERE m.id = ? AND s.deleted_at IS NULL AND s.is_hidden = 0 AND o.is_banned = 0",
      [(int)$p['id']]
    );
    if (!$m) throw new ApiException('Not found', 404);
    $spaceCheck = ['id' => $m['space_id'], 'type' => $m['type'], 'owner_id' => $m['owner_id'], 'expires_at' => $m['expires_at'], 'space_visibility' => $m['space_visibility']];
    if (!$this->canView((int)$u['id'], $spaceCheck)) throw new ApiException('Not found', 404);

    $path = Config::get('storage') . '/public_media/' . basename($m['path']);
    if (!is_file($path)) throw new ApiException('Not found', 404);
    $size = filesize($path);
    header('Content-Type: ' . $m['mime']);
    header('Content-Disposition: inline');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=300');
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
    while ($remaining > 0 && !feof($handle)) {
      $chunk = fread($handle, min(8192, $remaining));
      echo $chunk;
      $remaining -= strlen($chunk);
    }
    fclose($handle); exit;
  }

  public function impress(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    RateLimiter::hit('impress:' . $u['id'], 60, 60);
    $sid = Validator::int($r->input('space_id'), 'space_id');
    $sp = Database::one(
      "SELECT s.id, s.type, s.owner_id, s.title, o.space_visibility
       FROM spaces s JOIN users o ON o.id = s.owner_id
       WHERE s.id = ? AND s.deleted_at IS NULL AND s.is_hidden = 0",
      [$sid]
    );
    if (!$sp || !$this->canView((int)$u['id'], $sp)) throw new ApiException('Gallery not found', 404);
    $had = Database::one('SELECT 1 FROM space_impressions WHERE space_id = ? AND user_id = ?', [$sid, $u['id']]);
    $kind = $r->input('kind');
    if ($kind === null || $kind === '') {
      Database::run('DELETE FROM space_impressions WHERE space_id = ? AND user_id = ?', [$sid, $u['id']]);
    } else {
      Database::run(
        'INSERT INTO space_impressions (space_id, user_id, kind) VALUES (?,?,?) ON DUPLICATE KEY UPDATE kind = VALUES(kind)',
        [$sid, $u['id'], Validator::oneOf($kind, Validator::IMPRESSIONS, 'kind')]
      );
    }
    if (!$had && $kind && (int)$sp['owner_id'] !== (int)$u['id']) {
      Notifier::send((int)$sp['owner_id'], 'impression', 'Gallery reaction', $u['display_name'] . ' liked "' . $sp['title'] . '"', ['space_id' => $sid]);
    }
    Response::ok();
  }

  // ---------------- Private Comments & Owner Replies ----------------
  public function addComment(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    RateLimiter::hit('comment:' . $uid, 30, 60);
    $sid = Validator::int($r->input('space_id'), 'space_id');
    $sp = Database::one(
      "SELECT s.id, s.type, s.owner_id, s.title, o.space_visibility
       FROM spaces s JOIN users o ON o.id = s.owner_id
       WHERE s.id = ? AND s.deleted_at IS NULL AND s.is_hidden = 0",
      [$sid]
    );
    if (!$sp || !$this->canView($uid, $sp)) throw new ApiException('Gallery not found', 404);
    $body = Validator::str($r->input('body'), 'Comment', 1, 1000);
    $mediaId = $r->input('media_id') ? Validator::int($r->input('media_id'), 'media_id') : null;

    $cid = Database::insert(
      'INSERT INTO space_comments (space_id, media_id, user_id, body) VALUES (?,?,?,?)',
      [$sid, $mediaId, $uid, $body]
    );
    if ((int)$sp['owner_id'] !== $uid) {
      Notifier::send(
        (int)$sp['owner_id'],
        'space_comment',
        'New private comment on "' . $sp['title'] . '"',
        $u['display_name'] . ': ' . mb_substr($body, 0, 80),
        ['space_id' => $sid, 'comment_id' => (int)$cid]
      );
    }
    $comment = Database::one(
      'SELECT c.id, c.media_id, c.user_id, c.body, c.owner_reply, c.replied_at, c.created_at,
              u.display_name AS user_name, u.code AS user_code, u.personal_color AS user_color
       FROM space_comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?',
      [$cid]
    );
    Response::ok(['comment' => $comment]);
  }

  public function replyComment(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    $cid = Validator::int($r->input('comment_id'), 'comment_id');
    $reply = Validator::str($r->input('reply'), 'Reply', 1, 1000);
    $c = Database::one(
      'SELECT c.id, c.space_id, c.user_id, s.owner_id, s.title
       FROM space_comments c JOIN spaces s ON s.id = c.space_id
       WHERE c.id = ? AND s.deleted_at IS NULL',
      [$cid]
    );
    if (!$c || (int)$c['owner_id'] !== $uid) throw new ApiException('Only the gallery owner can reply to comments', 403);
    Database::run('UPDATE space_comments SET owner_reply = ?, replied_at = NOW() WHERE id = ?', [$reply, $cid]);
    if ((int)$c['user_id'] !== $uid) {
      Notifier::send(
        (int)$c['user_id'],
        'space_comment_reply',
        $u['display_name'] . ' replied to your comment',
        mb_substr($reply, 0, 80),
        ['space_id' => (int)$c['space_id'], 'comment_id' => $cid]
      );
    }
    Response::ok(['comment_id' => $cid, 'owner_reply' => $reply]);
  }

  public function deleteComment(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    $cid = Validator::int($r->input('comment_id'), 'comment_id');
    $c = Database::one(
      'SELECT c.id, c.user_id, s.owner_id FROM space_comments c JOIN spaces s ON s.id = c.space_id WHERE c.id = ?',
      [$cid]
    );
    if (!$c || ((int)$c['user_id'] !== $uid && (int)$c['owner_id'] !== $uid)) {
      throw new ApiException('Cannot delete this comment', 403);
    }
    Database::run('DELETE FROM space_comments WHERE id = ?', [$cid]);
    Response::ok();
  }

  // ---------------- Private Gallery Access Keys & Approval Workflow ----------------
  public function createKey(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    RateLimiter::hit('key:' . $u['id'], 30, 3600);
    $sid = Validator::int($r->input('space_id'), 'space_id');
    $s = $this->ownedPrivate((int)$u['id'], $sid);
    $rotate = $r->input('rotate', false) ? true : empty($s['access_key']);
    $newKey = $rotate ? self::generateAccessKey() : (string)$s['access_key'];
    $minutes = (int)$r->input('duration_minutes', $r->input('minutes', 4320));
    if ($minutes > 0) {
      $minutes = max(5, min(43200, $minutes));
      Database::run(
        'UPDATE spaces SET access_key = ?, expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?',
        [$newKey, $minutes, $s['id']]
      );
      $secondsLeft = $minutes * 60;
    } else {
      Database::run('UPDATE spaces SET access_key = ?, expires_at = NULL WHERE id = ?', [$newKey, $s['id']]);
      $secondsLeft = null;
    }
    Response::ok(['key' => $newKey, 'access_key' => $newKey, 'seconds_left' => $secondsLeft, 'duration_minutes' => $minutes]);
  }

  public function redeemKey(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    RateLimiter::hit('redeem:' . $uid, 15, 600);
    RateLimiter::hit('redeem-ip:' . $r->ip(), 30, 600);

    $rawKey = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$r->input('key', '')));
    if (strlen($rawKey) < 6) throw new ApiException('Enter a valid Hue Gallery Key', 422);
    $formatted = strlen($rawKey) === 8 ? substr($rawKey, 0, 4) . '-' . substr($rawKey, 4) : $rawKey;

    $s = Database::one(
      "SELECT s.id, s.title, s.owner_id, s.item_count, s.expires_at, o.display_name AS owner_name
       FROM spaces s JOIN users o ON o.id = s.owner_id
       WHERE (s.access_key = ? OR s.access_key = ?) AND s.type = 'private' AND s.deleted_at IS NULL AND o.is_banned = 0",
      [$formatted, $rawKey]
    );

    if ($s) {
      if (!empty($s['expires_at']) && strtotime((string)$s['expires_at']) <= time()) {
        throw new ApiException('This private gallery key has expired', 410);
      }
      $sid = (int)$s['id'];
      $ownerId = (int)$s['owner_id'];
      if ($ownerId === $uid) throw new ApiException('This is your own gallery key', 422);
      if (UserController::blocked($uid, $ownerId)) throw new ApiException('That key is invalid or unavailable', 404);

      $existing = Database::one('SELECT id, status FROM space_members WHERE space_id = ? AND user_id = ?', [$sid, $uid]);
      if ($existing && $existing['status'] === 'approved') {
        Response::ok([
          'status'     => 'approved',
          'space_id'   => $sid,
          'title'      => $s['title'],
          'owner_name' => $s['owner_name'],
          'message'    => 'You already have access to this gallery.',
        ]);
      }
      if ($existing && $existing['status'] === 'pending') {
        Response::ok([
          'status'     => 'pending',
          'space_id'   => $sid,
          'title'      => $s['title'],
          'owner_name' => $s['owner_name'],
          'message'    => 'Request already sent! Waiting for ' . $s['owner_name'] . ' to accept.',
        ]);
      }

      Database::run(
        "INSERT INTO space_members (space_id, user_id, status, requested_at, approved_at)
         VALUES (?, ?, 'pending', NOW(), NULL)
         ON DUPLICATE KEY UPDATE status = 'pending', requested_at = NOW(), approved_at = NULL",
        [$sid, $uid]
      );
      Notifier::send(
        $ownerId,
        'space_access_request',
        'Gallery Access Request',
        $u['display_name'] . ' used your key to request access to "' . $s['title'] . '"',
        ['space_id' => $sid, 'user_id' => $uid]
      );
      Response::ok([
        'status'     => 'pending',
        'space_id'   => $sid,
        'title'      => $s['title'],
        'owner_name' => $s['owner_name'],
        'message'    => 'Request sent to ' . $s['owner_name'] . '! Once accepted, pictures will be downloaded to your Hue gallery.',
      ]);
    }

    // Fallback to legacy temporary relay keys
    $k = Database::one("SELECT k.id, k.space_id, k.expires_at, s.title, s.item_count, s.owner_id, UNIX_TIMESTAMP(k.expires_at) - UNIX_TIMESTAMP(NOW()) AS seconds_left
        FROM space_access_keys k JOIN spaces s ON s.id = k.space_id
        WHERE k.key_hash = ? AND k.revoked_at IS NULL AND k.expires_at > NOW() AND s.deleted_at IS NULL", [hash('sha256', $rawKey)]);
    if (!$k) throw new ApiException('That gallery key is invalid or has been rotated', 404);
    if ((int)$k['owner_id'] === $uid || UserController::blocked($uid, (int)$k['owner_id'])) throw new ApiException('That key is invalid or has expired', 404);
    Database::run('INSERT INTO space_grants (space_id, key_id, viewer_id, expires_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE revoked_at = NULL', [$k['space_id'], $k['id'], $uid, $k['expires_at']]);
    $g = Database::one('SELECT id FROM space_grants WHERE key_id = ? AND viewer_id = ?', [$k['id'], $uid]);
    Notifier::send((int)$k['owner_id'], 'space_access', 'Space opened', $u['display_name'] . ' opened your private Space', ['space_id' => (int)$k['space_id']]);
    Response::ok(['status' => 'legacy_grant', 'grant_id' => (int)$g['id'], 'title' => $k['title'], 'item_count' => (int)$k['item_count'], 'seconds_left' => (int)$k['seconds_left']]);
  }

  public function listRequests(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    $incoming = Database::all("SELECT sm.id AS request_id, sm.space_id, sm.user_id, sm.status, sm.requested_at, sm.approved_at,
        s.title AS space_title, u.display_name AS user_name, u.code AS user_code, u.personal_color AS user_color
      FROM space_members sm
      JOIN spaces s ON s.id = sm.space_id
      JOIN users u ON u.id = sm.user_id
      WHERE s.owner_id = ? AND s.deleted_at IS NULL
      ORDER BY FIELD(sm.status, 'pending', 'approved', 'rejected', 'revoked'), sm.requested_at DESC", [$uid]);

    $outgoing = Database::all("SELECT sm.id AS request_id, sm.space_id, sm.status, sm.requested_at, sm.approved_at,
        s.title AS space_title, o.display_name AS owner_name, o.code AS owner_code, o.personal_color AS owner_color,
        (SELECT COUNT(*) FROM space_media WHERE space_id = s.id) AS media_count
      FROM space_members sm
      JOIN spaces s ON s.id = sm.space_id
      JOIN users o ON o.id = s.owner_id
      WHERE sm.user_id = ? AND s.deleted_at IS NULL
      ORDER BY sm.updated_at DESC", [$uid]);

    Response::ok(['incoming' => $incoming, 'outgoing' => $outgoing]);
  }

  public function respondRequest(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    $reqId = Validator::int($r->input('request_id'), 'request_id');
    $action = Validator::oneOf($r->input('action'), ['approve', 'reject', 'revoke'], 'action');

    $row = Database::one(
      'SELECT sm.id, sm.space_id, sm.user_id, s.owner_id, s.title
       FROM space_members sm JOIN spaces s ON s.id = sm.space_id
       WHERE sm.id = ? AND s.deleted_at IS NULL',
      [$reqId]
    );
    if (!$row || (int)$row['owner_id'] !== $uid) throw new ApiException('Request not found', 404);

    $statusMap = ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked'];
    $newStatus = $statusMap[$action];
    if ($newStatus === 'approved') {
      Database::run("UPDATE space_members SET status = 'approved', approved_at = NOW() WHERE id = ?", [$reqId]);
      Notifier::send(
        (int)$row['user_id'],
        'space_access_approved',
        'Gallery Access Accepted',
        $u['display_name'] . ' accepted your request to view "' . $row['title'] . '"',
        ['space_id' => (int)$row['space_id']]
      );
    } else {
      Database::run('UPDATE space_members SET status = ? WHERE id = ?', [$newStatus, $reqId]);
    }
    Response::ok(['request_id' => $reqId, 'status' => $newStatus]);
  }

  /**
   * Remote Cache Sync:
   * Viewer sends the list of space_ids and media_ids cached on their phone.
   * Returns which media_ids and space_ids are still valid; any deleted picture or revoked/deleted gallery
   * is returned in deleted_media_ids / deleted_space_ids so the phone wipes them immediately upon coming online!
   */
  public function syncCache(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $uid = (int)$u['id'];
    $mediaIdsRaw = $r->input('media_ids', []);
    $spaceIdsRaw = $r->input('space_ids', []);
    if (!is_array($mediaIdsRaw)) $mediaIdsRaw = [];
    if (!is_array($spaceIdsRaw)) $spaceIdsRaw = [];

    $validSpaces = [];
    $deletedSpaces = [];
    foreach ($spaceIdsRaw as $sidVal) {
      $sid = (int)$sidVal;
      if ($sid <= 0) continue;
      $s = Database::one(
        "SELECT s.id, s.type, s.owner_id, s.expires_at, o.space_visibility
         FROM spaces s JOIN users o ON o.id = s.owner_id
         WHERE s.id = ? AND s.deleted_at IS NULL AND s.is_hidden = 0 AND o.is_banned = 0",
        [$sid]
      );
      if ($s && $this->canView($uid, $s)) {
        $validSpaces[$sid] = true;
      } else {
        $deletedSpaces[] = $sid;
      }
    }

    $deletedMedia = [];
    foreach ($mediaIdsRaw as $midVal) {
      $mid = (int)$midVal;
      if ($mid <= 0) continue;
      $m = Database::one(
        "SELECT m.id, m.space_id, s.type, s.owner_id, s.expires_at, o.space_visibility
         FROM space_media m JOIN spaces s ON s.id = m.space_id JOIN users o ON o.id = s.owner_id
         WHERE m.id = ? AND s.deleted_at IS NULL AND s.is_hidden = 0 AND o.is_banned = 0",
        [$mid]
      );
      if (!$m) {
        $deletedMedia[] = $mid;
        continue;
      }
      $sid = (int)$m['space_id'];
      if (isset($validSpaces[$sid])) continue;
      $spaceCheck = ['id' => $sid, 'type' => $m['type'], 'owner_id' => $m['owner_id'], 'expires_at' => $m['expires_at'], 'space_visibility' => $m['space_visibility']];
      if ($this->canView($uid, $spaceCheck)) {
        $validSpaces[$sid] = true;
      } else {
        $deletedMedia[] = $mid;
      }
    }

    Response::ok([
      'deleted_space_ids' => array_values(array_unique($deletedSpaces)),
      'deleted_media_ids' => array_values(array_unique($deletedMedia)),
    ]);
  }

  // ---------------- Legacy Private Relay Compatibility ----------------
  public function registerPrivate(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $title = Validator::str($r->input('title', 'My private Space'), 'Title', 1, 80);
    $count = max(0, min(200, (int)$r->input('item_count', 0)));
    $s = Database::one("SELECT id FROM spaces WHERE owner_id = ? AND type = 'private' AND deleted_at IS NULL", [$u['id']]);
    if ($s) { Database::run('UPDATE spaces SET title = ?, item_count = ? WHERE id = ?', [$title, $count, $s['id']]); Response::ok(['space_id' => (int)$s['id']]); }
    Response::ok(['space_id' => Database::insert("INSERT INTO spaces (owner_id, type, title, item_count, access_key) VALUES (?, 'private', ?, ?, ?)", [$u['id'], $title, $count, self::generateAccessKey()])]);
  }

  private function ownedPrivate(int $uid, int $sid): array {
    $s = Database::one("SELECT * FROM spaces WHERE id = ? AND owner_id = ? AND type = 'private' AND deleted_at IS NULL", [$sid, $uid]);
    if (!$s) throw new ApiException('Gallery not found', 404);
    return $s;
  }

  public function revoke(Request $r): void {
    self::ensureSchema();
    $u = Auth::user($r);
    $s = $this->ownedPrivate((int)$u['id'], Validator::int($r->input('space_id'), 'space_id'));
    $newKey = self::generateAccessKey();
    Database::run('UPDATE spaces SET access_key = ? WHERE id = ?', [$newKey, $s['id']]);
    Database::run("UPDATE space_members SET status = 'revoked' WHERE space_id = ? AND status = 'approved'", [$s['id']]);
    Database::run('UPDATE space_access_keys SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$s['id']]);
    Database::run('UPDATE space_grants SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$s['id']]);
    $this->purgeRelay((int)$s['id']);
    Response::ok(['access_key' => $newKey]);
  }

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
