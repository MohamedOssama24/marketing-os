<?php
declare(strict_types=1);

final class Router
{
    /** @var array<int, array{0: string, 1: string, 2: callable}> */
    private array $routes = [];

    /** I segmenti `{nome}` accettano solo interi e arrivano all'handler come argomenti con nome. */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(string $method, string $path): never
    {
        $pathMatched = false;
        foreach ($this->routes as [$routeMethod, $regex, $handler]) {
            if (!preg_match($regex, $path, $matches)) continue;
            $pathMatched = true;
            if ($routeMethod !== $method) continue;
            $params = array_map('intval', array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
            $handler(...$params);
            Http::json(['error' => 'internal_server_error', 'message' => 'Nessuna risposta dall\'handler.'], 500);
        }
        if ($pathMatched) Http::json(['error' => 'method_not_allowed', 'message' => 'Metodo non consentito.'], 405);
        Http::json(['error' => 'not_found', 'message' => 'Endpoint non trovato.'], 404);
    }
}
