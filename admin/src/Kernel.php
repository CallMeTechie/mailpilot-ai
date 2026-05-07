<?php
declare(strict_types=1);

namespace MailPilot\Admin;

use MailPilot\Http\Kernel as BaseKernel;

/**
 * Admin kernel inherits DI from backend but exposes admin-specific config.
 */
final class Kernel extends BaseKernel
{
	public function isIpAllowed(string $ip): bool
	{
		if (!($this->config['admin']['require_ip_allowlist'] ?? false)) {
			return true;
		}
		$allowed = $this->config['admin']['allowed_ips'] ?? [];
		return in_array($ip, array_map('trim', $allowed), true);
	}
}
