<?php
namespace Hue\Core;
final class ApiException extends \Exception {
  public function __construct(string $m, public int $status = 400, public array $extra = []) { parent::__construct($m); }
}
final class Response {
  public static function json($data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
  }
  public static function ok(array $data = []): never { self::json(['ok' => true] + $data); }
}
