<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\RedactionService;
use MailPilot\Services\RuleMatchService;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

final class RuleMatchServiceTest extends TestCase
{
	private const PID = '00000000-0000-4000-8000-0000000000f0';

	public function testLlmMatchUsesMatchModelAndRedactsPayload(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->prepare('DELETE FROM llm_models WHERE provider_id = ?')->execute([self::PID]);
		$pdo->prepare('DELETE FROM llm_providers WHERE id = ?')->execute([self::PID]);
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'M', 'anthropic', 'http://x', 0, 1, 10)")->execute([self::PID]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (?, ?, 'claude-haiku-4-5-20251001', 'match', 1, 10)")->execute([Uuid::v4(), self::PID]);
		foreach ([['llm.routing_mode', 'router'], ['llm.privacy_mode', 'cloud_allowed'],
			['llm.match.fallback_chain', '["' . self::PID . '"]']] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`,`value`,`type`) VALUES (?,?,"string")
				ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$k, $v]);
		}

		$capture = new class implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				$this->seen = $r;
				return new NormalizedResponse('{"score":90}', ['inputTokens' => 5, 'outputTokens' => 2], 'end_turn', $r->modelHint, 'anthropic');
			}
			public function listModels(): array { return []; }
		};
		$router = new LlmRouter(['anthropic' => $capture], new LlmProviderRepository($pdo),
			new SettingsRepository($pdo), new NullLogger(), new LlmModelRepository($pdo));

		$svc = new RuleMatchService($router, new RedactionService(), new NullLogger());
		// match_sender_key enthält eine IBAN — muss via D7-Redaction aus dem Payload verschwinden.
		$rule = ['id' => 'r1', 'match_sender_key' => 'sk:DE89370400440532013000', 'set_priority' => 4];
		$mail = ['sender_key' => 'sk:acme', 'from_email' => 'a@acme.de', 'subject' => 'IBAN DE89370400440532013000', 'body_text' => 'x'];

		$score = $svc->scoreMatch($rule, $mail);

		self::assertSame(90, $score);
		self::assertNotNull($capture->seen);
		self::assertSame('claude-haiku-4-5-20251001', $capture->seen->modelHint);
		$payload = $capture->seen->systemPrompt . ' ' . json_encode($capture->seen->messages);
		// IBAN aus dem Subject muss redacted sein.
		self::assertStringNotContainsString('DE89370400440532013000', $payload, 'IBAN muss redacted sein (Subject + Rule)');
		// Domain darf kein führendes '@' enthalten.
		self::assertStringNotContainsString('@acme.de', $payload, 'Domain darf kein führendes @ haben');
		self::assertStringContainsString('acme.de', $payload, 'Domain muss ohne @ im Payload erscheinen');
	}

	public function testLlmRouterRolesContainsMatch(): void
	{
		self::assertContains('match', LlmRouter::ROLES);
	}
}
