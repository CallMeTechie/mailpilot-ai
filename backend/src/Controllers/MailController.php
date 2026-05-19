<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Graph\GraphClient;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Request;
use MailPilot\Http\Response;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\DraftRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Services\MailScoringService;
use MailPilot\Services\MailSummaryService;
use MailPilot\Services\QuotaExceededException;
use MailPilot\Services\ReplyDraftService;
use MailPilot\Services\RuleInferenceService;
use MailPilot\Services\Sender\FolderPathBuilder;
use MailPilot\Services\Sender\SenderResolver;
use MailPilot\Services\TokenService;

final class MailController extends BaseController
{
	public function list(array $params, array $body): void
	{
		$ctx = $this->requireAuth();

		$since = Request::query('since');
		$label = Request::query('label');
		$msMsgId = Request::query('ms_message_id');
		$limit = max(1, min(200, (int)(Request::query('limit', '50'))));

		$pdo = $this->kernel->get(\PDO::class);
		$sql = 'SELECT m.id, m.from_email, m.from_name, m.subject, m.received_at, m.ms_message_id,
					   s.label, s.action_required, s.priority, s.summary, s.scored_at
				FROM mails m
				LEFT JOIN mail_scores s ON s.mail_id = m.id
				WHERE m.tenant_id = :t AND m.deleted_at IS NULL';
		$p = [':t' => $ctx['tenant_id']];

		if ($since !== null) { $sql .= ' AND m.received_at >= :since'; $p[':since'] = $since; }
		if ($label !== null) { $sql .= ' AND s.label = :lbl';          $p[':lbl']   = $label; }
		if ($msMsgId !== null) { $sql .= ' AND m.ms_message_id = :mid'; $p[':mid']  = $msMsgId; }

		$sql .= ' ORDER BY m.received_at DESC LIMIT ' . $limit;

		$stmt = $pdo->prepare($sql);
		$stmt->execute($p);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		$items = array_map(static function (array $r): array {
			return [
				'id'             => $r['id'],
				'ms_message_id'  => $r['ms_message_id'],
				'from_email'     => $r['from_email'],
				'from_name'      => $r['from_name'],
				'subject'        => $r['subject'],
				'received_at'    => $r['received_at'],
				'score'          => $r['label'] === null ? null : [
					'label'           => $r['label'],
					'action_required' => (bool)$r['action_required'],
					'priority'        => (int)$r['priority'],
					'summary'         => $r['summary'],
					'scored_at'       => $r['scored_at'],
				],
			];
		}, $rows);

		Response::json(['items' => $items, 'next_cursor' => null]);
	}

