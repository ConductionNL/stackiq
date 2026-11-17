<?php

/**
 * The demo data seeds one likely duplicate application for OpenRegister's candidates page.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-002-the-catalogue-pages-shall-lead-an-administrator-to-openregisters-duplicate-candidates
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads lib/Settings/stackiq_mock_register.json and the reconciliation
 * fragment: the seeded pair matches on the fields the dedup rule compares,
 * and every seeded application and service starts Active.
 *
 * @coversNothing
 */
class RecordReconciliationSeedTest extends TestCase {

	/**
	 * The demo objects of the stackiq register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>> Slug to object.
	 */
	private static function seeds(string $schema): array {
		$mock = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/stackiq_mock_register.json'), true);
		$out  = [];
		foreach ($mock['components']['objects'] as $object) {
			if (($object['@self']['register'] ?? '') === 'stackiq' && ($object['@self']['schema'] ?? '') === $schema) {
				$out[$object['@self']['slug']] = $object;
			}
		}

		return $out;
	}//end seeds()

	/**
	 * The duplicate shares the original's provider and website and differs only in letter case in its name.
	 *
	 * @return void
	 */
	public function testTheSeededPairMatchesOnNameProviderAndWebsite(): void {
		$modules = self::seeds(schema: 'module');
		$this->assertArrayHasKey('module-duplicate-1', $modules);
		$this->assertArrayHasKey('module-voorbeeld-name-1-1', $modules);

		$original  = $modules['module-voorbeeld-name-1-1'];
		$duplicate = $modules['module-duplicate-1'];
		$this->assertNotSame($original['name'], $duplicate['name']);
		$this->assertSame(mb_strtolower($original['name']), mb_strtolower($duplicate['name']));
		$this->assertSame($original['provider'], $duplicate['provider']);
		$this->assertSame($original['website'], $duplicate['website']);
	}//end testTheSeededPairMatchesOnNameProviderAndWebsite()

	/**
	 * Every seeded application and service is Active, a value the fragment's enum allows.
	 *
	 * @return void
	 */
	public function testEverySeededApplicationAndServiceIsActive(): void {
		$fragment = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/operations-record-reconciliation.json'), true);
		foreach (['module', 'catalogService'] as $schema) {
			$enum = $fragment['components']['schemas'][$schema]['properties']['recordStatus']['enum'];
			$this->assertContains('Active', $enum);
			$seeds = self::seeds(schema: $schema);
			$this->assertNotEmpty($seeds);
			foreach ($seeds as $slug => $object) {
				$this->assertSame('Active', $object['recordStatus'] ?? null, $slug . ' has no Active record status');
			}
		}
	}//end testEverySeededApplicationAndServiceIsActive()
}//end class
