<?php
declare(strict_types=1);

namespace MailPilot\Tests\Fixtures;

use MailPilot\Graph\GraphClient;
use Psr\Log\NullLogger;

/**
 * Test double for GraphClient. Records folder-resolution calls and
 * move-to-folder calls, returns scripted folder ids, and lets a test
 * throw on demand to simulate Graph errors (404 stale folder etc.).
 *
 * Only the two methods AutoSortService actually exercises are
 * overridden — everything else inherits the real GraphClient body,
 * which is unreachable in tests because we never call those methods.
 */
final class FakeGraphClient extends GraphClient
{
	/** @var array<string, string> path → folder_id mapping */
	public array $folderIds = [];

	/** @var list<array{access_token:string, path:string}> */
	public array $folderCalls = [];

	/** @var list<array{access_token:string, message_id:string, folder_id:string}> */
	public array $moveCalls = [];

	private ?\Throwable $nextEnsureError = null;
	private ?\Throwable $nextMoveError   = null;

	/** @var array<string, array{id:string,displayName:string,parentFolderId:?string}> folder_id → folder-meta */
	public array $folderMeta = [];
	/** @var array<string, \Throwable> folder_id → Throwable (für 503-/throttle-Tests) */
	public array $folderErrors = [];
	/** @var list<string> abgefragte folder_ids */
	public array $getFolderCalls = [];

	public function __construct()
	{
		parent::__construct('fake-client', 'fake-secret', 'https://fake/cb', 'common', 'Mail.Read', new NullLogger());
	}

	/**
	 * Pre-bind a folder path → id mapping. Otherwise ensureFolderPath
	 * will hand out a deterministic synthetic id.
	 */
	public function scriptFolder(string $path, string $folderId): void
	{
		$this->folderIds[$path] = $folderId;
	}

	public function failNextEnsure(\Throwable $e): void
	{
		$this->nextEnsureError = $e;
	}

	public function failNextMove(\Throwable $e): void
	{
		$this->nextMoveError = $e;
	}

	public function ensureFolderPath(string $accessToken, string $path): string
	{
		$this->folderCalls[] = ['access_token' => $accessToken, 'path' => $path];
		if ($this->nextEnsureError !== null) {
			$e = $this->nextEnsureError;
			$this->nextEnsureError = null;
			throw $e;
		}
		return $this->folderIds[$path] ??= 'fake-folder-' . substr(md5($path), 0, 8);
	}

	public function moveToFolder(string $accessToken, string $messageId, string $folderId): ?string
	{
		$this->moveCalls[] = [
			'access_token' => $accessToken,
			'message_id'   => $messageId,
			'folder_id'    => $folderId,
		];
		if ($this->nextMoveError !== null) {
			$e = $this->nextMoveError;
			$this->nextMoveError = null;
			throw $e;
		}
		// Graph returnt das verschobene Item mit NEUER id. Fake simuliert
		// das mit einem deterministischen Suffix, damit Tests
		// verifizieren können dass AutoSortService die DB updated.
		return $messageId . '-moved-to-' . $folderId;
	}

	public function scriptFolderMeta(string $folderId, string $displayName, ?string $parentFolderId = null): void
	{
		$this->folderMeta[$folderId] = [
			'id' => $folderId, 'displayName' => $displayName, 'parentFolderId' => $parentFolderId,
		];
	}

	public function scriptFolderError(string $folderId, \Throwable $e): void
	{
		$this->folderErrors[$folderId] = $e;
	}

	public function getFolder(string $accessToken, string $folderId): ?array
	{
		$this->getFolderCalls[] = $folderId;
		if (isset($this->folderErrors[$folderId])) {
			throw $this->folderErrors[$folderId];
		}
		return $this->folderMeta[$folderId] ?? null;
	}

	/** @var array{messages: list<array<string,mixed>>, delta_token: ?string}|null */
	private ?array $syncInboxResponse = null;

	public function scriptSyncInbox(array $messages, ?string $deltaToken = null): void
	{
		$this->syncInboxResponse = ['messages' => $messages, 'delta_token' => $deltaToken];
	}

	public function syncInbox(string $accessToken, ?string $deltaToken = null): array
	{
		if ($this->syncInboxResponse !== null) {
			return $this->syncInboxResponse;
		}
		return ['messages' => [], 'delta_token' => null];
	}

	/** @var array<string, array{id:string, from_email:string, received_at:string, sent_at:?string}|null> */
	private array $conversationLast = [];

	public function scriptConversationLastMessage(string $conversationId, ?array $message): void
	{
		$this->conversationLast[$conversationId] = $message;
	}

	public function getConversationLastMessage(string $accessToken, string $conversationId): ?array
	{
		return $this->conversationLast[$conversationId] ?? null;
	}
}
