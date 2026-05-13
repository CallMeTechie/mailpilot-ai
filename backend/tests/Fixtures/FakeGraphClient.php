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

	public function moveToFolder(string $accessToken, string $messageId, string $folderId): void
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
	}
}
