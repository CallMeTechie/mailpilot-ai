<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase-H4 — pinnt CSP-Direktiven in nginx.conf + .htaccess.
 *
 * Test geht NICHT durchs Netzwerk (keine Live-Header-Pruefung), sondern
 * verifiziert dass beide Config-Files die geforderten Direktiven enthalten.
 * Live-Verifikation: `curl -I https://mailpilot.domaincaster.com/api/v1/health`
 * sollte CSP/HSTS/X-Frame-Options zeigen — manueller Smoke nach Deploy.
 */
final class CspHeaderShapeTest extends TestCase
{
	private string $nginxConf;
	private string $htaccess;

	protected function setUp(): void
	{
		$this->nginxConf = (string)file_get_contents(__DIR__ . '/../../../docker/nginx.conf');
		$this->htaccess  = (string)file_get_contents(__DIR__ . '/../../public/.htaccess');
	}

	public function testNginxHasDefaultStrictCsp(): void
	{
		$this->assertStringContainsString(
			"Content-Security-Policy \"default-src 'none'",
			$this->nginxConf,
			'Default-Pfad muss default-src none haben',
		);
		$this->assertStringContainsString("frame-ancestors 'none'", $this->nginxConf);
	}

	public function testNginxPhpLocationAlsoSetsSecurityHeaders(): void
	{
		// nginx-Quirk: try_files in `location /` macht internal rewrite zu
		// /index.php, dann matched `location ~ \.php$`. Ohne Header-Wiederholung
		// im PHP-Block bekommen API-Responses keine CSP/HSTS/X-Frame-Options.
		// Test pinnt dass der PHP-Block die Default-Header hat.
		$pos = strpos($this->nginxConf, 'location ~ \\.php');
		$this->assertNotFalse($pos, 'PHP-Location-Block muss existieren');
		$phpBlock = substr($this->nginxConf, $pos, 1500);
		$this->assertStringContainsString('X-Content-Type-Options "nosniff" always', $phpBlock,
			'PHP-Location braucht X-Content-Type-Options (sonst keine Header in API-Responses)');
		$this->assertStringContainsString("default-src 'none'", $phpBlock,
			'PHP-Location braucht default-CSP');
		$this->assertStringContainsString('X-Frame-Options "DENY" always', $phpBlock);
	}

	public function testNginxAddinAllowsOfficeJs(): void
	{
		$this->assertStringContainsString('appsforoffice.microsoft.com', $this->nginxConf,
			'Add-in script-src muss office.js CDN allowlisten');
		$this->assertStringContainsString('outlook.office.com', $this->nginxConf,
			'frame-ancestors muss outlook.office.com fuer Add-in zulassen');
		$this->assertStringContainsString('outlook.live.com', $this->nginxConf,
			'frame-ancestors muss outlook.live.com fuer Personal-Accounts zulassen');
	}

	public function testNginxHasHstsAndNosniff(): void
	{
		$this->assertStringContainsString('Strict-Transport-Security', $this->nginxConf);
		$this->assertStringContainsString('X-Content-Type-Options "nosniff"', $this->nginxConf);
		$this->assertStringContainsString('X-Permitted-Cross-Domain-Policies "none"', $this->nginxConf);
	}

	public function testNginxAlwaysFlagOnSecurityHeaders(): void
	{
		// nginx sendet Headers auch fuer 4xx/5xx wenn `always` gesetzt ist —
		// ohne wuerden 401/500-Responses die Headers nicht haben.
		$lines = explode("\n", $this->nginxConf);
		$headerLines = array_filter($lines, static fn(string $l): bool =>
			preg_match('/add_header\s+(X-|Strict|Content|Referrer)/', $l) === 1
		);
		$this->assertNotEmpty($headerLines, 'Mindestens ein Security-Header erwartet');
		foreach ($headerLines as $line) {
			$this->assertStringEndsWith('always;', trim($line),
				"Security-Header braucht always-Flag: {$line}");
		}
	}

	public function testHtaccessHasMatchingDefaultHeaders(): void
	{
		$this->assertStringContainsString('Header set X-Content-Type-Options "nosniff"', $this->htaccess);
		$this->assertStringContainsString('Header set X-Frame-Options "DENY"', $this->htaccess);
		$this->assertStringContainsString('Header set Content-Security-Policy', $this->htaccess);
		$this->assertStringContainsString("default-src 'none'", $this->htaccess);
		$this->assertStringContainsString('Strict-Transport-Security', $this->htaccess);
	}
}
