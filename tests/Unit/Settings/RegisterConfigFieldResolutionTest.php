<?php

/**
 * Guards the shipped register file against a config field that names a
 * property the schema does not declare.
 *
 * WHY THIS EXISTS
 * ---------------
 * OpenRegister's `SchemaMapper::validateConfigField()` THROWS when
 * `objectNameField` / `objectDescriptionField` names a property that is not in
 * the schema. `ImportHandler` catches that per schema and continues — but the
 * schema is then absent from the import's `schemasMap`, so the register that
 * references it is created **without that schema reference**, and
 * `SettingsService::configureVoorzieningen()` writes an EMPTY id for every
 * affected `*_schema` app-config key.
 *
 * The observable end state is not "one schema is missing". It is
 * `voorzieningen/config` returning `{"register":"14","organisatie_schema":"",…}`
 * and the whole e2e suite refusing to run. That happened on `development`
 * between 2026-08-14 18:53Z (last good run 31830739382) and 19:29Z (first bad
 * run 31833457673): commit 386771dc renamed the `view` schema's `summary`
 * property key to `omschrijving` while leaving `objectDescriptionField` on
 * `"summary"`, and separately rewrote `bioMeasure`'s
 * `objectDescriptionField` value from `"omschrijving"` to `"summary"`.
 * Two dangling references, fifteen detached schema links, zero Playwright
 * tests executed for two days.
 *
 * A JSON-schema check cannot see this: both files are valid JSON and valid
 * OpenAPI. Only the cross-reference between a configuration value and the
 * sibling `properties` map catches it, which is what this test does — using
 * OpenRegister's own three acceptance forms (Twig template, pipe-separated
 * fallback list, plain property name).
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every configuration field in the shipped register must resolve to a declared property.
 */
class RegisterConfigFieldResolutionTest extends TestCase {

	/**
	 * The configuration keys OpenRegister validates against the property map.
	 *
	 * `objectSummaryField` is deliberately NOT in this list: OpenRegister
	 * validates only these two (SchemaMapper.php, the two
	 * `validateConfigField()` call sites), so adding it here would fail the
	 * build on references OpenRegister accepts today. It is reported by
	 * testDanglingSummaryFieldsAreReportedNotEnforced() instead.
	 *
	 * @var array<int,string>
	 */
	private const VALIDATED_FIELDS = ['objectNameField', 'objectDescriptionField'];

	/**
	 * The decoded register file, loaded once in setUp().
	 *
	 * @var array<string,mixed>
	 */
	private array $register;

	/**
	 * Load and decode the register file once.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path = __DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json';
		$this->assertFileExists(filename: $path);
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray(actual: $decoded, message: 'register file must be valid JSON');
		$this->register = $decoded;
	}//end setUp()

	/**
	 * Decide whether a configuration value resolves against a property map.
	 *
	 * A transcription of OpenRegister's `SchemaMapper::validateConfigField()`.
	 * The three accepted forms, in the order OpenRegister tests them:
	 *  1. a Twig template — every `{{ prop }}` reference must exist;
	 *  2. a pipe-separated fallback list — AT LEAST ONE entry must exist;
	 *  3. a plain property name — it must exist.
	 *
	 * @param string $value The configuration value.
	 * @param array<int,string> $propertyKeys The schema's declared property keys.
	 *
	 * @return string|null The failure reason, or null when the value resolves.
	 */
	private function resolutionFailure(string $value, array $propertyKeys): ?string {
		if (str_contains($value, '{{') === true && str_contains($value, '}}') === true) {
			preg_match_all('/\{\{\s*([a-zA-Z0-9_-]+)\s*\}\}/', $value, $matches);
			$templateProps = ($matches[1] ?? []);
			if (empty($templateProps) === true) {
				return null;
			}

			foreach ($templateProps as $prop) {
				if (in_array($prop, $propertyKeys, true) === false) {
					return "template property '$prop' does not exist";
				}
			}

			return null;
		}

		if (str_contains($value, '|') === true) {
			foreach (array_map('trim', explode('|', $value)) as $fallback) {
				if (in_array($fallback, $propertyKeys, true) === true) {
					return null;
				}
			}

			return "none of the fallback fields in '$value' exist as properties";
		}

		if (in_array($value, $propertyKeys, true) === false) {
			return "'$value' does not exist as a property";
		}

		return null;
	}//end resolutionFailure()

