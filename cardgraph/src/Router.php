<?php
/**
 * Card Graph — Simple URL Router
 *
 * Maps HTTP method + path to controller methods.
 * Supports path parameters like {id}.
 * Handles auth middleware enforcement.
 */
class Router
{
    private array $routes = [];
    private array $authRoutes = [];

    public function get(string $path, array $handler, bool $requireAuth = true): void
    {
        $this->addRoute('GET', $path, $handler, $requireAuth);
    }

    public function post(string $path, array $handler, bool $requireAuth = true): void
    {
        $this->addRoute('POST', $path, $handler, $requireAuth);
    }

    public function put(string $path, array $handler, bool $requireAuth = true): void
    {
        $this->addRoute('PUT', $path, $handler, $requireAuth);
    }

    public function delete(string $path, array $handler, bool $requireAuth = true): void
    {
        $this->addRoute('DELETE', $path, $handler, $requireAuth);
    }

    private function addRoute(string $method, string $path, array $handler, bool $requireAuth): void
    {
        // Convert path params {id} to regex named groups
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method'      => $method,
            'pattern'     => $pattern,
            'handler'     => $handler,
            'requireAuth' => $requireAuth,
        ];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Handle CORS preflight
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                // Auth check
                if ($route['requireAuth']) {
                    $user = Auth::getCurrentUser();
                    if (!$user) {
                        jsonError('Unauthorized', 401);
                    }

                    // CSRF check for mutating requests
                    if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
                        CsrfGuard::validate();
                    }
                }

                // Extract path parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Instantiate controller and call method
                [$controllerClass, $methodName] = $route['handler'];
                $controller = new $controllerClass();
                $controller->$methodName($params);
                return;
            }
        }

        jsonError('Not found', 404);
    }
}
