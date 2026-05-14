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
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Repositories\SummaryRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Repositories\UserRepository;
use MailPilot\Repositories\VipRepository;
use MailPilot\Services\AutoSortService;
use MailPilot\Services\BudgetService;
use MailPilot\Services\JwtService;
use MailPilot\Services\MailScoringService;
use MailPilot\Services\MailSummaryService;
use MailPilot\Services\MoveDetectionService;
use MailPilot\Services\RedactionService;
use MailPilot\Services\ReconciliationService;
use MailPilot\Services\ReplyDraftService;
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
			PromptRepository::class   => new PromptRepository($this->get(PDO::class)),
			SettingsRepository::class => new SettingsRepository($this->get(PDO::class)),
			PendingActionRepository::class => new PendingActionRepository($this->get(PDO::class)),
			AutoSortCorrectionRepository::class => new AutoSortCorrectionRepository($this->get(PDO::class)),
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
			),
			RedactionService::class   => new RedactionService(),
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
