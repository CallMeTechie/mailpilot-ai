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
/**
 * Concrete implementation. Not declared `final` so test fakes can
 * extend it with a no-args constructor and override the handful of
 * methods they exercise — see backend/tests/Fixtures/FakeGraphClient.
 * Production code should still depend on this concrete type; we have
 * one provider, an interface here would be pure ceremony.
 */
class GraphClient
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

	/**
	 * Direct single-message fetch. Used by the ensure-scored endpoint
	 * when delta doesn't return a mail the user has open in Outlook
	 * (subfolder routing, stale cursor, focused-vs-other split, …).
	 *
	 * Returns null on HTTP 404 — caller decides whether to bubble up.
	 *
	 * @return array<string, mixed>|null
	 */
	public function fetchMessage(string $accessToken, string $messageId): ?array
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId)
			. '?$select=id,conversationId,internetMessageId,from,toRecipients,ccRecipients,'
			. 'subject,bodyPreview,body,hasAttachments,receivedDateTime,internetMessageHeaders,categories';
		try {
			return $this->get($accessToken, $url);
		} catch (\RuntimeException $e) {
			// GraphClient::get throws "Graph GET failed: <status>" — match
			// on the 404 suffix so we return null instead of bubbling a
			// 500 up to the caller.
			if (preg_match('/\b404\b/', $e->getMessage())) {
				return null;
			}
			throw $e;
		}
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

	/**
	 * Sprint 6d — liest eine einzelne MailFolder-Row aus Graph. Returns
	 * null bei 404 (Folder wurde gelöscht). ReconciliationService nutzt
	 * displayName + parentFolderId für die Drift-Erkennung.
	 *
	 * @return array{id:string, displayName:string, parentFolderId:?string}|null
	 */
	public function getFolder(string $accessToken, string $folderId): ?array
	{
		$url = self::GRAPH_BASE . '/me/mailFolders/' . rawurlencode($folderId)
			. '?$select=id,displayName,parentFolderId';
		try {
			$res = $this->get($accessToken, $url);
		} catch (\Throwable $e) {
			if (preg_match('/\b404\b|ErrorFolderNotFound/i', $e->getMessage())) {
				return null;
			}
			throw $e;
		}
		return [
			'id'             => (string)($res['id'] ?? $folderId),
			'displayName'    => (string)($res['displayName'] ?? ''),
			'parentFolderId' => isset($res['parentFolderId']) ? (string)$res['parentFolderId'] : null,
		];
	}

	public function deleteMessage(string $accessToken, string $messageId): void
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId);
		$this->del($accessToken, $url);
	}

	/**
	 * Find a direct-child folder by display name, or null if missing.
	 * parentId null searches the user's root mail folders; otherwise
	 * inside the given parent.
	 */
	public function findChildFolderByName(string $accessToken, string $displayName, ?string $parentId = null): ?string
	{
		$base = $parentId === null
			? self::GRAPH_BASE . '/me/mailFolders'
			: self::GRAPH_BASE . '/me/mailFolders/' . rawurlencode($parentId) . '/childFolders';
		$escaped = str_replace("'", "''", $displayName);
		$url = $base . '?$select=id,displayName&$filter=' . rawurlencode("displayName eq '{$escaped}'") . '&$top=1';
		$resp = $this->get($accessToken, $url);
		return isset($resp['value'][0]['id']) ? (string)$resp['value'][0]['id'] : null;
	}

	public function createChildFolder(string $accessToken, string $displayName, ?string $parentId = null): string
	{
		$url = $parentId === null
			? self::GRAPH_BASE . '/me/mailFolders'
			: self::GRAPH_BASE . '/me/mailFolders/' . rawurlencode($parentId) . '/childFolders';
		$resp = $this->postJson($accessToken, $url, ['displayName' => $displayName]);
		if (!isset($resp['id'])) {
			throw new \RuntimeException('Graph createFolder returned no id');
		}
		return (string)$resp['id'];
	}

	/**
	 * Resolve a "/"-separated folder path like "MailPilot/Newsletter"
	 * to a leaf folder id, creating any missing segments along the
	 * way. Idempotent.
	 */
	public function ensureFolderPath(string $accessToken, string $path): string
	{
		$segments = array_values(array_filter(array_map('trim', explode('/', $path)), static fn(string $s): bool => $s !== ''));
		if ($segments === []) {
			throw new \InvalidArgumentException('empty folder path');
		}
		$parentId = null;
		foreach ($segments as $name) {
			$found = $this->findChildFolderByName($accessToken, $name, $parentId);
			$parentId = $found ?? $this->createChildFolder($accessToken, $name, $parentId);
		}
		return (string)$parentId;
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
		$body   = (string)curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($status < 200 || $status >= 300) {
			// Graph 4xx liefert {error:{code,message}}. Wir packen den
			// error.code in die Exception, damit der Caller (AutoSortService)
			// zwischen "Message weg" (ErrorItemNotFound) und "Folder weg"
			// (ErrorFolderNotFound) unterscheiden kann.
			$code = '';
			try {
				$decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
				if (is_array($decoded) && isset($decoded['error']['code'])) {
					$code = ' (' . (string)$decoded['error']['code'] . ')';
				}
			} catch (\JsonException) { /* keine strukturierte Antwort */ }
			throw new RuntimeException("Graph POST failed: {$status}{$code}");
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
