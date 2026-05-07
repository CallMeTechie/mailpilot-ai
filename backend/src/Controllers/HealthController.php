<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Response;

final class HealthController extends BaseController
{
	public function ping(array $params, array $body): void
	{
		Response::json([
			'ok'       => true,
			'time'     => gmdate('Y-m-d\TH:i:s\Z'),
			'version'  => '0.1.0',
		]);
	}

	public function health(array $params, array $body): void
	{
		$checks = [
			'db'    => $this->checkDb(),
			'redis' => $this->checkRedis(),
		];
		$ok = !in_array(false, $checks, true);
		Response::json(['ok' => $ok, 'checks' => $checks], $ok ? 200 : 503);
	}

	private function checkDb(): bool
	{
		try {
			$this->kernel->get(\PDO::class)->query('SELECT 1')->fetch();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function checkRedis(): bool
	{
		try {
			$cfg = $this->kernel->config['redis'];
			$r = new \Redis();
			$r->connect($cfg['host'], (int)$cfg['port'], 1.0);
			$r->ping();
			$r->close();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}
}
