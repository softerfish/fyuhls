<?php

namespace App\Core;

class Router {
    private array $routes = [];

    public function get(string $path, callable|array $handler): void {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void {
        $this->addRoute('POST', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable|array $handler): void {
        // Convert route parameters like {id} or {name:.+} to regex
        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+)(?::(.+))?\}/', function($matches) {
            $name = $matches[1];
            $regex = $matches[2] ?? '[^/]+';
            return "(?P<$name>$regex)";
        }, $path);
        
        $pattern = "#^" . $pattern . "$#";

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }

    public function dispatch(string $uri, string $method) {
        $uri = parse_url($uri, PHP_URL_PATH);

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $params = array_map('urldecode', $params);

                return $this->handleCallback($route['handler'], $params);
            }
        }

        // 404 Not Found
        http_response_code(404);
        echo "404 Not Found";
    }

    private function handleCallback($handler, $params) {
        // PHP 8 treats associative arrays passed to call_user_func_array as named arguments.
        // Our route placeholders are keyed like ['id' => '...'], but controller methods often
        // use different parameter names such as $sessionId or $gateway. Pass positional values
        // in route order instead so /foo/{id} can map to any single-argument method cleanly.
        $args = array_values($params);

        if (is_array($handler)) {
            [$controllerClass, $method] = $handler;

            // Fallback for Plugin controllers if composer dump-autoload hasn't been run on the server
            if (!class_exists($controllerClass) && str_starts_with($controllerClass, 'Plugin\\')) {
                // e.g. Plugin\Rewards\Controller\FrontendController -> src/Plugin/Rewards/Controller/FrontendController.php
                $relativePath = str_replace('\\', '/', substr($controllerClass, 7)) . '.php';
                $fullPath = dirname(__DIR__) . '/Plugin/' . $relativePath;
                if (file_exists($fullPath)) {
                    require_once $fullPath;
                }
            }

            $controller = new $controllerClass();
            return call_user_func_array([$controller, $method], $args);
        }

        return call_user_func_array($handler, $args);
    }
}
