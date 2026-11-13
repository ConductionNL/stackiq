<?php

/**
 * Unit tests for ModuleSettingsHandler.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use InvalidArgumentException;
use OCA\Stackiq\Service\Settings\ModuleSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Round-trips the AMEF module settings through an in-memory IAppConfig double.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ModuleSettingsHandlerTest extends TestCase {

	/**
	 * Build a handler over a key/value store.
	 *
	 * @param array<string, string>  $store  Reference to the backing store.
	 * @param LoggerInterface|null   $logger The logger, or a dummy.
	 *
	 * @return ModuleSettingsHandler
	 */
	private function handler(array &$store, ?LoggerInterface $logger = null): ModuleSettingsHandler {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				TestCase::assertSame('stackiq', $app);
				return $store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				TestCase::assertSame('stackiq', $app);
				$store[$key] = $value;
				return true;
			}
		);

		return new ModuleSettingsHandler($config, $logger ?? $this->createMock(LoggerInterface::class));
	}//end handler()

	/**
	 * An empty store yields the defaults.
	 *
	 * @return void
	 */
	public function testDefaults(): void {
		$store = [];

		$this->assertSame(
			['enabled' => true, 'version' => '1.0', 'exportPath' => '', 'includeModules' => true],
			$this->handler($store)->getAmefConfig()
		);
	}//end testDefaults()

	/**
	 * Only the literal string true enables a flag.
	 *
	 * @return void
	 */
	public function testStoredFlagsAreParsedStrictly(): void {
		$store = ['amef_enabled' => '1', 'amef_include_modules' => 'false', 'amef_version' => '2.1', 'amef_export_path' => '/x'];

		$this->assertSame(
			['enabled' => false, 'version' => '2.1', 'exportPath' => '/x', 'includeModules' => false],
			$this->handler($store)->getAmefConfig()
		);
	}//end testStoredFlagsAreParsedStrictly()

	/**
	 * Every field is written and the new configuration is returned.
	 *
	 * @return void
	 */
	public function testSetWritesEveryFieldAndReturnsUpdatedConfig(): void {
		$store  = [];
		$data   = ['enabled' => false, 'version' => '3.2.1', 'exportPath' => '/exports', 'includeModules' => false];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('ModuleSettingsHandler: Updated AMEF configuration', ['data' => $data]);

		$result = $this->handler($store, $logger)->setAmefConfig($data);

		$this->assertSame(
			['amef_enabled' => 'false', 'amef_version' => '3.2.1', 'amef_export_path' => '/exports', 'amef_include_modules' => 'false'],
			$store
		);
		$this->assertSame(['enabled' => false, 'version' => '3.2.1', 'exportPath' => '/exports', 'includeModules' => false], $result);
	}//end testSetWritesEveryFieldAndReturnsUpdatedConfig()

	/**
	 * A truthy non-bool flag is stored as false; only bool true enables.
	 *
	 * @return void
	 */
	public function testNonBoolFlagsAreStoredAsFalse(): void {
		$store = [];

		$this->handler($store)->setAmefConfig(['enabled' => 'true', 'includeModules' => 1]);

		$this->assertSame(['amef_enabled' => 'false', 'amef_include_modules' => 'false'], $store);
	}//end testNonBoolFlagsAreStoredAsFalse()

	/**
	 * Bool true enables, and unset fields are left alone.
	 *
	 * @return void
	 */
	public function testPartialUpdateLeavesOtherKeysAlone(): void {
		$store = ['amef_version' => '1.5', 'amef_enabled' => 'false'];

		$this->handler($store)->setAmefConfig(['enabled' => true]);

		$this->assertSame(['amef_version' => '1.5', 'amef_enabled' => 'true'], $store);
	}//end testPartialUpdateLeavesOtherKeysAlone()

	/**
	 * An invalid version is refused before anything is written.
	 *
	 * @return void
	 */
	public function testInvalidVersionIsRefusedBeforeWriting(): void {
		$store = [];

		try {
			$this->handler($store)->setAmefConfig(['enabled' => true, 'version' => 'v2']);
			$this->fail('Expected an InvalidArgumentException');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Invalid AMEF version format "v2". Expected: major.minor[.patch]', $e->getMessage());
		}

		$this->assertSame([], $store);
	}//end testInvalidVersionIsRefusedBeforeWriting()

	/**
	 * A version needs at least major.minor and at most a patch.
	 *
	 * @return void
	 */
	public function testVersionFormatBoundaries(): void {
		$store   = [];
		$handler = $this->handler($store);

		$handler->setAmefConfig(['version' => '10.20']);
		$this->assertSame('10.20', $store['amef_version']);

		foreach (['1', '1.2.3.4', '1.x', ''] as $invalid) {
			try {
				$handler->setAmefConfig(['version' => $invalid]);
				$this->fail('Expected a refusal for "' . $invalid . '"');
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString('"' . $invalid . '"', $e->getMessage());
			}
		}

		$this->assertSame('10.20', $store['amef_version']);
	}//end testVersionFormatBoundaries()
}//end class
