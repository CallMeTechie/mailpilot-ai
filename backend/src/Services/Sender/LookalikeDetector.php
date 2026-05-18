<?php
declare(strict_types=1);

namespace MailPilot\Services\Sender;

use MailPilot\Repositories\SenderRepository;
use Psr\Log\LoggerInterface;

/**
 * Sort-Refactor Phase 2 — Detects sender-key lookalikes (Spoof/Phishing).
 *
 * Marc-Anforderung (2026-05-18): „info@ebay-mails.com" oder
 * „no-reply@amazon-email.com" sollen NICHT in den /Ebay- bzw. /Amazon-Bucket
 * sortiert werden, sondern als Spoof markiert + in der Inbox gepinnt werden.
 *
 * Algorithmus:
 *   - Vergleicht den Stem eines neuen Senders gegen alle trusted Senders
 *     desselben Tenants.
 *   - Match-Kriterien (jedes zaehlt, am Ende ODER-Logik):
 *       a) Levenshtein-Distanz <= LEVENSHTEIN_MAX UND Prefix-Match auf min.
 *          PREFIX_MATCH_MIN Zeichen — fangt „amazon" vs „amazon-email" UND
 *          „amaz0n" Tippfehler.
 *       b) Hyphen-Pattern: neuer Stem enthaelt den trusted Stem als
 *          Hyphen-getrenntes Token am Anfang (z.B. „amazon-..."). Klassisches
 *          Phishing-Muster.
 *   - Bei Match: trust_status='suspected_spoof' + spoof_of_sender_id auf
 *     den echten Sender. Aufrufer (MailScoringService Phase 3) liest das
 *     spaeter und setzt mail_scores.spoof_suspect=1.
 *
 * Bewusst NICHT hier:
 *   - DMARC/SPF-Check (Header-basiert, kommt in Phase 4 dazu)
 *   - User-Override (Settings-UI, Phase 6 — User kann false positive
 *     manuell auf trusted setzen).
 */
final class LookalikeDetector
{
	public const LEVENSHTEIN_MAX  = 3;   // ebay ↔ ebay-mails: 6, faellt durch — Hyphen-Pfad fangt's
	public const PREFIX_MATCH_MIN = 4;   // min. Stem-Laenge fuer Prefix-Vergleich (verhindert false positive auf 1-2-Zeichen-Stems)

	public function __construct(
		private readonly SenderRepository $senders,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Pruefen ob $candidateStem ein Lookalike eines bekannten trusted
	 * Senders desselben Tenants ist. Wenn ja: Sender per ID auf
	 * suspected_spoof flippen.
	 *
	 * @param string $candidateSenderId  frisch von SenderResolver erzeugt
	 * @param string $candidateStem      sender_key des frischen Buckets
	 * @return array{spoof:bool, of_sender_id?:string, of_sender_key?:string, reason?:string}
	 */
	public function check(string $tenantId, string $candidateSenderId, string $candidateStem): array
	{
		$candidate = strtolower(trim($candidateStem));
		if ($candidate === '') {
			return ['spoof' => false];
		}

		// Selbst-Match ausschliessen — kann vorkommen wenn der Sender
		// erst angelegt und dann erneut bei einer zweiten Mail durchlaeuft.
		$trusted = array_values(array_filter(
			$this->senders->listForTenant($tenantId, 'trusted'),
			fn(array $s): bool => (string)$s['id'] !== $candidateSenderId,
		));
		if ($trusted === []) {
			return ['spoof' => false];
		}

		foreach ($trusted as $known) {
			$knownStem = strtolower((string)$known['sender_key']);
			if ($knownStem === $candidate) {
				// Identischer Stem auf verschiedenen registrable Domains —
				// kommt theoretisch nicht vor (SenderResolver merged), aber
				// defensiv: kein Spoof, sondern Domain-Variante.
				continue;
			}

			$reason = self::detectReason($candidate, $knownStem);
			if ($reason === null) {
				continue;
			}

			$this->senders->updateTrustStatus(
				$tenantId,
				$candidateSenderId,
				'suspected_spoof',
				(string)$known['id'],
			);
			$this->logger->warning('lookalike.detected', [
				'candidate' => $candidate,
				'spoof_of'  => $knownStem,
				'reason'    => $reason,
			]);
			return [
				'spoof'         => true,
				'of_sender_id'  => (string)$known['id'],
				'of_sender_key' => $knownStem,
				'reason'        => $reason,
			];
		}

		return ['spoof' => false];
	}

	/**
	 * Liefert den Match-Grund als kurzen String fuer Logs/UI, oder null
	 * wenn die zwei Stems nicht aehnlich sind.
	 *
	 * Static + public, damit der LookalikeDetectorTest die Heuristik
	 * isoliert pruefen kann ohne SenderRepository (final → nicht mockbar)
	 * zu instanziieren.
	 */
	public static function detectReason(string $candidate, string $known): ?string
	{
		// Heuristik 1: Hyphen-Token. „amazon-email" beginnt mit „amazon-".
		// Erfasst auch „login-amazon" (suffix), daher beide Richtungen.
		if (strlen($known) >= self::PREFIX_MATCH_MIN) {
			if (str_starts_with($candidate, $known . '-') || str_ends_with($candidate, '-' . $known)) {
				return "hyphen_token_of:{$known}";
			}
		}

		// Heuristik 2: Levenshtein bei kurzen Strings (Tippfehler-Spoof
		// wie „amaz0n"). PHP-levenshtein arbeitet auf Bytes, max 255 —
		// trivial weil Stems immer < 64 Zeichen sind.
		if (strlen($candidate) <= 255 && strlen($known) <= 255) {
			$d = levenshtein($candidate, $known);
			if ($d > 0 && $d <= self::LEVENSHTEIN_MAX) {
				// Plus: gemeinsamer Prefix mind. PREFIX_MATCH_MIN Zeichen,
				// sonst sind „ebay" und „etsy" auch Distanz 3 → false positive.
				$prefixLen = self::commonPrefixLength($candidate, $known);
				if ($prefixLen >= self::PREFIX_MATCH_MIN) {
					return "levenshtein_{$d}_prefix_{$prefixLen}_of:{$known}";
				}
			}
		}

		return null;
	}

	private static function commonPrefixLength(string $a, string $b): int
	{
		$max = min(strlen($a), strlen($b));
		for ($i = 0; $i < $max; $i++) {
			if ($a[$i] !== $b[$i]) {
				return $i;
			}
		}
		return $max;
	}
}
