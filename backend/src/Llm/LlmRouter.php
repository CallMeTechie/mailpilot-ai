<?php
declare(strict_types=1);

namespace MailPilot\Llm;

use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Phase 9q-B (Marc 2026-05-22) — Failover-faehiger LLM-Dispatcher.
 *
 * Workflow:
 *   1. Chain auflesen — Reihenfolge der Provider-IDs aus Setting
 *      `llm.{taskType}.fallback_chain` (JSON-Array). Fallback wenn nicht
 *      vorhanden: `llm.primary_provider_id` als 1-elementige Chain.
 *   2. Jeden Provider in Reihenfolge probieren. Bei
 *      LlmOverloadedException oder LlmUnavailableException →
 *      naechsten probieren. Bei anderen Exceptions (Auth/Bad-Request) →
 *      sofort werfen (kein Failover).
 *   3. Wenn Chain erschoepft → LlmAllProvidersDownException.
 *
 * Provider-Liste wird als kind→provider-Map konstruktor-injiziert. Der
 * Router resolvet die Provider-Rows aus der DB nur fuer die Reihenfolge,
 * den eigentlichen complete()-Call macht das injizierte Provider-Objekt.
 */
final class LlmRouter
{
	/**
	 * @param array<string, LlmProvider> $providersByKind  z.B. ['anthropic'=>...,'openai'=>...]
	 */
	public function __construct(
		private readonly array $providersByKind,
		private readonly LlmProviderRepository $repo,
		private readonly SettingsRepository $settings,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Schickt einen Inferenz-Request gemaess der Provider-Chain fuer
	 * $taskType ('score'|'summary'|'draft'|'inference'). Wirft am Ende
	 * LlmAllProvidersDownException wenn alle Provider failen.
	 */
	public function complete(NormalizedRequest $request, string $taskType = 'score'): NormalizedResponse
	{
		$chain = $this->resolveChain($taskType);
		if ($chain === []) {
			throw new LlmAllProvidersDownException(
				sprintf('Keine Provider in der Chain fuer Task „%s"', $taskType)
			);
		}

		$lastException = null;
		foreach ($chain as $idx => $providerRow) {
			$kind     = (string)$providerRow['kind'];
			$provider = $this->providersByKind[$kind] ?? null;
			if ($provider === null) {
				$this->logger->warning('llm.router.unknown_kind', [
					'kind' => $kind, 'provider_id' => $providerRow['id'],
				]);
				continue;
			}
			if (!$provider->isHealthy()) {
				$this->logger->info('llm.router.skip_unhealthy', [
					'kind' => $kind, 'provider_id' => $providerRow['id'],
				]);
				continue;
			}
			try {
				$response = $provider->complete($request);
				if ($idx > 0) {
					$this->logger->info('llm.router.failover_succeeded', [
						'task'          => $taskType,
						'final_kind'    => $kind,
						'failover_step' => $idx,
					]);
				}
				return $response;
			} catch (LlmOverloadedException | LlmUnavailableException $e) {
				$lastException = $e;
				$this->logger->warning('llm.router.provider_failed', [
					'task'    => $taskType,
					'kind'    => $kind,
					'err'     => $e->getMessage(),
					'will_try_next' => $idx < count($chain) - 1,
				]);
				continue;
			}
			// Andere Exceptions (Auth, Bad-Request) propagieren — KEIN Failover.
		}

		throw new LlmAllProvidersDownException(
			sprintf('Alle %d Provider in der Chain fuer „%s" haben gefailed', count($chain), $taskType),
			$lastException,
		);
	}

	/**
	 * Liest die Provider-Chain aus den Settings + DB.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function resolveChain(string $taskType): array
	{
		$key  = sprintf('llm.%s.fallback_chain', $taskType);
		$json = $this->settings->getString($key, '');
		$ids  = [];
		if ($json !== '') {
			$decoded = json_decode($json, true);
			if (is_array($decoded)) {
				$ids = array_values(array_filter($decoded, 'is_string'));
			}
		}
		// Fallback: nur der primary_provider, wenn keine Chain konfiguriert.
		if ($ids === []) {
			$primary = $this->settings->getString('llm.primary_provider_id', '');
			if ($primary !== '') {
				$ids = [$primary];
			}
		}

		$chain = [];
		foreach ($ids as $id) {
			$row = $this->repo->findById($id);
			if ($row !== null && (int)($row['enabled'] ?? 0) === 1) {
				$chain[] = $row;
			}
		}
		return $chain;
	}
}