	/**
	 * Every validated configuration field resolves to a declared property.
	 *
	 * @return void
	 */
	public function testEveryValidatedConfigFieldResolvesToADeclaredProperty(): void {
		$schemas = ($this->register['components']['schemas'] ?? []);
		$this->assertNotEmpty(actual: $schemas, message: 'the register must declare schemas');

		$checked = 0;
		$failures = [];
		foreach ($schemas as $slug => $schema) {
			$propertyKeys = array_keys(($schema['properties'] ?? []));
			foreach (self::VALIDATED_FIELDS as $field) {
				$value = (string)(($schema['configuration'] ?? [])[$field] ?? '');
				if ($value === '') {
					continue;
				}

				$checked++;
				$failure = $this->resolutionFailure(value: $value, propertyKeys: $propertyKeys);
				if ($failure !== null) {
					$failures[] = "$slug.$field: $failure";
				}
			}
		}

		// COUNT WHAT WAS MEASURED. An empty `$failures` is also what a run over
		// zero fields produces, and that is the shape this whole test exists to
		// stop being mistaken for a pass.
		$this->assertGreaterThan(
			expected: 30,
			actual: $checked,
			message: 'far fewer configuration fields were checked than this register declares — the traversal is wrong, not the data'
		);

		$this->assertSame(
			expected: [],
			actual: $failures,
			message: 'a configuration field names a property its schema does not declare. OpenRegister REJECTS the whole schema for this, '
				. "the register is then imported without that schema link, and every dependent app-config id is written empty:\n- "
				. implode("\n- ", $failures)
		);
	}//end testEveryValidatedConfigFieldResolvesToADeclaredProperty()

	/**
	 * POSITIVE CONTROL — the check above can actually fail.
	 *
	 * Replays the exact shape that broke `development` on 2026-08-14: a
	 * property key renamed out from under a configuration value that still
	 * names the old key. Without this, a traversal bug would make the test
	 * above green forever.
	 *
	 * @return void
	 */
	public function testTheResolutionCheckCanFail(): void {
		$this->assertNotNull(
			actual: $this->resolutionFailure(value: 'summary', propertyKeys: ['name', 'omschrijving']),
			message: 'a plain value naming an absent property must be reported'
		);
		$this->assertNotNull(
			actual: $this->resolutionFailure(value: '{{ gone }}', propertyKeys: ['name']),
			message: 'a template referencing an absent property must be reported'
		);
		$this->assertNotNull(
			actual: $this->resolutionFailure(value: 'gone | alsoGone', propertyKeys: ['name']),
			message: 'a fallback list with no surviving entry must be reported'
		);

		// And the three forms that OpenRegister accepts must NOT be reported,
		// or the test above would fail on data that imports perfectly well.
		$this->assertNull(actual: $this->resolutionFailure(value: 'name', propertyKeys: ['name']));
		$this->assertNull(actual: $this->resolutionFailure(value: '{{ name }}', propertyKeys: ['name']));
		$this->assertNull(actual: $this->resolutionFailure(value: 'gone | name', propertyKeys: ['name']));
	}//end testTheResolutionCheckCanFail()

	/**
	 * Every schema a register references is declared in the same file.
	 *
	 * The second half of the same failure: `ImportHandler` resolves a
	 * register's `schemas` list against the slugs it imported in that session,
	 * so a reference to a slug this file does not declare silently produces a
	 * register with a missing link rather than an error.
	 *
	 * @return void
	 */
	public function testEveryRegisterSchemaReferenceIsDeclared(): void {
		$schemas = ($this->register['components']['schemas'] ?? []);
		$registers = ($this->register['components']['registers'] ?? []);
		$this->assertNotEmpty(actual: $registers, message: 'the register file must declare registers');

		$declared = [];
		foreach ($schemas as $key => $schema) {
			$declared[] = (string)($schema['slug'] ?? $key);
		}

		$checked = 0;
		$dangling = [];
		foreach ($registers as $registerSlug => $register) {
			foreach (($register['schemas'] ?? []) as $reference) {
				$checked++;
				if (in_array((string)$reference, $declared, true) === false) {
					$dangling[] = "$registerSlug -> $reference";
				}
			}
		}

		$this->assertGreaterThan(
			expected: 10,
			actual: $checked,
			message: 'almost no register->schema references were checked — the traversal is wrong'
		);
		$this->assertSame(expected: [], actual: $dangling, message: 'a register references a schema this file does not declare');
	}//end testEveryRegisterSchemaReferenceIsDeclared()

	/**
	 * Records the `objectSummaryField` values that do not resolve.
	 *
	 * OpenRegister does NOT validate this key today, so a dangling value is
	 * inert rather than fatal — but it is the same defect one release away
	 * from being fatal, and leaving it undocumented is how the two
	 * `objectDescriptionField` references survived review. This asserts the
	 * known set rather than zero, so ADDING a new one fails the build while
	 * the existing debt stays visible and counted.
	 *
	 * @return void
	 */
	public function testDanglingSummaryFieldsAreReportedNotEnforced(): void {
		$dangling = [];
		foreach (($this->register['components']['schemas'] ?? []) as $slug => $schema) {
			$value = (string)(($schema['configuration'] ?? [])['objectSummaryField'] ?? '');
			if ($value === '') {
				continue;
			}

			if ($this->resolutionFailure(value: $value, propertyKeys: array_keys(($schema['properties'] ?? []))) !== null) {
				$dangling[] = "$slug: $value";
			}
		}

		sort($dangling);
		$this->assertSame(
			expected: ['element: summary', 'relation: summary'],
			actual: $dangling,
			message: 'the set of dangling objectSummaryField references changed. OpenRegister ignores this key today; '
				. 'if you added one, point it at a declared property instead'
		);
	}//end testDanglingSummaryFieldsAreReportedNotEnforced()
}//end class
