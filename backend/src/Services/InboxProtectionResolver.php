<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Repositories\SettingsRepository;
use PDO;

/**
 * Phase 9m (Marc 2026-05-21) — deterministische Inbox-Schutz-Schicht.
 *
 * Phase 9n-Hotfix (Marc 2026-05-21, abends): TO/CC-Match und Alias-Body-Match
 * wieder entfernt. Grund: jede Mail die Marc im Reading-Pane oeffnet ist an
 * ihn gerichtet (TO=marc@...) und/oder mit personalisierter Anrede ("Hallo
 * Marc, deine Amazon-Bestellung..."). Beide Marker triggerten reflexartig
 * den Inbox-Schutz — auch fuer Newsletter und auto-Mails die laengst raus
 * sollten. Ergebnis: gar kein Auto-Move mehr, alles blieb in Inbox.
 *
 * Neue Erkenntnis: Die KI-Klassifizierung (label) ist das staerkere Signal.
 * Wenn die KI newsletter/auto/noise sagt, hat sie schon abgewaegt dass es
 * nicht persoenlich ist. Deterministische TO/Alias-Marker duerfen das nicht
 * overrulen.
 *
 * Aktive Schutz-Marker (Marc-Regel: Prio 4+5 in Inbox, 1-3 verschieben):
 *   1. priority >= inbox_pin_priority_min     (KI: stark wichtig, default 4)
 *   2. label === 'direct'                     (KI: persoenlich an Marc)
 *   3. action_required + action_owner='user'  (KI: Marc muss handeln)
 *   4. from_email in vip_senders              (explizite User-Konfig)
 *
 * Entfernt aus Phase 9m:
 *   - TO/CC enthaelt user/alias  → false-positive bei jedem Bestaetigungs-Mail
 *   - Alias-Match im Body         → false-positive bei personalisierten Newslettern
 */
final class InboxProtectionResolver
{
	public function __construct(
		private readonly PDO $db,
		private readonly SettingsRepository $settings,
	) {
	}

	/**
	 * Hauptcheck. Returnt {protected: bool, reason: string}.
	 *
	 * @param array<string,mixed> $mail   mit from_email
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

		return ['protected' => false, 'reason' => 'no_protection_markers'];
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

}
