<?php
declare(strict_types=1);

namespace MailPilot\Services\Sender;

use MailPilot\Repositories\SenderRepository;
use Pdp\Domain;
use Pdp\Rules;
use Psr\Log\LoggerInterface;

/**
 * Sort-Refactor Phase 2 — Resolves an email address to its sender bucket.
 *
 * Beispiel (Marc 2026-05-18):
 *   ebay@info.ebay.de       → sender_key='ebay',     registrable_domain='ebay.de'
 *   info@ebay.com           → sender_key='ebay'      (selber Bucket via PSL-Stem)
 *   info@mail.fuji-euro.de  → sender_key='fuji-euro', registrable_domain='fuji-euro.de'
 *   info@ebay-mails.com     → sender_key='ebay-mails' (eigener Bucket, KEIN Match auf 'ebay')
 *
 * Algorithmus:
 *   1. From-Email parsen, Host extrahieren.
 *   2. Via PSL die registrable domain ableiten (`ebay.de`, `amazon.co.uk`).
 *   3. Repo: existiert schon ein Sender mit dieser exakten Domain? → return.
 *   4. Sender-Key bilden = erstes Token der registrable Domain
 *      (`ebay.de` → `ebay`, `amazon.co.uk` → `amazon`, `fuji-euro.de` → `fuji-euro`).
 *   5. Repo: existiert Sender mit gleichem Key? → Domain ergaenzen, return.
 *   6. Sonst neuen Sender anlegen (trust_status=unknown). Spoof-Detection
 *      laeuft danach im LookalikeDetector — getrennte Verantwortung.
 *
 * Der LookalikeDetector kann unmittelbar nach create() den frischen Sender
 * uebernehmen und sein trust_status auf 'suspected_spoof' setzen. Resolver
 * traegt diese Entscheidung explizit nicht — single responsibility.
 */
final class SenderResolver
{
	private readonly Rules $psl;

	public function __construct(
		string $pslPath,
		private readonly SenderRepository $senders,
		private readonly LoggerInterface $logger,
	) {
		// PSL einmal pro Process-Lifetime laden — ~330KB, parst in <50ms.
		// Wenn der Pfad fehlt, ist das ein Config-Fehler beim Image-Build;
		// wir wollen NICHT silent auf einen leeren Resolver durchfallen.
		$this->psl = Rules::fromPath($pslPath);
	}

	/**
	 * Liefert den Sender-Bucket fuer eine From-Adresse. Legt einen neuen
	 * Bucket an, wenn keiner passt.
	 *
	 * Returnt `null` nur bei syntaktisch unbrauchbarer Adresse — der
	 * Aufrufer (MailScoringService Phase 3) faellt dann auf den
	 * Legacy-Label-Pfad zurueck statt die Mail zu verlieren.
	 *
	 * @return array<string,mixed>|null  Sender-Bucket (SenderRepository::hydrate-Shape)
	 */
	public function resolve(string $tenantId, string $fromEmail): ?array
	{
		$host = $this->extractHost($fromEmail);
		if ($host === null) {
			$this->logger->info('sender_resolver.invalid_email', ['email' => $fromEmail]);
			return null;
		}

		$registrable = $this->registrableDomain($host);
		if ($registrable === null) {
			$this->logger->info('sender_resolver.unparseable_host', ['host' => $host]);
			return null;
		}

		// Pfad 1: exakte Schreibweise bekannt
		$bucket = $this->senders->findByRegistrableDomain($tenantId, $registrable);
		if ($bucket !== null) {
			return $bucket;
		}

		// Pfad 2: gleicher Stem schon als Bucket? (z.B. ebay.de bekannt,
		// jetzt kommt ebay.com rein — selber Bucket, Domain anhaengen).
		$senderKey = $this->stemFromRegistrable($registrable);
		$existing  = $this->senders->findByKey($tenantId, $senderKey);
		if ($existing !== null) {
			$this->senders->addRegistrableDomain($tenantId, $existing['id'], $registrable);
			// Hydratisierte Kopie zurueckliefern mit angehaengter Domain,
			// ohne zweiten DB-Roundtrip.
			$existing['registrable_domains'] = array_values(array_unique(
				array_merge($existing['registrable_domains'], [strtolower($registrable)])
			));
			$this->logger->info('sender_resolver.extended', [
				'sender_key'  => $senderKey,
				'new_domain'  => $registrable,
			]);
			return $existing;
		}

		// Pfad 3: neuer Bucket. Display-Name + Folder-Name aus dem Stem
		// (User darf beides in den Settings editieren).
		$displayName = $this->capitalize($senderKey);
		$id = $this->senders->create(
			$tenantId,
			$senderKey,
			[$registrable],
			$displayName,
			$displayName,
			'unknown',
		);
		$this->logger->info('sender_resolver.created', [
			'sender_key' => $senderKey,
			'domain'     => $registrable,
			'sender_id'  => $id,
		]);
		// Re-Read, damit der Aufrufer eine vollstaendige (gehydrierte) Row bekommt.
		return $this->senders->findByKey($tenantId, $senderKey);
	}

	/**
	 * Bereit fuer Phase-4-Logik: identifiziert die effektive registrable
	 * Domain eines Hosts (z.B. 'noreply.notifications.github.com' → 'github.com').
	 * Public weil der LookalikeDetector dieselbe Logik braucht ohne den
	 * vollstaendigen resolve()-Pfad zu fahren.
	 */
	public function registrableDomain(string $host): ?string
	{
		$host = strtolower(trim($host));
		if ($host === '') return null;
		try {
			$result = $this->psl->resolve(Domain::fromIDNA2008($host));
			$rd = $result->registrableDomain()->toString();
			return $rd === '' ? null : $rd;
		} catch (\Throwable $e) {
			$this->logger->info('sender_resolver.psl_failed', [
				'host' => $host, 'err' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Stem = alles vor dem ersten Punkt der registrable Domain.
	 * 'ebay.de' → 'ebay', 'amazon.co.uk' → 'amazon', 'fuji-euro.de' → 'fuji-euro'.
	 *
	 * Public weil der LookalikeDetector den Stem zweier Domains vergleichen
	 * muss (Levenshtein), ohne den vollstaendigen Resolver-Cycle.
	 */
	public function stemFromRegistrable(string $registrable): string
	{
		$pos = strpos($registrable, '.');
		return $pos === false ? strtolower($registrable) : strtolower(substr($registrable, 0, $pos));
	}

	private function extractHost(string $email): ?string
	{
		$at = strrpos($email, '@');
		if ($at === false || $at === strlen($email) - 1) {
			return null;
		}
		$host = substr($email, $at + 1);
		// IDNs werden vom PSL-Resolver behandelt; hier nur grundlegende
		// Filter-Validation um Whitespace / leere Strings rauszuwerfen.
		if (filter_var('user@' . $host, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}
		return $host;
	}

	private function capitalize(string $stem): string
	{
		// Multi-Wort-Stems wie „fuji-euro" sollen „Fuji-Euro" werden — jedes
		// Segment nach Bindestrich kapitalisieren. ucwords + Bindestrich-Delim.
		return ucwords($stem, '-');
	}
}
