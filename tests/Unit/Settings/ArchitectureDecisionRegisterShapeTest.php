<?php

/**
 * The architectureDecision schema as the import sees it: the monolith with
 * every register.d fragment merged in by SettingsService's own merge.
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
 * @spec openspec/changes/architecture-decision-register/tasks.md#task-1-register-fragment-with-lifecycle-and-notifications
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Lifecycle\ArchitectureDecisionReviewGuard;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges every register.d fragment the way loadSettings() does and reads the
 * architectureDecision schema, the stackiq register and the seed from the result.
 *
 * @coversNothing
 */
class ArchitectureDecisionRegisterShapeTest extends TestCase {

	/**
	 * The register configuration after every fragment is merged in.
	 *
	 * @return array<string, mixed> The merged configuration.
	 */
	private function merged(): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string) file_get_contents($file), true);
			$merged   = $merge->invoke(null, $merged, $fragment);
		}

		return $merged;
	}//end merged()

	/**
	 * The architectureDecision schema.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(): array {
		$schemas = $this->merged()['components']['schemas'];
		$this->assertArrayHasKey(key: 'architectureDecision', array: $schemas);

		return $schemas['architectureDecision'];
	}//end schema()

	/**
	 * The stackiq register lists the schema with magic mapping, and keeps the ones it had.
	 *
	 * @return void
	 */
	public function testTheStackiqRegisterListsTheSchemaWithMagicMapping(): void {
		$register = $this->merged()['components']['registers']['stackiq'];

		$this->assertContains(needle: 'architectureDecision', haystack: $register['schemas']);
		$this->assertContains(needle: 'usage', haystack: $register['schemas']);
		$this->assertSame(expected: count(value: $register['schemas']), actual: count(value: array_unique(array: $register['schemas'])));
		$this->assertSame(
			expected: ['magicMapping' => true, 'autoCreateTable' => true],
			actual: $register['configuration']['schemas']['architectureDecision']
		);
	}//end testTheStackiqRegisterListsTheSchemaWithMagicMapping()

	/**
	 * The record holds the fields of REQ-ADREG-001, with facetable classification.
	 *
	 * @return void
	 */
	public function testTheRecordHoldsTheDecisionFields(): void {
		$props = $this->schema()['properties'];

		$this->assertSame(expected: ['title'], actual: $this->schema()['required']);
		$this->assertSame(
			expected: ['application', 'data', 'integration', 'infrastructure', 'security', 'standards'],
			actual: $props['category']['enum']
		);
		$this->assertSame(expected: ['low', 'medium', 'high'], actual: $props['impact']['enum']);
		$this->assertSame(
			expected: ['draft', 'in review', 'accepted', 'rejected', 'superseded', 'deprecated'],
			actual: $props['status']['enum']
		);
		$this->assertSame(expected: 'draft', actual: $props['status']['default']);
		foreach (['category', 'impact', 'status'] as $field) {
			$this->assertTrue(condition: $props[$field]['facetable'], message: $field);
			$this->assertSame(expected: $props[$field]['enum'], actual: array_keys(array: $props[$field]['x-enum-labels']), message: $field);
		}

		$this->assertSame(expected: ['option', 'reasonRejected'], actual: array_keys(array: $props['alternatives']['items']['properties']));
		$this->assertSame(expected: '#/components/schemas/usage', actual: $props['applications']['items']['$ref']);
		$this->assertSame(expected: '#/components/schemas/element', actual: $props['elements']['items']['$ref']);
		$this->assertSame(expected: '#/components/schemas/architectureDecision', actual: $props['supersedes']['$ref']);
		$this->assertSame(expected: '#/components/schemas/architectureDecision', actual: $props['supersededBy']['$ref']);
		$this->assertSame(expected: 'date', actual: $props['decidedOn']['format']);
		$this->assertSame(expected: 'string', actual: $props['reviewer']['type']);
	}//end testTheRecordHoldsTheDecisionFields()

	/**
	 * Every rule reads only the organisation's own decisions.
	 *
	 * @return void
	 */
	public function testReadRulesMatchOnTheOrganisation(): void {
		$read = $this->schema()['authorization']['read'];

		$this->assertNotEmpty(actual: $read);
		foreach ($read as $rule) {
			$this->assertSame(expected: ['_organisation' => '$organisation'], actual: $rule['match'], message: $rule['group']);
			$this->assertArrayNotHasKey(key: 'crossOrganisation', array: $rule, message: $rule['group']);
		}
	}//end testReadRulesMatchOnTheOrganisation()

	/**
	 * The lifecycle names only status values and the guard class by its exact name.
	 *
	 * @return void
	 */
	public function testTheLifecycleUsesEnumValuesAndTheGuardClass(): void {
		$lifecycle = $this->schema()['configuration']['x-openregister-lifecycle'];
		$statuses  = $this->schema()['properties']['status']['enum'];

		$this->assertSame(expected: 'status', actual: $lifecycle['field']);
		$this->assertContains(needle: $lifecycle['initial'], haystack: $statuses);
		$this->assertSame(
			expected: ['submit', 'accept', 'reject', 'rework', 'supersede', 'deprecate'],
			actual: array_keys($lifecycle['transitions'])
		);
		foreach ($lifecycle['final'] as $final) {
			$this->assertContains(needle: $final, haystack: $statuses);
		}

		$guarded = [];
		foreach ($lifecycle['transitions'] as $action => $transition) {
			$this->assertContains(needle: $transition['to'], haystack: $statuses, message: $action);
			foreach ($transition['from'] as $from) {
				$this->assertContains(needle: $from, haystack: $statuses, message: $action);
			}

			if (isset($transition['requires']) === true) {
				$this->assertSame(expected: ArchitectureDecisionReviewGuard::class, actual: $transition['requires'], message: $action);
				$guarded[] = $action;
			}
		}

		$this->assertSame(expected: ['submit', 'accept', 'reject', 'supersede'], actual: $guarded);
		$this->assertTrue(condition: class_exists(class: $lifecycle['transitions']['submit']['requires']));
	}//end testTheLifecycleUsesEnumValuesAndTheGuardClass()

	/**
	 * The reviewer is told when a decision enters review, the owners when it is concluded.
	 *
	 * @return void
	 */
	public function testNotificationsGoToTheReviewerAndTheOwners(): void {
		$rules = $this->schema()['configuration']['x-openregister-notifications'];

		$this->assertSame(expected: ['review-requested', 'review-accepted', 'review-rejected'], actual: array_keys(array: $rules));
		$this->assertSame(expected: [['kind' => 'field', 'field' => 'reviewer']], actual: $rules['review-requested']['recipients']);
		$this->assertSame(expected: 'in review', actual: $rules['review-requested']['trigger']['condition']['value']);
		foreach (['accepted', 'rejected'] as $status) {
			$rule = $rules['review-' . $status];
			$this->assertSame(expected: [['kind' => 'object-acl', 'permission' => 'manage']], actual: $rule['recipients']);
			$this->assertSame(expected: $status, actual: $rule['trigger']['condition']['value']);
			$this->assertSame(expected: 'in review', actual: $rule['trigger']['condition']['from']);
		}

		foreach ($rules as $key => $rule) {
			$this->assertSame(expected: 'updated', actual: $rule['trigger']['type'], message: $key);
			$this->assertSame(expected: 'status', actual: $rule['trigger']['condition']['field'], message: $key);
			$this->assertSame(expected: ['nc-notification', 'email'], actual: $rule['channels'], message: $key);
			$this->assertSame(expected: ['nl', 'en'], actual: array_keys(array: $rule['subject']), message: $key);
		}
	}//end testNotificationsGoToTheReviewerAndTheOwners()

	/**
	 * Board decisions are decidiq ids, shaped as catalogContract.decisions.
	 *
	 * @return void
	 */
	public function testBoardDecisionsMatchTheContractDecisionLink(): void {
		$schemas  = $this->merged()['components']['schemas'];
		$board    = $schemas['architectureDecision']['properties']['boardDecisions'];
		$contract = $schemas['catalogContract']['properties']['decisions'];

		foreach (['type', 'x-external-register', 'items', 'referenceType'] as $key) {
			$this->assertSame(expected: $contract[$key], actual: $board[$key], message: $key);
		}
	}//end testBoardDecisionsMatchTheContractDecisionLink()

	/**
	 * The two demo decisions are seeded, the accepted one linked to a seeded usage.
	 *
	 * @return void
	 */
	public function testTheTwoDemoDecisionsAreSeeded(): void {
		$objects = $this->merged()['components']['objects'];
		$seeded  = [];
		$usages  = [];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') === 'architectureDecision') {
				$seeded[$object['@self']['slug']] = $object;
			}

			if (($object['@self']['schema'] ?? '') === 'usage') {
				$usages[] = $object['@self']['slug'];
			}
		}

		$this->assertSame(expected: ['seed-ab-zaakgericht-werken', 'seed-ab-api-first'], actual: array_keys(array: $seeded));
		$this->assertSame(expected: 'accepted', actual: $seeded['seed-ab-zaakgericht-werken']['status']);
		$this->assertSame(expected: 'in review', actual: $seeded['seed-ab-api-first']['status']);
		$this->assertSame(expected: ['@ref:usage:gebruik-suite4-gem-delft-eigenaar'], actual: $seeded['seed-ab-zaakgericht-werken']['applications']);
		$this->assertContains(needle: 'gebruik-suite4-gem-delft-eigenaar', haystack: $usages);
	}//end testTheTwoDemoDecisionsAreSeeded()
}//end class
