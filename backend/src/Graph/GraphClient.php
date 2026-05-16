<?php
declare(strict_types=1);

namespace MailPilot\Graph;

/**
 * Facade ueber Microsoft Graph API. Public-API ist 100% identisch zur
 * Vor-Split-Version (FakeGraphClient extends GraphClient ueberschreibt
 * weiterhin die Public-Methoden direkt — die Sub-Clients werden in
 * diesem Fall nie aufgerufen).
 *
 * Scopes required (delegated):
 *  - offline_access
 *  - Mail.Read             (read inbox)
 *  - Mail.ReadWrite        (set categories, move, delete)
 *  - MailboxSettings.Read
 *  - User.Read
 *
 * Phase-3 Split: Sub-Logik in 4 fokussierte Klassen:
 *   - GraphHttpTransport   — curl + 429-Retry-Loop + JSON-Parsing
 *   - GraphOAuthClient     — authorizationUrl / exchangeCode / refreshToken
 *   - GraphMailClient      — Inbox-Delta-Sync / Single-Fetch / Move / Categories
 *   - GraphFolderClient    — Find/Create + Path-Resolution mit Lazy-Create
 *
 * Class ist nicht `final`, damit test fakes (FakeGraphClient) extend
 * koennen — vgl. backend/tests/Fixtures/FakeGraphClient.
 */
class GraphClient
{
	private readonly GraphOAuthClient $oauth;
	private readonly GraphMailClient $mail;
	private readonly GraphFolderClient $folder;

	public function __construct(
		string $clientId,
		string $clientSecret,
		string $redirectUri,
		string $tenant,
		string $scopes,
		\Psr\Log\LoggerInterface $logger,
	) {
		$http        = new GraphHttpTransport($logger);
		$this->oauth = new GraphOAuthClient($clientId, $clientSecret, $redirectUri, $tenant, $scopes);
		$this->mail  = new GraphMailClient($http, $logger);
		$this->folder = new GraphFolderClient($http);
	}

	// ============================================================
	// OAuth-Delegation (GraphOAuthClient)
	// ============================================================

	public function authorizationUrl(string $state, string $codeChallenge): string
	{
		return $this->oauth->authorizationUrl($state, $codeChallenge);
	}

	/**
	 * @return array{access_token:string, refresh_token:string, expires_in:int, scope:string}
	 */
	public function exchangeCode(string $code, string $codeVerifier): array
	{
		return $this->oauth->exchangeCode($code, $codeVerifier);
	}

	/**
	 * @return array{access_token:string, refresh_token:string, expires_in:int, scope:string}
	 */
	public function refreshToken(string $refreshToken): array
	{
		return $this->oauth->refreshToken($refreshToken);
	}

	// ============================================================
	// Mail-Delegation (GraphMailClient)
	// ============================================================

	/**
	 * @return array{messages: list<array<string, mixed>>, delta: ?string}
	 */
	public function syncInbox(string $accessToken, ?string $deltaToken = null): array
	{
		return $this->mail->syncInbox($accessToken, $deltaToken);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function fetchMessage(string $accessToken, string $messageId): ?array
	{
		return $this->mail->fetchMessage($accessToken, $messageId);
	}

	public function setCategories(string $accessToken, string $messageId, array $categories): void
	{
		$this->mail->setCategories($accessToken, $messageId, $categories);
	}

	public function markAsRead(string $accessToken, string $messageId): void
	{
		$this->mail->markAsRead($accessToken, $messageId);
	}

	public function moveToFolder(string $accessToken, string $messageId, string $folderId): ?string
	{
		return $this->mail->moveToFolder($accessToken, $messageId, $folderId);
	}

	public function deleteMessage(string $accessToken, string $messageId): void
	{
		$this->mail->deleteMessage($accessToken, $messageId);
	}

	/**
	 * @return array{id:string, from_email:string, received_at:string, sent_at:?string}|null
	 */
	public function getConversationLastMessage(string $accessToken, string $conversationId): ?array
	{
		return $this->mail->getConversationLastMessage($accessToken, $conversationId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getMe(string $accessToken): array
	{
		return $this->mail->getMe($accessToken);
	}

	// ============================================================
	// Folder-Delegation (GraphFolderClient)
	// ============================================================

	/**
	 * @return array{id:string, displayName:string, parentFolderId:?string}|null
	 */
	public function getFolder(string $accessToken, string $folderId): ?array
	{
		return $this->folder->get($accessToken, $folderId);
	}

	public function findChildFolderByName(string $accessToken, string $displayName, ?string $parentId = null): ?string
	{
		return $this->folder->findChildByName($accessToken, $displayName, $parentId);
	}

	public function createChildFolder(string $accessToken, string $displayName, ?string $parentId = null): string
	{
		return $this->folder->createChild($accessToken, $displayName, $parentId);
	}

	public function ensureFolderPath(string $accessToken, string $path): string
	{
		return $this->folder->ensurePath($accessToken, $path);
	}
}
