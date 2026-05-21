<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\InboxProtectionResolver;
use MailPilot\Tests\TestCase;

/**
 * Phase 9m (Marc 2026-05-21) — deterministische Inbox-Schutz-Logik.
 *
 * Marc's Anforderungen, je ein Test:
 *   1. priority >= inbox_pin_priority_min → protected (Phase 9f, bleibt)
 *   2. label='direct' → protected
 *   3. action_required + action_owner='user' → protected (priority egal)
 *   4. from_email in vip_senders → protected
 *   5. TO/CC enthält user-email oder alias → protected
 *   6. Subject/Body-Anfang enthält Alias (word-boundary) → protected
 *   7. nichts davon → NICHT protected
 *
 * Plus Edge-Cases: alias-Match nicht bei Substring ("Marca" matched kein "Marc"),
 * action_owner != user → kein Schutz.
 */
final class InboxProtectionResolverTest extends TestCase
{
	private InboxProtectionResolver $resolver;
	private string $tenantId;
	private string $userId;

	protected function setUp(): void
	{
		$this->truncateAll();
		[$this->tenantId, $this->userId] = $this->insertTenantAndUser();
		$this->pdo()->prepare('UPDATE users SET aliases = :a WHERE id = :id')
			->execute([
				':a'  => json_encode(['Marc', 'MB', 'marc.backes@privat.de'], JSON_UNESCAPED_UNICODE),
				':id' => $this->userId,
			]);
		$this->pdo()->prepare('INSERT INTO system_settings (`key`,`value`,`type`)
			VALUES ("inbox_pin_priority_min","4","int")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute();

		$this->resolver = new InboxProtectionResolver(
			$this->pdo(),
			new SettingsRepository($this->pdo()),
		);
	}

	public function testHighPriorityIsProtected(): void
	{
		$res = $this->resolver->evaluate($this->tenantId, $this->userId,
			$this->basicMail(),
			['priority' => 5, 'label' => 'auto']);
		$this->assertTrue($res['protected']);
		$this->assertSame('priority_high', $res['reason']);
	}

	public function testLabelDirectIsProtected(): void
	{
		$res = $this->resolver->evaluate($this->tenantId, $this->userId,
			$this->basicMail(),
			['priority' => 1, 'label' => 'direct']);
		$this->assertTrue($res['protected']);
		$this->assertSame('label_direct', $res['reason']);
	}

	public function testActionRequiredByUserIsProtectedEvenAtLowPriority(): void
	{
		$res = $this->resolver->evaluate($this->tenantId, $this->userId,
			$this->basicMail(),
			['priority' => 2, 'label' => 'action', 'action_required' => true, 'action_owner' => 'user']);
		$this->assertTrue($res['protected']);
		$this->assertSame('action_required', $res['reason']);
	}

	public function testActionRequiredByOtherIsNotProtected(): void
	{
		$res = $this->resolver->evaluate($this->tenantId, $this->userId,
			$this->basicMail(),
			['priority' => 2, 'label' => 'cc', 'action_required' => true, 'action_owner' => 'other']);
		$this->assertFalse($res['protected']);
	}

	public function testVipSenderIsProtected(): void
	{
		$this->pdo()->prepare('INSERT INTO vip_senders (id, tenant_id, user_id, email, created_at)
			VALUES (:i, :t, :u, :e, UTC_TIMESTAMP(3))')
			->execute([':i' => $this->uuid(), ':t' => $this->tenantId, ':u' => $this->userId, ':e' => 'chef@firma.de']);
		$res = $this->resolver->evaluate($this->tenantId, $this->userId,
			$this->basicMail(['from_email' => 'chef@firma.de']),
			['priority' => 1, 'label' => 'cc']);
		$this->assertTrue($res['protected']);
		$this->assertSame('vip_sender', $res['reason']);
	}

	// Phase 9n-Hotfix (2026-05-21): TO/CC und Alias-Match wieder entfernt.
	// Newsletter und auto-Mails sind oft an Marc gerichtet ("Hallo Marc,
	// deine Amazon-Bestellung..."), aber sollen trotzdem verschoben werden.
	// KI-Label gewinnt jetzt — kein deterministischer Override.

	public function testToListContainingUserEmailIsNotProtectedAlone(): void
	{
		$res = $this->resolver->evaluate($this->tenantId, $this->userId,
			$this->basicMail(['to_json' => json_encode(['marc@test.de'], JSON_UNESCAPED_UNICODE)]),
			['priority' => 1, 'label' => 'newsletter']);
		$this->assertFalse($res['protected'], 'TO=user darf alleine nicht schuetzen — Amazon-Bestellbestaetigung ist auch an user gerichtet');
	}

	public function testAliasInSubjectIsNotProtectedAlone(): void
	{
		$res = $this->resolver->evaluate($this->tenantId, $this->userId,
			$this->basicMail([
				'subject'   => 'Hallo Marc, dein Wochenangebot bei Penny',
				'body_text' => 'Diese Woche im Angebot',
			]),
			['priority' => 1, 'label' => 'newsletter']);
		$this->assertFalse($res['protected'], 'Personalisierte Newsletter-Anrede darf nicht schuetzen');
	}

	public function testNoMarkersIsNotProtected(): void
	{
		$res = $this->resolver->evaluate($this->tenantId, $this->userId,
			$this->basicMail([
				'subject'   => 'Wochenangebote bei Penny',
				'body_text' => 'Diese Woche im Angebot',
			]),
			['priority' => 1, 'label' => 'newsletter']);
		$this->assertFalse($res['protected']);
		$this->assertSame('no_protection_markers', $res['reason']);
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function basicMail(array $overrides = []): array
	{
		return array_merge([
			'from_email' => 'noreply@penny.de',
			'subject'    => 'Wochenangebot',
			'body_text'  => 'Diese Woche bei Penny',
			'to_json'    => json_encode(['marketing-list@penny.de'], JSON_UNESCAPED_UNICODE),
			'cc_json'    => json_encode([], JSON_UNESCAPED_UNICODE),
		], $overrides);
	}
}
