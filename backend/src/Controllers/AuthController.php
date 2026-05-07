<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Graph\GraphClient;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\UserRepository;
use MailPilot\Services\JwtService;
use MailPilot\Services\TokenService;

final class AuthController extends BaseController
{
	/**
	 * Start OAuth flow — generate state + PKCE verifier, persist, return auth URL.
	 */
	public function oauthStart(array $params, array $body): void
	{
		$state    = bin2hex(random_bytes(16));
		$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

		$pdo = $this->kernel->get(\PDO::class);
		$pdo->prepare('INSERT INTO oauth_states (state, code_verifier, created_at)
			VALUES (:s, :v, UTC_TIMESTAMP(3))')
			->execute([':s' => $state, ':v' => $verifier]);

		$graph = $this->kernel->get(GraphClient::class);
		$url = $graph->authorizationUrl($state, $challenge);

		Response::json(['auth_url' => $url]);
	}

	/**
	 * OAuth callback — exchange code, parse id_token for ms_tenant_id (tid),
	 * upsert user + mailbox, issue JWT, redirect to add-in.
	 */
	public function oauthCallback(array $params, array $body): void
	{
		$code  = $_GET['code']  ?? null;
		$state = $_GET['state'] ?? null;
		if (!is_string($code) || !is_string($state)) {
			throw HttpException::badRequest('VALIDATION', 'code oder state fehlen');
		}

		$pdo  = $this->kernel->get(\PDO::class);
		$stmt = $pdo->prepare('SELECT code_verifier FROM oauth_states
			WHERE state = :s AND created_at >= (UTC_TIMESTAMP(3) - INTERVAL 10 MINUTE)');
		$stmt->execute([':s' => $state]);
		$row = $stmt->fetch();
		if ($row === false) {
			throw HttpException::badRequest('OAUTH_STATE_EXPIRED', 'State ungültig oder abgelaufen');
		}
		$verifier = (string)$row['code_verifier'];

		$pdo->prepare('DELETE FROM oauth_states WHERE state = :s')->execute([':s' => $state]);

		$graph  = $this->kernel->get(GraphClient::class);
		$tokens = $graph->exchangeCode($code, $verifier);

		// Parse id_token claims for tenant id (tid). Falls back to graph /me.
		$idClaims = isset($tokens['id_token']) && is_string($tokens['id_token'])
			? self::parseJwtClaims($tokens['id_token'])
			: [];

		$me = $graph->getMe($tokens['access_token']);

		$email      = (string)($me['mail'] ?? $me['userPrincipalName'] ?? $idClaims['preferred_username'] ?? '');
		$name       = (string)($me['displayName'] ?? $idClaims['name'] ?? '');
		$msTenantId = (string)($idClaims['tid'] ?? '');
		$msUserId   = (string)($me['id']  ?? $idClaims['oid'] ?? '');

		if ($email === '') {
			throw HttpException::badRequest('OAUTH_PROFILE_INCOMPLETE', 'E-Mail-Adresse konnte nicht ermittelt werden');
		}

		$userRepo    = $this->kernel->get(UserRepository::class);
		$mailboxRepo = $this->kernel->get(MailboxRepository::class);

		[$tenantId, $userId] = $userRepo->upsertTenantAndUser($email, $name);

		$tokenService = $this->kernel->get(TokenService::class);
		$expires = gmdate('Y-m-d H:i:s.000', time() + (int)$tokens['expires_in']);

		$mailboxRepo->upsert(
			$tenantId,
			$userId,
			$email,
			$name,
			$tokenService->encrypt((string)$tokens['access_token']),
			$tokenService->encrypt((string)$tokens['refresh_token']),
			$expires,
			(string)$tokens['scope'],
			$msTenantId !== '' ? $msTenantId : null,
			$msUserId !== ''   ? $msUserId   : null,
		);

		$jwt = $this->kernel->get(JwtService::class)->issue($tenantId, $userId, $email);

		$base = (string)$this->kernel->config['app']['base_url'];
		header('Location: ' . $base . '/addin/auth-complete.html#token=' . urlencode($jwt['token']));
		http_response_code(302);
	}

	/**
	 * Refresh JWT — rotates token: revokes old jti, issues a fresh one.
	 */
	public function refresh(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$jwtService = $this->kernel->get(JwtService::class);
		$jwtService->revoke($ctx['jti'], $ctx['exp']);
		$new = $jwtService->issue($ctx['tenant_id'], $ctx['user_id'], $ctx['email']);
		Response::json([
			'token'      => $new['token'],
			'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $new['exp']),
		]);
	}

	/**
	 * Decode the payload of a JWT WITHOUT signature verification.
	 *
	 * Safe for our use case because the id_token is delivered to us by Microsoft
	 * over a TLS-protected back-channel, not via the user-agent. We only need
	 * the claims to populate our local denormalised user/mailbox records.
	 *
	 * @return array<string, mixed>
	 */
	private static function parseJwtClaims(string $jwt): array
	{
		$parts = explode('.', $jwt);
		if (count($parts) < 2) {
			return [];
		}
		$payload = strtr($parts[1], '-_', '+/');
		$pad = strlen($payload) % 4;
		if ($pad !== 0) {
			$payload .= str_repeat('=', 4 - $pad);
		}
		$json = base64_decode($payload, true);
		if ($json === false) {
			return [];
		}
		try {
			$decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
			return is_array($decoded) ? $decoded : [];
		} catch (\JsonException) {
			return [];
		}
	}
}
