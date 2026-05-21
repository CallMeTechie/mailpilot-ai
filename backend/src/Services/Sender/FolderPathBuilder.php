<?php
declare(strict_types=1);

namespace MailPilot\Services\Sender;


/**
 * Sort-Refactor Phase 4 — baut den finalen Outlook-Folder-Pfad aus
 * einem Sender-Bucket + KI-Vorschlag (folder_segments).
 *
 * Regeln (Marc 2026-05-18):
 *   - Erster Pfad-Teil ist IMMER der Sender-Root-Folder-Name (User-editierbar
 *     in den Settings, Default = kapitalisierter sender_key).
 *     Wenn die KI auch ein Top-Segment liefert, das nicht zum Sender-Root
 *     passt, gewinnt der Sender-Root (User-Setting wins over KI).
 *   - Restliche segments werden angehaengt (max. 3 Ebenen total inkl. Root).
 *   - Wenn folder_segments leer ist → null (keine Sortier-Empfehlung;
 *     Aufrufer haelt die Mail in der Inbox).
 *   - sort_root-Setting (z.B. „Archiv") wird als Praefix vorgesetzt:
 *       sort_root=''       → „Amazon/OTP"
 *       sort_root='Archiv' → „Archiv/Amazon/OTP"
 *
 * Beispiele:
 *   bucket=['root_folder_name'=>'Amazon'], segments=['Amazon','OTP']
 *     → „Amazon/OTP"
 *   bucket=['root_folder_name'=>'GitHub'], segments=['GitHub','GateControl','Security']
 *     → „GitHub/GateControl/Security"
 *   bucket=['root_folder_name'=>'Amazon'], segments=[]
 *     → null
 *   bucket=['root_folder_name'=>'Apfelhof'], segments=['Apple','Newsletter']
 *     (User hat Apple in „Apfelhof" umbenannt)
 *     → „Apfelhof/Newsletter"  (Sender-Root wins, segments[0] verworfen)
 */
final class FolderPathBuilder
{
	/** Hartes Limit gegen Outlook-Pfadtiefen-Issues. */
	public const MAX_DEPTH = 3;

	/**
	 * @param \Closure():string  $sortRootResolver      Legacy sort_root-Lookup (Phase 4).
	 * @param \Closure():string|null $mailpilotRootResolver Phase 9m: 'account_root', 'inbox',
	 *   oder Custom-Path. Wird bei build() den finalen Pfaden vorangestellt.
	 */
	public function __construct(
		private readonly \Closure $sortRootResolver,
		private readonly ?\Closure $mailpilotRootResolver = null,
	) {
	}

