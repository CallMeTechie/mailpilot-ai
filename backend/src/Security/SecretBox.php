<?php
declare(strict_types=1);

namespace MailPilot\Security;

use RuntimeException;

/**
 * Phase 9q-A (Marc 2026-05-22) — symmetrische Verschluesselung fuer API-
 * Keys in der DB (llm_providers.api_key_encrypted).
 *
 * Nutzt libsodium secretbox (XSalsa20 + Poly1305-MAC). Authenticated
 * encryption — Manipulation am Ciphertext fuehrt zu DecryptException,
 * NICHT zu garbage-plaintext. Damit kann ein DB-Dump zwar gesehen
 * werden, aber ohne Master-Key NICHT entschluesselt oder gefaked.
 *
 * Master-Key:
 *   - Bevorzugt: /run/secrets/llm_master_key (Docker-Secret, nur im
 *     backend-Container gemounted, nicht im DB-Dump enthalten)
 *   - Fallback: env-Var LLM_MASTER_KEY (hex-kodiert)
 *   - Falls beides fehlt: wir crashen mit klarer Fehlermeldung. Marc
 *     soll bewusst den Master-Key anlegen — niemals auto-generate, weil
 *     ein neu-erzeugter Key alle Bestands-Encrypted-Werte unlesbar macht.
 *
 * Master-Key-Format: 32 Bytes raw oder 64 Hex-Zeichen. Lifetime: stabil
 * — wenn rotiert, muessen alle bestehenden Encrypted-Werte neu
 * verschluesselt werden (Re-Encryption-Tooling kommt in 9q-G falls noetig).
 */
final class SecretBox
{
	private const SECRET_FILE = '/run/secrets/llm_master_key';
	private const ENV_VAR     = 'LLM_MASTER_KEY';

	private readonly string $key;

	public function __construct(?string $masterKey = null)
	{
		$raw = $masterKey ?? self::loadKey();
		if (strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
			throw new RuntimeException(sprintf(
				'Master-Key muss exakt %d Bytes haben (gefunden: %d)',
				SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($raw)
			));
		}
		$this->key = $raw;
	}

	/**
	 * Liest den Master-Key aus Docker-Secret-Datei oder env-Var. Hex-
	 * kodierte Werte werden automatisch dekodiert.
	 */
	private static function loadKey(): string
	{
		if (is_readable(self::SECRET_FILE)) {
			$raw = trim((string)file_get_contents(self::SECRET_FILE));
		} else {
			$raw = trim((string)getenv(self::ENV_VAR));
		}
		if ($raw === '') {
			throw new RuntimeException(
				'Master-Key nicht gefunden. Lege ' . self::SECRET_FILE . ' an '
				. '(32 Bytes raw oder 64 Hex-Zeichen) oder setze die Env-Var ' . self::ENV_VAR
			);
		}
		// Hex-kodiert? Dann decodieren.
		if (strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2 && ctype_xdigit($raw)) {
			return sodium_hex2bin($raw);
		}
		return $raw;
	}

	/**
	 * Verschluesselt $plaintext. Output ist base64 von (nonce | ciphertext).
	 * Nonce wird per Aufruf neu zufaellig erzeugt — zweimal das gleiche
	 * Plaintext liefert unterschiedliche Cipher (semantically secure).
	 */
	public function encrypt(string $plaintext): string
	{
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
		return base64_encode($nonce . $cipher);
	}

	/**
	 * Entschluesselt $token. Wirft RuntimeException bei MAC-Mismatch,
	 * kaputter Base64-Encodierung oder falsche Laenge.
	 */
	public function decrypt(string $token): string
	{
		$raw = base64_decode($token, true);
		if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
			throw new RuntimeException('SecretBox: ciphertext zu kurz oder kein gueltiges Base64');
		}
		$nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
		if ($plain === false) {
			throw new RuntimeException('SecretBox: Entschluesselung fehlgeschlagen (MAC mismatch oder falscher Master-Key)');
		}
		return $plain;
	}
}
