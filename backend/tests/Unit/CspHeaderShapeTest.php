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

	public function testAddinLocationHasEmpiricalCsp(): void
	{
		// 2026-05-18 H4-Re: CSP basierend auf Marcs Outlook-Desktop-
		// Network-Tab empirisch aufgebaut. Konkrete Hosts:
		//   - appsforoffice.microsoft.com (office.js + outlook-win32 + strings + telemetry)
		//   - ajax.aspnetcdn.com (MicrosoftAjax.js, Legacy-CDN!)
		// Alle fetches sind same-origin → connect-src 'self' reicht.
		// KEIN frame-ancestors / X-Frame-Options (Outlook-WebView2-Origin
		// undokumentiert, Whitelisting wuerde iframe brechen).
		$pos = strpos($this->nginxConf, 'location ~ ^/addin/');
		$this->assertNotFalse($pos, 'Add-in-Location-Block muss existieren');
		$end = strpos($this->nginxConf, "\n\t\t}", $pos);
		$this->assertNotFalse($end, 'Add-in-Block-Ende nicht gefunden');
		$addinBlock = substr($this->nginxConf, $pos, $end - $pos);

		$this->assertStringContainsString('Content-Security-Policy', $addinBlock,
			'Add-in-Location braucht empirische CSP');
		// Extrahiere NUR die echte add_header-Direktive (nicht Kommentare die
		// das Wort 'script-src' enthalten). Pattern matched den Quoted-Wert
		// von 'add_header Content-Security-Policy "...";'.
		$this->assertSame(1,
			preg_match('/add_header Content-Security-Policy "([^"]+)"/', $addinBlock, $cspMatch),
			'CSP-Direktive im Add-in-Block muss existieren',
		);
		$csp = $cspMatch[1];

		// Hosts in der CSP (egal welche Direktive)
		$this->assertStringContainsString('https://appsforoffice.microsoft.com', $csp,
			'CSP muss appsforoffice.microsoft.com whitelisten');
		$this->assertStringContainsString('https://ajax.aspnetcdn.com', $csp,
			'CSP muss ajax.aspnetcdn.com fuer MicrosoftAjax.js whitelisten');

		// Direktive-spezifische Pruefungen via Sub-Match in $csp
		$this->assertSame(1, preg_match('/script-src ([^;]+)/', $csp, $sm), 'script-src nicht gefunden');
		$this->assertStringNotContainsString("'unsafe-inline'", $sm[1],
			'script-src darf KEIN unsafe-inline haben — H9 ist in history-shim-*.js ausgelagert');

		$this->assertSame(1, preg_match('/connect-src ([^;]+)/', $csp, $ccm), 'connect-src nicht gefunden');
		$this->assertStringContainsString("'self'", $ccm[1]);
		$this->assertStringContainsString('https://ajax.aspnetcdn.com', $ccm[1],
			'connect-src braucht ajax.aspnetcdn.com — MS-Ajax laedt Resources via XHR');
		$this->assertStringContainsString('https://appsforoffice.microsoft.com', $ccm[1]);
		$this->assertStringNotContainsString('X-Frame-Options', $addinBlock,
			'Add-in-Location darf KEIN X-Frame-Options haben — Add-in muss iframe-bar sein');
		$this->assertStringNotContainsString('frame-ancestors', $addinBlock,
			'Add-in-Location darf KEIN frame-ancestors haben — Outlook-WebView2-Origin undokumentiert');

		// Defense-in-depth-Header bleiben
		$this->assertStringContainsString('X-Content-Type-Options "nosniff" always', $addinBlock);
		$this->assertStringContainsString('Strict-Transport-Security', $addinBlock);
		$this->assertStringContainsString('Referrer-Policy', $addinBlock);
	}

	public function testHistoryShimFilesExist(): void
	{
		// Phase-H9 (CSP-clean) — inline scripts ausgelagert.
		$before = __DIR__ . '/../../../addin/src/history-shim-before.js';
		$after  = __DIR__ . '/../../../addin/src/history-shim-after.js';
		$this->assertFileExists($before, 'history-shim-before.js muss existieren (sonst bricht taskpane.html)');
		$this->assertFileExists($after,  'history-shim-after.js muss existieren');
		$this->assertStringContainsString('_historyCache', file_get_contents($before));
		$this->assertStringContainsString('replaceState', file_get_contents($after));
	}

	public function testTaskpaneHtmlReferencesExternalShimsNotInlineScripts(): void
	{
		$html = file_get_contents(__DIR__ . '/../../../addin/src/taskpane.html');
		$this->assertStringContainsString('history-shim-before.js', $html);
		$this->assertStringContainsString('history-shim-after.js', $html);
		// Es darf KEIN inline <script>...</script> mit echtem Code mehr da sein.
		// Der office.js-Tag ist external (src=), nicht inline.
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
