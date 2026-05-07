<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\MailboxRepository;

/**
 * Encrypts/decrypts OAuth tokens and refreshes access tokens before expiry.
 * Encryption: AES-256-GCM with per-install master key.
 */
final class TokenService
{
	private const CIPHER = 'aes-256-gcm';
	private const REFRESH_BUFFER_SECONDS = 120;

	public function __construct(
		private readonly GraphClient $graph,
		private readonly MailboxRepository $mailboxes,
		private readonly string $keyHex,
	) {
		if (strlen($this->keyHex) !== 64) {
			throw new \RuntimeException('encrypt_key must be 32 bytes hex-encoded');
		}
	}

	public function encrypt(string $plaintext): string
	{
		$key = hex2bin($this->keyHex);
		$iv  = random_bytes(12);
		$tag = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
		);
		if ($ciphertext === false) {
			throw new \RuntimeException('encrypt failed');
		}
		return $iv . $tag . $ciphertext;
	}

	public function decrypt(string $blob): string
	{
		$key = hex2bin($this->keyHex);
		$iv  = substr($blob, 0, 12);
		$tag = substr($blob, 12, 16);
		$ct  = substr($blob, 28);
		$pt = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($pt === false) {
			throw new \RuntimeException('decrypt failed');
		}
		return $pt;
	}

	/**
	 * Returns a valid access token, refreshing if necessary.
	 * Mutates DB state on refresh.
	 */
	public function ensureFreshAccessToken(array $mailbox): string
	{
		$expires = $mailbox['access_token_expires'] ?? null;
		$needsRefresh = $expires === null
			|| strtotime((string)$expires) <= (time() + self::REFRESH_BUFFER_SECONDS);

		if (!$needsRefresh && !empty($mailbox['access_token_enc'])) {
			return $this->decrypt((string)$mailbox['access_token_enc']);
		}

		$refreshToken = $this->decrypt((string)$mailbox['refresh_token_enc']);
		$tokens = $this->graph->refreshToken($refreshToken);

		$newExpires = gmdate('Y-m-d H:i:s.000', time() + (int)$tokens['expires_in']);
		$this->mailboxes->updateTokens(
			$mailbox['id'],
			$this->encrypt((string)$tokens['access_token']),
			$this->encrypt((string)($tokens['refresh_token'] ?? $refreshToken)),
			$newExpires,
		);

		return (string)$tokens['access_token'];
	}
}
