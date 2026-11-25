<?php

/**
 * Every lifecycle schema defaults its status to the lifecycle's initial state.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * OpenRegister starts a new record in its lifecycle's `initial` state and refuses
 * any other status on create (`lifecycle-invalid-initial`, HTTP 422). A schema
 * whose status default names a later state therefore refuses every record that
 * is created without naming its status: the Add forms prefill the default, and
 * an API client that leaves the field out gets it filled in. On a live instance
 * (25 Nov 2026) that refused new usages, connections, versions, AI systems,
 * technology components and APIs.
 *
 * The check runs on the REAL register with every `register.d` fragment merged
 * the way SettingsService merges it, so a later fragment cannot undo it.
 */
class LifecycleInitialDefaultTest extends TestCase {

	/**
	 * The register JSON with every fragment merged over it, highest schema versions kept.
	 *
	 * @return array<mixed> The merged register.
	 */
	private function mergedRegister(): array {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string) file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'), true);
		$this->assertIsArray(actual: $base, message: 'the register JSON must parse');

		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep  = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');

		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string) file_get_contents($fragment), true);
			$this->assertIsArray(actual: $overlay, message: basename($fragment).' must parse');
			$base = $keep->invoke(null, $base, $merge->invoke(null, $base, $overlay));
		}

		return $base;
	}//end mergedRegister()

	/**
	 * A status default, where a lifecycle schema has one, is the lifecycle's initial state.
	 *
	 * @return void
	 */
	public function testEveryLifecycleStatusDefaultIsTheInitialState(): void {
		$wrong   = [];
		$checked = 0;
		foreach ($this->mergedRegister()['components']['schemas'] as $slug => $schema) {
			$lifecycle = ($schema['configuration']['x-openregister-lifecycle'] ?? null);
			if (is_array($lifecycle) === false || isset($lifecycle['initial']) === false) {
				continue;
			}

			$checked++;
			$field   = ($lifecycle['field'] ?? 'status');
			$default = ($schema['properties'][$field]['default'] ?? null);
			if ($default !== null && $default !== $lifecycle['initial']) {
				$wrong[] = $slug.': default '.json_encode($default).', initial '.json_encode($lifecycle['initial']);
			}
		}

		$this->assertGreaterThan(expected: 10, actual: $checked, message: 'the lifecycle schemas must be found');
		$this->assertSame(expected: [], actual: $wrong);
	}//end testEveryLifecycleStatusDefaultIsTheInitialState()

	/**
	 * The initial state is one of the status values, so the default is a valid value too.
	 *
	 * @return void
	 */
	public function testEveryInitialStateIsAStatusValue(): void {
		foreach ($this->mergedRegister()['components']['schemas'] as $slug => $schema) {
			$lifecycle = ($schema['configuration']['x-openregister-lifecycle'] ?? null);
			$field     = ($lifecycle['field'] ?? 'status');
			$values    = ($schema['properties'][$field]['enum'] ?? null);
			if (is_array($lifecycle) === false || isset($lifecycle['initial']) === false || is_array($values) === false) {
				continue;
			}

			$this->assertContains(needle: $lifecycle['initial'], haystack: $values, message: $slug);
		}
	}//end testEveryInitialStateIsAStatusValue()
}//end class
