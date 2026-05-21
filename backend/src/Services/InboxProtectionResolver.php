<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Repositories\SettingsRepository;
use PDO;

/**
 * Phase 9m (Marc 2026-05-21) — deterministische Inbox-Schutz-Schicht.
 *
 * Marc-Regel (woertlich, 2026-05-21):
 *   "E-Mail bei denen ich auf CC stehe, keine direkte Aktion von mir
 *   erwartet wird, keine Anrede-Alias getroffen wird und/oder kein
 *   VIP-Absender ist werden verschoben. Emails die direkt an mich gesendet
 *   werden, ich angesprochen werde oder das Anrede-Alias wie in den
 *   Einstellungen unter Profil & Filter getroffen wird oder ein VIP-Absender
 *   ist, oder eine Anweisung, eine Bitte, oder Aehnliches enthalten, was
 *   eine Aktion oder eine Antwort von mir erfordert verbleiben in der
 *   Inbox."
 *
 * Diese Klasse setzt das deterministisch um — unabhaengig davon was die KI
 * klassifiziert hat. Schutz greift wenn IRGENDEINER der folgenden Marker
 * zutrifft:
 *
 *   1. priority >= inbox_pin_priority_min                  (Phase 9f)
 *   2. label === 'direct'                                  (an mich)
 *   3. action_required=true AND action_owner='user'        (Aktion verlangt)
 *   4. from_email in vip_senders                           (VIP-Absender)
 *   5. TO oder CC enthaelt user.email oder einen Alias     (direkt adressiert)
 *   6. Subject oder Body-Anfang enthaelt einen Alias       (angesprochen)
 *
 * Word-Boundary-Match in (6) verhindert false-positives wie "Marc" in
 * "Marca de Agua". Body wird nur in den ersten 1000 Zeichen gescannt
 * (Anrede steht uebliche praefixiert).
 */
final class InboxProtectionResolver
{
	private const BODY_SCAN_BYTES = 1000;

	public function __construct(
		private readonly PDO $db,
		private readonly SettingsRepository $settings,
	) {
	}

	/**
	 * Hauptcheck. Returnt {protected: bool, reason: string}.
	 *
	 * @param array<string,mixed> $mail   mit to_json, cc_json, from_email, subject, body_text
	 * @param array<string,mixed> $score  mit label, priority, action_required, action_owner
	 * @return array{protected:bool, reason:string}
	 */
	public function evaluate(string $tenantId, string $userId, array $mail, array $score): array
	{
		// 1. Priority (Phase 9f Logik, bleibt bestehen).
		$priority = isset($score['priority']) ? (int)$score['priority'] : 0;
		$minPrio  = max(1, min(5, $this->settings->getInt('inbox_pin_priority_min', 4)));
		if ($priority >= $minPrio) {
			return ['protected' => true, 'reason' => 'priority_high'];
		}

		// 2. Label direct.
		if (($score['label'] ?? '') === 'direct') {
			return ['protected' => true, 'reason' => 'label_direct'];
		}

		// 3. Aktion verlangt.
		$actionRequired = !empty($score['action_required']);
		$actionOwner    = (string)($score['action_owner'] ?? '');
		if ($actionRequired && $actionOwner === 'user') {
			return ['protected' => true, 'reason' => 'action_required'];
		}

		// 4. VIP-Absender.
		$fromEmail = strtolower((string)($mail['from_email'] ?? ''));
		if ($fromEmail !== '' && $this->isVipSender($userId, $fromEmail)) {
			return ['protected' => true, 'reason' => 'vip_sender'];
		}

		// 5+6. User-Identifier laden (email + aliases) — nur einmal pro Aufruf.
		$identifiers = $this->loadUserIdentifiers($userId);
		if ($identifiers === []) {
			return ['protected' => false, 'reason' => 'no_protection_markers'];
		}

		// 5. TO/CC enthaelt user oder alias.
		$toCc = $this->collectAddresses($mail);
		foreach ($toCc as $addr) {
			if (in_array($addr, $identifiers['emails'], true)) {
				return ['protected' => true, 'reason' => 'addressed_directly'];
			}
		}

		// 6. Subject oder Body-Anfang enthaelt Alias (word-boundary, case-insensitive).
		$haystack = (string)($mail['subject'] ?? '') . "\n"
			. mb_substr((string)($mail['body_text'] ?? ''), 0, self::BODY_SCAN_BYTES);
		if ($haystack !== '' && $identifiers['aliases'] !== []) {
			$matchedAlias = $this->matchesAnyAliasWordBoundary($haystack, $identifiers['aliases']);
			if ($matchedAlias !== null) {
				return ['protected' => true, 'reason' => 'alias_match:' . $matchedAlias];
			}
		}

		return ['protected' => false, 'reason' => 'no_protection_markers'];
	}