	/**
	 * Guarantee that a mail (identified by its Graph REST id) is present
	 * in our DB and has a score. Synchronous: one Graph GET if missing,
	 * one Claude call if unscored — no polling, no delta-cursor roulette.
	 *
	 * Used by the add-in's "Diese Mail" tab to replace the old 60-second
	 * mails.list / sync.status polling loop. Returns the same shape as
	 * one row of MailController::list so the renderer is unchanged.
	 */
	public function ensureScored(array $params, array $body): void
	{
		$ctx  = $this->requireAuth();
		// Router doesn't decode path parameters — the encoded "=" at the
		// end of every Graph REST id arrives as "%3D" and never matches
		// the un-encoded ms_message_id stored in mails.
		$msId = rawurldecode((string)($params['ms_message_id'] ?? ''));
		if ($msId === '') {
			throw HttpException::badRequest('VALIDATION', 'ms_message_id fehlt');
		}

		$mailRepo = $this->kernel->get(MailRepository::class);
		$mailboxes = $this->kernel->get(MailboxRepository::class)
			->findByUser($ctx['tenant_id'], $ctx['user_id']);
		if ($mailboxes === []) {
			throw HttpException::preconditionFailed('MAILBOX_NOT_CONNECTED', 'Kein Postfach verbunden');
		}

		$mail = $mailRepo->findByMsMessageId($ctx['tenant_id'], $msId);

		if ($mail === null) {
			$graph  = $this->kernel->get(GraphClient::class);
			$tokens = $this->kernel->get(TokenService::class);
			$graphMsg = null;
			$mbHit = null;
			foreach ($mailboxes as $mb) {
				$token = $tokens->ensureFreshAccessToken($mb);
				$graphMsg = $graph->fetchMessage($token, $msId);
				if ($graphMsg !== null) { $mbHit = $mb; break; }
			}
			if ($graphMsg === null || $mbHit === null) {
				throw HttpException::notFound('NOT_FOUND', 'Mail in Microsoft 365 nicht gefunden');
			}
			$mailRepo->upsertFromGraph($ctx['tenant_id'], (string)$mbHit['id'], $graphMsg);
			// upsertFromGraph mints a fresh UUID for the INSERT and
			// returns it — but ON DUPLICATE KEY UPDATE keeps the
			// existing row's id, so the returned UUID won't match.
			// Look the row back up by its stable Graph id instead.
			$mail = $mailRepo->findByMsMessageId($ctx['tenant_id'], $msId);
			if ($mail === null) {
				throw HttpException::notFound('NOT_FOUND', 'Mail konnte nach Import nicht geladen werden');
			}
		}

		$pdo = $this->kernel->get(\PDO::class);
		$scoreStmt = $pdo->prepare('SELECT 1 FROM mail_scores WHERE mail_id = :id LIMIT 1');
		$scoreStmt->execute([':id' => $mail['id']]);
		$wasJustScored = false;
		if ($scoreStmt->fetchColumn() === false) {
			$userRow = $this->fetchUser($ctx['user_id']);
			$profile = $this->buildUserProfile($ctx, $userRow);
			$this->kernel->get(MailScoringService::class)
				->scoreBatch($ctx['tenant_id'], $profile, [$mail]);
			$wasJustScored = true;
		}

		$stmt = $pdo->prepare('SELECT m.id, m.mailbox_id, m.from_email, m.from_name, m.subject, m.received_at, m.ms_message_id,
				s.label, s.sub_label, s.action_required, s.action_owner, s.priority, s.summary, s.scored_at,
				s.inbox_score, s.folder_segments, s.spoof_suspect
			FROM mails m LEFT JOIN mail_scores s ON s.mail_id = m.id
			WHERE m.id = :id LIMIT 1');
		$stmt->execute([':id' => $mail['id']]);
		$r = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

		// Phase 9d (Marc 2026-05-19): Score-Override nachtraeglich auf
		// existierende Scores anwenden. Greift fuer Mails, die VOR der
		// Aktivierung einer Regel gescored wurden — sonst blieben sie auf
		// dem alten KI-Score haengen. scoreBatch hat das beim wasJustScored-
		// Pfad bereits erledigt, daher nur auf existing scores nachschieben.
		if (!$wasJustScored && ($r['label'] ?? null) !== null) {
			try {
				$existingSegs = null;
				if (!empty($r['folder_segments'])) {
					$decoded = json_decode((string)$r['folder_segments'], true);
					if (is_array($decoded)) $existingSegs = array_values(array_map('strval', $decoded));
				}
				$applied = $this->kernel->get(MailScoringService::class)
					->applyOverrideToExistingScore($ctx['tenant_id'], $ctx['user_id'], $mail, [
						'label'           => $r['label'],
						'priority'        => isset($r['priority']) ? (int)$r['priority'] : null,
						'action_required' => isset($r['action_required']) ? (int)(bool)$r['action_required'] : 0,
						'folder_segments' => $existingSegs,
					]);
				if (($applied['matched'] ?? false) && !empty($applied['changes'])) {
					$stmt->execute([':id' => $mail['id']]);
					$r = $stmt->fetch(\PDO::FETCH_ASSOC) ?: $r;
				}
			} catch (\Throwable) { /* best-effort */ }
		}


		// Click-time AutoSort: run regardless of whether this is the
		// first score or a stale score we just retrieved. The
		// applyToScoredMail call is cheap when there's nothing to do
		// (rule disabled, high-priority direct/action, already moved
		// via auto_sorted_at), and when there IS work to do this is
		// the difference between "moves in the next 5 minutes" and
		// "moves now".
		if (($r['label'] ?? null) !== null) {
			$mb = null;
			foreach ($mailboxes as $cand) {
				if ((string)$cand['id'] === (string)$r['mailbox_id']) { $mb = $cand; break; }
			}
			if ($mb !== null) {
				try {
					$token = $this->kernel->get(TokenService::class)->ensureFreshAccessToken($mb);
					// Phase 7: folder_segments + inbox_score mitgeben, damit
					// AutoSortService den Sender-Pfad statt Legacy-Rule nimmt.
					$claudeSegments = null;
					if (!empty($r['folder_segments'])) {
						$decoded = json_decode((string)$r['folder_segments'], true);
						if (is_array($decoded)) $claudeSegments = array_values(array_map('strval', $decoded));
					}
					$this->kernel->get(\MailPilot\Services\AutoSortService::class)
						->applyToScoredMail($token, $ctx['tenant_id'], $ctx['user_id'], $mail, [
							'label'           => $r['label'],
							'sub_label'       => $r['sub_label'] ?? null,
							'priority'        => $r['priority'],
							'action_required' => $r['action_required'],
							// 2026-05-15 Bug-Fix: action_owner war hier
							// nicht durchgereicht → user_action_required-
							// Schutz griff bei Click-time-AutoSort nie.
							'action_owner'    => $r['action_owner'] ?? '',
							'folder_segments' => $claudeSegments,
							'inbox_score'     => $r['inbox_score'] !== null ? (int)$r['inbox_score'] : null,
						]);
				} catch (\Throwable) { /* best-effort, never break the response */ }
			}
		}

		// Phase 5b: Pfad-Vorschau fuer den Done-Button im DieseMail-Tab.
		// Identische Mechanik wie BriefingController::buildPinnedList — Sender
		// via PSL aufloesen, FolderPathBuilder baut den finalen Pfad.
		$segments = null;
		if (!empty($r['folder_segments'])) {
			$decoded = json_decode((string)$r['folder_segments'], true);
			if (is_array($decoded)) {
				$segments = array_values(array_map('strval', $decoded));
			}
		}
		$previewPath = null;
		if ($segments !== null && !empty($r['from_email'])) {
			$host = strrpos((string)$r['from_email'], '@') !== false
				? substr((string)$r['from_email'], strrpos((string)$r['from_email'], '@') + 1)
				: '';
			$regDomain = $host !== ''
				? $this->kernel->get(SenderResolver::class)->registrableDomain($host)
				: null;
			$bucket = $regDomain !== null
				? $this->kernel->get(\MailPilot\Repositories\SenderRepository::class)
					->findByRegistrableDomain($ctx['tenant_id'], $regDomain)
				: null;
			$previewPath = $this->kernel->get(FolderPathBuilder::class)->build($bucket, $segments);
		}

		Response::json(['mail' => [
			'id'             => $r['id']            ?? null,
			'ms_message_id'  => $r['ms_message_id'] ?? null,
			'from_email'     => $r['from_email']    ?? null,
			'from_name'      => $r['from_name']     ?? null,
			'subject'        => $r['subject']       ?? null,
			'received_at'    => $r['received_at']   ?? null,
			'score'          => ($r['label'] ?? null) === null ? null : [
				'label'           => $r['label'],
				'action_required' => (bool)$r['action_required'],
				'priority'        => (int)$r['priority'],
				'summary'         => $r['summary'],
				'scored_at'       => $r['scored_at'],
				// Phase 5b: KI-Felder fuer Done-Button + Spoof-Indicator
				'inbox_score'     => $r['inbox_score'] !== null ? (int)$r['inbox_score'] : null,
				'spoof_suspect'   => (bool)(int)($r['spoof_suspect'] ?? 0),
				'preview_path'    => $previewPath,
				// Phase 9e (Marc 2026-05-19): segments separat, damit der
				// Add-in das aktuelle Topic im Korrektur-Form vorausfuellt.
				'folder_segments' => $segments,
			],
		]]);
	}

	public function summarize(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$userRow = $this->fetchUser($ctx['user_id']);
		$summary = $this->kernel->get(MailSummaryService::class)
			->summarize(
				$ctx['tenant_id'],
				(string)$params['id'],
				$ctx['email'],
				(string)($userRow['language'] ?? 'de'),
				$ctx['user_id'],
			);
		Response::json(['summary' => $summary]);
	}

	public function draftReply(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$instruction = isset($body['instruction']) ? (string)$body['instruction'] : null;
		$draft = $this->kernel->get(ReplyDraftService::class)
			->draft($ctx['tenant_id'], (string)$params['id'], $instruction, $ctx['user_id']);
		Response::json(['draft' => $draft]);
	}

	/**
	 * Sprint 6f — liefert die aktive (non-dismissed, non-stale) Draft für
	 * eine Mail, oder null. Wird vom Add-in im „Diese Mail"-Tab gepollt
	 * um die Draft-Box anzuzeigen.
	 */
	public function getActiveDraft(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$mailId = (string)($params['id'] ?? '');
		$draft = $this->kernel->get(DraftRepository::class)
			->findActiveForMail($ctx['tenant_id'], $mailId);
		if ($draft === null) {
			Response::json(['draft' => null]);
			return;
		}
		Response::json(['draft' => [
			'id'           => (string)$draft['id'],
			'draft_text'   => (string)$draft['draft_text'],
			'created_by'   => (string)($draft['created_by'] ?? 'user'),
			'generated_at' => (string)$draft['generated_at'],
			'stale_at'     => $draft['stale_at']     !== null ? (string)$draft['stale_at']     : null,
			'dismissed_at' => $draft['dismissed_at'] !== null ? (string)$draft['dismissed_at'] : null,
		]]);
	}

	/**
	 * Sprint 6f — User verwirft eine Draft (Auto- oder On-demand).
	 * Setzt dismissed_at; Worker generiert keine neue, bis der User
	 * im Add-in „Neuen Entwurf" klickt (= draftReply on-demand).
	 */
	public function dismissDraft(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$draftId = (string)($params['id'] ?? '');
		$ok = $this->kernel->get(DraftRepository::class)
			->markDismissed($ctx['tenant_id'], $draftId);
		if (!$ok) {
			throw HttpException::notFound('DRAFT_NOT_FOUND', 'Draft nicht gefunden oder bereits verworfen');
		}
		Response::json(['ok' => true]);
	}

	public function rescore(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$mail = $this->kernel->get(MailRepository::class)->findById($ctx['tenant_id'], (string)$params['id']);
		if ($mail === null) {
			throw HttpException::notFound('NOT_FOUND', 'Mail nicht gefunden');
		}
		$userRow = $this->fetchUser($ctx['user_id']);
		$profile = $this->buildUserProfile($ctx, $userRow);

		$this->kernel->get(MailScoringService::class)
			->scoreBatch($ctx['tenant_id'], $profile, [$mail]);

		Response::json(['ok' => true]);
	}

	/**
	 * User-driven score correction.
	 *
	 * Body: {
	 *   "label":           "direct"|"action"|"cc"|"newsletter"|"auto"|"noise",
	 *   "priority":        1..5,
	 *   "action_required": bool,
	 *   "reasoning":       string|null   (max 500 chars, optional)
	 * }
	 *
	 * Stores the correction (with the KI's original values for context),
	 * overwrites mail_scores with the new values, and stamps
	 * user_corrected_at so future Claude runs don't overwrite the
	 * human verdict. MailScoringService (Stage 4c) reads recent
	 * corrections back into the system prompt as few-shot examples.
	 */
	public function correctScore(array $params, array $body): void
	{
		$ctx    = $this->requireAuth();
		$mailId = (string)($params['id'] ?? '');
		if ($mailId === '') {
			throw HttpException::badRequest('VALIDATION', 'Mail-ID fehlt');
		}

		$labelsAllowed = ['direct', 'action', 'cc', 'newsletter', 'auto', 'noise'];
		$label = (string)($body['label'] ?? '');
		if (!in_array($label, $labelsAllowed, true)) {
			throw HttpException::badRequest('VALIDATION', 'Ungültiges Label');
		}
		$priority = max(1, min(5, (int)($body['priority'] ?? 0)));

		$mail = $this->kernel->get(MailRepository::class)->findById($ctx['tenant_id'], $mailId);
		if ($mail === null) {
			throw HttpException::notFound('NOT_FOUND', 'Mail nicht gefunden');
		}

		// Read current (about-to-be-overwritten) score so we can preserve
		// the KI's original verdict alongside the correction.
		$pdo = $this->kernel->get(\PDO::class);
		$origStmt = $pdo->prepare('SELECT label, priority, action_required
			FROM mail_scores WHERE mail_id = :id AND tenant_id = :t LIMIT 1');
		$origStmt->execute([':id' => $mailId, ':t' => $ctx['tenant_id']]);
		$orig = $origStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

		$reasoningText = isset($body['reasoning']) ? trim((string)$body['reasoning']) : '';

		// Phase 9e (Marc 2026-05-19): Topic-Korrektur. Wenn der User in der
		// „Klassifikation korrigieren"-Form ein Topic eingibt, persistieren
		// wir das als folder_segments-Override + verschieben die Mail JETZT
		// in den neuen Pfad + leiten ggf. eine generelle Topic-Regel ab.
		$topicText = isset($body['topic']) ? trim((string)$body['topic']) : '';
		if (mb_strlen($topicText) > 64) {
			throw HttpException::badRequest('VALIDATION', 'Topic max 64 Zeichen');
		}
		if (str_contains($topicText, '/') || str_contains($topicText, '\\')) {
			throw HttpException::badRequest('VALIDATION', 'Topic darf kein "/" oder "\\" enthalten');
		}

		$this->kernel->get(CorrectionRepository::class)->record(
			$ctx['tenant_id'],
			$ctx['user_id'],
			$mailId,
			[
				'label'           => $label,
				'priority'        => $priority,
				'action_required' => (bool)($body['action_required'] ?? false),
				'reasoning'       => $reasoningText !== '' ? $reasoningText : null,
			],
			[
				'label'           => $orig['label']           ?? null,
				'priority'        => isset($orig['priority']) ? (int)$orig['priority'] : null,
				'action_required' => isset($orig['action_required']) ? (bool)$orig['action_required'] : null,
			],
		);

		// Sprint 6g — wenn der User eine Begründung mitgegeben hat,
		// versucht der RuleInferenceService daraus eine AutoSort-Regel
		// abzuleiten. Failures sind nicht fatal — die Korrektur selbst
		// ist schon committed; das Add-in zeigt nur einen weniger
		// hilfreichen Toast.
		$ruleResult = null;
		$scoreRuleResult = null;
		if ($reasoningText !== '') {
			try {
				$ruleResult = $this->kernel->get(RuleInferenceService::class)
					->infer($ctx['tenant_id'], $ctx['user_id'], $mailId, $reasoningText);
			} catch (QuotaExceededException $e) {
				throw HttpException::tooManyRequests(
					'QUOTA_EXCEEDED',
					'Tageslimit für Auto-Rule-Inference erreicht. Korrektur ist gespeichert; die Regel-Ableitung ist morgen wieder verfügbar.'
				);
			} catch (\Throwable $e) {
				// Logging übernimmt der Service. Wir scheitern still und
				// liefern die Korrektur-Response ohne rule_inference-Block.
				$ruleResult = ['action' => 'error', 'reason' => $e->getMessage()];
			}

			// Phase 9b (Marc 2026-05-19): zusaetzlich Score-Override-Regel
			// ableiten. Parallel zur Folder-Inference oben — share denselben
			// rule_inference-Quota-Counter. Wenn Folder-Inferenz Quota frisst,
			// kann Score-Inferenz im selben Call den 429 werfen — wir
			// schlucken den Throw still, weil die Korrektur selbst bereits
			// committed ist.
			try {
				$scoreRuleResult = $this->kernel->get(RuleInferenceService::class)
					->inferScoreRule(
						$ctx['tenant_id'],
						$ctx['user_id'],
						$mailId,
						[
							'label'           => $label,
							'priority'        => $priority,
							'action_required' => (bool)($body['action_required'] ?? false),
						],
						[
							'label'           => $orig['label']           ?? null,
							'priority'        => isset($orig['priority']) ? (int)$orig['priority'] : null,
							'action_required' => isset($orig['action_required']) ? (bool)$orig['action_required'] : null,
						],
						$reasoningText,
					);
			} catch (QuotaExceededException $e) {
				$scoreRuleResult = ['action' => 'skipped', 'reason' => 'quota_exceeded'];
			} catch (\Throwable $e) {
				$scoreRuleResult = ['action' => 'error', 'reason' => $e->getMessage()];
			}
		}

		// Phase 9e (Marc 2026-05-19): Topic-Apply. Wenn der User ein Topic
		// gegeben hat: (a) folder_segments im mail_scores persistieren mit
		// sticky-Flag, (b) Mail jetzt nach Sender-Root/<Topic> verschieben,
		// (c) KI-Inferenz fuer eine Topic-Regel (immer, auch ohne reasoning —
		// dann mit niedriger Confidence + enabled=false).
		$topicApplied  = null;
		$movedTo       = null;
		$topicRuleInfo = null;
		if ($topicText !== '') {
			$bucket = $this->kernel->get(SenderResolver::class)
				->resolve($ctx['tenant_id'], (string)($mail['from_email'] ?? ''));
			$senderRoot = $bucket['root_folder_name'] ?? null;
			$segments   = $senderRoot !== null && $senderRoot !== ''
				? [(string)$senderRoot, $topicText]
				: [$topicText];

			// (a) Persistieren — sticky setzen, sonst ueberschreibt naechstes Scoring.
			$pdo->prepare('UPDATE mail_scores
				SET folder_segments     = :fs,
				    user_corrected_fields = CONCAT_WS(",",
				        NULLIF(user_corrected_fields, ""), "folder_segments"),
				    user_corrected_at   = COALESCE(user_corrected_at, UTC_TIMESTAMP(3))
				WHERE mail_id = :id AND tenant_id = :t')
				->execute([
					':fs' => json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
					':id' => $mailId,
					':t'  => $ctx['tenant_id'],
				]);
			$topicApplied = $segments;

			// (b) Move-Now via AutoSortService::applyManualMove.
			try {
				$folderPath = $this->kernel->get(FolderPathBuilder::class)->build($bucket, $segments);
				if ($folderPath !== null) {
					$mbAll = $this->kernel->get(\MailPilot\Repositories\MailboxRepository::class)
						->findByUser($ctx['tenant_id'], $ctx['user_id']);
					$mb = null;
					foreach ($mbAll as $cand) {
						if ((string)$cand['id'] === (string)$mail['mailbox_id']) { $mb = $cand; break; }
					}
					if ($mb !== null) {
						$token = $this->kernel->get(TokenService::class)->ensureFreshAccessToken($mb);
						$moveResult = $this->kernel->get(\MailPilot\Services\AutoSortService::class)
							->applyManualMove($token, $ctx['tenant_id'], $ctx['user_id'], $mail, $folderPath);
						if (!empty($moveResult['moved'])) {
							$movedTo = $folderPath;
						}
					}
				}
			} catch (\Throwable) { /* best-effort */ }

			// (c) Topic-Regel ableiten (immer, auch ohne reasoning).
			try {
				$topicRuleInfo = $this->kernel->get(RuleInferenceService::class)
					->inferTopicRule(
						$ctx['tenant_id'],
						$ctx['user_id'],
						$mailId,
						$segments,
						$reasoningText,
					);
			} catch (QuotaExceededException $e) {
				$topicRuleInfo = ['action' => 'skipped', 'reason' => 'quota_exceeded'];
			} catch (\Throwable $e) {
				$topicRuleInfo = ['action' => 'error', 'reason' => $e->getMessage()];
			}
		}

		$response = ['ok' => true, 'score' => [
			'label'           => $label,
			'priority'        => $priority,
			'action_required' => (bool)($body['action_required'] ?? false),
			'user_corrected'  => true,
		]];
		if ($ruleResult !== null) {
			$response['rule_inference'] = $ruleResult;
		}
		if ($scoreRuleResult !== null) {
			$response['score_rule_inference'] = $scoreRuleResult;
		}
		if ($topicApplied !== null) {
			$response['topic_applied'] = $topicApplied;
		}
		if ($movedTo !== null) {
			$response['moved_to'] = $movedTo;
		}
		if ($topicRuleInfo !== null) {
			$response['topic_rule_inference'] = $topicRuleInfo;
		}
		Response::json($response);
	}

	/**
	 * Phase 4 (Marc 2026-05-18) — User klickt „Erledigt — verschieben".
	 *
	 * Flow:
	 *   1. mails.user_cleared_at setzen (idempotent).
	 *   2. Score-Felder folder_segments laden.
	 *   3. SenderResolver liefert Sender-Bucket (root_folder_name).
	 *   4. FolderPathBuilder baut finalen Pfad. Wenn null → kein Move,
	 *      bleibt in Inbox (KI hatte keinen Sortier-Vorschlag).
	 *   5. AutoSortService::applyManualMove fuehrt den Graph-Move aus.
	 *
	 * Response: { ok, moved:bool, folder?:string, reason?:string }
	 */
	public function markUserDone(array $params, array $body): void
	{
		$ctx    = $this->requireAuth();
		$mailId = (string)($params['id'] ?? '');
		if ($mailId === '') {
			throw HttpException::badRequest('VALIDATION', 'Mail-ID fehlt');
		}

		$mail = $this->kernel->get(MailRepository::class)->findById($ctx['tenant_id'], $mailId);
		if ($mail === null) {
			throw HttpException::notFound('NOT_FOUND', 'Mail nicht gefunden');
		}

		// 1) Idempotent done-Marker setzen.
		$this->kernel->get(MailRepository::class)->markUserDone($ctx['tenant_id'], $mailId);

		// 2) Score laden, um folder_segments zu bekommen.
		$pdo = $this->kernel->get(\PDO::class);
		$stmt = $pdo->prepare('SELECT folder_segments FROM mail_scores
			WHERE mail_id = :id AND tenant_id = :t LIMIT 1');
		$stmt->execute([':id' => $mailId, ':t' => $ctx['tenant_id']]);
		$scoreRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
		$segments = null;
		if (isset($scoreRow['folder_segments']) && $scoreRow['folder_segments'] !== null) {
			$decoded = json_decode((string)$scoreRow['folder_segments'], true);
			if (is_array($decoded)) {
				$segments = array_values(array_map('strval', $decoded));
			}
		}

		// 3) Sender-Bucket holen — registriert ggf. einen neuen Bucket,
		// haengt registrable Domain an existierenden an.
		$bucket = $this->kernel->get(SenderResolver::class)
			->resolve($ctx['tenant_id'], (string)($mail['from_email'] ?? ''));

		// 4) Pfad bauen. Wenn keine Empfehlung → return ohne Move.
		$folderPath = $this->kernel->get(FolderPathBuilder::class)->build($bucket, $segments);
		if ($folderPath === null) {
			Response::json([
				'ok'     => true,
				'moved'  => false,
				'reason' => 'no_folder_suggestion',
				'cleared_at' => gmdate('Y-m-d\TH:i:s\Z'),
			]);
			return;
		}

		// 5) Graph-Move ausfuehren. Mailbox aus mail.mailbox_id finden, Token holen.
		$mailboxes = $this->kernel->get(\MailPilot\Repositories\MailboxRepository::class)
			->findByUser($ctx['tenant_id'], $ctx['user_id']);
		$mb = null;
		foreach ($mailboxes as $cand) {
			if ((string)$cand['id'] === (string)$mail['mailbox_id']) { $mb = $cand; break; }
		}
		if ($mb === null) {
			throw HttpException::preconditionFailed('MAILBOX_NOT_CONNECTED', 'Postfach der Mail nicht verfuegbar');
		}
		$token = $this->kernel->get(TokenService::class)->ensureFreshAccessToken($mb);

		$result = $this->kernel->get(\MailPilot\Services\AutoSortService::class)
			->applyManualMove($token, $ctx['tenant_id'], $ctx['user_id'], $mail, $folderPath);

		Response::json(['ok' => true] + $result + [
			'cleared_at' => gmdate('Y-m-d\TH:i:s\Z'),
		]);
	}

	/**
	 * Bulk action on all mails matching a label within a time window.
	 *
	 * Action (route param):
	 *   mark-read  → PATCH isRead:true via Graph
	 *   archive    → POST /move destinationId=archive via Graph
	 *   delete     → DELETE via Graph (moves to Deleted Items) + soft-delete in DB
	 *   hide       → soft-delete in our DB only, Outlook untouched
	 *
	 * Body: { label: <enum>, since?: 'YYYY-MM-DD HH:MM:SS.000', limit?: 50 }
	 */
	public function bulkAction(array $params, array $body): void
	{
		$ctx    = $this->requireAuth();
		$action = (string)($params['action'] ?? '');
		$valid  = ['mark-read', 'archive', 'delete', 'hide'];
		if (!in_array($action, $valid, true)) {
			throw HttpException::badRequest('VALIDATION', 'Ungültige Aktion');
		}

		$label = isset($body['label']) ? (string)$body['label'] : null;
		if ($label === null || $label === '') {
			throw HttpException::badRequest('VALIDATION', 'label fehlt');
		}
		$sinceUtc = isset($body['since']) ? (string)$body['since']
			: gmdate('Y-m-d H:i:s.000', time() - 7 * 86400);
		$limit = max(1, min(200, (int)($body['limit'] ?? 50)));

		$pdo = $this->kernel->get(\PDO::class);
		$stmt = $pdo->prepare('SELECT m.id, m.ms_message_id, m.mailbox_id
			FROM mails m
			JOIN mail_scores s ON s.mail_id = m.id
			WHERE m.tenant_id = :t
			  AND m.deleted_at IS NULL
			  AND s.label = :l
			  AND m.received_at >= :since
			ORDER BY m.received_at DESC
			LIMIT ' . $limit);
		$stmt->execute([':t' => $ctx['tenant_id'], ':l' => $label, ':since' => $sinceUtc]);
		$mails = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		if ($mails === []) {
			Response::json(['processed' => 0, 'failed' => []]);
			return;
		}

		// "hide" is DB-only: mark rows deleted, Outlook untouched.
		if ($action === 'hide') {
			$ids = array_column($mails, 'id');
			$placeholders = implode(',', array_fill(0, count($ids), '?'));
			$update = $pdo->prepare("UPDATE mails SET deleted_at = UTC_TIMESTAMP(3)
				WHERE tenant_id = ? AND id IN ($placeholders)");
			$update->execute(array_merge([$ctx['tenant_id']], $ids));
			Response::json(['processed' => count($ids), 'failed' => []]);
			return;
		}

		// Graph-backed actions — fresh access token per mailbox.
		$mailboxes = $this->kernel->get(MailboxRepository::class)
			->findByUser($ctx['tenant_id'], $ctx['user_id']);
		$tokenByMb = [];
		$tokenService = $this->kernel->get(TokenService::class);
		foreach ($mailboxes as $mb) {
			try {
				$tokenByMb[(string)$mb['id']] = $tokenService->ensureFreshAccessToken($mb);
			} catch (\Throwable) {
				// Refresh failed → its mails will end up in `failed`.
			}
		}

		$graph     = $this->kernel->get(GraphClient::class);
		$processed = 0;
		$failed    = [];

		foreach ($mails as $mail) {
			$token = $tokenByMb[(string)$mail['mailbox_id']] ?? null;
			if ($token === null) {
				$failed[] = $mail['id'];
				continue;
			}
			try {
				if ($action === 'mark-read') {
					$graph->markAsRead($token, (string)$mail['ms_message_id']);
				} elseif ($action === 'archive') {
					$graph->moveToFolder($token, (string)$mail['ms_message_id'], 'archive');
				} elseif ($action === 'delete') {
					$graph->deleteMessage($token, (string)$mail['ms_message_id']);
					$pdo->prepare('UPDATE mails SET deleted_at = UTC_TIMESTAMP(3) WHERE id = :id')
						->execute([':id' => $mail['id']]);
				}
				$processed++;
			} catch (\Throwable) {
				$failed[] = $mail['id'];
			}
		}

		Response::json(['processed' => $processed, 'failed' => $failed]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchUser(string $userId): array
	{
		$stmt = $this->kernel->get(\PDO::class)->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
		$stmt->execute([':id' => $userId]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row === false ? [] : $row;
	}

	/**
	 * @param array{tenant_id:string, user_id:string, email:string} $ctx
	 * @param array<string, mixed> $userRow
	 * @return array<string, mixed>
	 */
	private function buildUserProfile(array $ctx, array $userRow): array
	{
		$pdo = $this->kernel->get(\PDO::class);

		$vipStmt = $pdo->prepare('SELECT email FROM vip_senders
			WHERE user_id = :u AND deleted_at IS NULL');
		$vipStmt->execute([':u' => $ctx['user_id']]);
		$vips = array_column($vipStmt->fetchAll(\PDO::FETCH_ASSOC), 'email');

		$kwStmt = $pdo->prepare('SELECT keyword FROM project_keywords
			WHERE user_id = :u AND deleted_at IS NULL');
		$kwStmt->execute([':u' => $ctx['user_id']]);
		$kws = array_column($kwStmt->fetchAll(\PDO::FETCH_ASSOC), 'keyword');

		return [
			'tenant_id'        => $ctx['tenant_id'],
			'user_id'          => $ctx['user_id'],
			'email'            => $ctx['email'],
			'language'         => (string)($userRow['language'] ?? 'de'),
			'vip_senders'      => $vips,
			'project_keywords' => $kws,
			'user_role'        => '',
		];
	}
}
