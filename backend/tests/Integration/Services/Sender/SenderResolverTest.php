<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services\Sender;

use MailPilot\Repositories\SenderRepository;
use MailPilot\Services\Sender\SenderResolver;
use MailPilot\Tests\TestCase;
use Psr\Log\NullLogger;

/**
 * Sort-Refactor Phase 2 — Integration-Test fuer den SenderResolver gegen
 * eine echte Test-DB + die echte Public Suffix List (var/psl/).
 *
 * Pinnt Marcs Beispieltabelle vom 2026-05-18:
 *   ebay@info.ebay.de       → bucket 'ebay'  / domain 'ebay.de'
 *   info@ebay.com           → bucket 'ebay'  / domain 'ebay.com'  (gemerged)
 *   info@mail.fuji-euro.de  → bucket 'fuji-euro' / domain 'fuji-euro.de'
 *   info@ebay-mails.com     → bucket 'ebay-mails' (NICHT ebay)
 *   kunde@amazon.co.uk      → bucket 'amazon' / domain 'amazon.co.uk' (PSL-Edge)
 *
 * @group integration
 */
final class SenderResolverTest extends TestCase
{
	private SenderRepository $repo;
	private SenderResolver $resolver;
	private string $tenantId;

	protected function setUp(): void
	{
		$this->truncateAll();
		[$this->tenantId] = $this->insertTenantAndUser();

		$this->repo = new SenderRepository($this->pdo());
		// __DIR__ ist backend/tests/Integration/Services/Sender → 4× hoch zu backend/
		$pslPath = dirname(__DIR__, 4) . '/var/psl/public_suffix_list.dat';
		$this->resolver = new SenderResolver($pslPath, $this->repo, new NullLogger());
	}

	public function testEbayMultiSubdomainMergesIntoOneBucket(): void
	{
		$first  = $this->resolver->resolve($this->tenantId, 'ebay@info.ebay.de');
		$second = $this->resolver->resolve($this->tenantId, 'info@ebay.com');
		$third  = $this->resolver->resolve($this->tenantId, 'info@ebay.de');

		$this->assertNotNull($first);
		$this->assertNotNull($second);
		$this->assertNotNull($third);

		// Alle drei zeigen auf denselben Bucket.
		$this->assertSame('ebay', $first['sender_key']);
		$this->assertSame($first['id'], $second['id'], 'info@ebay.com muss den ebay-Bucket recyclen');
		$this->assertSame($first['id'], $third['id'],  'info@ebay.de muss den ebay-Bucket recyclen');

		// registrable_domains hat alle drei Schreibweisen
		$this->assertEqualsCanonicalizing(
			['ebay.de', 'ebay.com'],
			$second['registrable_domains'],
			'Bucket muss beide Domains kennen — eine fuer Match-Fortschritt'
		);
	}

	public function testFujiEuroSubdomainMerges(): void
	{
		$first  = $this->resolver->resolve($this->tenantId, 'info@mail.fuji-euro.de');
		$second = $this->resolver->resolve($this->tenantId, 'info@fuji-euro.de');

		$this->assertNotNull($first);
		$this->assertSame('fuji-euro', $first['sender_key']);
		$this->assertSame($first['id'], $second['id']);
		$this->assertSame('Fuji-Euro', $first['display_name'], 'Multi-Wort-Stem muss korrekt kapitalisiert werden');
	}

	public function testEbayMailsIsSeparateBucket(): void
	{
		$ebay      = $this->resolver->resolve($this->tenantId, 'info@ebay.com');
		$lookalike = $this->resolver->resolve($this->tenantId, 'info@ebay-mails.com');

		$this->assertNotNull($ebay);
		$this->assertNotNull($lookalike);
		$this->assertNotSame($ebay['id'], $lookalike['id'], 'ebay-mails.com darf NICHT in ebay-Bucket landen (Marc-Anforderung)');
		$this->assertSame('ebay', $ebay['sender_key']);
		$this->assertSame('ebay-mails', $lookalike['sender_key']);
	}

	public function testAmazonCoUkIsPslCorrect(): void
	{
		// PSL-Edge: amazon.co.uk hat mehrteilige TLD — naive Heuristik
		// wuerde 'co.uk' als Stem nehmen. Mit PSL korrekt → 'amazon'.
		$bucket = $this->resolver->resolve($this->tenantId, 'kunde@amazon.co.uk');
		$this->assertNotNull($bucket);
		$this->assertSame('amazon', $bucket['sender_key']);
		$this->assertContains('amazon.co.uk', $bucket['registrable_domains']);
	}

	public function testNewBucketStartsWithUnknownTrust(): void
	{
		$bucket = $this->resolver->resolve($this->tenantId, 'bestellung@amazon.de');
		$this->assertNotNull($bucket);
		$this->assertSame('unknown', $bucket['trust_status'],
			'Frische Buckets sind unknown bis LookalikeDetector oder User entschieden hat');
		$this->assertNull($bucket['spoof_of_sender_id']);
	}

	public function testInvalidEmailReturnsNull(): void
	{
		$this->assertNull($this->resolver->resolve($this->tenantId, 'nicht-eine-email'));
		$this->assertNull($this->resolver->resolve($this->tenantId, 'user@'));
	}

	public function testIdempotentResolveDoesNotCreateDuplicates(): void
	{
		// Vier Mails von info@ebay.com → ein einziger Bucket, keine Duplikate
		for ($i = 0; $i < 4; $i++) {
			$this->resolver->resolve($this->tenantId, 'info@ebay.com');
		}
		$all = $this->repo->listForTenant($this->tenantId);
		$ebay = array_values(array_filter($all, fn(array $s): bool => $s['sender_key'] === 'ebay'));
		$this->assertCount(1, $ebay, 'Mehrfacher Resolve auf dieselbe Adresse darf nur EINEN Bucket erzeugen');
	}
}
