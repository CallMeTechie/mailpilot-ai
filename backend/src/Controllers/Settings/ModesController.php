<?php
declare(strict_types=1);

namespace MailPilot\Controllers\Settings;

use MailPilot\Controllers\BaseController;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\AutoReplyService;

/**
 * /api/v1/settings/modes + auto-reply/include-backlog.
 * Ausgegliedert aus SettingsController (Phase 2 split).
 *
 * Sprint 6c+6f Toggles:
 *   - autosort_move_mode
 *   - autosort_create_topic_mode
 *   - autosort_reply_mode
 *   - rule_inference_enabled / -backfill_range
 *   - autoreply_enabled / -max_per_day
 */
final class ModesController extends BaseController
{
	public function getModes(array $params, array $body): void
	{
		$this->requireAuth();
		$s = $this->kernel->get(SettingsRepository::class);
		Response::json([
			'autosort_move_mode'            => $s->getString('autosort_move_mode',         'suggest'),
			'autosort_create_topic_mode'    => $s->getString('autosort_create_topic_mode', 'suggest'),
			'autosort_reply_mode'           => $s->getString('autosort_reply_mode',        'suggest'),
			'rule_inference_enabled'        => $s->getBool('rule_inference_enabled', true),
			'rule_inference_backfill_range' => $s->getString('rule_inference_backfill_range', 'last_30_days'),
			'autoreply_enabled'             => $s->getBool('autoreply_enabled', false),
			'autoreply_enabled_at'          => $s->getString('autoreply_enabled_at', ''),
			'autoreply_max_per_day'         => $s->getInt('autoreply_max_per_day', 15),
		]);
	}

	/**
	 * Sprint 6f — Backlog inkludieren: setzt enabled_at zurueck und
	 * triggert EINEN sofortigen AutoReply-Tick.
	 */
	public function includeAutoReplyBacklog(array $params, array $body): void
	{
		$this->requireAuth();
		$s = $this->kernel->get(SettingsRepository::class);
		if (!$s->getBool('autoreply_enabled', false)) {
			throw HttpException::preconditionFailed('AUTOREPLY_DISABLED',
				'Auto-Reply-Drafts sind deaktiviert — erst im Auto-Sort-Tab aktivieren.');
		}
		$s->set('autoreply_enabled_at', '');
		$result = $this->kernel->get(AutoReplyService::class)->tick();
		Response::json(['ok' => true, 'tick' => $result]);
	}

	/**
	 * Hierarchie-Pruefung DA-Finding 3:
	 *   level(autosort_create_topic_mode) <= level(autosort_move_mode)
	 */
	public function saveModes(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$allowed = ['off', 'suggest', 'auto'];
		$level   = ['off' => 0, 'suggest' => 1, 'auto' => 2];

		$move   = isset($body['autosort_move_mode'])         ? (string)$body['autosort_move_mode']         : null;
		$topic  = isset($body['autosort_create_topic_mode']) ? (string)$body['autosort_create_topic_mode'] : null;
		$reply  = isset($body['autosort_reply_mode'])        ? (string)$body['autosort_reply_mode']        : null;

		foreach ([$move, $topic, $reply] as $v) {
			if ($v !== null && !in_array($v, $allowed, true)) {
				throw HttpException::badRequest('INVALID_MODE', 'Modus muss off/suggest/auto sein');
			}
		}

		$s = $this->kernel->get(SettingsRepository::class);
		$effMove  = $move  ?? $s->getString('autosort_move_mode',         'suggest');
		$effTopic = $topic ?? $s->getString('autosort_create_topic_mode', 'suggest');

		if ($level[$effTopic] > $level[$effMove]) {
			throw HttpException::badRequest('TOGGLE_HIERARCHY',
				"autosort_create_topic_mode ({$effTopic}) darf nicht aggressiver sein als autosort_move_mode ({$effMove})");
		}

		if ($move  !== null) $s->set('autosort_move_mode',         $move);
		if ($topic !== null) $s->set('autosort_create_topic_mode', $topic);
		if ($reply !== null) $s->set('autosort_reply_mode',        $reply);

		if (array_key_exists('rule_inference_enabled', $body)) {
			$s->set('rule_inference_enabled', ((bool)$body['rule_inference_enabled']) ? '1' : '0');
		}
		if (array_key_exists('rule_inference_backfill_range', $body)) {
			$range = (string)$body['rule_inference_backfill_range'];
			if (!in_array($range, ['future_only', 'last_30_days', 'all'], true)) {
				throw HttpException::badRequest('INVALID_RANGE',
					'rule_inference_backfill_range muss future_only|last_30_days|all sein');
			}
			$s->set('rule_inference_backfill_range', $range);
		}

		if (array_key_exists('autoreply_enabled', $body)) {
			$wasEnabled = $s->getBool('autoreply_enabled', false);
			$now = (bool)$body['autoreply_enabled'];
			$s->set('autoreply_enabled', $now ? '1' : '0');
			if ($now && !$wasEnabled) {
				$s->set('autoreply_enabled_at', gmdate('Y-m-d\TH:i:s\Z'));
			}
		}

		$pendingCounts = $this->kernel
			->get(PendingActionRepository::class)
			->countByKind($ctx['tenant_id'], $ctx['user_id']);

		Response::json([
			'autosort_move_mode'         => $effMove,
			'autosort_create_topic_mode' => $effTopic,
			'autosort_reply_mode'        => $reply ?? $s->getString('autosort_reply_mode', 'suggest'),
			'existing_pending'           => $pendingCounts,
		]);
	}
}
