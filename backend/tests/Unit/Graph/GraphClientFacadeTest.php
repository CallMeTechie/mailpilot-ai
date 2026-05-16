<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Graph;

use MailPilot\Graph\GraphClient;
use MailPilot\Graph\GraphFolderClient;
use MailPilot\Graph\GraphHttpTransport;
use MailPilot\Graph\GraphMailClient;
use MailPilot\Graph\GraphOAuthClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase-3 Facade-Smoke-Test. Stellt sicher:
 *   - GraphClient hat alle vorigen Public-Methoden
 *   - GraphClient ist nicht-final (FakeGraphClient kann extenden)
 *   - Sub-Clients existieren mit ihren Public-Methoden
 *   - authorizationUrl liefert eine deterministische URL
 *
 * Network-Calls (curl) sind NICHT getestet. Tests des Mail/Folder-Pfads
 * laufen via AutoSortServiceTest, SyncServiceTest etc. mit FakeGraphClient.
 */
final class GraphClientFacadeTest extends TestCase
{
	private function makeClient(): GraphClient
	{
		return new GraphClient(
			'cid', 'secret', 'https://x/cb', 'common', 'Mail.Read', new NullLogger(),
		);
	}

	public function testFacadeExposesAllPreviouslyPublicMethods(): void
	{
		$expected = [
			'authorizationUrl', 'exchangeCode', 'refreshToken',
			'syncInbox', 'fetchMessage', 'setCategories', 'markAsRead',
			'moveToFolder', 'deleteMessage', 'getConversationLastMessage', 'getMe',
			'getFolder', 'findChildFolderByName', 'createChildFolder', 'ensureFolderPath',
		];
		foreach ($expected as $method) {
			$this->assertTrue(method_exists(GraphClient::class, $method),
				"GraphClient::{$method} muss existieren");
			$ref = new ReflectionMethod(GraphClient::class, $method);
			$this->assertTrue($ref->isPublic(), "GraphClient::{$method} muss public sein");
		}
	}

	public function testGraphClientIsNotFinalSoFakesCanExtend(): void
	{
		$ref = new ReflectionClass(GraphClient::class);
		$this->assertFalse($ref->isFinal(),
			'GraphClient darf nicht final sein — FakeGraphClient extends GraphClient');
	}

	public function testFakeGraphClientStillExtendsAndIsConstructable(): void
	{
		$this->assertTrue(
			is_subclass_of(\MailPilot\Tests\Fixtures\FakeGraphClient::class, GraphClient::class),
			'FakeGraphClient muss weiterhin GraphClient extenden',
		);
		$fake = new \MailPilot\Tests\Fixtures\FakeGraphClient();
		$this->assertInstanceOf(GraphClient::class, $fake);
	}

	public function testSubClientsExistAndExposeExpectedMethods(): void
	{
		$this->assertTrue(method_exists(GraphHttpTransport::class, 'get'));
		$this->assertTrue(method_exists(GraphHttpTransport::class, 'patch'));
		$this->assertTrue(method_exists(GraphHttpTransport::class, 'postJson'));
		$this->assertTrue(method_exists(GraphHttpTransport::class, 'delete'));

		$this->assertTrue(method_exists(GraphOAuthClient::class, 'authorizationUrl'));
		$this->assertTrue(method_exists(GraphOAuthClient::class, 'exchangeCode'));
		$this->assertTrue(method_exists(GraphOAuthClient::class, 'refreshToken'));

		$this->assertTrue(method_exists(GraphMailClient::class, 'syncInbox'));
		$this->assertTrue(method_exists(GraphMailClient::class, 'moveToFolder'));

		$this->assertTrue(method_exists(GraphFolderClient::class, 'ensurePath'));
		$this->assertTrue(method_exists(GraphFolderClient::class, 'get'));
	}

	public function testAuthorizationUrlDelegationProducesExpectedShape(): void
	{
		$url = $this->makeClient()->authorizationUrl('state-abc', 'challenge-xyz');
		$this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);
		$this->assertStringContainsString('client_id=cid', $url);
		$this->assertStringContainsString('response_type=code', $url);
		$this->assertStringContainsString('state=state-abc', $url);
		$this->assertStringContainsString('code_challenge=challenge-xyz', $url);
		$this->assertStringContainsString('code_challenge_method=S256', $url);
		$this->assertStringContainsString('scope=Mail.Read', $url);
	}
}
