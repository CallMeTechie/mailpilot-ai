<?php
declare(strict_types=1);

namespace MailPilot\Http;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Claude\ProviderFactory;
use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\AutoSortCorrectionRepository;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\DraftRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\RedactionRepository;
use MailPilot\Repositories\RescoreJobRepository;
use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\SenderRepository;
use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Repositories\SummaryRepository;
use MailPilot\Repositories\UsageCounterRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Repositories\UserRepository;
use MailPilot\Repositories\VipRepository;
use MailPilot\Services\AutoReplyService;
use MailPilot\Services\AutoSortService;
use MailPilot\Services\JobRecoveryService;
use MailPilot\Services\BudgetService;
use MailPilot\Services\JwtService;
use MailPilot\Services\MailScoringService;
use MailPilot\Services\MailSummaryService;
use MailPilot\Services\MoveDetectionService;
use MailPilot\Services\RedactionService;
use MailPilot\Services\ReconciliationService;
use MailPilot\Services\ReplyDraftService;
use MailPilot\Services\RescoreJobService;
use MailPilot\Services\RuleInferenceService;
use MailPilot\Services\ScoreOverrideService;
use MailPilot\Services\Sender\FolderPathBuilder;
use MailPilot\Services\Sender\LookalikeDetector;
use MailPilot\Services\Sender\SenderResolver;
use MailPilot\Services\SyncService;
use MailPilot\Services\TokenService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PDO;

/**
 * Minimal service container. Lazy-constructed services via ->get().
 * No third-party DI lib on purpose — fewer moving parts, easier to reason about.
 */
class Kernel
{
	/** @var array<string, mixed> */
	private array $instances = [];

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct(public readonly array $config)
	{
	}

