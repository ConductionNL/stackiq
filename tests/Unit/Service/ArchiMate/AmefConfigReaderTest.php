<?php

/**
 * Unit tests for AmefConfigReader.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\ArchiMate;

use OCA\Stackiq\Service\ArchiMate\AmefConfigReader;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the JSON read, the legacy per-id fallback and the failed read.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AmefConfigReaderTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $config;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Back the config mock with a key/value map.
	 *
	 * @param array<string, string> $values The stored values.
	 *
	 * @return void
	 */
	private function storeValues(array $values): void {
		$this->config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values): string {
				TestCase::assertSame('stackiq', $app);
				return $values[$key] ?? $default;
			}
		);
	}//end storeValues()

	/**
	 * A stored JSON object is returned decoded, without touching the legacy keys.
	 *
	 * @return void
	 */
	public function testReturnsDecodedJsonConfig(): void {
		$this->config->expects($this->once())
			->method('getValueString')
			->with('stackiq', 'amef_config', '{}')
			->willReturn('{"register_id":"5","views_schema":"9"}');
		$this->logger->expects($this->never())->method('warning');

		$reader = new AmefConfigReader($this->config, $this->logger);

		$this->assertSame(['register_id' => '5', 'views_schema' => '9'], $reader->read());
	}//end testReturnsDecodedJsonConfig()

	/**
	 * The default empty object decodes to an empty array, which is still an array.
	 *
	 * @return void
	 */
	public function testEmptyJsonObjectYieldsEmptyArray(): void {
		$this->storeValues([]);

		$reader = new AmefConfigReader($this->config, $this->logger);

		$this->assertSame([], $reader->read());
	}//end testEmptyJsonObjectYieldsEmptyArray()

	/**
	 * Invalid JSON falls back to the legacy keys and omits the unset ones.
	 *
	 * @return void
	 */
	public function testInvalidJsonFallsBackToLegacyIdsAndOmitsUnset(): void {
		$this->storeValues(
			[
				'amef_config'          => 'not json',
				'amef_register'        => '12',
				'amef_elements_schema' => '34',
				'amef_views_schema'    => '   ',
			]
		);

		$warned = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warned): void {
				$warned[] = $context['key'];
			}
		);

		$reader = new AmefConfigReader($this->config, $this->logger);

		$this->assertSame(['register_id' => '12', 'elements_schema' => '34'], $reader->read());
		$this->assertSame(
			[
				'amef_model_schema',
				'amef_relationships_schema',
				'amef_views_schema',
				'amef_organizations_schema',
				'amef_folders_schema',
				'amef_property_definitions_schema',
			],
			$warned
		);
	}//end testInvalidJsonFallsBackToLegacyIdsAndOmitsUnset()

	/**
	 * A JSON scalar is not a configuration, so the legacy keys are read.
	 *
	 * @return void
	 */
	public function testJsonScalarFallsBackToLegacyIds(): void {
		$this->storeValues(
			[
				'amef_config'                      => '42',
				'amef_register'                    => 'r',
				'amef_model_schema'                => 'm',
				'amef_elements_schema'             => 'e',
				'amef_relationships_schema'        => 'rel',
				'amef_views_schema'                => 'v',
				'amef_organizations_schema'        => 'o',
				'amef_folders_schema'              => 'f',
				'amef_property_definitions_schema' => 'p',
			]
		);
		$this->logger->expects($this->never())->method('warning');

		$reader = new AmefConfigReader($this->config, $this->logger);

		$this->assertSame(
			[
				'register_id'                 => 'r',
				'model_schema_id'             => 'm',
				'elements_schema'             => 'e',
				'relationships_schema'        => 'rel',
				'views_schema'                => 'v',
				'organizations_schema'        => 'o',
				'folders_schema'              => 'f',
				'property_definitions_schema' => 'p',
			],
			$reader->read()
		);
	}//end testJsonScalarFallsBackToLegacyIds()

	/**
	 * A failing config read is logged and reported as a failure.
	 *
	 * @return void
	 */
	public function testReadFailureIsLoggedAndReported(): void {
		$this->config->method('getValueString')->willThrowException(new \RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Failed to get AMEF configuration', ['error' => 'db down']);

		$reader = new AmefConfigReader($this->config, $this->logger);

		$this->assertSame(['success' => false, 'error' => 'db down'], $reader->read());
	}//end testReadFailureIsLoggedAndReported()
}//end class
