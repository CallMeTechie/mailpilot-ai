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

	public function testAddinLocationHasNoCspButKeepsDefenseInDepth(): void
	{
		// 2026-05-18 FINAL: Add-in CSP komplett entfernt. Versuche mit
		// empirischer Allowlist + H9-Revert brachen jeweils MS-Ajax-Init
		// (Sys.CultureInfo._parse TypeError). Outlook-Desktop-WebView2
		// zeigt CSP-Warnings nicht zuverlaessig in der Console an, deshalb
		// war Debugging unmoeglich. Pragmatisch: keine CSP, andere
		// Defense-in-depth-Header bleiben aktiv. API-Pfade haben weiterhin
		// strikte CSP (default-src 'none').
		$pos = strpos($this->nginxConf, 'location ~ ^/addin/');
		$this->assertNotFalse($pos, 'Add-in-Location-Block muss existieren');
		$end = strpos($this->nginxConf, "\n\t\t}", $pos);
		$this->assertNotFalse($end, 'Add-in-Block-Ende nicht gefunden');
		$addinBlock = substr($this->nginxConf, $pos, $end - $pos);

		$this->assertSame(0,
			preg_match('/add_header Content-Security-Policy/', $addinBlock),
			'Add-in-Block darf KEINE CSP setzen — bricht MS-Ajax-Init',
		);
		$this->assertStringNotContainsString('X-Frame-Options', $addinBlock,
			'Add-in-Location darf KEIN X-Frame-Options haben — Add-in muss iframe-bar sein');

		// Defense-in-depth-Header bleiben
		$this->assertStringContainsString('X-Content-Type-Options "nosniff" always', $addinBlock);
		$this->assertStringContainsString('Strict-Transport-Security', $addinBlock);
		$this->assertStringContainsString('Referrer-Policy', $addinBlock);
		$this->assertStringContainsString('Cache-Control', $addinBlock);
	}

	public function testTaskpaneHasNoInlineOrHistoryShimScripts(): void
	{
		// 2026-05-18: H9 reverted. history-shim-Files brachen MicrosoftAjax-
		// Init (Sys.CultureInfo._parse TypeError). Add-in nutzt keinen
		// Router → history-Workaround nicht gebraucht. Test pinnt dass
		// taskpane.html NICHT mehr auf shim-Files referenziert und KEINE
		// inline-script-Bloecke mit echtem Code hat.
		$html = file_get_contents(__DIR__ . '/../../../addin/src/taskpane.html');
		$this->assertStringNotContainsString('history-shim-before.js', $html);
		$this->assertStringNotContainsString('history-shim-after.js', $html);
		$this->assertSame(0, preg_match_all('/<script>\s*(?:window|var|let|const|function)/', $html),
			'Inline <script>-Bloecke mit Code im taskpane.html — bricht strikte CSP');
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
