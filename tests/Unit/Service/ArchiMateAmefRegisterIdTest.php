<?php

/**
 * Characterisation test for getAmefRegisterId of ArchiMateService and
 * ArchiMateImportService.
 *
 * Pins, for both services, the register id resolved from the AMEF
 * configuration (register_id, then register), the legacy app config keys it
 * falls back to (amef_register_id, overridden by amef_register), the reads it
 * makes, and the rejection of non-numeric, zero and negative ids.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Characterisation of the AMEF register id.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ArchiMateAmefRegisterIdTest extends TestCase {

	/**
	 * Each case: the AMEF configuration, the legacy app config values, the id and the legacy reads.
	 *
	 * @return array<string, array{0: array, 1: array<string, string>, 2: int|null, 3: array<int, string>}>
	 */
	public static function cases(): array {
		$both = ['amef_register_id', 'amef_register'];
		return [
			'register_id'             => [['register_id' => '7', 'register' => '8'], [], 7, []],
			'register'                => [['register' => '8'], [], 8, []],
			'int register_id'         => [['register_id' => 9], [], 9, []],
			'empty register_id'       => [['register_id' => ''], ['amef_register_id' => '3'], 3, $both],
			'legacy register id'      => [[], ['amef_register_id' => '3'], 3, $both],
			'legacy register wins'    => [[], ['amef_register_id' => '3', 'amef_register' => '4'], 4, ['amef_register_id', 'amef_register', 'amef_register']],
			'nothing configured'      => [[], [], null, $both],
			'non-numeric'             => [['register_id' => 'abc'], [], null, []],
			'zero'                    => [['register_id' => '0'], [], null, []],
			'negative'                => [['register_id' => '-2'], [], null, []],
			'decimal'                 => [['register_id' => '5.7'], [], 5, []],
		];

	}//end cases()

	/**
	 * Both services resolve the pinned id with the pinned legacy reads.
	 *
	 * @param array                 $amefConfig The AMEF configuration.
	 * @param array<string, string> $legacy     The legacy app config values.
	 * @param int|null              $expected   The register id.
	 * @param array<int, string>    $reads      The legacy keys read, in order.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testBothServicesResolveThePinnedId(array $amefConfig, array $legacy, ?int $expected, array $reads): void {
		foreach ([ArchiMateService::class, ArchiMateImportService::class] as $class) {
			$asked   = new \ArrayObject();
			$config  = $this->createMock(originalClassName: IAppConfig::class);
			$config->method('getValueString')->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($asked, $legacy, $amefConfig): string {
					if ($key === 'amef_config') {
						return (string) json_encode(value: $amefConfig);
					}

					$asked[] = $key;
					return $legacy[$key] ?? $default;
				}
			);
			$settings = $this->createMock(originalClassName: SettingsService::class);
			$settings->method('getAmefConfig')->willReturn($amefConfig);

			$service = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
			$values  = ['config' => $config, 'logger' => new NullLogger()];
			if ($class === ArchiMateService::class) {
				$values['settingsService'] = $settings;
			}

			foreach ($values as $prop => $value) {
				(new \ReflectionProperty(class: $class, property: $prop))->setValue($service, $value);
			}

			$id = (new \ReflectionMethod(objectOrMethod: $class, method: 'getAmefRegisterId'))->invoke($service);
			$this->assertSame(expected: $expected, actual: $id, message: $class);
			$this->assertSame(expected: $reads, actual: $asked->getArrayCopy(), message: $class);
		}

	}//end testBothServicesResolveThePinnedId()

}//end class
