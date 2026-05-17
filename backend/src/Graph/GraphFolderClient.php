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
	 *
	 * Phase-H7 — Path-Traversal-Sanity: $path kann aus Claude-Output
	 * stammen (sub_label-Discovery → folder_default.<primary>/<candidate>).
	 * Reject Segmente die als Path-Escape interpretierbar waeren.
	 *
	 * @throws InvalidArgumentException bei leerem oder unsicherem Pfad
	 */
	public function ensurePath(string $accessToken, string $path): string
	{
		$segments = self::validateAndSplitPath($path);
		$parentId = null;
		foreach ($segments as $name) {
			$found = $this->findChildByName($accessToken, $name, $parentId);
			$parentId = $found ?? $this->createChild($accessToken, $name, $parentId);
		}
		return (string)$parentId;
	}

	/**
	 * Splittet "/"-getrennten Pfad in Segmente und prueft jedes Segment
	 * auf Path-Traversal-Tricks. Static, damit der Test ohne Transport-
	 * Dependency aufrufen kann.
	 *
	 * Verboten in einem Segment:
	 *   - leer (nach trim)
	 *   - '.' oder '..' (Parent-Folder-Trick)
	 *   - leading/trailing '.' (Hidden-Folder)
	 *   - Control-Chars (< 0x20 oder DEL)
	 *   - Backslash, NUL-Byte, Newlines (Path-Injection in Graph-API-URL)
	 *   - laenger als 64 Zeichen pro Segment (Graph-Limit)
	 *
	 * Erlaubt: Unicode-Letters/Digits + Whitespace + '-' '_' '(' ')' '&' '+' '.'
	 * (Punkt nur INNEN, nicht am Anfang/Ende).
	 *
	 * @return list<string>
	 * @throws InvalidArgumentException
	 */
	public static function validateAndSplitPath(string $path): array
	{
		if ($path === '' || trim($path) === '') {
			throw new InvalidArgumentException('empty folder path');
		}
		if (str_starts_with($path, '/')) {
			throw new InvalidArgumentException('absolute folder path not allowed (no leading slash)');
		}
		if (str_contains($path, "\0")) {
			throw new InvalidArgumentException('null byte in folder path');
		}
		if (str_contains($path, '\\')) {
			throw new InvalidArgumentException('backslash in folder path');
		}

		$segments = array_values(array_filter(
			array_map('trim', explode('/', $path)),
			static fn(string $s): bool => $s !== '',
		));
		if ($segments === []) {
			throw new InvalidArgumentException('empty folder path');
		}

		foreach ($segments as $seg) {
			if ($seg === '.' || $seg === '..') {
				throw new InvalidArgumentException("path traversal segment forbidden: {$seg}");
			}
			if (str_starts_with($seg, '.') || str_ends_with($seg, '.')) {
				throw new InvalidArgumentException("folder segment cannot start/end with dot: {$seg}");
			}
			if (mb_strlen($seg) > 64) {
				throw new InvalidArgumentException("folder segment exceeds 64 chars: {$seg}");
			}
			if (preg_match('/[\x00-\x1F\x7F]/', $seg) === 1) {
				throw new InvalidArgumentException("control char in folder segment: {$seg}");
			}
			if (preg_match('/^[\p{L}\p{N}\s\-_().&+]+$/u', $seg) !== 1) {
				throw new InvalidArgumentException("invalid char in folder segment: {$seg}");
			}
		}
		return $segments;
	}
}
