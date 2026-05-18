<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Services\Sender;

use MailPilot\Services\Sender\FolderPathBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 — Pfad-Bau-Regeln (Marc 2026-05-18):
 *   - Sender-Root immer erstes Segment (User-Setting wins)
 *   - Keine Mail direkt in /Sender/, immer Unterordner
 *   - sort_root-Setting als Prefix
 *   - max 3 Ebenen
 */
final class FolderPathBuilderTest extends TestCase
{
	private function builder(string $sortRoot = ''): FolderPathBuilder
	{
		return new FolderPathBuilder(fn(): string => $sortRoot);
	}

	private function bucket(string $rootFolderName): array
	{
		return ['root_folder_name' => $rootFolderName];
	}

	public function testAmazonOtpPath(): void
	{
		$this->assertSame(
			'Amazon/OTP',
			$this->builder()->build($this->bucket('Amazon'), ['Amazon', 'OTP'])
		);
	}

	public function testGithubMultiLevel(): void
	{
		$this->assertSame(
			'GitHub/GateControl/Security',
			$this->builder()->build($this->bucket('GitHub'), ['GitHub', 'GateControl', 'Security'])
		);
	}

	public function testEmptySegmentsReturnsNullForInboxPin(): void
	{
		$this->assertNull(
			$this->builder()->build($this->bucket('Amazon'), []),
			'Leere Segments → Mail bleibt in Inbox'
		);
		$this->assertNull(
			$this->builder()->build($this->bucket('Amazon'), null)
		);
	}

	public function testOnlySenderSegmentReturnsNull(): void
	{
		// Marc-Regel: NIE direkt in /Amazon/
		$this->assertNull(
			$this->builder()->build($this->bucket('Amazon'), ['Amazon']),
			'KI liefert nur Sender ohne Topic → Inbox (kein Pfad)'
		);
	}

	public function testUserSenderRenameWinsOverAiPrefix(): void
	{
		// User hat „Apple" in „Apfelhof" umbenannt. KI weiss das nicht.
		$this->assertSame(
			'Apfelhof/Newsletter',
			$this->builder()->build($this->bucket('Apfelhof'), ['Apple', 'Newsletter']),
			'segments[0] verworfen wenn != sender_root, Apfelhof wins'
		);
	}

	public function testSenderRootPrefixedWhenAiOmits(): void
	{
		// KI liefert nur Topic-Ebenen, kein Sender. Wir praefixen mit Root.
		$this->assertSame(
			'Amazon/Bestellbestaetigung',
			$this->builder()->build($this->bucket('Amazon'), ['Bestellbestaetigung'])
		);
	}

	public function testMaxDepthCapped(): void
	{
		// 5 segments + sender = 6. Cap auf 3.
		$path = $this->builder()->build(
			$this->bucket('GitHub'),
			['GitHub', 'a', 'b', 'c', 'd']
		);
		$this->assertSame(3, substr_count((string)$path, '/') + 1, 'Max 3 Ebenen');
	}

	public function testSortRootPrefix(): void
	{
		$this->assertSame(
			'Archiv/Amazon/OTP',
			$this->builder('Archiv')->build($this->bucket('Amazon'), ['Amazon', 'OTP'])
		);
		$this->assertSame(
			'Archiv/Amazon/OTP',
			$this->builder('Archiv/')->build($this->bucket('Amazon'), ['Amazon', 'OTP']),
			'Trailing slash im Setting wird normalisiert'
		);
	}

	public function testNullBucketReturnsNull(): void
	{
		$this->assertNull(
			$this->builder()->build(null, ['Amazon', 'OTP']),
			'Ohne Sender-Bucket koennen wir Marc-Vertrag nicht halten'
		);
	}

	public function testSegmentWithSlashGetsSanitized(): void
	{
		// Outlook erlaubt keine Slashes in Folder-Namen — Sanitizer macht „-".
		$this->assertSame(
			'GitHub/PR-1234',
			$this->builder()->build($this->bucket('GitHub'), ['GitHub', 'PR/1234'])
		);
	}
}
