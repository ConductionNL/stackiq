<?php

/**
 * Characterisation test for ReferenceComponentCoverageService::buildCsv().
 *
 * Pins the exact CSV document (header, one row per reference component, the
 * fillable column and the joined application names) before the row building
 * is split into its own step.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
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
 * Tests for the coverage CSV.
 */
final class ReferenceComponentCoverageCsvTest extends TestCase {

	/**
	 * The CSV of a small coverage, including a cell that needs the formula guard.
	 *
	 * @return void
	 */
	public function testCsvDocument(): void {
		$usages  = [
			['id' => 'u1', 'module' => 'm1', 'status' => 'In production', 'usedForReferenceComponents' => ['zrc']],
			['id' => 'u2', 'module' => ['id' => 'm2'], 'status' => 'In production', 'usedForReferenceComponents' => ['mail']],
		];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (...$args) use ($usages): array {
				return match ($args[0]['@self']['schema']) {
					20 => ['results' => $usages, 'total' => count($usages)],
					22 => ['results' => [['id' => 'm1', 'name' => 'Mozard', 'referenceComponents' => ['zrc', 'doc']], ['@self' => ['id' => 'm2'], 'name' => '=Djuma', 'referenceComponents' => ['mail']]]],
					30 => ['results' => [['id' => 'zrc', 'name' => 'Zaakregistratiecomponent'], ['id' => 'doc', 'name' => 'Documentbeheercomponent'], ['id' => 'mail', 'name' => 'Mail, "post"']]],
				};
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => '1', 'gebruik_schema' => '20', 'module_schema' => '22']);
		$settings->method('getAmefConfig')->willReturn(['register' => '2', 'element_schema' => '30']);
		$relations = new PortfolioReportDerivation();

		$service = new ReferenceComponentCoverageService(
			settingsService: $settings,
			appManager: $apps,
			container: $container,
			logger: new NullLogger(),
			derivation: new ReferenceComponentCoverageDerivation($relations),
			relations: $relations
		);

		$this->assertSame(
			"group,referenceComponent,state,fillable,applications\n"
			. ",Documentbeheercomponent,gap,yes,\n"
			. ",\"Mail, \"\"post\"\"\",covered,no,'=Djuma\n"
			. ",Zaakregistratiecomponent,covered,no,Mozard\n",
			$service->buildCsv(organisationUuid: 'zuiddrecht')
		);

	}//end testCsvDocument()
}//end class