	/**
	 * Phase 9m (Marc 2026-05-21) — Signatur erweitert um $label. Newsletter
	 * werden Klassen-First sortiert ({prefix}/Newsletter/{display}, max 2
	 * Tiefen), alle anderen Labels behalten Sender-First-Logik.
	 *
	 * @param string                   $label          mail_scores.label, steuert Newsletter-Special-Case
	 * @param array<string,mixed>|null $senderBucket   Output von SenderRepository::hydrate, oder null
	 * @param list<string>|null        $folderSegments KI-Vorschlag aus mail_scores.folder_segments
	 * @return string|null  finaler Pfad oder null wenn keine Sortier-Empfehlung
	 */
	public function build(string $label, ?array $senderBucket, ?array $folderSegments): ?string
	{
		// Newsletter-Special-Case (Phase 9m): Sender-Root-Magie wird
		// uebersprungen, Pfad ist immer {prefix}/Newsletter/{display}.
		if ($label === 'newsletter') {
			return $this->buildNewsletterPath($senderBucket);
		}

		if (!is_array($folderSegments) || $folderSegments === []) {
			// Phase 9m: Fallback auf senderBucket.root_folder_name allein —
			// wenn der User im Absender-Subtab einen Ordner gesetzt hat,
			// landet die Mail dort, auch ohne Topic-Segments.
			return $this->buildBucketOnlyPath($senderBucket);
		}
		// Sender-Root als verbindlicher erster Pfad-Teil.
		$senderRoot = $senderBucket !== null
			? trim((string)($senderBucket['root_folder_name'] ?? ''))
			: '';
		if ($senderRoot === '') {
			// Ohne Sender-Root koennen wir den Marc-Vertrag „nie direkt in /Sender/"
			// nicht halten. Lieber kein Move als ein chaotischer Pfad.
			return null;
		}

		// Sender-Root wins. Logik:
		//   - segments[0] = Sender-Root  → drop (KI hat Sender mitgeliefert)
		//   - segments[0] != Sender-Root UND length>=2 → drop (User-Rename
		//     wie „Apple"→„Apfelhof" muss vorne stehen, KI-Sender raus)
		//   - segments[0] != Sender-Root UND length==1 → behalten (KI hat
		//     nur ein Topic geliefert ohne Sender-Aussage)
		$segments = array_values(array_map(
			fn(string $s): string => trim($s),
			array_filter($folderSegments, 'is_string'),
		));
		if ($segments !== []) {
			$origCount = count($segments);
			$matchesRoot = mb_strtolower($segments[0]) === mb_strtolower($senderRoot);
			if ($matchesRoot || $origCount >= 2) {
				array_shift($segments);
			}
		}

		// Phase 9m: Wenn nach Bereinigung keine Sub-Segments uebrig → Bucket-Only-
		// Pfad (User-konfigurierter Sender-Ordner). Vor 9m war das null + Inbox-
		// Stau. Marc-Wunsch: KI liefert nur Sender → Mail geht trotzdem in den
		// User-Ordner ohne Topic-Subfolder.
		if ($segments === []) {
			return $this->buildBucketOnlyPath($senderBucket);
		}

		$parts = array_merge([$senderRoot], $segments);
		$parts = array_slice($parts, 0, self::MAX_DEPTH);
		$path = implode('/', array_map(fn(string $p): string => $this->sanitizeSegment($p), $parts));

		return $this->withPrefix($path);
	}

	/**
	 * Phase 9m — Newsletter immer Klassen-First. Display-Name aus
	 * senderBucket.root_folder_name oder display_name, sonst null.
	 */
	private function buildNewsletterPath(?array $senderBucket): ?string
	{
		if ($senderBucket === null) {
			return null;
		}
		$display = trim((string)($senderBucket['root_folder_name']
			?? $senderBucket['display_name']
			?? ''));
		if ($display === '') {
			return null;
		}
		$path = 'Newsletter/' . $this->sanitizeSegment($display);
		return $this->withPrefix($path);
	}

	/**
	 * Phase 9m — Bucket-only-Pfad: User hat im Absender-Subtab einen
	 * root_folder_name konfiguriert, KI hat aber keine Topic-Segments. Mail
	 * geht direkt in den User-konfigurierten Ordner.
	 */
	private function buildBucketOnlyPath(?array $senderBucket): ?string
	{
		if ($senderBucket === null) {
			return null;
		}
		$root = trim((string)($senderBucket['root_folder_name'] ?? ''));
		if ($root === '') {
			return null;
		}
		return $this->withPrefix($this->sanitizeSegment($root));
	}

	/**
	 * Setzt mailpilot_root (Phase 9m) und Legacy sort_root (Phase 4) als
	 * Praefix. mailpilot_root='account_root' = kein Prefix, 'inbox' = "Inbox/",
	 * sonst literal.
	 */
	private function withPrefix(string $path): string
	{
		if ($this->mailpilotRootResolver !== null) {
			$mp = trim(($this->mailpilotRootResolver)());
			if ($mp !== '' && $mp !== 'account_root') {
				$prefix = $mp === 'inbox' ? 'Inbox' : trim($mp, '/');
				if ($prefix !== '') {
					$path = $prefix . '/' . $path;
				}
			}
		}
		$root = trim(($this->sortRootResolver)());
		if ($root !== '') {
			$root = trim($root, '/');
			$path = $root . '/' . $path;
		}
		return $path;
	}

	/**
	 * Outlook erlaubt keine Pfad-Separator-Zeichen IN einem Folder-Namen.
	 * Backslash + Forward-Slash raus, Steuerzeichen raus, Trimming.
	 */
	private function sanitizeSegment(string $s): string
	{
		$s = str_replace(['/', '\\'], '-', $s);
		$s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? $s;
		return trim($s);
	}
}
