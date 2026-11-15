<?php

/**
 * Characterisation tests for ModuleComplianceService::extractStandaardversieUuids.
 *
 * Pins the returned UUID list (keys included), every log call in order and
 * the per-shape tracking tally, across the missing, string, array, object and
 * invalid standaardversie shapes.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ModuleComplianceService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

/**
 * Test class pinning the extraction log trail.
 */
class ModuleComplianceServiceExtractLogTest extends TestCase {
	/**
	 * Logger that records every call.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * The service under test.
	 *
	 * @var ModuleComplianceService
	 */
	private ModuleComplianceService $service;

	/**
	 * Set up the service with a recording logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = new class extends AbstractLogger {
			/**
			 * @var array<int, array{0: mixed, 1: string, 2: array}>
			 */
			public array $records = [];

			/**
			 * @param mixed  $level   The level.
			 * @param mixed  $message The message.
			 * @param array  $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string)$message, $context];
			}
		};

		$this->service = new ModuleComplianceService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(SettingsService::class),
			$this->logger
		);
	}//end setUp()

	/**
	 * Build a fake compliance object exposing getObject()/getId().
	 *
	 * @param array $data The object data bag.
	 * @param mixed $id   The object id.
	 *
	 * @return object A stub compliance object.
	 */
	private function complianceObject(array $data, mixed $id): object {
		return new class($data, $id) {
			/**
			 * @param array $data The data bag.
			 * @param mixed $id   The id.
			 */
			public function __construct(
				private array $data,
				private mixed $id,
			) {
			}

			/**
			 * @return array The object data.
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * @return mixed The object id.
			 */
			public function getId(): mixed {
				return $this->id;
			}
		};
	}//end complianceObject()

	/**
	 * Run the private extraction.
	 *
	 * @param array $objects The compliance objects.
	 *
	 * @return array The extracted UUIDs.
	 */
	private function extract(array $objects): array {
		$reflection = new ReflectionMethod(ModuleComplianceService::class, 'extractStandaardversieUuids');
		$reflection->setAccessible(true);
		return $reflection->invoke($this->service, $objects);
	}//end extract()

	/**
	 * Every shape logs once, in input order, and the summary carries the tally.
	 *
	 * @return void
	 */
	public function testEveryShapeLogsInOrderWithTally(): void {
		$objects = [
			$this->complianceObject(['uuid' => 'c-1'], 1),
			$this->complianceObject([], 2),
			$this->complianceObject(['standardVersion' => 'sv-a'], 3),
			$this->complianceObject(['standardVersion' => ['uuid' => 'sv-b']], 4),
			$this->complianceObject(['standardVersion' => (object)['uuid' => 'sv-c']], 5),
			$this->complianceObject(['standardVersion' => 42], 6),
			$this->complianceObject(['standardVersion' => 'sv-a'], 7),
			$this->complianceObject(['standardVersion' => ''], 8),
			$this->complianceObject(['standardVersion' => false], 9),
		];

		$result = $this->extract($objects);

		$this->assertSame([0 => 'sv-a', 1 => 'sv-b', 2 => 'sv-c'], $result);

		$prefix = 'ModuleComplianceService: ';
		$expected = [
			['debug', $prefix.'Compliance object missing standaardversie', ['complianceId' => 1, 'complianceUuid' => 'c-1']],
			['debug', $prefix.'Compliance object missing standaardversie', ['complianceId' => 2, 'complianceUuid' => 'unknown']],
			['debug', $prefix.'Found string standaardversie', ['complianceId' => 3, 'standardVersion' => 'sv-a']],
			['debug', $prefix.'Found array standaardversie', ['complianceId' => 4, 'standardVersion' => 'sv-b']],
			['debug', $prefix.'Found object standaardversie', ['complianceId' => 5, 'standardVersion' => 'sv-c']],
			['warning', $prefix.'Invalid standaardversie type', ['complianceId' => 6, 'type' => 'integer', 'value' => '42']],
			['debug', $prefix.'Found string standaardversie', ['complianceId' => 7, 'standardVersion' => 'sv-a']],
			['debug', $prefix.'Found string standaardversie', ['complianceId' => 8, 'standardVersion' => '']],
			['warning', $prefix.'Invalid standaardversie type', ['complianceId' => 9, 'type' => 'boolean', 'value' => '']],
			[
				'info',
				$prefix.'Extracted standaardversie UUIDs',
				[
					'complianceCount' => 9,
					'tracking' => [
						'withStandaardversie' => 7,
						'withoutStandaardversie' => 2,
						'stringType' => 3,
						'arrayType' => 1,
						'objectType' => 1,
						'invalidType' => 2,
					],
					'uniqueStandaardversieUuids' => 3,
					'standaardversieUuids' => [0 => 'sv-a', 1 => 'sv-b', 2 => 'sv-c'],
				],
			],
		];
		$this->assertSame($expected, $this->logger->records);
	}//end testEveryShapeLogsInOrderWithTally()

	/**
	 * An array or object without a uuid is invalid; an array value is logged as JSON.
	 *
	 * @return void
	 */
	public function testUuidlessArrayAndObjectAreInvalid(): void {
		$objects = [
			$this->complianceObject(['standardVersion' => ['id' => 'x']], 1),
			$this->complianceObject(['standardVersion' => ['uuid' => null]], 2),
		];

		$result = @$this->extract($objects);

		$this->assertSame([], $result);
		$this->assertSame(
			['warning', 'ModuleComplianceService: Invalid standaardversie type', ['complianceId' => 1, 'type' => 'array', 'value' => '{"id":"x"}']],
			$this->logger->records[0]
		);
		$this->assertSame(
			['warning', 'ModuleComplianceService: Invalid standaardversie type', ['complianceId' => 2, 'type' => 'array', 'value' => '{"uuid":null}']],
			$this->logger->records[1]
		);
		$this->assertSame(0, $this->logger->records[2][2]['tracking']['arrayType']);
		$this->assertSame(2, $this->logger->records[2][2]['tracking']['invalidType']);
		$this->assertSame(2, $this->logger->records[2][2]['tracking']['withStandaardversie']);
	}//end testUuidlessArrayAndObjectAreInvalid()
}//end class
