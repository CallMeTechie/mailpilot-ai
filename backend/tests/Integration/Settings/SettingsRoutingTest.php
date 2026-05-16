<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Settings;

use MailPilot\Controllers\Settings\AutoSortController;
use MailPilot\Controllers\Settings\ModesController;
use MailPilot\Controllers\Settings\RedactionController;
use MailPilot\Controllers\Settings\SubLabelController;
use MailPilot\Controllers\Settings\UserSettingsController;
use MailPilot\Controllers\Settings\VipController;
use ReflectionClass;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;

/**
 * Phase-2 Smoke-Test: stellt sicher, dass alle sechs Controller existieren,
 * von BaseController erben, und die in public/index.php registrierten
 * Routen-Methoden vorhanden sind.
 *
 * Ohne diesen Test wuerde ein Tippfehler in der Routen-Konfiguration erst
 * zur Laufzeit auffallen (RuntimeException aus dem Router::invoke).
 */
final class SettingsRoutingTest extends TestCase
{
	/**
	 * @return array<string, array{0:class-string, 1:list<string>}>
	 */
	public static function controllerMethods(): array
	{
		return [
			'user'      => [UserSettingsController::class, ['getUser', 'updateUser']],
			'vip'       => [VipController::class,          ['listVip', 'addVip', 'deleteVip']],
			'redaction' => [RedactionController::class,    ['listRedaction', 'addRedaction']],
			'autosort'  => [AutoSortController::class,     [
				'listAutoSort', 'updateAutoSort', 'applyAutoSortNow',
				'deleteAutoSortSub', 'rescoreAll',
			]],
			'sublabel'  => [SubLabelController::class,     [
				'listSubLabels', 'addSubLabel', 'updateSubLabel', 'deleteSubLabel',
			]],
			'modes'     => [ModesController::class,        [
				'getModes', 'saveModes', 'includeAutoReplyBacklog',
			]],
		];
	}

	/**
	 * @dataProvider controllerMethods
	 * @param class-string $class
	 * @param list<string> $methods
	 */
	public function testControllerExistsAndExtendsBase(string $class, array $methods): void
	{
		$this->assertTrue(class_exists($class), "Class {$class} muss existieren");
		$ref = new ReflectionClass($class);
		$this->assertTrue(
			$ref->isSubclassOf(\MailPilot\Controllers\BaseController::class),
			"{$class} muss BaseController erweitern (fuer requireAuth)",
		);
	}

	/**
	 * @dataProvider controllerMethods
	 * @param class-string $class
	 * @param list<string> $methods
	 */
	public function testAllRoutedMethodsExistAndArePublic(string $class, array $methods): void
	{
		foreach ($methods as $method) {
			$this->assertTrue(method_exists($class, $method),
				"{$class}::{$method} muss existieren (in public/index.php registriert)");
			$ref = new ReflectionMethod($class, $method);
			$this->assertTrue($ref->isPublic(),
				"{$class}::{$method} muss public sein (Router invokiert sie)");
		}
	}

	/**
	 * @dataProvider controllerMethods
	 * @param class-string $class
	 * @param list<string> $methods
	 */
	public function testRoutedMethodsAcceptTwoArrayArgs(string $class, array $methods): void
	{
		foreach ($methods as $method) {
			$ref = new ReflectionMethod($class, $method);
			$params = $ref->getParameters();
			$this->assertGreaterThanOrEqual(2, count($params),
				"{$class}::{$method} braucht (array \$params, array \$body) — Router::invoke ruft so");
			$this->assertSame('params', $params[0]->getName(), "{$class}::{$method} param0 muss \$params heissen");
			$this->assertSame('body',   $params[1]->getName(), "{$class}::{$method} param1 muss \$body heissen");
		}
	}

	public function testOldMonolithIsGone(): void
	{
		$this->assertFalse(
			class_exists(\MailPilot\Controllers\SettingsController::class, false),
			'Der alte SettingsController-Monolith muss nach Phase 2 weg sein',
		);
	}
}
