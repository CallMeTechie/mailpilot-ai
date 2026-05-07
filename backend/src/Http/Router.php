<?php
declare(strict_types=1);

namespace MailPilot\Http;

use MailPilot\Controllers\AuthController;
use MailPilot\Controllers\BriefingController;
use MailPilot\Controllers\MailController;
use MailPilot\Controllers\MeController;
use MailPilot\Controllers\SettingsController;
use MailPilot\Controllers\SyncController;
use MailPilot\Http\Exceptions\HttpException;

/**
 * Tiny pattern-matching router. Supports {params} in path.
 *
 * Convention: handler string is "ControllerClass@method".
 */
final class Router
{
	/** @var list<array{method:string, pattern:string, handler:string}> */
	private array $routes = [];

	public function __construct(private readonly Kernel $kernel)
	{
	}

	public function get(string $pattern, string $handler): void    { $this->add('GET',    $pattern, $handler); }
	public function post(string $pattern, string $handler): void   { $this->add('POST',   $pattern, $handler); }
	public function patch(string $pattern, string $handler): void  { $this->add('PATCH',  $pattern, $handler); }
	public function delete(string $pattern, string $handler): void { $this->add('DELETE', $pattern, $handler); }

	private function add(string $method, string $pattern, string $handler): void
	{
		$this->routes[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler];
	}

	public function dispatch(): void
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

		// Handle CORS preflight globally
		if ($method === 'OPTIONS') {
			$this->applyCors();
			http_response_code(204);
			return;
		}
		$this->applyCors();

		foreach ($this->routes as $route) {
			if ($route['method'] !== $method) {
				continue;
			}
			$params = $this->match($route['pattern'], $path);
			if ($params !== null) {
				$this->invoke($route['handler'], $params);
				return;
			}
		}

		Response::error(404, 'NOT_FOUND', 'Route nicht gefunden');
	}

	/**
	 * @return array<string, string>|null
	 */
	private function match(string $pattern, string $path): ?array
	{
		$regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
		$regex = '#^' . $regex . '$#';
		if (!preg_match($regex, $path, $matches)) {
			return null;
		}
		$params = [];
		foreach ($matches as $k => $v) {
			if (is_string($k)) {
				$params[$k] = $v;
			}
		}
		return $params;
	}

	/**
	 * @param array<string, string> $params
	 */
	private function invoke(string $handler, array $params): void
	{
		[$controllerName, $methodName] = explode('@', $handler);
		$class = match ($controllerName) {
			'HealthController'   => \MailPilot\Controllers\HealthController::class,
			'AuthController'     => AuthController::class,
			'BriefingController' => BriefingController::class,
			'MailController'     => MailController::class,
			'SyncController'     => SyncController::class,
			'SettingsController' => SettingsController::class,
			'MeController'       => MeController::class,
			default              => throw new \RuntimeException("Unknown controller: {$controllerName}"),
		};

		try {
			$controller = new $class($this->kernel);
			$body = Request::jsonBody();
			$controller->$methodName($params, $body);
		} catch (HttpException $e) {
			Response::error($e->status, $e->errorCode, $e->getMessage());
		} catch (\Throwable $e) {
			$this->kernel->get(\Monolog\Logger::class)->error('dispatch.error', [
				'handler' => $handler,
				'err'     => $e->getMessage(),
				'trace'   => $e->getTraceAsString(),
			]);
			$debug = (bool)($this->kernel->config['app']['debug'] ?? false);
			$msg = $debug ? $e->getMessage() : 'Interner Fehler';
			Response::error(500, 'INTERNAL', $msg);
		}
	}

	private function applyCors(): void
	{
		$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
		$allowed = (array)($this->kernel->config['cors']['allowed_origins'] ?? []);

		header('Vary: Origin');
		header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
		header('Access-Control-Allow-Headers: Authorization, Content-Type');
		header('Access-Control-Max-Age: 600');

		if ($origin === '' || !in_array($origin, $allowed, true)) {
			return;
		}
		header('Access-Control-Allow-Origin: ' . $origin);
		header('Access-Control-Allow-Credentials: true');
	}
}