	/**
	 * Laedt user.email + alle aliases. Aliases werden in Email-Identifier
	 * (enthaelt @) und Name-Alias (Body-Match) aufgespalten.
	 *
	 * @return array{emails:list<string>, aliases:list<string>}|array{}
	 */
	private function loadUserIdentifiers(string $userId): array
	{
		$stmt = $this->db->prepare('SELECT email, aliases FROM users WHERE id = :id LIMIT 1');
		$stmt->execute([':id' => $userId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row === false) {
			return [];
		}
		$emails  = [];
		$aliases = [];

		$primary = strtolower(trim((string)($row['email'] ?? '')));
		if ($primary !== '') {
			$emails[] = $primary;
		}

		$decoded = $row['aliases'] !== null && $row['aliases'] !== ''
			? json_decode((string)$row['aliases'], true)
			: [];
		if (!is_array($decoded)) {
			$decoded = [];
		}
		foreach ($decoded as $a) {
			$s = trim((string)$a);
			if ($s === '') continue;
			if (str_contains($s, '@')) {
				$emails[] = strtolower($s);
			} else {
				$aliases[] = $s; // Original-Case fuer Logging, Match ist ci
			}
		}
		return [
			'emails'  => array_values(array_unique($emails)),
			'aliases' => array_values(array_unique($aliases)),
		];
	}

	private function isVipSender(string $userId, string $fromEmail): bool
	{
		$stmt = $this->db->prepare(
			'SELECT 1 FROM vip_senders
			 WHERE user_id = :u AND email = :e AND deleted_at IS NULL
			 LIMIT 1'
		);
		$stmt->execute([':u' => $userId, ':e' => $fromEmail]);
		return $stmt->fetchColumn() !== false;
	}

	/**
	 * Sammelt alle Email-Adressen aus to_json + cc_json (lowercase).
	 *
	 * @return list<string>
	 */
	private function collectAddresses(array $mail): array
	{
		$result = [];
		foreach (['to_json', 'cc_json'] as $field) {
			$raw = $mail[$field] ?? null;
			$decoded = is_string($raw) ? json_decode($raw, true) : $raw;
			if (!is_array($decoded)) continue;
			foreach ($decoded as $entry) {
				if (is_string($entry)) {
					$result[] = strtolower(trim($entry));
				} elseif (is_array($entry) && isset($entry['email'])) {
					$result[] = strtolower(trim((string)$entry['email']));
				}
			}
		}
		return array_values(array_filter(array_unique($result), fn(string $s): bool => $s !== ''));
	}

	/**
	 * Word-Boundary-Match: alias ist nur Treffer wenn er als ganzes Wort
	 * vorkommt. Verhindert false-positive von "Marc" in "Marca de Agua".
	 * Returnt den ersten gematchten Alias oder null.
	 *
	 * @param list<string> $aliases
	 */
	private function matchesAnyAliasWordBoundary(string $haystack, array $aliases): ?string
	{
		foreach ($aliases as $alias) {
			$escaped = preg_quote($alias, '/');
			if (preg_match('/\b' . $escaped . '\b/iu', $haystack) === 1) {
				return $alias;
			}
		}
		return null;
	}
}
