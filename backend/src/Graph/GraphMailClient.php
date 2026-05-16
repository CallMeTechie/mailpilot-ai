<?php
declare(strict_types=1);

namespace MailPilot\Graph;

use Psr\Log\LoggerInterface;

/**
 * Mail-Operationen: Inbox-Delta-Sync, Single-Message-Fetch, Kategorien,
 * Read-Flag, Move, Delete, Conversation-Last-Message, /me.
 *
 * Ausgegliedert aus GraphClient (Phase-3 split). Konsumiert
 * GraphHttpTransport als shared HTTP-Layer.
 */
final class GraphMailClient
{
	private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

	public function __construct(
		private readonly GraphHttpTransport $http,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Delta sync der Inbox. Liefert messages + next delta token.
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

			$resp = $this->http->get($accessToken, $url);
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
	 * @return array<string, mixed>|null
	 */
	public function fetchMessage(string $accessToken, string $messageId): ?array
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId)
			. '?$select=id,conversationId,internetMessageId,from,toRecipients,ccRecipients,'
			. 'subject,bodyPreview,body,hasAttachments,receivedDateTime,internetMessageHeaders,categories';
		try {
			return $this->http->get($accessToken, $url);
		} catch (\RuntimeException $e) {
			if (preg_match('/\b404\b/', $e->getMessage())) {
				return null;
			}
			throw $e;
		}
	}

	public function setCategories(string $accessToken, string $messageId, array $categories): void
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId);
		$this->http->patch($accessToken, $url, ['categories' => array_values($categories)]);
	}

	public function markAsRead(string $accessToken, string $messageId): void
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId);
		$this->http->patch($accessToken, $url, ['isRead' => true]);
	}

	/**
	 * Verschiebt eine Mail. Liefert NEUE message id — AQMk-IDs sind
	 * nicht stabil ueber Moves. Caller muss das in mails.ms_message_id
	 * schreiben.
	 */
	public function moveToFolder(string $accessToken, string $messageId, string $folderId): ?string
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId) . '/move';
		$resp = $this->http->postJson($accessToken, $url, ['destinationId' => $folderId]);
		if ($resp !== null && isset($resp['id']) && is_string($resp['id']) && $resp['id'] !== '') {
			return $resp['id'];
		}
		return null;
	}

	public function deleteMessage(string $accessToken, string $messageId): void
	{
		$url = self::GRAPH_BASE . '/me/messages/' . rawurlencode($messageId);
		$this->http->delete($accessToken, $url);
	}

	/**
	 * @return array{id:string, from_email:string, received_at:string, sent_at:?string}|null
	 */
	public function getConversationLastMessage(string $accessToken, string $conversationId): ?array
	{
		$url = self::GRAPH_BASE . '/me/messages'
			. '?$filter=' . rawurlencode("conversationId eq '" . $conversationId . "'")
			. '&$top=1&$orderby=receivedDateTime%20desc'
			. '&$select=id,from,sender,receivedDateTime,sentDateTime';
		try {
			$res = $this->http->get($accessToken, $url);
		} catch (\RuntimeException $e) {
			if (preg_match('/\b404\b/', $e->getMessage())) {
				return null;
			}
			throw $e;
		}
		$rows = $res['value'] ?? [];
		if (!is_array($rows) || $rows === []) {
			return null;
		}
		$msg = $rows[0];
		$fromEmail = $msg['from']['emailAddress']['address']
			?? $msg['sender']['emailAddress']['address']
			?? '';
		return [
			'id'          => (string)($msg['id'] ?? ''),
			'from_email'  => strtolower((string)$fromEmail),
			'received_at' => (string)($msg['receivedDateTime'] ?? ''),
			'sent_at'     => isset($msg['sentDateTime']) ? (string)$msg['sentDateTime'] : null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getMe(string $accessToken): array
	{
		return $this->http->get($accessToken, self::GRAPH_BASE . '/me');
	}
}
