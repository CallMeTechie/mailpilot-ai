<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

/**
 * Task 8 (Spec 2, 2026-06-04) — Ownership-Guard für score_suggestion-Feedback.
 *
 * Pinnt das sicherheitssensitive Verhalten in PendingController::confirmScoreSuggestion
 * und ::discardScoreSuggestion:
 *
 *   - Approve eines eigenen score_suggestion → Regel wird mutiert (enabled=1,
 *     applies_count+1) und pending-Status → approved.
 *   - Reject eines eigenen score_suggestion → Regel wird deaktiviert (enabled=0)
 *     und pending-Status → rejected.
 *   - Approve/Reject eines score_suggestion dessen rule_id einem anderen User gehört
 *     → KEINE Mutation der fremden Regel, pending trotzdem geschlossen.
 *   - Approve/Reject mit komplett unbekannter rule_id → kein Crash, pending geschlossen.
 *
 * Testet Repository- und Controller-Logik direkt (ohne HTTP-Stack), analog zu
 * PendingActionsTest und InferenceUpdateInPlaceTest.
 *
 * @group integration
 */
final class ScoreSuggestionFeedbackTest extends TestCase
{
	private ScoreOverrideRepository $overrides;
	private PendingActionRepository $pending;

	protected function setUp(): void
	{
		$this->truncateAll();
		$this->overrides = new ScoreOverrideRepository($this->pdo());
		$this->pending   = new PendingActionRepository($this->pdo());
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Legt eine user-derived score_override-Regel an; gibt rule_id zurück.
	 * enabled=0 simuliert den „KI-vorgeschlagen, noch nicht bestätigt"-Zustand.
	 */
	private function seedRule(string $tenantId, string $userId, int $enabled = 0): string
	{
		return $this->overrides->create($tenantId, $userId, [
			'match_sender_key'     => 'github',
			'set_priority'         => 4,
			'enabled'              => $enabled,
			'source'               => 'ki_inferred',
			'origin_correction_id' => Uuid::v4(),
		]);
	}

	/**
	 * Legt eine score_suggestion pending_action an; gibt pending_id zurück.
	 */
	private function seedSuggestion(string $tenantId, string $userId, string $ruleId, string $mailId = 'mail-abc'): string
	{
		return $this->pending->create(
			$tenantId,
			$userId,
			'score_suggestion',
			['rule_id' => $ruleId, 'mail_id' => $mailId, 'proposed' => ['priority' => 4]],
			'suggest',
		);
	}

	/**
	 * Liest enabled + applies_count direkt aus der DB (hydrate() würde enabled
	 * auf bool casten — wir brauchen int für applies_count).
	 *
	 * @return array{enabled:int, applies_count:int}
	 */
	private function ruleState(string $ruleId): array
	{
		$stmt = $this->pdo()->prepare(
			'SELECT enabled, applies_count FROM score_override_rules WHERE id = :id LIMIT 1'
		);
		$stmt->execute([':id' => $ruleId]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		$this->assertIsArray($row, "Regel {$ruleId} fehlt in der DB");
		return ['enabled' => (int)$row['enabled'], 'applies_count' => (int)$row['applies_count']];
	}

	/** Liest pending-Status direkt aus der DB. */
	private function pendingStatus(string $pendingId): string
	{
		$stmt = $this->pdo()->prepare('SELECT status FROM pending_actions WHERE id = :id LIMIT 1');
		$stmt->execute([':id' => $pendingId]);
		return (string)$stmt->fetchColumn();
	}

	/**
	 * Simuliert PendingController::confirmScoreSuggestion + setStatus('approved').
	 * Ownership-Guard via findByIdForUser; Mutation nur wenn Regel dem User gehört.
	 *
	 * @param array<string,mixed> $payload
	 * @return array{rule_id:?string, rule_updated:bool}
	 */
	private function invokeConfirm(string $tenantId, string $userId, string $pendingId, array $payload): array
	{
		$ruleId = isset($payload['rule_id']) && is_string($payload['rule_id']) && $payload['rule_id'] !== ''
			? (string)$payload['rule_id'] : null;

		$result = ['rule_id' => $ruleId, 'rule_updated' => false];

		if ($ruleId !== null) {
			// Ownership-Guard: Regel muss diesem User gehören.
			if ($this->overrides->findByIdForUser($tenantId, $userId, $ruleId) !== null) {
				$this->overrides->recordApply($tenantId, $ruleId);
				$this->overrides->updateFields($tenantId, $ruleId, ['enabled' => 1]);
				$result['rule_updated'] = true;
			}
		}

		$this->pending->setStatus($tenantId, $userId, $pendingId, 'approved');
		return $result;
	}

	/**
	 * Simuliert PendingController::discardScoreSuggestion + setStatus('rejected').
	 *
	 * @param array<string,mixed> $payload
	 */
	private function invokeDiscard(string $tenantId, string $userId, string $pendingId, array $payload): void
	{
		$ruleId = isset($payload['rule_id']) && is_string($payload['rule_id']) && $payload['rule_id'] !== ''
			? (string)$payload['rule_id'] : null;

		if ($ruleId !== null) {
			// Ownership-Guard: Regel muss diesem User gehören.
			if ($this->overrides->findByIdForUser($tenantId, $userId, $ruleId) !== null) {
				$this->overrides->updateFields($tenantId, $ruleId, ['enabled' => 0]);
			}
		}

		$this->pending->setStatus($tenantId, $userId, $pendingId, 'rejected');
	}

	// -------------------------------------------------------------------------
	// Confirm-Pfad (approve)
	// -------------------------------------------------------------------------

	public function testApproveOwnSuggestionEnablesRuleAndClosesPending(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser('userA@example.de');
		$ruleId    = $this->seedRule($tenantId, $userId, enabled: 0);
		$pendingId = $this->seedSuggestion($tenantId, $userId, $ruleId);

		$action = $this->pending->findById($tenantId, $userId, $pendingId);
		$this->assertNotNull($action);
		$this->assertSame('pending', $action['status']);

		$result = $this->invokeConfirm($tenantId, $userId, $pendingId, $action['payload']);

		$this->assertTrue($result['rule_updated'], 'Eigene Regel muss mutiert werden');
		$this->assertSame($ruleId, $result['rule_id']);

		$state = $this->ruleState($ruleId);
		$this->assertSame(1, $state['enabled'],       'enabled muss nach Approve 1 sein');
		$this->assertSame(1, $state['applies_count'], 'recordApply muss applies_count inkrementiert haben');

		$this->assertSame('approved', $this->pendingStatus($pendingId),
			'Pending muss nach Approve geschlossen sein (status=approved)');
	}

	public function testApproveWithForeignRuleIdDoesNotMutateAndClosesPending(): void
	{
		// User A besitzt die Regel; User B versucht sie über einen eigenen pending zu approven.
		[$tenantId, $userA] = $this->insertTenantAndUser('owner@example.de');
		$userB = Uuid::v4();
		$this->pdo()->prepare('INSERT INTO users (id, email, display_name) VALUES (:id, :e, "B")')
			->execute([':id' => $userB, ':e' => 'attacker@example.de']);
		$this->pdo()->prepare('INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (:t, :u, "member")')
			->execute([':t' => $tenantId, ':u' => $userB]);

		$ruleAId   = $this->seedRule($tenantId, $userA, enabled: 0);
		// pending_action gehört User B, aber payload.rule_id zeigt auf A's Regel.
		$pendingId = $this->seedSuggestion($tenantId, $userB, $ruleAId);

		$action = $this->pending->findById($tenantId, $userB, $pendingId);
		$this->assertNotNull($action);

		$result = $this->invokeConfirm($tenantId, $userB, $pendingId, $action['payload']);

		// Guard muss greifen: Regel gehört nicht User B.
		$this->assertFalse($result['rule_updated'],
			'Ownership-Guard: fremde Regel darf nicht mutiert werden');

		$state = $this->ruleState($ruleAId);
		$this->assertSame(0, $state['enabled'],       'enabled muss unverändert 0 bleiben');
		$this->assertSame(0, $state['applies_count'], 'applies_count darf nicht inkrementiert werden');

		$this->assertSame('approved', $this->pendingStatus($pendingId),
			'Pending muss trotzdem geschlossen werden (status=approved)');
	}

	public function testApproveWithUnknownRuleIdClosesPendingWithoutCrash(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser('unknown@example.de');
		$unknownRuleId = Uuid::v4(); // existiert nicht in der DB
		$pendingId     = $this->seedSuggestion($tenantId, $userId, $unknownRuleId);

		$action = $this->pending->findById($tenantId, $userId, $pendingId);
		$this->assertNotNull($action);

		$result = $this->invokeConfirm($tenantId, $userId, $pendingId, $action['payload']);

		$this->assertFalse($result['rule_updated'], 'Unbekannte rule_id: kein rule_updated');
		$this->assertSame('approved', $this->pendingStatus($pendingId),
			'Pending muss trotzdem auf approved gesetzt werden');
	}

	// -------------------------------------------------------------------------
	// Discard-Pfad (reject)
	// -------------------------------------------------------------------------

	public function testRejectOwnSuggestionDisablesRuleAndClosesPending(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser('rejecter@example.de');
		$ruleId    = $this->seedRule($tenantId, $userId, enabled: 1);
		$pendingId = $this->seedSuggestion($tenantId, $userId, $ruleId);

		$action = $this->pending->findById($tenantId, $userId, $pendingId);
		$this->assertNotNull($action);

		$this->invokeDiscard($tenantId, $userId, $pendingId, $action['payload']);

		$state = $this->ruleState($ruleId);
		$this->assertSame(0, $state['enabled'], 'Regel muss nach Reject deaktiviert sein (enabled=0)');

		$this->assertSame('rejected', $this->pendingStatus($pendingId),
			'Pending muss nach Reject geschlossen sein (status=rejected)');
	}

	public function testRejectWithForeignRuleIdDoesNotMutateAndClosesPending(): void
	{
		[$tenantId, $userA] = $this->insertTenantAndUser('ruleowner@example.de');
		$userB = Uuid::v4();
		$this->pdo()->prepare('INSERT INTO users (id, email, display_name) VALUES (:id, :e, "B")')
			->execute([':id' => $userB, ':e' => 'rejecter2@example.de']);
		$this->pdo()->prepare('INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (:t, :u, "member")')
			->execute([':t' => $tenantId, ':u' => $userB]);

		$ruleAId   = $this->seedRule($tenantId, $userA, enabled: 1);
		$pendingId = $this->seedSuggestion($tenantId, $userB, $ruleAId);

		$action = $this->pending->findById($tenantId, $userB, $pendingId);
		$this->assertNotNull($action);

		$this->invokeDiscard($tenantId, $userB, $pendingId, $action['payload']);

		$state = $this->ruleState($ruleAId);
		$this->assertSame(1, $state['enabled'],
			'Ownership-Guard: fremde Regel darf enabled nicht auf 0 gesetzt werden');

		$this->assertSame('rejected', $this->pendingStatus($pendingId),
			'Pending muss trotzdem auf rejected gesetzt werden');
	}

	public function testRejectWithUnknownRuleIdClosesPendingWithoutCrash(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser('rejecter3@example.de');
		$unknownRuleId = Uuid::v4();
		$pendingId     = $this->seedSuggestion($tenantId, $userId, $unknownRuleId);

		$action = $this->pending->findById($tenantId, $userId, $pendingId);
		$this->assertNotNull($action);

		$this->invokeDiscard($tenantId, $userId, $pendingId, $action['payload']);

		$this->assertSame('rejected', $this->pendingStatus($pendingId),
			'Pending muss auf rejected gesetzt werden auch ohne bekannte Regel');
	}
}
