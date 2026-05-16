<?php
declare(strict_types=1);

namespace MailPilot\Graph;

use InvalidArgumentException;
use RuntimeException;

/**
 * MailFolder-Operationen — find/create + Path-Resolution mit Lazy-Create.
 *
 * Ausgegliedert aus GraphClient (Phase-3 split). Konsumiert
 * GraphHttpTransport als shared HTTP-Layer.
 */
final class GraphFolderClient
{
	private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

	public function __construct(
		private readonly GraphHttpTransport $http,
	) {
	}

	/**
	 * @return array{id:string, displayName:string, parentFolderId:?string}|null
	 */
	public function get(string $accessToken, string $folderId): ?array
	{
		$url = self::GRAPH_BASE . '/me/mailFolders/' . rawurlencode($folderId)
			. '?$select=id,displayName,parentFolderId';
		try {
			$res = $this->http->get($accessToken, $url);
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

	public function findChildByName(string $accessToken, string $displayName, ?string $parentId = null): ?string
	{
		$base = $parentId === null
			? self::GRAPH_BASE . '/me/mailFolders'
			: self::GRAPH_BASE . '/me/mailFolders/' . rawurlencode($parentId) . '/childFolders';
		$escaped = str_replace("'", "''", $displayName);
		$url = $base . '?$select=id,displayName&$filter=' . rawurlencode("displayName eq '{$escaped}'") . '&$top=1';
		$resp = $this->http->get($accessToken, $url);
		return isset($resp['value'][0]['id']) ? (string)$resp['value'][0]['id'] : null;
	}

	public function createChild(string $accessToken, string $displayName, ?string $parentId = null): string
	{
		$url = $parentId === null
			? self::GRAPH_BASE . '/me/mailFolders'
			: self::GRAPH_BASE . '/me/mailFolders/' . rawurlencode($parentId) . '/childFolders';
		$resp = $this->http->postJson($accessToken, $url, ['displayName' => $displayName]);
		if (!isset($resp['id'])) {
			throw new RuntimeException('Graph createFolder returned no id');
		}
		return (string)$resp['id'];
	}

	/**
	 * Resolved "A/B/C"-Path zu leaf-folder-id, erstellt fehlende Segmente.
	 * Idempotent.
	 */
	public function ensurePath(string $accessToken, string $path): string
	{
		$segments = array_values(array_filter(array_map('trim', explode('/', $path)), static fn(string $s): bool => $s !== ''));
		if ($segments === []) {
			throw new InvalidArgumentException('empty folder path');
		}
		$parentId = null;
		foreach ($segments as $name) {
			$found = $this->findChildByName($accessToken, $name, $parentId);
			$parentId = $found ?? $this->createChild($accessToken, $name, $parentId);
		}
		return (string)$parentId;
	}
}
