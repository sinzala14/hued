<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, ApiException, Database, Auth, Validator, RateLimiter, Notifier};

final class FriendController {
  public function list(Request $r): void {
    $u = Auth::user($r); $id = (int)$u['id']; $cols = Auth::PUBLIC_COLS;
    $block = 'AND NOT EXISTS (SELECT 1 FROM blocked_users b WHERE (b.user_id = ? AND b.blocked_id = u.id) OR (b.user_id = u.id AND b.blocked_id = ?))';
    $friends  = Database::all("SELECT $cols FROM friendships f JOIN users u ON u.id = IF(f.requester_id = ?, f.addressee_id, f.requester_id)
        WHERE f.status = 'accepted' AND ? IN (f.requester_id, f.addressee_id) AND u.is_banned = 0 $block ORDER BY u.display_name", [$id, $id, $id, $id]);
    $incoming = Database::all("SELECT $cols, f.id AS request_id FROM friendships f JOIN users u ON u.id = f.requester_id WHERE f.addressee_id = ? AND f.status = 'pending' AND u.is_banned = 0 $block", [$id, $id, $id]);
    $outgoing = Database::all("SELECT $cols FROM friendships f JOIN users u ON u.id = f.addressee_id WHERE f.requester_id = ? AND f.status = 'pending' AND u.is_banned = 0 $block", [$id, $id, $id]);
    $map = fn($rows) => array_map(fn($x) => Auth::publicProfile($x, $id) + (isset($x['request_id']) ? ['request_id' => (int)$x['request_id']] : []), $rows);
    Response::ok(['friends' => $map($friends), 'incoming' => $map($incoming), 'outgoing' => $map($outgoing)]);
  }

  public function request(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    RateLimiter::hit('freq:' . $me, 20, 3600);
    $to = Database::one('SELECT id, who_can_request FROM users WHERE code = ? AND is_banned = 0', [strtoupper((string)$r->input('code', ''))]);
    if (!$to || (int)$to['id'] === $me || UserController::blocked($me, (int)$to['id'])) throw new ApiException('No one found with that code', 404);
    RateLimiter::hit("freq:$me:{$to['id']}", 2, 86400);                      // no repeat-spamming one person
    if ($to['who_can_request'] === 'nobody') throw new ApiException('This person is not accepting friend requests', 403);
    $st = self::statusBetween($me, (int)$to['id']);
    if ($st === 'friends') throw new ApiException('You are already friends', 409);
    if ($st === 'outgoing') throw new ApiException('Request already sent', 409);
    if ($st === 'incoming') throw new ApiException('They already sent you a request. Accept it in People.', 409);
    Database::run("INSERT IGNORE INTO friendships (requester_id, addressee_id) VALUES (?,?)", [$me, $to['id']]);
    Notifier::send((int)$to['id'], 'friend_request', 'Friend request', $u['display_name'] . ' wants to connect', ['from_code' => $u['code']]);
    Response::ok();
  }

  public function accept(Request $r): void {
    $u = Auth::user($r);
    $f = Database::one("SELECT id, requester_id FROM friendships WHERE id = ? AND addressee_id = ? AND status = 'pending'", [Validator::int($r->input('request_id'), 'request_id'), $u['id']]);
    if (!$f || UserController::blocked((int)$u['id'], (int)$f['requester_id'])) throw new ApiException('Request not found', 404);
    Database::run("UPDATE friendships SET status = 'accepted' WHERE id = ?", [$f['id']]);
    Notifier::send((int)$f['requester_id'], 'friend_accepted', 'Friend request accepted', $u['display_name'] . ' accepted your request', ['from_code' => $u['code']]);
    Response::ok();
  }

  /** Recipient declines an incoming request. The row is removed so they can be asked again later. */
  public function reject(Request $r): void {
    $u = Auth::user($r);
    if (!Database::run("DELETE FROM friendships WHERE id = ? AND addressee_id = ? AND status = 'pending'", [Validator::int($r->input('request_id'), 'request_id'), $u['id']])) throw new ApiException('Request not found', 404);
    Response::ok();
  }

  /** Sender withdraws their own pending request. */
  public function cancel(Request $r): void {
    $u = Auth::user($r);
    if (!Database::run("DELETE FROM friendships WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'", [$u['id'], Validator::int($r->input('user_id'), 'user_id')])) throw new ApiException('Request not found', 404);
    Response::ok();
  }

  public function remove(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id']; $other = Validator::int($r->input('user_id'), 'user_id');
    Database::run("DELETE FROM friendships WHERE status = 'accepted' AND ((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?))", [$me, $other, $other, $me]);
    Response::ok();
  }

  /** none | friends | outgoing | incoming */
  public static function statusBetween(int $a, int $b): string {
    $f = Database::one('SELECT requester_id, status FROM friendships WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)', [$a, $b, $b, $a]);
    if (!$f) return 'none';
    if ($f['status'] === 'accepted') return 'friends';
    if ($f['status'] === 'declined') return 'none';
    return (int)$f['requester_id'] === $a ? 'outgoing' : 'incoming';
  }
}
