<?php

/**
 * The contact person carries the access review, as the merged register declares it.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
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
 * Merges every fragment the way the loader does and reads contactPerson.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
 */
final class AccessReviewDeclarationTest extends TestCase {

	/**
	 * The register with every fragment merged.
	 *
	 * @return array<string, mixed>
	 */
	private function merged(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $keep->invoke(null, $before, $merge->invoke(null, $merged, json_decode((string) file_get_contents($file), true)));
		}

		return $merged;
	}//end merged()

	/**
	 * The merged contactPerson has the two review fields, keeps its own, and its version rose.
	 *
	 * @return void
	 */
	public function testContactPersonCarriesTheReview(): void {
		$base = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);
		$contactPerson = $this->merged()['components']['schemas']['contactPerson'];

		$this->assertSame('date-time', $contactPerson['properties']['accessReviewedAt']['format']);
		$this->assertSame('string', $contactPerson['properties']['accessReviewedBy']['type']);
		foreach (array_keys($base['components']['schemas']['contactPerson']['properties']) as $existing) {
			$this->assertArrayHasKey($existing, $contactPerson['properties'], $existing . ' must survive the merge');
		}

		$this->assertTrue(
			version_compare($contactPerson['version'], $base['components']['schemas']['contactPerson']['version'], '>'),
			'Without a higher version an existing install skips the import and never gets the fields.'
		);
		$this->assertSame('0.0.30', $contactPerson['version']);
	}//end testContactPersonCarriesTheReview()

	/**
	 * Only an admin sets the review fields through the plain object API, so nobody marks themselves reviewed.
	 *
	 * @return void
	 */
	public function testOnlyAnAdminWritesTheReviewDirectly(): void {
		$properties = $this->merged()['components']['schemas']['contactPerson']['properties'];

		foreach (['accessReviewedAt', 'accessReviewedBy'] as $field) {
			$this->assertSame(['admin'], $properties[$field]['authorization']['update'], $field);
			$this->assertSame(['admin'], $properties[$field]['authorization']['create'], $field);
			$this->assertTrue($properties[$field]['hideOnForm'], $field);
		}
	}//end testOnlyAnAdminWritesTheReviewDirectly()

	/**
	 * The fragment touches only contactPerson.
	 *
	 * @return void
	 */
	public function testTheFragmentTouchesOnlyContactPerson(): void {
		$fragment = json_decode(
			(string) file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/organisations-role-mapping-and-access-review.json'),
			true
		);

		$this->assertSame(['contactPerson'], array_keys($fragment['components']['schemas']));
		$this->assertEqualsCanonicalizing(['_comment', 'components'], array_keys($fragment));
	}//end testTheFragmentTouchesOnlyContactPerson()
}//end class
