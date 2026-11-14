<?php

/**
 * Shape of the process and processStep schemas the architecture-process-mapping
 * fragment adds to the stackiq register, read from the register as it loads.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-003-a-step-shall-carry-a-risk-level-and-a-compliance-check
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProcessMappingRegisterShapeTest extends TestCase {

	/**
	 * The register with every fragment merged, as SettingsService::loadSettings() builds it.
	 *
	 * @return array<string,mixed>
	 */
	private function merged(): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$files  = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string) file_get_contents($file), true);
			$this->assertIsArray($fragment, basename($file) . ' must parse');
			$merged = $merge->invoke(null, $merged, $fragment);
		}

		return $merged;
	}//end merged()

	public function testTheStackiqRegisterListsBothSchemasWithMagicMapping(): void {
		$register = $this->merged()['components']['registers']['stackiq'];

		foreach (['process', 'processStep'] as $slug) {
			$this->assertContains($slug, $register['schemas']);
			$this->assertSame(['magicMapping' => true, 'autoCreateTable' => true], $register['configuration']['schemas'][$slug]);
		}

		// The fragment adds to the list; it does not replace it.
		$this->assertContains('usage', $register['schemas']);
		$this->assertSame(1, count(array_keys($register['schemas'], 'process', true)));
	}//end testTheStackiqRegisterListsBothSchemasWithMagicMapping()

	public function testRiskAndComplianceHaveTheDesignedEnumsDefaultsAndFacets(): void {
		$step = $this->merged()['components']['schemas']['processStep']['properties'];

		$this->assertSame(['not assessed', 'low', 'medium', 'high'], $step['riskLevel']['enum']);
		$this->assertSame('not assessed', $step['riskLevel']['default']);
		$this->assertTrue($step['riskLevel']['facetable']);
		$this->assertSame(['not checked', 'compliant', 'not compliant', 'not applicable'], $step['complianceCheck']['enum']);
		$this->assertSame('not checked', $step['complianceCheck']['default']);
		$this->assertTrue($step['complianceCheck']['facetable']);
		$this->assertSame(['task', 'event', 'decision'], $step['stepType']['enum']);
		$this->assertSame('integer', $step['position']['type']);
		$this->assertSame('#/components/schemas/process', $step['process']['$ref']);
		$this->assertSame('#/components/schemas/usage', $step['usages']['items']['$ref']);
		$this->assertSame('date', $step['checkedOn']['format']);
		foreach ($step as $name => $property) {
			$this->assertNotEmpty($property['title'] ?? '', "processStep.$name needs a title");
		}
	}//end testRiskAndComplianceHaveTheDesignedEnumsDefaultsAndFacets()

	public function testEveryLifecycleStateIsAStatusValue(): void {
		$process   = $this->merged()['components']['schemas']['process'];
		$lifecycle = $process['configuration']['x-openregister-lifecycle'];
		$enum      = $process['properties']['status']['enum'];

		$this->assertSame(['draft', 'active', 'retired'], $enum);
		$this->assertSame('draft', $process['properties']['status']['default']);
		$this->assertContains($lifecycle['initial'], $enum);
		$this->assertSame(['activate', 'retire', 'reopen'], array_keys($lifecycle['transitions']));
		foreach ($lifecycle['transitions'] as $name => $transition) {
			$this->assertContains($transition['to'], $enum, "$name to");
			foreach ($transition['from'] as $from) {
				$this->assertContains($from, $enum, "$name from");
			}
		}
	}//end testEveryLifecycleStateIsAStatusValue()

	public function testReadsMatchOnTheOrganisationAsUsageDoes(): void {
		$schemas = $this->merged()['components']['schemas'];

		foreach (['process', 'processStep'] as $slug) {
			$read = $schemas[$slug]['authorization']['read'];
			$scoped = array_filter($read, static fn ($rule): bool => is_array($rule) && ($rule['crossOrganisation'] ?? false) === false);
			$this->assertNotEmpty($scoped, $slug);
			foreach ($scoped as $rule) {
				$this->assertSame(['_organisation' => '$organisation'], $rule['match'], $slug . ' ' . $rule['group']);
			}

			foreach (['gebruik-beheerder', 'aanbod-beheerder'] as $group) {
				$this->assertContains(['group' => $group, 'match' => ['_organisation' => '$organisation']], $read, "$slug read for $group");
			}

			// Only the catalogue admins read across organisations.
			$cross = array_values(array_filter($read, static fn ($rule): bool => is_array($rule) && ($rule['crossOrganisation'] ?? false) === true));
			$this->assertSame([['group' => 'software-catalog-admins', 'crossOrganisation' => true]], $cross, $slug);
		}
	}//end testReadsMatchOnTheOrganisationAsUsageDoes()

	public function testTheSeedHoldsTheProcessAndItsThreeOrderedSteps(): void {
		$objects = $this->merged()['components']['objects'];
		$slugs = static fn (string $schema): array => array_values(array_map(
			static fn (array $object): string => $object['@self']['slug'],
			array_filter($objects, static fn (array $object): bool => ($object['@self']['schema'] ?? '') === $schema)
		));

		$this->assertSame(['seed-proces-vergunningaanvraag'], $slugs('process'));
		$this->assertSame(['seed-stap-intake', 'seed-stap-toetsen', 'seed-stap-besluiten'], $slugs('processStep'));

		$usages = $slugs('usage');
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') !== 'processStep') {
				continue;
			}

			$this->assertSame('@ref:process:seed-proces-vergunningaanvraag', $object['process']);
			foreach ($object['usages'] as $usage) {
				$this->assertContains(substr($usage, strlen('@ref:usage:')), $usages, 'a seeded step names a seeded usage');
			}
		}
	}//end testTheSeedHoldsTheProcessAndItsThreeOrderedSteps()
}//end class
