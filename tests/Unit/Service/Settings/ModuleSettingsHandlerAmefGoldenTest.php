<?php

/**
 * Golden tests for ModuleSettingsHandler::setAmefConfig().
 *
 * Pins which AMEF fields are written, in which order, as which strings
 * (flags only `true` for a real boolean true), the version check before any
 * write, the log call and the returned configuration, so splitting the method
 * keeps every outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
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
 * Golden tests for the AMEF configuration update.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ModuleSettingsHandlerAmefGoldenTest extends TestCase {

	/**
	 * Every config write and log call, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * The stored values.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * Update the AMEF configuration and return the result.
	 *
	 * @param array $data The fields to update.
	 *
	 * @return array The returned configuration.
	 */
	private function update(array $data): array {
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->calls[] = ['set', $app, $key, $value];
				$this->values[$key] = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => $this->values[$key] ?? $default
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['info', $message, $context];
			}
		);

		return (new ModuleSettingsHandler(config: $config, logger: $logger))->setAmefConfig($data);
	}//end update()

	/**
	 * Every field written in order; flags are 'true' only for boolean true.
	 *
	 * @return void
	 */
	public function testEveryFieldIsWrittenInOrder(): void {
		$data = ['includeModules' => 'true', 'exportPath' => 12, 'version' => '2.1.3', 'enabled' => true, 'other' => 'x'];

		$result = $this->update(data: $data);

		$this->assertSame(
			[
				['set', 'stackiq', 'amef_enabled', 'true'],
				['set', 'stackiq', 'amef_version', '2.1.3'],
				['set', 'stackiq', 'amef_export_path', '12'],
				['set', 'stackiq', 'amef_include_modules', 'false'],
				['info', 'ModuleSettingsHandler: Updated AMEF configuration', ['data' => $data]],
			],
			$this->calls
		);
		$this->assertSame(['enabled' => true, 'version' => '2.1.3', 'exportPath' => '12', 'includeModules' => false], $result);
	}//end testEveryFieldIsWrittenInOrder()

	/**
	 * Absent and null fields are not written.
	 *
	 * @return void
	 */
	public function testAbsentAndNullFieldsAreNotWritten(): void {
		$result = $this->update(data: ['enabled' => null, 'exportPath' => null, 'includeModules' => false, 'version' => null]);

		$this->assertSame(
			[
				['set', 'stackiq', 'amef_include_modules', 'false'],
				['info', 'ModuleSettingsHandler: Updated AMEF configuration', ['data' => ['enabled' => null, 'exportPath' => null, 'includeModules' => false, 'version' => null]]],
			],
			$this->calls
		);
		$this->assertSame(['enabled' => true, 'version' => '1.0', 'exportPath' => '', 'includeModules' => false], $result);

		$this->calls = [];
		$this->update(data: ['enabled' => 1, 'includeModules' => true]);
		$this->assertSame(['set', 'stackiq', 'amef_enabled', 'false'], $this->calls[0]);
		$this->assertSame(['set', 'stackiq', 'amef_include_modules', 'true'], $this->calls[1]);
	}//end testAbsentAndNullFieldsAreNotWritten()

	/**
	 * A bad version is refused before anything is written.
	 *
	 * @return void
	 */
	public function testABadVersionIsRefusedBeforeAnyWrite(): void {
		try {
			$this->update(data: ['enabled' => true, 'version' => 'v2']);
			$this->fail('Expected the version to be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Invalid AMEF version format "v2". Expected: major.minor[.patch]', $e->getMessage());
		}

		$this->assertSame([], $this->calls);
	}//end testABadVersionIsRefusedBeforeAnyWrite()

}//end class
