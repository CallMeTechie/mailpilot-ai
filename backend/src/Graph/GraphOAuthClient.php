<?php
declare(strict_types=1);

namespace MailPilot\Graph;

use RuntimeException;

/**
 * OAuth2 authorization-code flow + token-refresh fuer Microsoft Entra.
 *
 * Token-Endpoint nutzt application/x-www-form-urlencoded (nicht JSON wie
 * /graph.microsoft.com), deshalb eigene curl-Implementierung — kein
 * GraphHttpTransport-Shared-Use.
 *
 * Ausgegliedert aus GraphClient (Phase-3 split).
 */
final class GraphOAuthClient
{
	private const AUTH_BASE = 'https://login.microsoftonline.com';

	public function __construct(
		private readonly string $clientId,
		private readonly string $clientSecret,
		private readonly string $redirectUri,
		private readonly string $tenant,
		private readonly string $scopes,
	) {
	}

	public function authorizationUrl(string $state, string $codeChallenge): string
	{
		return sprintf(
			'%s/%s/oauth2/v2.0/authorize?%s',
			self::AUTH_BASE,
			$this->tenant,
			http_build_query([
				'client_id'             => $this->clientId,
				'response_type'         => 'code',
				'redirect_uri'          => $this->redirectUri,
				'response_mode'         => 'query',
				'scope'                 => $this->scopes,
				'state'                 => $state,
				'code_challenge'        => $codeChallenge,
				'code_challenge_method' => 'S256',
			]),
		);
	}

	/**
	 * @return array{access_token:string, refresh_token:string, expires_in:int, scope:string}
	 */
	public function exchangeCode(string $code, string $codeVerifier): array
	{
		return $this->tokenRequest([
			'client_id'     => $this->clientId,
			'client_secret' => $this->clientSecret,
			'code'          => $code,
			'redirect_uri'  => $this->redirectUri,
			'grant_type'    => 'authorization_code',
			'scope'         => $this->scopes,
			'code_verifier' => $codeVerifier,
		]);
	}

	/**
	 * @return array{access_token:string, refresh_token:string, expires_in:int, scope:string}
	 */
	public function refreshToken(string $refreshToken): array
	{
		return $this->tokenRequest([
			'client_id'     => $this->clientId,
			'client_secret' => $this->clientSecret,
			'refresh_token' => $refreshToken,
			'grant_type'    => 'refresh_token',
			'scope'         => $this->scopes,
		]);
	}

	/**
	 * @param array<string, string> $params
	 * @return array<string, mixed>
	 */
	private function tokenRequest(array $params): array
	{
		$url = self::AUTH_BASE . '/' . $this->tenant . '/oauth2/v2.0/token';
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query($params),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
		]);
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($status < 200 || $status >= 300 || !is_string($body)) {
			throw new RuntimeException('Graph token request failed: ' . $status);
		}
		return json_decode($body, true, 16, JSON_THROW_ON_ERROR);
	}
}
