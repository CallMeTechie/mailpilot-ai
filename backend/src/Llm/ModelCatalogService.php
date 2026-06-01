<?php
declare(strict_types=1);

namespace MailPilot\Llm;

use MailPilot\Repositories\LlmModelCatalogRepository;
use MailPilot\Repositories\LlmProviderRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestriert die Modell-Discovery. refreshProvider() fragt EINEN Provider
 * ab (Admin-Button); refreshAll() macht den Voll-Sweep (Worker). Ein Provider-
 * Fehler leert den Katalog NIE (markStale nur bei erfolgreichem Listing).
 */
final class ModelCatalogService
{
	/** Dokumentierte Effort-Gesamtmenge — nur Validierungs-Fallback. */
	public const EFFORT_LEVELS_ALL = ['low', 'medium', 'high', 'xhigh', 'max'];

	/**
	 * @param array<string, LlmProvider> $providersByKind
	 */
	public function __construct(
		private readonly array $providersByKind,
		private readonly LlmModelCatalogRepository $catalog,
		private readonly LlmProviderRepository $providers,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{discovered:int, error:?string}
	 */
	public function refreshProvider(string $providerId, string $kind): array
	{
		$provider = $this->providersByKind[$kind] ?? null;
		if ($provider === null) {
			return ['discovered' => 0, 'error' => 'unknown provider kind: ' . $kind];
		}
		try {
			$models = $provider->listModels();
			$seen = [];
			foreach ($models as $d) {
				$this->catalog->upsert($providerId, $d);
				$seen[] = $d->modelId;
			}
			$this->catalog->markStale($providerId, $seen);
			return ['discovered' => count($seen), 'error' => null];
		} catch (Throwable $e) {
			$msg = substr($e->getMessage(), 0, 200);
			$this->logger->warning('llm.catalog.refresh_failed', [
				'provider_id' => $providerId, 'kind' => $kind, 'err' => $msg,
			]);
			return ['discovered' => 0, 'error' => $msg];
		}
	}

	/**
	 * @return array<string, array{discovered:int, error:?string}>
	 */
	public function refreshAll(): array
	{
		$out = [];
		foreach ($this->providers->listAll(includeDisabled: false) as $row) {
			$id   = (string)$row['id'];
			$kind = (string)$row['kind'];
			$out[$id] = $this->refreshProvider($id, $kind);
		}
		return $out;
	}
}
