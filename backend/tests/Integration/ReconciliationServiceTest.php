<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\ReconciliationService;
use MailPilot\Services\TokenService;
use MailPilot\Tests\Fixtures\FakeGraphClient;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * Sprint 6d — Reconciliation (PRD §9). Pinnt:
 *   - First-Touch: NULL-Tracker → schreibt initial, kein Drift-Log.
 *   - Drift mit follow: User hat folder_name nicht überschrieben →
 *     Graph-Wahrheit gewinnt.
 *   - Drift mit User-Override: User hat folder_name geändert →
 *     folder_name bleibt, nur Tracker wandern (DA-Finding 3).
 *   - Folder-Gone: 404 → enabled=0 + last_error.
 *   - 503/Throttle: Throw NICHT als "gone" missinterpretieren.
 *
 * @group integration
 */
final class ReconciliationServiceTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	private const ENCRYPT_KEY = '0000000000000000000000000000000000000000000000000000000000000001';

	private function makeService(FakeGraphClient $graph): ReconciliationService
	{
		$pdo = $this->pdo();
		return new ReconciliationService(
			$pdo,
			$graph,
			new TokenService($graph, new MailboxRepository($pdo), self::ENCRYPT_KEY),
			new MailboxRepository($pdo),
			new SettingsRepository($pdo),
			new NullLogger(),
		);
	}

	private function encryptedToken(): string
	{
		// TokenService.encrypt mit derselben Key, damit decrypt im
		// reconcileAll-Loop nicht failt.
		$svc = new TokenService(new FakeGraphClient(),
			new MailboxRepository($this->pdo()), self::ENCRYPT_KEY);
		return $svc->encrypt('fake-access-token');
	}

	private function seedRule(array $opts = []): array
	{
		$tenantId  = Uuid::v4();
		$userId    = Uuid::v4();
		$mailboxId = Uuid::v4();
		$ruleId    = Uuid::v4();
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO tenants (id, name) VALUES (:id, "T")')->execute([':id' => $tenantId]);
		$pdo->prepare('INSERT INTO users (id, email, display_name) VALUES (:id, "marc@example.de", "Marc")')->execute([':id' => $userId]);
		$pdo->prepare('INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (:t, :u, "owner")')
			->execute([':t' => $tenantId, ':u' => $userId]);
		// access_token_expires zukunft → ensureFreshAccessToken refresht nicht
		$pdo->prepare('INSERT INTO mailboxes
			(id, tenant_id, user_id, ms_user_id, ms_tenant_id, email,
			 refresh_token_enc, access_token_enc, access_token_expires, scopes)
			VALUES (:id, :t, :u, "msu", "mst", "marc@example.de",
			        :rt, :at, (UTC_TIMESTAMP(3) + INTERVAL 1 HOUR), "Mail.Read")')
			->execute([
				':id' => $mailboxId, ':t' => $tenantId, ':u' => $userId,
				':rt' => $this->encryptedToken(),
				':at' => $this->encryptedToken(),
			]);

		$pdo->prepare('INSERT INTO auto_sort_rules
			(id, tenant_id, user_id, label, enabled, folder_name, folder_id,
			 last_known_display_name, parent_folder_id, last_reconciled_at)
			VALUES (:id, :t, :u, "auto", 1, :fn, :fid, :lkn, :pfi, :lr)')
			->execute([
				':id' => $ruleId, ':t' => $tenantId, ':u' => $userId,
				':fn'  => $opts['folder_name']             ?? 'MailPilot/Auto/X',
				':fid' => $opts['folder_id']               ?? 'fid-x',
				':lkn' => $opts['last_known_display_name'] ?? null,
				':pfi' => $opts['parent_folder_id']        ?? null,
				':lr'  => $opts['last_reconciled_at']      ?? null,
			]);
		return [$tenantId, $userId, $ruleId];
	}

	public function testFirstTouchFillsTrackerWithoutChangingFolderName(): void
	{
		[, , $ruleId] = $this->seedRule([
			'folder_name' => 'MailPilot/Auto/X',
			'folder_id'   => 'fid-x',
		]);
		$graph = new FakeGraphClient();
		$graph->scriptFolderMeta('fid-x', 'X', 'parent-1');

		$stats = $this->makeService($graph)->reconcileAll();
		$this->assertSame(1, $stats['first_touch']);

		$row = $this->pdo()->query("SELECT folder_name, last_known_display_name, parent_folder_id
			FROM auto_sort_rules WHERE id = " . $this->pdo()->quote($ruleId))->fetch();
		$this->assertSame('MailPilot/Auto/X', $row['folder_name'],
			'First-Touch berührt folder_name nicht');
		$this->assertSame('X', $row['last_known_display_name'], 'Tracker gefüllt');
		$this->assertSame('parent-1', $row['parent_folder_id']);
	}

	public function testDriftFollowsGraphWhenUserHasNotRenamed(): void
	{
		[, , $ruleId] = $this->seedRule([
			'folder_name'             => 'GitHub CI',
			'folder_id'               => 'fid-gh',
			'last_known_display_name' => 'GitHub CI',
			'parent_folder_id'        => 'p-old',
		]);
		$graph = new FakeGraphClient();
		$graph->scriptFolderMeta('fid-gh', 'GitHub Actions', 'p-new');

		$stats = $this->makeService($graph)->reconcileAll();
		$this->assertSame(1, $stats['drift']);

		$row = $this->pdo()->query("SELECT folder_name, last_known_display_name, parent_folder_id
			FROM auto_sort_rules WHERE id = " . $this->pdo()->quote($ruleId))->fetch();
		$this->assertSame('GitHub Actions', $row['folder_name'], 'folder_name folgt Graph');
		$this->assertSame('GitHub Actions', $row['last_known_display_name']);
		$this->assertSame('p-new', $row['parent_folder_id']);
	}

	public function testDriftPreservesFolderNameWhenUserHasRenamed(): void
	{
		[, , $ruleId] = $this->seedRule([
			'folder_name'             => 'Mein Lieblings-CI',
			'folder_id'               => 'fid-gh',
			'last_known_display_name' => 'GitHub CI',
			'parent_folder_id'        => 'p-old',
		]);
		$graph = new FakeGraphClient();
		$graph->scriptFolderMeta('fid-gh', 'GitHub Actions', 'p-old');

		$stats = $this->makeService($graph)->reconcileAll();
		$this->assertSame(1, $stats['drift']);

		$row = $this->pdo()->query("SELECT folder_name, last_known_display_name
			FROM auto_sort_rules WHERE id = " . $this->pdo()->quote($ruleId))->fetch();
		$this->assertSame('Mein Lieblings-CI', $row['folder_name'],
			'DA-Finding 3: User-Override darf nicht überschrieben werden');
		$this->assertSame('GitHub Actions', $row['last_known_display_name'],
			'Tracker geht trotzdem mit, damit nächste Iteration keinen Phantom-Drift sieht');
	}

	public function testFolderGoneDisablesRule(): void
	{
		[, , $ruleId] = $this->seedRule([
			'folder_id'               => 'fid-vanished',
			'last_known_display_name' => 'X',
			'parent_folder_id'        => 'parent-x',
		]);
		$graph = new FakeGraphClient(); // getFolder gibt null → behandelt als 404

		$stats = $this->makeService($graph)->reconcileAll();
		$this->assertSame(1, $stats['gone']);

		$row = $this->pdo()->query("SELECT enabled, folder_id, last_error
			FROM auto_sort_rules WHERE id = " . $this->pdo()->quote($ruleId))->fetch();
		$this->assertSame(0, (int)$row['enabled']);
		$this->assertNull($row['folder_id']);
		$this->assertSame('folder_gone', $row['last_error']);
	}

	public function testThrottleErrorDoesNotDisableRule(): void
	{
		[, , $ruleId] = $this->seedRule([
			'folder_id'               => 'fid-throttled',
			'last_known_display_name' => 'X',
			'parent_folder_id'        => 'parent-x',
		]);
		$graph = new FakeGraphClient();
		$graph->scriptFolderError('fid-throttled',
			new \RuntimeException('Graph 503: ServiceUnavailable Retry-After: 60'));

		$stats = $this->makeService($graph)->reconcileAll();
		$this->assertSame(1, $stats['errors']);
		$this->assertSame(0, $stats['gone'], '503 darf NICHT als gone behandelt werden');

		$row = $this->pdo()->query("SELECT enabled FROM auto_sort_rules
			WHERE id = " . $this->pdo()->quote($ruleId))->fetch();
		$this->assertSame(1, (int)$row['enabled'], 'Rule bleibt enabled bei transient Graph-Fehler');
	}
}