	/**
	 * @template T
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get(string $id): object
	{
		if (isset($this->instances[$id])) {
			/** @var T */
			return $this->instances[$id];
		}
		$this->instances[$id] = $this->build($id);
		return $this->instances[$id];
	}

	private function build(string $id): object
	{
		return match ($id) {
			PDO::class                => $this->buildPdo(),
			Logger::class             => $this->buildLogger(),
			ClaudeClient::class       => new ClaudeClient(
				$this->config['claude']['api_key'],
				$this->config['claude']['base_url'],
				$this->config['claude']['anthropic_version'],
				(int)$this->config['claude']['timeout'],
				$this->get(Logger::class),
			),
			ClaudeProvider::class     => ProviderFactory::build($this->config, $this->get(Logger::class)),
			GraphClient::class        => new GraphClient(
				$this->config['graph']['client_id'],
				$this->config['graph']['client_secret'],
				$this->config['graph']['redirect_uri'],
				$this->config['graph']['tenant'],
				$this->config['graph']['scopes'],
				$this->get(Logger::class),
			),
			MailRepository::class     => new MailRepository($this->get(PDO::class)),
			MailboxRepository::class  => new MailboxRepository($this->get(PDO::class)),
			ScoreRepository::class    => new ScoreRepository($this->get(PDO::class)),
			SummaryRepository::class  => new SummaryRepository($this->get(PDO::class)),
			DraftRepository::class    => new DraftRepository($this->get(PDO::class)),
			UserRepository::class     => new UserRepository($this->get(PDO::class)),
			VipRepository::class      => new VipRepository($this->get(PDO::class)),
			RedactionRepository::class => new RedactionRepository($this->get(PDO::class)),
			CacheRepository::class    => new CacheRepository(
				$this->get(PDO::class),
				(int)$this->config['limits']['cache_ttl_days'],
			),
			AutoSortRepository::class => new AutoSortRepository(
				$this->get(PDO::class),
				$this->get(SettingsRepository::class),
			),
			CorrectionRepository::class => new CorrectionRepository($this->get(PDO::class)),
			SubLabelRepository::class => new SubLabelRepository($this->get(PDO::class)),
			SenderRepository::class   => new SenderRepository($this->get(PDO::class)),
			ScoreOverrideRepository::class => new ScoreOverrideRepository($this->get(PDO::class)),
			PromptRepository::class   => new PromptRepository($this->get(PDO::class)),
			SettingsRepository::class => new SettingsRepository($this->get(PDO::class)),
			PendingActionRepository::class => new PendingActionRepository($this->get(PDO::class)),
			UsageCounterRepository::class => new UsageCounterRepository($this->get(PDO::class)),
			AutoSortCorrectionRepository::class => new AutoSortCorrectionRepository($this->get(PDO::class)),
			// Phase 9l: Bulk-Rescore Async-Job-Queue.
			RescoreJobRepository::class => new RescoreJobRepository($this->get(PDO::class)),
			MoveDetectionService::class => new MoveDetectionService(
				$this->get(MailRepository::class),
				$this->get(ScoreRepository::class),
				$this->get(AutoSortRepository::class),
				$this->get(AutoSortCorrectionRepository::class),
				$this->get(PDO::class),
				$this->get(Logger::class),
			),
			ReconciliationService::class => new ReconciliationService(
				$this->get(PDO::class),
				$this->get(GraphClient::class),
				$this->get(TokenService::class),
				$this->get(MailboxRepository::class),
				$this->get(SettingsRepository::class),
				$this->get(Logger::class),
			),
			PricingRepository::class  => new PricingRepository($this->get(PDO::class)),
			UsageRepository::class    => new UsageRepository($this->get(PDO::class)),
			BudgetService::class      => new BudgetService(
				$this->get(SettingsRepository::class),
				$this->get(UsageRepository::class),
				$this->get(PricingRepository::class),
				$this->get(Logger::class),
			),
			AutoSortService::class    => new AutoSortService(
				$this->get(GraphClient::class),
				$this->get(AutoSortRepository::class),
				$this->get(PDO::class),
				$this->get(Logger::class),
				$this->get(SettingsRepository::class),
				$this->get(PendingActionRepository::class),
				$this->get(SenderResolver::class),
				$this->get(SenderRepository::class),
				$this->get(FolderPathBuilder::class),
				$this->get(MailboxRepository::class),
				// Phase 9m: deterministische Inbox-Schutz-Schicht.
				$this->get(\MailPilot\Services\InboxProtectionResolver::class),
			),
			RedactionService::class   => new RedactionService(),
			// Sort-Refactor Phase 2 — Domain-Layer. PSL liegt unter backend/var/psl/
			// und wird vom Image-Build (COPY backend/) mitgenommen. Kernel.php
			// liegt in backend/src/Http/, daher 2× dirname() → backend/.
			SenderResolver::class     => new SenderResolver(
				dirname(__DIR__, 2) . '/var/psl/public_suffix_list.dat',
				$this->get(SenderRepository::class),
				$this->get(Logger::class),
			),
			LookalikeDetector::class  => new LookalikeDetector(
				$this->get(SenderRepository::class),
				$this->get(Logger::class),
			),
			// Phase 9a: Klassifikations-Overrides
			ScoreOverrideService::class => new ScoreOverrideService(
				$this->get(ScoreOverrideRepository::class),
				$this->get(Logger::class),
			),
			// Phase 9p: Auto-Cleanup fuer Score-Override-Regeln
			\MailPilot\Services\ScoreOverrideCleanupService::class => new \MailPilot\Services\ScoreOverrideCleanupService(
				$this->get(PDO::class),
				$this->get(SettingsRepository::class),
				$this->get(Logger::class),
			),
			// Phase 9q-A/B: Multi-Provider-LLM-Schicht. SecretBox laed beim
			// Konstruktor den Master-Key — wirft wenn LLM_MASTER_KEY fehlt.
			// Daher nur instantiieren wenn wirklich gebraucht (lazy via match):
			// Solange routing_mode='direct' ist, wird LlmRouter nie gebaut.
			\MailPilot\Security\SecretBox::class => new \MailPilot\Security\SecretBox(),
			\MailPilot\Repositories\LlmProviderRepository::class =>
				new \MailPilot\Repositories\LlmProviderRepository($this->get(PDO::class)),
			\MailPilot\Repositories\LlmModelRepository::class =>
				new \MailPilot\Repositories\LlmModelRepository($this->get(PDO::class)),
			\MailPilot\Repositories\LlmCallLogRepository::class =>
				new \MailPilot\Repositories\LlmCallLogRepository($this->get(PDO::class)),
			\MailPilot\Repositories\LlmGoldenRepository::class =>
				new \MailPilot\Repositories\LlmGoldenRepository($this->get(PDO::class)),
			\MailPilot\Llm\GoldenSetRunner::class =>
				new \MailPilot\Llm\GoldenSetRunner(
					$this->get(\MailPilot\Repositories\LlmGoldenRepository::class),
					$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
					$this->get(\MailPilot\Repositories\LlmModelRepository::class),
					[
						'anthropic'         => $this->get(\MailPilot\Llm\Providers\AnthropicProvider::class),
						'openai'            => $this->get(\MailPilot\Llm\Providers\OpenAiProvider::class),
						'openai_compatible' => $this->get(\MailPilot\Llm\Providers\OpenAiCompatibleProvider::class),
						'gemini'            => $this->get(\MailPilot\Llm\Providers\GeminiProvider::class),
						'mistral'           => $this->get(\MailPilot\Llm\Providers\MistralProvider::class),
					],
					$this->get(Logger::class),
					// Phase 9q B3: Test-Runs landen im Usage-Dashboard.
					$this->get(\MailPilot\Llm\LlmCallLogger::class),
				),
			\MailPilot\Llm\LlmCallLogger::class =>
				new \MailPilot\Llm\LlmCallLogger(
					$this->get(\MailPilot\Repositories\LlmCallLogRepository::class),
					$this->get(\MailPilot\Repositories\LlmModelRepository::class),
					$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
					$this->get(Logger::class),
				),
			\MailPilot\Llm\Providers\AnthropicProvider::class =>
				new \MailPilot\Llm\Providers\AnthropicProvider(
					$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
					$this->get(\MailPilot\Security\SecretBox::class),
					$this->get(Logger::class),
				),
			\MailPilot\Llm\Providers\OpenAiProvider::class =>
				new \MailPilot\Llm\Providers\OpenAiProvider(
					$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
					$this->get(\MailPilot\Security\SecretBox::class),
					$this->get(Logger::class),
				),
			// Phase 9q-D: lokale + Ollama/LM-Studio/llama.cpp via OpenAI-API
			\MailPilot\Llm\Providers\OpenAiCompatibleProvider::class =>
				new \MailPilot\Llm\Providers\OpenAiCompatibleProvider(
					$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
					$this->get(\MailPilot\Security\SecretBox::class),
					$this->get(Logger::class),
				),
			// Phase 9q-E: Cloud-Provider mit eigener API-Shape (Gemini)
			// + eigenem kind fuer Display/Pricing (Mistral).
			\MailPilot\Llm\Providers\GeminiProvider::class =>
				new \MailPilot\Llm\Providers\GeminiProvider(
					$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
					$this->get(\MailPilot\Security\SecretBox::class),
					$this->get(Logger::class),
				),
			\MailPilot\Llm\Providers\MistralProvider::class =>
				new \MailPilot\Llm\Providers\MistralProvider(
					$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
					$this->get(\MailPilot\Security\SecretBox::class),
					$this->get(Logger::class),
				),
			\MailPilot\Llm\LlmRouter::class => new \MailPilot\Llm\LlmRouter(
				[
					'anthropic'         => $this->get(\MailPilot\Llm\Providers\AnthropicProvider::class),
					'openai'            => $this->get(\MailPilot\Llm\Providers\OpenAiProvider::class),
					'openai_compatible' => $this->get(\MailPilot\Llm\Providers\OpenAiCompatibleProvider::class),
					'gemini'            => $this->get(\MailPilot\Llm\Providers\GeminiProvider::class),
					'mistral'           => $this->get(\MailPilot\Llm\Providers\MistralProvider::class),
				],
				$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
				$this->get(SettingsRepository::class),
				$this->get(Logger::class),
				$this->get(\MailPilot\Repositories\LlmModelRepository::class),
				$this->get(\MailPilot\Llm\LlmCallLogger::class),
			),
			\MailPilot\Repositories\LlmModelCatalogRepository::class =>
				new \MailPilot\Repositories\LlmModelCatalogRepository($this->get(PDO::class)),
			\MailPilot\Llm\ModelCatalogService::class =>
				new \MailPilot\Llm\ModelCatalogService(
					[
						'anthropic'         => $this->get(\MailPilot\Llm\Providers\AnthropicProvider::class),
						'openai'            => $this->get(\MailPilot\Llm\Providers\OpenAiProvider::class),
						'openai_compatible' => $this->get(\MailPilot\Llm\Providers\OpenAiCompatibleProvider::class),
						'gemini'            => $this->get(\MailPilot\Llm\Providers\GeminiProvider::class),
						'mistral'           => $this->get(\MailPilot\Llm\Providers\MistralProvider::class),
					],
					$this->get(\MailPilot\Repositories\LlmModelCatalogRepository::class),
					$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
					$this->get(Logger::class),
				),
			FolderPathBuilder::class  => new FolderPathBuilder(
				fn(): string => $this->get(SettingsRepository::class)->getString('sort_root', ''),
				// Phase 9m (Marc 2026-05-21): mailpilot_root steuert die
				// Wurzel unter der Folder angelegt werden duerfen.
				fn(): string => $this->get(SettingsRepository::class)->getString('mailpilot_root', 'account_root'),
			),
			// Phase 9m: Deterministische Inbox-Schutz-Schicht.
			\MailPilot\Services\InboxProtectionResolver::class => new \MailPilot\Services\InboxProtectionResolver(
				$this->get(PDO::class),
				$this->get(SettingsRepository::class),
			),
			// Phase 9n-Hotfix: Backfill fuer Mails ohne parent_folder_id.
			\MailPilot\Services\ParentFolderBackfillService::class => new \MailPilot\Services\ParentFolderBackfillService(
				$this->get(PDO::class),
				$this->get(MailboxRepository::class),
				$this->get(TokenService::class),
				$this->get(GraphClient::class),
				$this->get(Logger::class),
			),
			JwtService::class         => new JwtService(
				(string)$this->config['app']['jwt_secret'],
				(string)$this->config['app']['jwt_issuer'],
				(string)$this->config['app']['jwt_audience'],
				(int)$this->config['app']['jwt_ttl'],
				$this->get(PDO::class),
			),
			TokenService::class       => new TokenService(
				$this->get(GraphClient::class),
				$this->get(MailboxRepository::class),
				$this->config['app']['encrypt_key'],
			),
			MailScoringService::class => new MailScoringService(
				$this->get(ClaudeProvider::class),
				$this->get(MailRepository::class),
				$this->get(ScoreRepository::class),
				$this->get(CacheRepository::class),
				$this->get(RedactionService::class),
				$this->get(BudgetService::class),
				$this->get(CorrectionRepository::class),
				$this->get(SubLabelRepository::class),
				$this->get(AutoSortRepository::class),
				$this->get(PromptRepository::class),
				$this->get(SettingsRepository::class),
				(int)$this->config['limits']['scoring_batch_size'],
				(int)$this->config['limits']['max_body_bytes'],
				$this->get(Logger::class),
				$this->get(PendingActionRepository::class),
				$this->get(AutoSortCorrectionRepository::class),
				// Phase 3a: Sender-Resolver + Lookalike-Detector werden vor
				// upsertMany in scoreBatch aufgerufen → registriert neue
				// Absender + flippt spoof_suspect bei Lookalike-Treffer.
				$this->get(SenderResolver::class),
				$this->get(LookalikeDetector::class),
				// Phase 9a: Klassifikations-Overrides nach KI-Score.
				$this->get(ScoreOverrideService::class),
				// Phase 9q-B (Marc 2026-05-22): optionaler Failover-Router.
				// Aktiv wenn Setting llm.routing_mode='router'. Default 'direct'
				// → bestehender Pfad via ClaudeProvider.
				$this->get(\MailPilot\Llm\LlmRouter::class),
				// Phase 9q B-Fix (Marc 2026-05-23): direct-Mode-Calls in
				// llm_call_log spiegeln, sonst zeigt /admin/llm/usage = 0.
				$this->get(\MailPilot\Llm\LlmCallLogger::class),
				$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
			),
			MailSummaryService::class => new MailSummaryService(
				$this->get(ClaudeProvider::class),
				$this->get(MailRepository::class),
				$this->get(SummaryRepository::class),
				$this->get(RedactionService::class),
				$this->get(BudgetService::class),
				$this->get(PromptRepository::class),
			),
			ReplyDraftService::class  => new ReplyDraftService(
				$this->get(ClaudeProvider::class),
				$this->get(MailRepository::class),
				$this->get(DraftRepository::class),
				$this->get(RedactionService::class),
				$this->get(BudgetService::class),
				$this->get(PromptRepository::class),
				$this->get(RedactionRepository::class), // Sprint 6f DA-R2 #3: per-user-scope
			),
			JobRecoveryService::class => new JobRecoveryService(
				$this->get(PDO::class),
				$this->get(Logger::class),
			),
			AutoReplyService::class   => new AutoReplyService(
				$this->get(PDO::class),
				$this->get(GraphClient::class),
				$this->get(TokenService::class),
				$this->get(SettingsRepository::class),
				$this->get(UsageCounterRepository::class),
				$this->get(DraftRepository::class),
				$this->get(MailboxRepository::class),
				$this->get(ReplyDraftService::class),
				$this->get(Logger::class),
			),
			RuleInferenceService::class => new RuleInferenceService(
				$this->get(PDO::class),
				$this->get(ClaudeClient::class),
				$this->get(RedactionService::class),
				$this->get(SettingsRepository::class),
				$this->get(UsageCounterRepository::class),
				$this->get(AutoSortRepository::class),
				$this->get(PendingActionRepository::class),
				$this->get(PromptRepository::class),
				$this->get(Logger::class),
				// Phase 9b: inferScoreRule() braucht das Override-Repository.
				$this->get(ScoreOverrideRepository::class),
				// Phase 9h.2: SenderResolver fuer Dedup-Check (sender_key-Lookup).
				$this->get(\MailPilot\Services\Sender\SenderResolver::class),
			),
			// Phase 9l: Async Bulk-Rescore — Worker-Aufrufer + Controller nutzen
			// dieselbe Run-Methode (Controller delegiert nur an Repository,
			// Worker ruft tatsächlich run()).
			RescoreJobService::class => new RescoreJobService(
				$this->get(PDO::class),
				$this->get(RescoreJobRepository::class),
				$this->get(MailboxRepository::class),
				$this->get(MailRepository::class),
				$this->get(GraphClient::class),
				$this->get(TokenService::class),
				$this->get(MailScoringService::class),
				$this->get(Logger::class),
			),
			SyncService::class        => new SyncService(
				$this->get(GraphClient::class),
				$this->get(MailRepository::class),
				$this->get(MailboxRepository::class),
				$this->get(ScoreRepository::class),
				$this->get(MailScoringService::class),
				$this->get(TokenService::class),
				$this->get(AutoSortService::class),
				$this->get(Logger::class),
				$this->get(MoveDetectionService::class),
				$this->get(DraftRepository::class), // Sprint 6f: Stale-Hook
			),
			default => throw new \RuntimeException("No factory for service: {$id}"),
		};
	}

	private function buildPdo(): PDO
	{
		$db = $this->config['db'];
		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=%s',
			$db['host'],
			$db['port'],
			$db['name'],
			$db['charset'],
		);
		$pdo = new PDO($dsn, $db['user'], $db['pass'], [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', NAMES utf8mb4",
		]);
		return $pdo;
	}

	private function buildLogger(): Logger
	{
		$logger = new Logger('mailpilot');
		$path = (string)$this->config['log']['path'];
		if ($path !== '') {
			@mkdir(dirname($path), 0775, true);
			$logger->pushHandler(new StreamHandler($path, Logger::toMonologLevel($this->config['log']['level'] ?? 'info')));
		}
		return $logger;
	}
}
