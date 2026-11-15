<?php

/**
 * Golden test for ReferenceComponentCoverageService::build().
 *
 * Records the exact reads the coverage makes (the organisation-scoped
 * usage query with its rbac/multitenancy flags, the module and component
 * reads), and the coverage or the refusal it ends in.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\ReferenceComponentCoverageDerivation;
use OCA\Stackiq\Service\ReferenceComponentCoverageService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Pins the coverage's read scope and output.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
final class ReferenceComponentCoverageBuildGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/coverage/build.json';

	private const CONFIG = ['register' => '1', 'gebruik_schema' => '20', 'module_schema' => '22'];

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		return [
			'no openregister'    => ['apps' => ['files']],
			'no register'        => ['config' => ['gebruik_schema' => '20']],
			'no usage schema'    => ['config' => ['register' => '1', 'gebruik_schema' => 'x']],
			'coverage'           => [],
			'other organisation' => ['organisation' => 'westerdam'],
			'truncated'          => ['usageTotal' => 900],
			'no total'           => ['usageTotal' => null],
			'no module schema'   => ['config' => ['register' => '1', 'gebruik_schema' => '20']],
			'no gemma model'     => ['amef' => []],
			'no usages'          => ['usages' => []],
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
		$trace   = [];
		$usages  = ($case['usages'] ?? [
			['id' => 'u1', 'module' => 'm1', 'status' => 'In production', 'usedForReferenceComponents' => ['zrc']],
			['id' => 'u2', 'module' => ['id' => 'm2']],
			['id' => 'u3', 'module' => 'm1'],
		]);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (...$args) use (&$trace, $case, $usages): array {
				$trace[] = ['searchObjectsPaginated', $args];
				$total   = array_key_exists('usageTotal', $case) === true ? $case['usageTotal'] : count($usages);
				return match ($args[0]['@self']['schema']) {
					20 => array_filter(['results' => $usages, 'total' => $total], static fn ($v): bool => $v !== null),
					22 => ['results' => [['id' => 'm1', 'name' => 'Mozard', 'referenceComponents' => ['zrc', 'doc']], ['@self' => ['id' => 'm2'], 'name' => 'Djuma']]],
					30 => ['results' => [['id' => 'zrc', 'name' => 'Zaakregistratiecomponent'], ['id' => 'doc', 'name' => 'Documentbeheercomponent']]],
				};
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use (&$trace, $objects) {
			$trace[] = ['container.get', $id];
			return $objects;
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister']));
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(($case['config'] ?? self::CONFIG));
		$settings->method('getAmefConfig')->willReturn(($case['amef'] ?? ['register' => '2', 'element_schema' => '30']));
		$relations = new PortfolioReportDerivation();

		$service = new ReferenceComponentCoverageService(
			settingsService: $settings,
			appManager: $apps,
			container: $container,
			logger: new NullLogger(),
			derivation: new ReferenceComponentCoverageDerivation($relations),
			relations: $relations
		);
		try {
			$coverage = $service->build(organisationUuid: ($case['organisation'] ?? 'zuiddrecht'));
			$coverage['generatedAt'] = (preg_match('/^\d{4}-\d\d-\d\dT/', $coverage['generatedAt']) === 1) ? 'ATOM' : $coverage['generatedAt'];
			$outcome = ['coverage' => $coverage];
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
		$lines  = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
			$lines[]       = json_encode($name) . ': ' . json_encode($actual[$name], JSON_UNESCAPED_SLASHES);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));

	}//end testBuildMatchesGolden()
}//end class
