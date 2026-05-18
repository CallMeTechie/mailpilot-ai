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
	 * @param \Closure():string $sortRootResolver  Just-in-time-Lookup, damit
	 *   Setting-Aenderungen ohne Service-Rebuild greifen. Test injiziert eine
	 *   einfache Closure; Production-Kernel uebergibt fn() => $settings->getString(...).
	 */
	public function __construct(private readonly \Closure $sortRootResolver)
	{
	}

	/**
	 * @param array<string,mixed>|null $senderBucket  Output von SenderRepository::hydrate, oder null
	 * @param list<string>|null        $folderSegments KI-Vorschlag aus mail_scores.folder_segments
	 * @return string|null  finaler Pfad oder null wenn keine Sortier-Empfehlung
	 */
	public function build(?array $senderBucket, ?array $folderSegments): ?string
	{
		if (!is_array($folderSegments) || $folderSegments === []) {
			return null;
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

		// Wenn nach Bereinigung keine Sub-Segments uebrig → null (Marc-Regel:
		// nie direkt in /Sender/, immer in Unterordner).
		if ($segments === []) {
			return null;
		}

		$parts = array_merge([$senderRoot], $segments);
		$parts = array_slice($parts, 0, self::MAX_DEPTH);
		$path = implode('/', array_map(fn(string $p): string => $this->sanitizeSegment($p), $parts));

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
