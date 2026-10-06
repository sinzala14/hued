<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, Database, Auth, Validator, Notifier, RateLimiter};

function hueLabel(string $c): string {
  return ['green'=>'Ready for a relationship','red'=>'Not ready for anything','purple'=>'Feeling sad',"black"=>"Doesn't want to talk","pink"=>'In a relationship','yellow'=>'Open to friendship',
    'blue'=>'Open to meeting new people','orange'=>'Figuring things out','white'=>'Just chilling','gray'=>'Taking personal space','cyan'=>'Feeling social','brown'=>'Having a rough day'][$c] ?? $c;
}
final class ColorController {
  public function profile(Request $r): void {
    $u = Auth::user($r);
    $input = $r->input('colors');
    if (!is_array($input) || !$input) $input = [$r->input('color')];
    if (count($input) > 4) throw new \Hue\Core\ApiException('Choose no more than four colors', 422);
    $colors = array_values(array_unique(array_map(fn($c) => Validator::oneOf($c, Validator::COLORS, 'color'), $input)));
    $c = $colors[0];
    Database::run('UPDATE users SET personal_color = ?, personal_colors = ? WHERE id = ?', [$c, json_encode($colors), $u['id']]);
    foreach ($colors as $color) Database::run('INSERT INTO user_colors (user_id, color) VALUES (?,?)', [$u['id'], $color]);
    if ($u['color_visibility'] === 'everyone' && $u['personal_color'] !== $c) {
      try { RateLimiter::hit('colornotify:' . $u['id'], 1, 600);
        foreach (Database::all("SELECT IF(requester_id = ?, addressee_id, requester_id) AS fid FROM friendships WHERE status = 'accepted' AND ? IN (requester_id, addressee_id)", [$u['id'], $u['id']]) as $f)
          Notifier::send((int)$f['fid'], 'friend_activity', $u['display_name'], 'Updated their color: ' . hueLabel($c), ['code' => $u['code']]);
      } catch (\Hue\Core\ApiException $e) {}
    }
    Response::ok(['color' => $c, 'colors' => $colors]);
  }

  /** Chat colors are private; they are only exposed to the other person when revealed = true. */
  public function chat(Request $r): void {
    $u = Auth::user($r);
    $conv = ChatController::membership(Validator::int($r->input('conversation_id'), 'conversation_id'), (int)$u['id']);
    if ($r->input('color') === null) { Database::run('DELETE FROM chat_colors WHERE user_id = ? AND conversation_id = ?', [$u['id'], $conv['id']]); Response::ok(); }
    $c = Validator::oneOf($r->input('color'), Validator::COLORS, 'color');
    Database::run('INSERT INTO chat_colors (user_id, conversation_id, color, revealed) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE color = VALUES(color), revealed = VALUES(revealed)',
      [$u['id'], $conv['id'], $c, $r->input('reveal') ? 1 : 0]);
    Response::ok();
  }
}
