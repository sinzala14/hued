<?php
namespace Hue\Core;
final class Router {
  private array $routes = [];
  public function add(string $method, string $path, array $h): void {
    $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path) . '$#';
    $this->routes[] = [$method, $re, $h];
  }
  public function dispatch(Request $req, string $path): void {
    foreach ($this->routes as [$m, $re, $h]) {
      if ($m === $req->method() && preg_match($re, $path, $mm)) {
        $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
        [$cls, $fn] = $h; (new $cls())->$fn($req, $params); return;
      }
    }
    throw new ApiException('Not found', 404);
  }
}
