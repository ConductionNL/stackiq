<?php

/**
 * The contract expiry warning, checked against the contract schema it reads.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contracts-expiry-warning-fires/specs/contract-expiry-warning/spec.md#requirement-req-cew-001-the-contract-expiry-rule-must-match-active-and-expiring-contracts-whose-end-date-falls-within-90-days
 * @spec openspec/changes/contracts-expiry-warning-fires/specs/contract-expiry-warning/spec.md#requirement-req-cew-002-the-warning-must-name-the-contract-number-and-the-end-date
 * @spec openspec/changes/contracts-expiry-warning-fires/specs/contract-expiry-warning/spec.md#requirement-req-cew-003-the-warning-shall-come-once-per-end-date
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
 * The rule never fired because it asked for status `Actief`, a value no
 * contract holds since the enum became English, and OpenRegister compares
 * strictly. Nothing compared the rule with the schema, so nothing noticed.
 * This test does, on the register as the import sees it: the monolith with
 * every register.d fragment merged in by SettingsService's own merge.
 */
class ContractExpiryRuleTest extends TestCase {

	/**
	 * Keys of a scheduled filter that combine clauses instead of naming a field.
	 *
	 * @var array<int, string>
	 */
	private const COMBINATORS = ['and', 'or', 'not', 'anyOf', 'allOf'];

	/**
	 * The merged register, as loadSettings() builds it before the import.
	 *
	 * @return array<string, mixed>
	 */
	private static function register(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$register = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$fragments = glob($dir . '/register.d/*.json');
		sort($fragments);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		foreach ($fragments as $fragment) {
			$register = $merge->invoke(null, $register, json_decode((string) file_get_contents($fragment), true));
		}

		return $register;

	}//end register()

	/**
	 * The merged catalogContract schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function contractSchema(): array {
		return self::register()['components']['schemas']['catalogContract'];

	}//end contractSchema()

	/**
	 * The contract-expiry rule.
	 *
	 * @return array<string, mixed>
	 */
	private static function rule(): array {
		return self::contractSchema()['x-openregister-notifications']['contract-expiry'];

	}//end rule()

	/**
	 * Every name in a rule that is not a property of the schema: subject
	 * placeholders in every locale, filter fields and dedupe fields.
	 *
	 * @param array<string, mixed> $rule   The rule.
	 * @param array<string, mixed> $schema The schema it is declared on.
	 *
	 * @return array<int, string> The unknown names, prefixed with where they were found.
	 */
	private static function unknownNames(array $rule, array $schema): array {
		$properties = array_keys($schema['properties'] ?? []);
		$names = [];
		foreach (($rule['subject'] ?? []) as $locale => $subject) {
			preg_match_all('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', (string) $subject, $found);
			foreach ($found[1] as $placeholder) {
				$names[] = ['subject.' . $locale, $placeholder];
			}
		}

		foreach (array_keys($rule['trigger']['filter'] ?? []) as $field) {
			if (in_array($field, self::COMBINATORS, true) === false) {
				$names[] = ['filter', $field];
			}
		}

		foreach (($rule['trigger']['dedupeFields'] ?? []) as $field) {
			$names[] = ['dedupeFields', $field];
		}

		$unknown = [];
		foreach ($names as [$where, $name]) {
			if (in_array($name, $properties, true) === false) {
				$unknown[] = $where . ': ' . $name;
			}
		}

		return $unknown;

	}//end unknownNames()

	/**
	 * REQ-CEW-002: every placeholder, filter field and dedupe field is a contract property.
	 *
	 * @return void
	 */
	public function testEveryNameTheRuleReadsIsAContractProperty(): void {
		$this->assertSame([], self::unknownNames(self::rule(), self::contractSchema()));

	}//end testEveryNameTheRuleReadsIsAContractProperty()

