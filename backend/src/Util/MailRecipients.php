<?php
declare(strict_types=1);

namespace MailPilot\Util;

/**
 * Reine Helper-Klasse fuer Recipient-Listen-Aufbau aus mails.to_json/cc_json.
 * Frueher in MailScoringService::buildRecipients gewachsen; wurde fuer den
 * Split nach Scoring/ActionOwnerResolver gemeinsam genutzt — daher hier
 * extrahiert.
 */
final class MailRecipients
{
	/**
	 * Baut das recipients-Array aus mails.to_json/cc_json (JSON-Strings oder
	 * bereits dekodierte Arrays). is_user wird via lowercase Email-Vergleich
	 * gesetzt. Wenn die User-Email nicht in den Recipients steht (BCC), ist
	 * sie nicht im Array — Caller muss das via Original-to_json/cc_json
	 * erkennen.
	 *
	 * @param array<string,mixed> $mail
	 * @return list<array{email:string,name:string,role:string,is_user:bool}>
	 */
	public static function build(array $mail, string $userEmail): array
	{
		$out = [];
		foreach (['to_json' => 'to', 'cc_json' => 'cc'] as $field => $role) {
			$raw = $mail[$field] ?? null;
			if (is_string($raw)) {
				$raw = json_decode($raw, true) ?: [];
			}
			if (!is_array($raw)) {
				continue;
			}
			foreach ($raw as $entry) {
				if (!is_array($entry)) continue;
				$email = strtolower((string)($entry['address'] ?? $entry['email'] ?? ''));
				if ($email === '') continue;
				$out[] = [
					'email'   => $email,
					'name'    => (string)($entry['name'] ?? $entry['display_name'] ?? ''),
					'role'    => $role,
					'is_user' => $email === $userEmail,
				];
			}
		}
		return $out;
	}
}
