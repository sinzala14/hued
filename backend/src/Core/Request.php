<?php
namespace Hue\Core;
final class Request {
  public array $json = [];
  public function __construct() {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
      $d = json_decode($raw, true);
      if (!is_array($d)) throw new ApiException('Invalid JSON body', 400);
      $this->json = $d;
    }
  }
  public function method(): string { return $_SERVER['REQUEST_METHOD']; }
  public function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
  public function bearer(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $h, $m) ? $m[1] : null;
  }
  public function input(string $k, $d = null) { return $this->json[$k] ?? $_POST[$k] ?? $_GET[$k] ?? $d; }
}
