<?php
declare(strict_types=1);

namespace MailPilot\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Util\Uuid;
use PDO;

/**
 * Centralised JWT issuance and verification.
 *
 * Hardening:
 *  - issuer (iss) and audience (aud) bound to deployment
 *  - jti per token, persisted in jwt_blacklist on revoke / refresh-rotation
 *  - HS256 with operator-supplied secret
 *  - 30 s leeway compensates for client/server clock skew
 */
final class JwtService
{
	private const ALGO = 'HS256';
	private const LEEWAY = 30;

	public function __construct(
		private readonly string $secret,
		private readonly string $issuer,
		private readonly string $audience,
		private readonly int $ttlSeconds,
		private readonly ?PDO $db = null,
	) {
		if ($this->secret === '') {
			throw new \RuntimeException('JWT secret missing');
		}
	}

	/**
	 * @return array{token:string, jti:string, exp:int}
	 */
	public function issue(string $tenantId, string $userId, string $email): array
	{
		$now = time();
		$jti = Uuid::v4();
		$exp = $now + $this->ttlSeconds;
		$token = JWT::encode([
			'iss'       => $this->issuer,
			'aud'       => $this->audience,
			'jti'       => $jti,
			'tenant_id' => $tenantId,
			'user_id'   => $userId,
			'email'     => $email,
			'iat'       => $now,
			'nbf'       => $now,
			'exp'       => $exp,
		], $this->secret, self::ALGO);

		return ['token' => $token, 'jti' => $jti, 'exp' => $exp];
	}

	/**
	 * @return array{tenant_id:string, user_id:string, email:string, jti:string, exp:int}
	 */
	public function verify(string $token): array
	{
		JWT::$leeway = self::LEEWAY;
		try {
			$decoded = (array)JWT::decode($token, new Key($this->secret, self::ALGO));
		} catch (\Throwable) {
			throw HttpException::unauthorized('AUTH_INVALID', 'Token ungültig oder abgelaufen');
		}

		if (($decoded['iss'] ?? null) !== $this->issuer) {
			throw HttpException::unauthorized('AUTH_INVALID_ISSUER', 'Issuer ungültig');
		}
		if (($decoded['aud'] ?? null) !== $this->audience) {
			throw HttpException::unauthorized('AUTH_INVALID_AUDIENCE', 'Audience ungültig');
		}

		$jti = (string)($decoded['jti'] ?? '');
		if ($jti !== '' && $this->isRevoked($jti)) {
			throw HttpException::unauthorized('AUTH_REVOKED', 'Token wurde widerrufen');
		}

		return [
			'tenant_id' => (string)($decoded['tenant_id'] ?? ''),
			'user_id'   => (string)($decoded['user_id']   ?? ''),
			'email'     => (string)($decoded['email']     ?? ''),
			'jti'       => $jti,
			'exp'       => (int)($decoded['exp']          ?? 0),
		];
	}

	public function revoke(string $jti, int $exp): void
	{
		if ($this->db === null || $jti === '') {
			return;
		}
		$stmt = $this->db->prepare(
			'INSERT IGNORE INTO jwt_blacklist (jti, expires_at) VALUES (:j, FROM_UNIXTIME(:e))'
		);
		$stmt->execute([':j' => $jti, ':e' => $exp]);
	}

	public function purgeExpiredBlacklist(): int
	{
		if ($this->db === null) {
			return 0;
		}
		$stmt = $this->db->prepare('DELETE FROM jwt_blacklist WHERE expires_at < UTC_TIMESTAMP()');
		$stmt->execute();
		return $stmt->rowCount();
	}

	private function isRevoked(string $jti): bool
	{
		if ($this->db === null) {
			return false;
		}
		$stmt = $this->db->prepare('SELECT 1 FROM jwt_blacklist WHERE jti = :j LIMIT 1');
		$stmt->execute([':j' => $jti]);
		return $stmt->fetchColumn() !== false;
	}
}
