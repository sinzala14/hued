<?php
namespace Hue\Core;
final class Validator {
  public const COLORS = ['green','red','purple','black','pink','yellow','blue','orange','white','gray','cyan','brown'];
  public const IMPRESSIONS = ['like','love','amazing','funny','interesting','beautiful','wow','emotional','unexpected','creative'];
  public const REACTIONS = ['like','love','amazing','funny','wow'];

  public static function str($v, string $field, int $min, int $max): string {
    if (!is_string($v)) throw new ApiException("$field is required", 422);
    $v = trim($v); $len = mb_strlen($v);
    if ($len < $min || $len > $max) throw new ApiException("$field must be $min-$max characters", 422);
    return $v;
  }
  public static function int($v, string $field): int {
    if (!is_numeric($v) || (int)$v < 1) throw new ApiException("$field is invalid", 422);
    return (int)$v;
  }
  public static function oneOf($v, array $allowed, string $field): string {
    if (!in_array($v, $allowed, true)) throw new ApiException("$field is invalid", 422);
    return $v;
  }
  public static function uuid($v, string $field): string {
    if (!is_string($v) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v))
      throw new ApiException("$field must be a UUID", 422);
    return strtolower($v);
  }
}
