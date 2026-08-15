<?php

/**
 * Verification tests for the `gebruik`, `koppeling`, and `organisatie`
 * schemas' OpenRegister RBAC read rules (vendor-visibility-rbac REQ-008,
 * schema-rbac-hardening).
 *
 * Neither `koppeling` nor `organisatie` has an app-local controller, so a
 * read of either schema through OpenRegister's standard (non-`_rbac:false`)
 * object API is gated solely by this schema config. Before this change, all
 * three schemas granted `gebruik-beheerder` an unscoped read, letting any
 * `gebruik-beheerder` in any organisation read every other organisation's
 * koppelingen and organisaties (and, were a future code path ever to read
 * `gebruik` via the standard RBAC-enabled API, every other organisation's
 * gebruik too).
 *
 * As with `ContractRbacTest`, a live OpenRegister RBAC engine is not
 * available in this sandboxed unit-test environment, so these tests assert
 * on the deployed rule's *shape*: no bare `gebruik-beheerder` grant remains,
 * and the replacement grant(s) are match-scoped to the caller's own
 * organisation via the `_organisation` OR-stamped multitenancy field (and,
 * for `gebruik`, additionally via the `afnemer` ownership field — see
 * design.md Decision 1).
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-gebruik-koppeling-and-organisatie-schema-level-rbac-reads-must-deny-cross-organisation-access-for-gebruik-beheerder-req-008
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the `gebruik`, `koppeling`, and `organisatie` schemas'
 * `authorization.read` rules.
 */
class SchemaRbacTest extends TestCase {

	/**
	 * Load a schema's `authorization` block from the real, deployed
	 * register config — not a fixture — so this test fails the moment the
	 * shipped config regresses.
	 *
	 * @param string $schemaName The schema to load (gebruik, koppeling,
	 *                           organisatie).
	 *
	 * @return array<string,mixed>
	 */
	private function loadAuthorization(string $schemaName): array {
		$path = __DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json';
		$this->assertFileExists($path, 'softwarecatalogus_register.json must exist');

		$config = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

		$this->assertArrayHasKey($schemaName, $config['components']['schemas']);
		$this->assertArrayHasKey('authorization', $config['components']['schemas'][$schemaName]);

		return $config['components']['schemas'][$schemaName]['authorization'];
	}//end loadAuthorization()

	/**
	 * Data provider of the three schemas this requirement covers.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function schemaProvider(): array {
		return [
			'usage' => ['usage'],
			'connection' => ['connection'],
			'organisatie' => ['organisatie'],
		];

	}//end schemaProvider()

	/**
	 * REQ-008 / TC-1,2,3: `gebruik-beheerder` MUST NOT be a bare unscoped
	 * read grant on any of the three schemas.
	 *
	 * @dataProvider schemaProvider
	 *
	 * @param string $schemaName The schema under test.
	 *
	 * @return void
	 */
	public function testGebruikBeheerderReadIsNotBare(string $schemaName): void {
		$authorization = $this->loadAuthorization($schemaName);

		foreach ($authorization['read'] as $entry) {
			$this->assertNotSame(
				'gebruik-beheerder',
				$entry,
				"gebruik-beheerder MUST NOT be a bare unscoped read grant on {$schemaName} (REQ-008)"
			);
		}

	}//end testGebruikBeheerderReadIsNotBare()

	/**
	 * REQ-008 / TC-1,2,3: `gebruik-beheerder` MUST have at least one
	 * match-scoped read grant on `_organisation` for every one of the three
	 * schemas.
	 *
	 * @dataProvider schemaProvider
	 *
	 * @param string $schemaName The schema under test.
	 *
	 * @return void
	 */
	public function testGebruikBeheerderReadIsOrganisationScoped(string $schemaName): void {
		$authorization = $this->loadAuthorization($schemaName);

		$organisationScoped = array_values(
			array_filter(
				$authorization['read'],
				static function ($entry) {
					return is_array($entry) === true
						&& ($entry['group'] ?? null) === 'gebruik-beheerder'
						&& ($entry['match']['_organisation'] ?? null) === '$organisation';
				}
			)
		);

		$this->assertNotEmpty(
			$organisationScoped,
			"gebruik-beheerder MUST have an _organisation-scoped read grant on {$schemaName} (REQ-008)"
		);

	}//end testGebruikBeheerderReadIsOrganisationScoped()

