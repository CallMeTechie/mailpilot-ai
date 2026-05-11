<?php
declare(strict_types=1);

namespace MailPilot\Graph;

use RuntimeException;

/**
 * Minimal Microsoft Graph API client for mail + categories.
 *
 * Scopes required (delegated):
 *  - offline_access
 *  - Mail.Read             (read inbox)
 *  - Mail.ReadWrite        (set categories)
 *  - MailboxSettings.Read
 *  - User.Read
 *
 * Uses OAuth2 authorization code flow; tokens are stored encrypted per mailbox.
 */
final class GraphClient
{
	private const AUTH_BASE  = 'https://login.microsoftonline.com';
	private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

	public function __construct(
		private readonly string $clientId,
		private readonly string $clientSecret,
		private readonly string $redirectUri,
		private readonly string $tenant,
		private readonly string $scopes,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
	}

	public function authorizationUrl(string $state, string $codeChallenge): string
	{
		return sprintf(
			'%s/%s/oauth2/v2.0/authorize?%s',
			self::AUTH_BASE,
			$this->tenant,
			http_build_query([
				'client_id'     => $this->clientId,
				'response_type' => 'code',
				'redirect_uri'  => $this->redirectUri,
				'response_mode' => 'query',
				'scope'         => $this->scopes,
				'state'         => $state,
				'code_challenge' => $codeChallenge,
				'code_challenge_method' => 'S256',
			]),
		);
	}

	/**
	 * Exchange authorization code for tokens.
	 *
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
	 * Delta sync of inbox. Returns list of messages + next delta token.
	 *
	 * @return array{messages: list<array<string, mixed>>, delta: ?string}
	 */
	public function syncInbox(string $accessToken, ?string $deltaToken = null): array
	{
		$url = $deltaToken
			?? self::GRAPH_BASE . '/me/mailFolders/Inbox/messages/delta?$select=id,conversationId,internetMessageId,from,toRecipients,ccRecipients,subject,bodyPreview,body,hasAttachments,receivedDateTime,internetMessageHeaders,categories';

		$messages = [];
		$nextDelta = null;
		$loopGuard = 0;

		while ($url !== null) {
			if (++$loopGuard > 50) {
				$this->logger->warning('graph.delta.loop_guard_hit');
				break;
			}

			$resp = $this->get($accessToken, $url);
			$messages = array_merge($messages, $resp['value'] ?? []);

			if (isset($resp['@odata.nextLink'])) {
				$url = (string)$resp['@odata.nextLink'];
			} elseif (isset($resp['@odata.deltaLink'])) {
				$nextDelta = (string)$resp['@odata.deltaLink'];
				$url = null;
			} else {
				$url = null;
			}
		}

		return ['messages' => $messages, 'delta' => $nextDelta];
	}

	public function setCategories(string $accessToken, string $messageId, array $categories): void
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId);
		$this->patch($accessToken, $url, ['categories' => array_values($categories)]);
	}

	public function markAsRead(string $accessToken, string $messageId): void
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId);
		$this->patch($accessToken, $url, ['isRead' => true]);
	}

	public function moveToFolder(string $accessToken, string $messageId, string $folderId): void
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId) . '/move';
		$this->postJson($accessToken, $url, ['destinationId' => $folderId]);
	}

	public function deleteMessage(string $accessToken, string $messageId): void
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId);
		$this->del($accessToken, $url);
	}

	public function getMe(string $accessToken): array
	{
		return $this->get($accessToken, self::GRAPH_BASE . '/me');
	}

	// -----------------------------------------------------------------
	// HTTP helpers
	// -----------------------------------------------------------------

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

	/**
	 * @return array<string, mixed>
	 */
	private function get(string $accessToken, string $url): array
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $accessToken,
				// REST-ID (the default) is what Office.js convertToRestId()
				// returns — keeping the on-disk format and the add-in's
				// identifier in sync. ImmutableId would survive folder moves
				// but is unreachable from Office.js.
			],
		]);
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($status < 200 || $status >= 300 || !is_string($body)) {
			throw new RuntimeException("Graph GET failed: {$status}");
		}
		return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
	}

	private function patch(string $accessToken, string $url, array $payload): void
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => 'PATCH',
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $accessToken,
				'Content-Type: application/json',
			],
		]);
		curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($status < 200 || $status >= 300) {
			throw new RuntimeException("Graph PATCH failed: {$status}");
		}
	}

	private function postJson(string $accessToken, string $url, array $payload): void
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $accessToken,
				'Content-Type: application/json',
			],
		]);
		curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($status < 200 || $status >= 300) {
			throw new RuntimeException("Graph POST failed: {$status}");
		}
	}

	private function del(string $accessToken, string $url): void
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => 'DELETE',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $accessToken,
			],
		]);
		curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($status < 200 || $status >= 300) {
			throw new RuntimeException("Graph DELETE failed: {$status}");
		}
	}
}
