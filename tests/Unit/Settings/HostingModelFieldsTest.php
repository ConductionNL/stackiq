<?php

/**
 * An application run by a hosting party records it as IaaS or PaaS, with where it runs.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Settings
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/application-hosting-model/spec.md#requirement-an-application-run-by-a-hosting-party-records-it-as-iaas-or-paas-req-clr-020
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Reads the module schema as it is imported (monolith plus fragments).
 *
 * @coversNothing
 */
final class HostingModelFieldsTest extends TestCase {

	/**
	 * The merged module properties.
	 *
	 * @return array<string, mixed>
	 */
	private function properties(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $keep->invoke(null, $before, $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true)));
		}

		return $merged['components']['schemas']['module']['properties'];
	}//end properties()

	public function testHostingOffersIaasAndPaasNextToOnPremisesAndSaasAndIsFilterable(): void {
		$hosting = $this->properties()['cloudDienstverleningsmodel'];

		$this->assertSame('Hosting', $hosting['title']);
		$this->assertSame(['On-premises (self-managed)', 'IaaS', 'PaaS', 'SaaS'], $hosting['items']['enum']);
		$this->assertTrue($hosting['facetable']);
	}

	public function testTheHostingLocationAndJurisdictionRecordWhereThePartyRunsIt(): void {
		$properties = $this->properties();

		foreach (['hostingLocation', 'hostingJurisdiction'] as $field) {
			$this->assertContains('NL', $properties[$field]['enum'], $field);
			$this->assertTrue($properties[$field]['visible'], $field);
		}
	}
}
