<?php
declare(strict_types=1);

namespace MailPilot\Controllers\Settings;

use MailPilot\Controllers\BaseController;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\SubLabelRepository;

/**
 * /api/v1/settings/sub-labels/* — Sub-Label CRUD inkl. Cache-Purge bei
 * Name/Description-Aenderung (sonst liefern Cache-Hits den alten Wert).
 * Ausgegliedert aus SettingsController (Phase 2 split).
 */
final class SubLabelController extends BaseController
{
	public function listSubLabels(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$items = $this->kernel->get(SubLabelRepository::class)
			->listForUser($ctx['tenant_id'], $ctx['user_id']);
		Response::json(['items' => $items]);
	}

	public function addSubLabel(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$parent = (string)($body['parent'] ?? '');
		$name   = (string)($body['name']   ?? '');
		if ($name === '' || $parent === '') {
			throw HttpException::badRequest('VALIDATION', 'parent + name required');
		}
		try {
			$id = $this->kernel->get(SubLabelRepository::class)->create(
				$ctx['tenant_id'],
				$ctx['user_id'],
				$parent,
				$name,
				isset($body['description']) ? (string)$body['description'] : null,
				isset($body['color']) ? (string)$body['color'] : null,
			);
		} catch (\InvalidArgumentException $e) {
			throw HttpException::badRequest('VALIDATION', $e->getMessage());
		}

		// Bisher gecachte Scores wurden ohne diesen Sub-Label berechnet —
		// atomar wischen, damit die naechsten Score-Calls Claude wieder fragen.
		$purged = $this->kernel->get(CacheRepository::class)
			->purgeForTenant($ctx['tenant_id']);

		Response::json(['id' => $id, 'parent' => $parent, 'name' => $name, 'cache_purged' => $purged], 201);
	}

	public function updateSubLabel(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$id   = (string)($params['id'] ?? '');
		$name = (string)($body['name'] ?? '');
		try {
			$ok = $this->kernel->get(SubLabelRepository::class)->update(
				$ctx['tenant_id'],
				$ctx['user_id'],
				$id,
				$name,
				isset($body['description']) ? (string)$body['description'] : null,
				isset($body['color']) ? (string)$body['color'] : null,
			);
		} catch (\InvalidArgumentException $e) {
			throw HttpException::badRequest('VALIDATION', $e->getMessage());
		}
		if (!$ok) {
			throw HttpException::notFound('NOT_FOUND', 'Sub-Label nicht gefunden');
		}

		$purged = $this->kernel->get(CacheRepository::class)
			->purgeForTenant($ctx['tenant_id']);

		Response::json(['ok' => true, 'cache_purged' => $purged]);
	}

	public function deleteSubLabel(array $params, array $body): void
	{
		$ctx  = $this->requireAuth();
		$id   = (string)($params['id'] ?? '');
		$subs = $this->kernel->get(SubLabelRepository::class);

		$row = $subs->findById($ctx['tenant_id'], $ctx['user_id'], $id);
		if ($row === null) {
			throw HttpException::notFound('NOT_FOUND', 'Sub-Label nicht gefunden');
		}

		$autoSort = $this->kernel->get(AutoSortRepository::class);
		$pdo      = $this->kernel->get(\PDO::class);

		$alreadyInTx = $pdo->inTransaction();
		if (!$alreadyInTx) $pdo->beginTransaction();
		$cachePurged = 0;
		try {
			$ruleCount = $autoSort->countBySubLabel(
				$ctx['tenant_id'], $ctx['user_id'], $row['parent'], $row['name'],
			);
			if ($ruleCount > 0) {
				$autoSort->delete($ctx['tenant_id'], $ctx['user_id'], $row['parent'], $row['name']);
			}
			$subs->delete($ctx['tenant_id'], $ctx['user_id'], $id);
			$cachePurged = $this->kernel->get(CacheRepository::class)
				->purgeForTenant($ctx['tenant_id']);
			if (!$alreadyInTx) $pdo->commit();
		} catch (\Throwable $e) {
			if (!$alreadyInTx && $pdo->inTransaction()) $pdo->rollBack();
			throw $e;
		}

		Response::json([
			'ok'            => true,
			'deleted_rules' => $ruleCount,
			'cache_purged'  => $cachePurged,
		]);
	}
}
