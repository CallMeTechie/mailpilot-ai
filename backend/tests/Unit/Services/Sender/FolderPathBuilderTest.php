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
 *
 * Phase 9m — Newsletter-Special-Case + Bucket-Only + mailpilot_root.
 */
final class FolderPathBuilderTest extends TestCase
{
	private function builder(string $sortRoot = '', string $mailpilotRoot = 'account_root'): FolderPathBuilder
	{
		return new FolderPathBuilder(
			fn(): string => $sortRoot,
			fn(): string => $mailpilotRoot,
		);
	}

	private function bucket(string $rootFolderName, ?string $displayName = null): array
	{
		return [
			'root_folder_name' => $rootFolderName,
			'display_name'     => $displayName ?? $rootFolderName,
		];
	}

	public function testAmazonOtpPath(): void
	{
		$this->assertSame(
			'Amazon/OTP',
			$this->builder()->build('action', $this->bucket('Amazon'), ['Amazon', 'OTP'])
		);
	}

	public function testGithubMultiLevel(): void
	{
		$this->assertSame(
			'GitHub/GateControl/Security',
			$this->builder()->build('action', $this->bucket('GitHub'), ['GitHub', 'GateControl', 'Security'])
		);
	}

	public function testEmptySegmentsWithoutBucketReturnsNull(): void
	{
		// Phase 9m: leerer Bucket + leere Segments → null (kein Pfad).
		$this->assertNull(
			$this->builder()->build('action', null, [])
		);
		$this->assertNull(
			$this->builder()->build('action', null, null)
		);
	}

	public function testEmptySegmentsWithBucketUsesBucketOnly(): void
	{
		// Phase 9m: User hat im Absender-Subtab den Folder gesetzt,
		// aber KI hat keine Topic-Segmente → Mail geht direkt in den Folder.
		$this->assertSame(
			'Amazon',
			$this->builder()->build('action', $this->bucket('Amazon'), []),
			'Bucket-only: User-konfigurierter Folder ohne Topic'
		);
		$this->assertSame(
			'Amazon',
			$this->builder()->build('action', $this->bucket('Amazon'), null),
		);
	}

	public function testOnlySenderSegmentReturnsBucketPath(): void
	{
		// Phase 9m: KI liefert nur Sender ohne Topic → Bucket-Path (Sender-Root).
		// Vor 9m war das null; mit 9m fallen wir auf den User-konfigurierten
		// Folder zurueck, weil der Marc-Wunsch jetzt ist: kein Inbox-Stau.
		$this->assertSame(
			'Amazon',
			$this->builder()->build('action', $this->bucket('Amazon'), ['Amazon']),
			'KI nur Sender → Bucket-Pfad (kein Topic-Subfolder)'
		);
	}

	public function testUserSenderRenameWinsOverAiPrefix(): void
	{
		// User hat „Apple" in „Apfelhof" umbenannt. KI weiss das nicht.
		$this->assertSame(
			'Apfelhof/Bestellung',
			$this->builder()->build('action', $this->bucket('Apfelhof'), ['Apple', 'Bestellung']),
			'segments[0] verworfen wenn != sender_root, Apfelhof wins'
		);
	}

	public function testSenderRootPrefixedWhenAiOmits(): void
	{
		// KI liefert nur Topic-Ebenen, kein Sender. Wir praefixen mit Root.
		$this->assertSame(
			'Amazon/Bestellbestaetigung',
			$this->builder()->build('action', $this->bucket('Amazon'), ['Bestellbestaetigung'])
		);
	}

	public function testMaxDepthCapped(): void
	{
		// 5 segments + sender = 6. Cap auf 3.
		$path = $this->builder()->build(
			'action',
			$this->bucket('GitHub'),
			['GitHub', 'a', 'b', 'c', 'd']
		);
		$this->assertSame(3, substr_count((string)$path, '/') + 1, 'Max 3 Ebenen');
	}

	public function testSortRootPrefix(): void
	{
		$this->assertSame(
			'Archiv/Amazon/OTP',
			$this->builder('Archiv')->build('action', $this->bucket('Amazon'), ['Amazon', 'OTP'])
		);
		$this->assertSame(
			'Archiv/Amazon/OTP',
			$this->builder('Archiv/')->build('action', $this->bucket('Amazon'), ['Amazon', 'OTP']),
			'Trailing slash im Setting wird normalisiert'
		);
	}

	public function testNullBucketReturnsNull(): void
	{
		$this->assertNull(
			$this->builder()->build('action', null, ['Amazon', 'OTP']),
			'Ohne Sender-Bucket koennen wir Marc-Vertrag nicht halten'
		);
	}

	public function testSegmentWithSlashGetsSanitized(): void
	{
		// Outlook erlaubt keine Slashes in Folder-Namen — Sanitizer macht „-".
		$this->assertSame(
			'GitHub/PR-1234',
			$this->builder()->build('action', $this->bucket('GitHub'), ['GitHub', 'PR/1234'])
		);
	}

	// ====================================================================
	// Phase 9m — Newsletter-Special-Case
	// ====================================================================

	public function testNewsletterUsesClassFirstPath(): void
	{
		// Phase 9m: Newsletter immer Klassen-First, Sender-Root als 2. Segment.
		// KEINE Sender-Root-Magie, KEIN Stichwort-Unter.
		$this->assertSame(
			'Newsletter/Penny',
			$this->builder()->build('newsletter', $this->bucket('Penny'), null)
		);
		$this->assertSame(
			'Newsletter/Penny',
			$this->builder()->build('newsletter', $this->bucket('Penny'), ['Penny', 'Angebote', 'Rabatt']),
			'KI-Segmente werden bei Newsletter ignoriert — immer 2-Segment-Pfad'
		);
	}

	public function testNewsletterWithoutBucketReturnsNull(): void
	{
		$this->assertNull(
			$this->builder()->build('newsletter', null, ['ignored'])
		);
	}

	// ====================================================================
	// Phase 9m — mailpilot_root Setting
	// ====================================================================

	public function testMailpilotRootInboxPrefix(): void
	{
		$this->assertSame(
			'Inbox/Newsletter/Penny',
			$this->builder('', 'inbox')->build('newsletter', $this->bucket('Penny'), null)
		);
		$this->assertSame(
			'Inbox/Amazon/OTP',
			$this->builder('', 'inbox')->build('action', $this->bucket('Amazon'), ['Amazon', 'OTP'])
		);
	}

	public function testMailpilotRootAccountRootNoPrefix(): void
	{
		// 'account_root' = leer = kein Prefix
		$this->assertSame(
			'Newsletter/Penny',
			$this->builder('', 'account_root')->build('newsletter', $this->bucket('Penny'), null)
		);
	}

	public function testMailpilotRootCustomPath(): void
	{
		$this->assertSame(
			'Archiv/Newsletter/Penny',
			$this->builder('', 'Archiv')->build('newsletter', $this->bucket('Penny'), null)
		);
	}
}
