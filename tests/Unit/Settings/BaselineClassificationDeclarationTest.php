<?php

/**
 * The usage schema declares the baseline classification, readable and writable only by the owning organisation.
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
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-001-an-organisation-shall-classify-each-application-it-uses-on-availability-integrity-and-confidentiality
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\UsageClassification;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-BCL-001: nine properties on usage, with the owning-organisation rule.
 *
 * @coversNothing
 */
final class BaselineClassificationDeclarationTest extends TestCase {

	/**
	 * The nine properties.
	 */
	private const PROPERTIES = [
		'availabilityLevel', 'availabilityReason', 'integrityLevel', 'integrityReason', 'confidentialityLevel',
		'confidentialityReason', 'bbnLevel', 'classifiedAt', 'classifiedBy',
	];

	/**
	 * The register with every fragment merged the way the loader merges them.
	 *
	 * @return array<string,mixed>
	 */
	private function merged(): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep   = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true));
			$merged = $keep->invoke(null, $before, $merged);
		}

		return $merged;
	}//end merged()

	public function testUsageHasTheNinePropertiesEachForTheOwningOrganisationOnly(): void {
		$usage = $this->merged()['components']['schemas']['usage'];
		$rule  = [['group' => 'public', 'match' => ['_organisation' => '$organisation']]];

		foreach (self::PROPERTIES as $name) {
			$this->assertArrayHasKey(key: $name, array: $usage['properties'], message: $name);
			$this->assertSame(
				expected: ['read' => $rule, 'update' => $rule],
				actual: $usage['properties'][$name]['authorization'],
				message: $name . ' is the owning organisation\'s'
			);
		}

		$this->assertSame(
			expected: $usage['properties']['interneAnnotation']['authorization'],
			actual: $usage['properties']['availabilityLevel']['authorization'],
			message: 'the rule usage.interneAnnotation carries'
		);
	}

	public function testTheLevelsAreTheBioBaselineLevelsAndTheDerivedOnesAreReadOnly(): void {
		$properties = $this->merged()['components']['schemas']['usage']['properties'];

		foreach (array_merge(UsageClassification::ASPECTS, ['bbnLevel']) as $name) {
			$this->assertSame(expected: UsageClassification::LEVELS, actual: $properties[$name]['enum'], message: $name);
		}

		foreach (['bbnLevel', 'classifiedAt', 'classifiedBy'] as $name) {
			$this->assertTrue(condition: $properties[$name]['readOnly'], message: $name . ' is set by the server');
		}

		$this->assertSame(expected: 'date-time', actual: $properties['classifiedAt']['format']);
		$this->assertTrue(condition: $properties['bbnLevel']['facetable'], message: 'the Gebruik list filters on it');
	}

	public function testTheUsageVersionRisesSoTheImportRuns(): void {
		$base = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);

		$this->assertTrue(
			condition: version_compare(
				$this->merged()['components']['schemas']['usage']['version'],
				$base['components']['schemas']['usage']['version'],
				'>'
			)
		);
		// The loader keeps the highest fragment version: operations-technology-components
		// raises usage to 1.6.1 on top of this change's 1.5.12, architecture-data-model-and-ggm
		// to 1.6.2 and shared-usage-visible-to-participants to 1.6.3; the merged schema carries
		// every fragment's properties.
		$this->assertSame(expected: '1.6.3', actual: $this->merged()['components']['schemas']['usage']['version']);
	}

	public function testTheFormLabelsAreDutchOnADutchInstance(): void {
		$nl = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];

		$this->assertSame(expected: 'Beschikbaarheid', actual: $nl['Availability']);
		$this->assertSame(expected: 'Integriteit', actual: $nl['Integrity']);
		$this->assertSame(expected: 'Vertrouwelijkheid', actual: $nl['Confidentiality']);
		foreach (['Availability reason', 'Integrity reason', 'Confidentiality reason', 'BBN level', 'Classified at', 'Classified by'] as $key) {
			$this->assertArrayHasKey(key: $key, array: $nl, message: $key);
		}
	}
}
