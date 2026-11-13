<?php

/**
 * Golden test for DataQualityReportService::build().
 *
 * Records the exact query the report runs (its organisation scope and its
 * rbac/multitenancy flags), the lookups it makes, and the report or the
 * refusal it ends in.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\DataQualityDerivation;
use OCA\Stackiq\Service\DataQualityReportService;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Pins the data quality report's query scope and output.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
final class DataQualityReportBuildGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/data-quality/build.json';

	/**
	 * The usages most cases return.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function usages(): array {
		return [
			['id' => 'u-1', 'module' => 'm-1', 'businessOwner' => 'marloes', 'lastReviewed' => '1900-01-01'],
			['id' => 'u-2', 'module' => 'm-2', 'businessOwner' => ''],
			['id' => 'u-3', 'module' => ['id' => 'm-3'], 'businessOwner' => 'x'],
			['uuid' => 'u-4', 'module' => 'm-9'],
		];
	}//end usages()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$page = ['results' => self::usages(), 'total' => 4];
		return [
			'no openregister'      => ['apps' => ['files']],
			'no register'          => ['config' => ['gebruik_schema' => '20']],
			'no usage schema'      => ['config' => ['register' => '1', 'gebruik_schema' => 'x']],
			'report'               => ['page' => $page],
			'other organisation'   => ['page' => $page, 'organisation' => 'westerdam'],
			'truncated'            => ['page' => ['results' => self::usages(), 'total' => 900]],
			'no total'             => ['page' => ['results' => self::usages()]],
			'no results'           => ['page' => []],
			'no rules'             => ['page' => $page, 'rules' => []],
		];
	}//end cases()

	/**
	 * Run one case and record what happened.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $case): array {
		$trace         = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (...$args) use (&$trace, $case): array {
				$trace[] = ['searchObjectsPaginated', $args];
				return ($case['page'] ?? []);
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (string|int $id) use (&$trace): ObjectEntityInterface {
				$trace[] = ['find', $id];
				$entity  = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn(['name' => 'Name of ' . $id]);
				return $entity;
			}
		);
		$rules        = ($case['rules'] ?? [
			['type' => 'required', 'field' => 'businessOwner'],
			['type' => 'required', 'field' => 'module', 'weight' => 2],
			['type' => 'freshness', 'field' => 'lastReviewed', 'halfLifeDays' => 365],
		]);
		$schemaMapper = new class ($rules, $trace) {
			/**
			 * Remember the rules and the trace.
			 *
			 * @param array<int, mixed> $rules The rules.
			 * @param array<int, mixed> $trace The trace.
			 */
			public function __construct(private array $rules, private array &$trace) {
			}

			/**
			 * Find the schema.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return object
			 */
			public function find(...$args): object {
				$this->trace[] = ['schemaMapper.find', $args];
				$rules = $this->rules;
				return new class ($rules) {
					/**
					 * Keep the rules.
					 *
					 * @param array<int, mixed> $rules The rules.
					 */
					public function __construct(private array $rules) {
					}

					/**
					 * The configuration.
					 *
					 * @return array<string, mixed>
					 */
					public function getConfiguration(): array {
						return ['x-openregister-quality' => ['rules' => $this->rules]];
					}

					/**
					 * The properties.
					 *
					 * @return array<string, mixed>
					 */
					public function getProperties(): array {
						return ['businessOwner' => ['title' => 'Business owner'], 'module' => ['title' => 'Application']];
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $name) use (&$trace, $schemaMapper, $objectService) {
				$trace[] = ['container.get', $name];
				return str_ends_with($name, 'SchemaMapper') ? $schemaMapper : $objectService;
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister']));
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(($case['config'] ?? ['register' => '1', 'gebruik_schema' => '20']));

		$service = new DataQualityReportService(
			settingsService: $settings,
			appManager: $appManager,
			container: $container,
			logger: new NullLogger(),
			derivation: new DataQualityDerivation(),
			relations: new PortfolioReportDerivation()
		);
		try {
			$report = $service->build(organisationUuid: ($case['organisation'] ?? 'zuiddrecht'));
			$report['generatedAt'] = (preg_match('/^\d{4}-\d\d-\d\dT/', $report['generatedAt']) === 1) ? 'ATOM' : $report['generatedAt'];
			$outcome = ['report' => $report];
		} catch (\Throwable $e) {
			$outcome = ['refused' => get_class($e) . ': ' . $e->getMessage()];
		}

		return $outcome + ['trace' => $trace];
	}//end record()

	/**
	 * Every case matches the recorded outcome.
	 *
	 * @return void
	 */
	public function testBuildMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			$lines = [];
			foreach ($actual as $case => $row) {
				$lines[] = json_encode($case) . ': ' . json_encode($row, JSON_UNESCAPED_SLASHES);
			}

			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
		}

		$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame($golden, json_decode((string) json_encode($actual), true));

	}//end testBuildMatchesGolden()
}//end class
