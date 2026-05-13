<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Tests\TestCase;

/**
 * @group integration
 */
final class AutoSortRepositoryTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testListForUserReturnsSixDefaultCatchAlls(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$rules = $repo->listForUser($tenantId, $userId);

		$this->assertCount(6, $rules);
		$labels = array_column($rules, 'label');
		$this->assertSame(['direct', 'action', 'cc', 'newsletter', 'auto', 'noise'], $labels);
		foreach ($rules as $r) {
			$this->assertNull($r['sub_label'], 'Materialised defaults must be catch-alls');
			$this->assertFalse($r['enabled']);
			$this->assertStringStartsWith('MailPilot/', $r['folder_name']);
		}
	}

	public function testUpsertCatchAllOverridesDefault(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$repo->upsert($tenantId, $userId, 'newsletter', null, true, 'Inbox/News');
		$rules = $repo->listForUser($tenantId, $userId);

		$nl = array_values(array_filter($rules, fn($r) => $r['label'] === 'newsletter' && $r['sub_label'] === null))[0];
		$this->assertTrue($nl['enabled']);
		$this->assertSame('Inbox/News', $nl['folder_name']);
	}

	public function testUpsertCatchAllIsIdempotent(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$repo->upsert($tenantId, $userId, 'auto', null, true,  'MailPilot/Auto');
		$repo->upsert($tenantId, $userId, 'auto', null, false, 'MailPilot/Auto-v2');

		$rules = array_values(array_filter(
			$repo->listForUser($tenantId, $userId),
			fn($r) => $r['label'] === 'auto' && $r['sub_label'] === null,
		));
		$this->assertCount(1, $rules, 'Second upsert must NOT create a duplicate catch-all');
		$this->assertFalse($rules[0]['enabled']);
		$this->assertSame('MailPilot/Auto-v2', $rules[0]['folder_name']);
	}

	public function testSubLabelRuleAppearsAlongsideCatchAll(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$repo->upsert($tenantId, $userId, 'auto', null,        true, 'MailPilot/Auto');
		$repo->upsert($tenantId, $userId, 'auto', 'GitHub CI', true, 'MailPilot/Auto/CI');

		$rules = $repo->listForUser($tenantId, $userId);

		// 6 catch-alls + 1 sub-rule = 7 rows
		$this->assertCount(7, $rules);

		$autoRules = array_values(array_filter($rules, fn($r) => $r['label'] === 'auto'));
		$this->assertCount(2, $autoRules);

		// catch-all comes first (it's in the LABELS-ordered prefix);
		// sub-rule is appended after.
		$this->assertNull($autoRules[0]['sub_label']);
		$this->assertSame('GitHub CI', $autoRules[1]['sub_label']);
		$this->assertSame('MailPilot/Auto/CI', $autoRules[1]['folder_name']);
	}

	public function testUpsertSubLabelDefaultsFolderToNestedPath(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$repo->upsert($tenantId, $userId, 'auto', 'Bestellung', true, ''); // empty folder ⇒ default

		$rule = $repo->findRule($tenantId, $userId, 'auto', 'Bestellung');
		$this->assertNotNull($rule);
		$this->assertSame('MailPilot/Auto/Bestellung', $rule['folder_name']);
	}

	public function testFindRuleExactMatchPreferredOverCatchAll(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$repo->upsert($tenantId, $userId, 'auto', null,        true, 'MailPilot/Auto');
		$repo->upsert($tenantId, $userId, 'auto', 'GitHub CI', true, 'MailPilot/Auto/CI');

		$exact = $repo->findRule($tenantId, $userId, 'auto', 'GitHub CI');
		$this->assertSame('GitHub CI',          $exact['sub_label']);
		$this->assertSame('MailPilot/Auto/CI',  $exact['folder_name']);
	}

	public function testFindRuleFallsBackToCatchAllWhenSubLabelUnknown(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$repo->upsert($tenantId, $userId, 'auto', null, true, 'MailPilot/Auto');

		$res = $repo->findRule($tenantId, $userId, 'auto', 'Unbekannt');
		$this->assertNotNull($res);
		$this->assertNull($res['sub_label'], 'fallback rule is the catch-all');
		$this->assertSame('MailPilot/Auto', $res['folder_name']);
	}

	public function testFindRuleReturnsNullWhenNothingMatches(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$this->assertNull($repo->findRule($tenantId, $userId, 'auto', null));
		$this->assertNull($repo->findRule($tenantId, $userId, 'auto', 'GitHub CI'));
	}

	public function testFindRuleReturnsDisabledRuleSoCallerCanGate(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$repo->upsert($tenantId, $userId, 'auto', null, false, 'MailPilot/Auto');

		$res = $repo->findRule($tenantId, $userId, 'auto', null);
		$this->assertNotNull($res, 'Disabled is still a hit — the service layer decides what to do');
		$this->assertFalse($res['enabled']);
	}

	public function testRememberFolderIdAndErrorTargetTheRightRow(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$repo->upsert($tenantId, $userId, 'auto', null,        true, 'MailPilot/Auto');
		$repo->upsert($tenantId, $userId, 'auto', 'GitHub CI', true, 'MailPilot/Auto/CI');

		$repo->rememberFolderId($tenantId, $userId, 'auto', 'GitHub CI', 'graph-folder-ci');
		$repo->rememberError($tenantId, $userId, 'auto', null, 'token expired');

		$exact = $repo->findRule($tenantId, $userId, 'auto', 'GitHub CI');
		$this->assertSame('graph-folder-ci', $exact['folder_id']);

		// Catch-all's folder_id stays null (only the sub-rule got it)
		$catch = $repo->findRule($tenantId, $userId, 'auto', null);
		$this->assertNull($catch['folder_id']);

		// last_error sits on the catch-all
		$stmt = $this->pdo()->prepare("SELECT last_error FROM auto_sort_rules
			WHERE tenant_id = :t AND user_id = :u AND label = 'auto' AND sub_label IS NULL");
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		$this->assertSame('token expired', $stmt->fetchColumn());
	}

	public function testDeleteRemovesOnlyExactRow(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());

		$repo->upsert($tenantId, $userId, 'auto', null,        true, 'MailPilot/Auto');
		$repo->upsert($tenantId, $userId, 'auto', 'GitHub CI', true, 'MailPilot/Auto/CI');

		$this->assertTrue($repo->delete($tenantId, $userId, 'auto', 'GitHub CI'));

		// Catch-all is still there
		$this->assertNotNull($repo->findRule($tenantId, $userId, 'auto', null));
		// Sub-rule lookup falls back to catch-all
		$res = $repo->findRule($tenantId, $userId, 'auto', 'GitHub CI');
		$this->assertNull($res['sub_label']);

		$this->assertFalse($repo->delete($tenantId, $userId, 'auto', 'GitHub CI'),
			'second delete is a no-op');
	}

	public function testUpsertRejectsUnknownPrimary(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortRepository($this->pdo());
		$this->expectException(\InvalidArgumentException::class);
		$repo->upsert($tenantId, $userId, 'spam', null, true, 'x');
	}
}
