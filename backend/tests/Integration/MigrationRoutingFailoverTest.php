<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\SettingsRepository;
use MailPilot\Tests\TestCase;

final class MigrationRoutingFailoverTest extends TestCase
{
	public function testUpgradeSetsRouterAndSeedsInferenceChainAndNotice(): void
	{
		$set = new SettingsRepository($this->pdo());
		self::assertSame('router', $set->getString('llm.routing_mode', ''), 'Bestand auf router migriert');
		self::assertNotSame('', $set->getString('llm.inference.fallback_chain', ''), 'inference-Chain geseedet');
		self::assertNotSame('', $set->getString('llm.draft.fallback_chain', ''), 'draft-Chain geseedet');
		self::assertSame('1', $set->getString('llm.routing_mode_upgrade_notice', ''), 'Upgrade-Notice-Flag gesetzt');
	}
}