	/**
	 * REQ-008 / Decision 1: `gebruik` specifically MUST also grant
	 * `gebruik-beheerder` a match-scoped read on `afnemer`, so a
	 * municipality retains visibility of gebruik records it is the afnemer
	 * on even when a different session/organisation created the record.
	 *
	 * @return void
	 */
	public function testGebruikBeheerderReadOnGebruikIsAlsoAfnemerScoped(): void {
		$authorization = $this->loadAuthorization('usage');

		$consumerScoped = array_values(
			array_filter(
				$authorization['read'],
				static function ($entry) {
					return is_array($entry) === true
						&& ($entry['group'] ?? null) === 'gebruik-beheerder'
						&& ($entry['match']['consumer'] ?? null) === '$organisation';
				}
			)
		);

		$this->assertNotEmpty(
			$consumerScoped,
			'gebruik-beheerder MUST have an afnemer-scoped read grant on gebruik (REQ-008, Decision 1)'
		);

	}//end testGebruikBeheerderReadOnGebruikIsAlsoAfnemerScoped()

	/**
	 * REQ-008: the pre-existing `aanbod-beheerder` scoped grants on
	 * `gebruik` and `koppeling` (REQ-002) MUST be untouched by this change.
	 *
	 * @return void
	 */
	public function testAanbodBeheerderGrantsAreUntouchedOnGebruikAndKoppeling(): void {
		foreach (['usage', 'connection'] as $schemaName) {
			$authorization = $this->loadAuthorization($schemaName);

			$organisationScoped = array_values(
				array_filter(
					$authorization['read'],
					static function ($entry) {
						return is_array($entry) === true
							&& ($entry['group'] ?? null) === 'aanbod-beheerder'
							&& ($entry['match']['_organisation'] ?? null) === '$organisation';
					}
				)
			);
			$providerScoped = array_values(
				array_filter(
					$authorization['read'],
					static function ($entry) {
						return is_array($entry) === true
							&& ($entry['group'] ?? null) === 'aanbod-beheerder'
							&& ($entry['match']['provider'] ?? null) === '$organisation';
					}
				)
			);

			$this->assertNotEmpty($organisationScoped, "aanbod-beheerder _organisation grant missing on {$schemaName}");
			$this->assertNotEmpty($providerScoped, "aanbod-beheerder aanbieder grant missing on {$schemaName}");
		}

	}//end testAanbodBeheerderGrantsAreUntouchedOnGebruikAndKoppeling()

	/**
	 * REQ-008: the pre-existing `public` match rules on `organisatie` and
	 * `koppeling` (unrelated to this change) MUST be untouched.
	 *
	 * @return void
	 */
	public function testPublicGrantsAreUntouchedOnOrganisatieAndKoppeling(): void {
		$organisation = $this->loadAuthorization('organisatie');
		$integration = $this->loadAuthorization('connection');

		$publicEntries = array_values(
			array_filter(
				$organisation['read'],
				static function ($entry) {
					return is_array($entry) === true && ($entry['group'] ?? null) === 'public';
				}
			)
		);
		$this->assertCount(4, $publicEntries, 'organisatie MUST retain its 4 pre-existing public match rules');

		$publicEntriesIntegration = array_values(
			array_filter(
				$integration['read'],
				static function ($entry) {
					return is_array($entry) === true && ($entry['group'] ?? null) === 'public';
				}
			)
		);
		$this->assertCount(1, $publicEntriesIntegration, 'koppeling MUST retain its 1 pre-existing public match rule');

	}//end testPublicGrantsAreUntouchedOnOrganisatieAndKoppeling()

}//end class
