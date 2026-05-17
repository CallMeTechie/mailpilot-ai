<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Graph;

use InvalidArgumentException;
use MailPilot\Graph\GraphFolderClient;
use PHPUnit\Framework\TestCase;

/**
 * Phase-H7 — Path-Traversal-Sanity fuer GraphFolderClient::ensurePath().
 *
 * folder_path kann aus Claude-Output stammen (sub_label-Discovery).
 * Diese Tests pinnen die Validation: erlaubte Pfade kommen sauber durch,
 * unsichere werfen InvalidArgumentException bevor irgendwas an Graph
 * geschickt wird.
 */
final class GraphFolderPathValidationTest extends TestCase
{
	public function testValidStandardPath(): void
	{
		$segments = GraphFolderClient::validateAndSplitPath('MailPilot/Newsletter');
		$this->assertSame(['MailPilot', 'Newsletter'], $segments);
	}

	public function testValidWithGermanUmlauts(): void
	{
		$segments = GraphFolderClient::validateAndSplitPath('MailPilot/Geschäftliches/Rechnungen');
		$this->assertSame(['MailPilot', 'Geschäftliches', 'Rechnungen'], $segments);
	}

	public function testValidWithBrackets(): void
	{
		// trailing-dot ist bewusst verboten (typisches Hidden-Folder-
		// Konvention auf Unix). Erlaubte Variante ohne Endpunkt:
		$segments = GraphFolderClient::validateAndSplitPath('Auto/Stripe (Payments) & Co');
		$this->assertCount(2, $segments);
		$this->assertSame('Stripe (Payments) & Co', $segments[1]);
	}

	public function testValidWithInteriorDot(): void
	{
		// Punkt im Inneren ist OK (z.B. "Version 1.2"), nur Anfang/Ende verboten.
		$segments = GraphFolderClient::validateAndSplitPath('MailPilot/Version 1.2');
		$this->assertSame(['MailPilot', 'Version 1.2'], $segments);
	}

	public function testTrailingSlashesAreTrimmed(): void
	{
		$segments = GraphFolderClient::validateAndSplitPath('MailPilot/Auto/');
		$this->assertSame(['MailPilot', 'Auto'], $segments);
	}

	public function testMultipleSlashesAreCollapsed(): void
	{
		$segments = GraphFolderClient::validateAndSplitPath('MailPilot//Auto');
		$this->assertSame(['MailPilot', 'Auto'], $segments);
	}

	public static function malicious(): array
	{
		return [
			'empty'              => [''],
			'whitespace-only'    => ['   '],
			'leading-slash'      => ['/etc/passwd'],
			'parent-traversal'   => ['MailPilot/../etc'],
			'parent-only'        => ['..'],
			'current-only'       => ['.'],
			'hidden-folder'      => ['.ssh/keys'],
			'trailing-dot'       => ['Auto/etc.'],
			'null-byte'          => ["MailPilot\0/Auto"],
			'backslash'          => ['MailPilot\\Auto'],
			'newline'            => ["MailPilot\nAuto"],
			'control-char'       => ["MailPilot/\x07bell"],
			'oversized-segment'  => ['MailPilot/' . str_repeat('A', 65)],
			'angle-bracket'      => ['Auto/<script>'],
			'pipe'               => ['Auto|cat'],
			'semicolon'          => ['Auto;rm -rf /'],
			'dollar'             => ['Auto/${HOME}'],
			'backtick'           => ['Auto/`whoami`'],
		];
	}

	/**
	 * @dataProvider malicious
	 */
	public function testRejectsMaliciousPath(string $path): void
	{
		$this->expectException(InvalidArgumentException::class);
		GraphFolderClient::validateAndSplitPath($path);
	}

	public function testValidMaxLengthSegment(): void
	{
		// 64 chars = grenzwertig erlaubt
		$segment = str_repeat('A', 64);
		$segments = GraphFolderClient::validateAndSplitPath("MailPilot/{$segment}");
		$this->assertSame(['MailPilot', $segment], $segments);
	}
}
