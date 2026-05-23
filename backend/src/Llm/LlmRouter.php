<?php
declare(strict_types=1);

namespace MailPilot\Llm;

use MailPilot\Repositories\LlmModelRepository;
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
		// Phase 9q-C (Marc 2026-05-23): wenn NormalizedRequest.modelHint leer
		// ist, resolved der Router das Model pro (provider_id, role) aus
		// llm_models. Bei null bleibt das alte Verhalten — Caller muss
		// modelHint setzen. Optional damit Tests mit Mocks ohne Model-Repo
		// weiterfunktionieren.
		private readonly ?LlmModelRepository $models = null,
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

			// Phase 9q-C: wenn der Caller keinen modelHint gesetzt hat,
			// resolved der Router pro Provider+Task das Model. Damit muss
			// MailScoringService nur „score" sagen, kein „claude-haiku-4-5".
			$effectiveRequest = $request;
			if ($request->modelHint === '' && $this->models !== null) {
				$modelRow = $this->models->findForProviderAndRole((string)$providerRow['id'], $taskType);
				if ($modelRow === null) {
					$this->logger->warning('llm.router.no_model_for_role', [
						'provider_id' => $providerRow['id'], 'task' => $taskType,
					]);
					continue;
				}
				$effectiveRequest = new NormalizedRequest(
					systemPrompt:   $request->systemPrompt,
					messages:       $request->messages,
					maxTokens:      $request->maxTokens,
					temperature:    $request->temperature,
					modelHint:      (string)$modelRow['model_id'],
					responseFormat: $request->responseFormat,
					cacheSegments:  $request->cacheSegments,
				);
			}

			try {
				$response = $provider->complete($effectiveRequest);
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
	 * Liest die Provider-Chain aus den Settings + DB und wendet Privacy-
	 * Mode-Filter an.
	 *
	 * Privacy-Modi (Phase 9q-C):
	 *   - cloud_allowed    (default) — alle Provider in Reihenfolge.
	 *   - local_preferred  — erst alle is_local=1, dann der Rest.
	 *   - local_only       — nur is_local=1. Cloud-Provider werden
	 *                        komplett entfernt; wenn alle lokalen down →
	 *                        LlmAllProvidersDownException, KEIN Cloud-Fallback.
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

		// Privacy-Mode-Filter (Phase 9q-C).
		$mode = $this->settings->getString('llm.privacy_mode', 'cloud_allowed');
		return $this->applyPrivacyMode($chain, $mode);
	}

	/**
	 * @param  list<array<string,mixed>> $chain
	 * @return list<array<string,mixed>>
	 */
	private function applyPrivacyMode(array $chain, string $mode): array
	{
		switch ($mode) {
			case 'local_only':
				return array_values(array_filter(
					$chain,
					static fn(array $r): bool => (int)($r['is_local'] ?? 0) === 1,
				));
			case 'local_preferred':
				$local = [];
				$cloud = [];
				foreach ($chain as $r) {
					if ((int)($r['is_local'] ?? 0) === 1) {
						$local[] = $r;
					} else {
						$cloud[] = $r;
					}
				}
				return array_merge($local, $cloud);
			case 'cloud_allowed':
			default:
				return $chain;
		}
	}
}
