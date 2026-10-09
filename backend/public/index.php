<?php
declare(strict_types=1);
ini_set('display_errors', '0'); ini_set('log_errors', '1');
require __DIR__ . '/../src/Core/Response.php';
spl_autoload_register(function (string $c) {
  if (str_starts_with($c, 'Hue\\')) { $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (is_file($f)) require $f; }
});
use Hue\Core\{Router, Request, Response, ApiException, Config, RateLimiter};
use Hue\Controllers\{AuthController as A, UserController as U, FriendController as F, ChatController as C, MessageController as M, ColorController as K, SpaceController as S, ReportController as P, NotificationController as N};

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
try {
  if (Config::get('force_https') && empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https')
    throw new ApiException('HTTPS required', 400);
  $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
  // Shared hosting may expose the repository root and execute this front
  // controller through PATH_INFO, e.g. /backend/public/index.php/api/chats.
  if (!empty($_SERVER['PATH_INFO'])) $requestPath = $_SERVER['PATH_INFO'];
  elseif (preg_match('#/index\.php(/.*)$#', $requestPath, $match)) $requestPath = $match[1];
  $path = '/' . trim(preg_replace('#^/api(?:/|$)#', '', $requestPath), '/');
  $req = new Request();
  RateLimiter::hit('ip:' . $req->ip(), 300, 60);

  $r = new Router();
  $r->add('POST', '/auth/forgot',   [A::class, 'forgot']);
  $r->add('POST', '/auth/reset',    [A::class, 'reset']);
  $r->add('POST', '/auth/change-password', [A::class, 'changePassword']);
  $r->add('GET',  '/auth/sessions', [A::class, 'sessions']);
  $r->add('POST', '/auth/sessions/revoke', [A::class, 'revokeSession']);
  $r->add('POST', '/auth/logout-others', [A::class, 'logoutOthers']);
  $r->add('POST', '/users/privacy', [U::class, 'privacy']);
  $r->add('GET',  '/users/blocked', [U::class, 'blockedList']);
  $r->add('POST', '/users/avatar',  [U::class, 'uploadAvatar']);
  $r->add('GET',  '/users/avatar/{id}', [U::class, 'avatar']);
  $r->add('POST', '/friends/reject',   [F::class, 'reject']);
  $r->add('POST', '/friends/cancel',   [F::class, 'cancel']);
  $r->add('GET',  '/chats/{id}/search', [C::class, 'search']);
  $r->add('POST', '/chats/mute',  [C::class, 'mute']);
  $r->add('POST', '/chats/clear', [C::class, 'clear']);
  $r->add('POST', '/reports',     [P::class, 'create']);
  $r->add('GET',  '/notifications/settings', [N::class, 'getSettings']);
  $r->add('POST', '/notifications/settings', [N::class, 'setSettings']);
  $r->add('POST', '/devices/register',   [N::class, 'registerDevice']);
  $r->add('POST', '/devices/unregister', [N::class, 'unregisterDevice']);
  $r->add('GET',  '/spaces/public/{id}', [S::class, 'show']);
  $r->add('POST', '/auth/register', [A::class, 'register']);
  $r->add('POST', '/auth/login',    [A::class, 'login']);
  $r->add('POST', '/auth/logout',   [A::class, 'logout']);
  $r->add('GET',  '/users/profile', [U::class, 'me']);
  $r->add('POST', '/users/profile', [U::class, 'update']);
  $r->add('GET',  '/users/search',  [U::class, 'search']);
  $r->add('POST', '/users/block',   [U::class, 'block']);
  $r->add('GET',  '/users/{code}',  [U::class, 'byCode']);
  $r->add('GET',  '/friends',          [F::class, 'list']);
  $r->add('POST', '/friends/request',  [F::class, 'request']);
  $r->add('POST', '/friends/accept',   [F::class, 'accept']);
  $r->add('POST', '/friends/remove',   [F::class, 'remove']);
  $r->add('GET',  '/chats',            [C::class, 'list']);
  $r->add('POST', '/chats',            [C::class, 'open']);
  $r->add('GET',  '/chats/{id}/messages', [C::class, 'messages']);
  $r->add('POST', '/messages/send',       [M::class, 'send']);
  $r->add('POST', '/messages/send-voice', [M::class, 'sendVoice']);
  $r->add('POST', '/messages/image',      [M::class, 'sendImage']);
  $r->add('POST', '/messages/edit',    [M::class, 'edit']);
  $r->add('GET',  '/messages/media/{id}', [M::class, 'media']);
  $r->add('POST', '/messages/delete',  [M::class, 'delete']);
  $r->add('POST', '/messages/react',   [M::class, 'react']);
  $r->add('POST', '/messages/read',    [M::class, 'read']);
  $r->add('POST', '/colors/profile',   [K::class, 'profile']);
  $r->add('POST', '/colors/chat',      [K::class, 'chat']);
  $r->add('GET',  '/spaces/public',    [S::class, 'listPublic']);
  $r->add('POST', '/spaces/public',    [S::class, 'createPublic']);
  $r->add('GET',  '/spaces/mine',      [S::class, 'mine']);
  $r->add('POST', '/spaces/delete',    [S::class, 'delete']);
  $r->add('GET',  '/spaces/media/{id}',[S::class, 'media']);
  $r->add('POST', '/spaces/impressions',[S::class, 'impress']);
  $r->add('POST', '/spaces/private',   [S::class, 'registerPrivate']);
  $r->add('POST', '/spaces/private/key',[S::class, 'createKey']);
  $r->add('POST', '/spaces/access',    [S::class, 'redeemKey']);
  $r->add('POST', '/spaces/revoke',    [S::class, 'revoke']);
  $r->add('GET',  '/spaces/private/pending', [S::class, 'pending']);
  $r->add('POST', '/spaces/private/request', [S::class, 'requestItem']);
  $r->add('POST', '/spaces/private/fulfil',  [S::class, 'fulfil']);
  $r->add('GET',  '/spaces/private/fetch/{id}', [S::class, 'fetch']);
  $r->dispatch($req, $path);
} catch (ApiException $e) {
  Response::json(['ok' => false, 'error' => $e->getMessage()] + $e->extra, $e->status);
} catch (Throwable $e) {
  error_log((string)$e);
  Response::json(['ok' => false, 'error' => 'Server error'], 500);
}