	/**
	 * REQ-CEW-002: the checker names a placeholder the schema does not have.
	 *
	 * @return void
	 */
	public function testTheCheckerNamesAPlaceholderTheSchemaLacks(): void {
		$rule = self::rule();
		$rule['subject']['nl'] = 'Contract verloopt: {{contractNummer}} (einddatum {{endDate}})';
		$rule['trigger']['dedupeFields'] = ['eindDatum'];

		$this->assertSame(
			['subject.nl: contractNummer', 'dedupeFields: eindDatum'],
			self::unknownNames($rule, self::contractSchema())
		);

	}//end testTheCheckerNamesAPlaceholderTheSchemaLacks()

	/**
	 * REQ-CEW-001: the status clause lists Active and Expiring, strictly as the enum spells them.
	 *
	 * @return void
	 */
	public function testTheStatusClauseMatchesActiveAndExpiringContracts(): void {
		$clause = self::rule()['trigger']['filter']['status'];
		$enum = self::contractSchema()['properties']['status']['enum'];

		$this->assertSame(['operator' => 'in', 'values' => ['Active', 'Expiring']], $clause);
		$this->assertContains('Active', $enum, 'Active must be a status a contract can hold today');
		$this->assertNotContains('In negotiation', $clause['values'], 'a negotiation is not an expiry');
		foreach ($clause['values'] as $value) {
			$this->assertDoesNotMatchRegularExpression('/^(Actief|Verlopen|In onderhandeling)$/', $value, 'a Dutch status matches no row');
		}

	}//end testTheStatusClauseMatchesActiveAndExpiringContracts()

	/**
	 * REQ-CEW-001 and REQ-CEW-003: a daily scan, 90 days ahead, once per end date.
	 *
	 * @return void
	 */
	public function testTheWarningComes90DaysAheadOncePerEndDate(): void {
		$trigger = self::rule()['trigger'];

		$this->assertSame('scheduled', $trigger['type']);
		$this->assertSame(86400, $trigger['intervalSec']);
		$this->assertSame(['operator' => 'withinNext', 'value' => 'P90D'], $trigger['filter']['endDate']);
		$this->assertSame(['endDate'], $trigger['dedupeFields']);
		$this->assertTrue(self::rule()['enabled']);

	}//end testTheWarningComes90DaysAheadOncePerEndDate()

	/**
	 * REQ-CEW-002: the subject names the contract number and the end date in both locales.
	 *
	 * @return void
	 */
	public function testTheSubjectNamesTheContractNumberAndEndDate(): void {
		$this->assertSame(
			[
				'nl' => 'Contract verloopt: {{contractNumber}} (einddatum {{endDate}})',
				'en' => 'Contract expiring: {{contractNumber}} (end date {{endDate}})',
			],
			self::rule()['subject']
		);

	}//end testTheSubjectNamesTheContractNumberAndEndDate()

	/**
	 * The fix deploys: the contract schema and the register move past the versions already installed.
	 *
	 * @return void
	 */
	public function testTheVersionsMovePastTheInstalledOnes(): void {
		$register = self::register();

		// The loader keeps the highest version a schema carries across the
		// monolith and the fragments, so that is the version the import compares.
		$dir = __DIR__ . '/../../../lib/Settings';
		$versions = [];
		foreach (array_merge([$dir . '/softwarecatalogus_register.json'], glob($dir . '/register.d/*.json')) as $file) {
			$schema = (json_decode((string) file_get_contents($file), true)['components']['schemas']['catalogContract'] ?? []);
			if (isset($schema['version']) === true) {
				$versions[] = $schema['version'];
			}
		}

		usort($versions, 'version_compare');
		$this->assertTrue(version_compare((string) end($versions), '0.1.5', '>'), 'catalogContract must move past 0.1.5');
		$this->assertTrue(version_compare($register['info']['version'], '2.5.8', '>'), 'the register must move past 2.5.8');
		$this->assertStringContainsString('contracts-expiry-warning-fires', $register['info']['changelog']);

	}//end testTheVersionsMovePastTheInstalledOnes()
}//end class
