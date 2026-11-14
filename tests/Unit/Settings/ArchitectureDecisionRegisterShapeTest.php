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
		$this->assertArrayHasKey('architectureDecision', $schemas);

		return $schemas['architectureDecision'];
	}//end schema()

	/**
	 * The stackiq register lists the schema with magic mapping, and keeps the ones it had.
	 *
	 * @return void
	 */
	public function testTheStackiqRegisterListsTheSchemaWithMagicMapping(): void {
		$register = $this->merged()['components']['registers']['stackiq'];

		$this->assertContains('architectureDecision', $register['schemas']);
		$this->assertContains('usage', $register['schemas']);
		$this->assertSame(count($register['schemas']), count(array_unique($register['schemas'])));
		$this->assertSame(
			['magicMapping' => true, 'autoCreateTable' => true],
			$register['configuration']['schemas']['architectureDecision']
		);
	}//end testTheStackiqRegisterListsTheSchemaWithMagicMapping()

	/**
	 * The record holds the fields of REQ-ADREG-001, with facetable classification.
	 *
	 * @return void
	 */
	public function testTheRecordHoldsTheDecisionFields(): void {
		$props = $this->schema()['properties'];

		$this->assertSame(['title'], $this->schema()['required']);
		$this->assertSame(
			['application', 'data', 'integration', 'infrastructure', 'security', 'standards'],
			$props['category']['enum']
		);
		$this->assertSame(['low', 'medium', 'high'], $props['impact']['enum']);
		$this->assertSame(
			['draft', 'in review', 'accepted', 'rejected', 'superseded', 'deprecated'],
			$props['status']['enum']
		);
		$this->assertSame('draft', $props['status']['default']);
		foreach (['category', 'impact', 'status'] as $field) {
			$this->assertTrue($props[$field]['facetable'], $field);
			$this->assertSame($props[$field]['enum'], array_keys($props[$field]['x-enum-labels']), $field);
		}

		$this->assertSame(['option', 'reasonRejected'], array_keys($props['alternatives']['items']['properties']));
		$this->assertSame('#/components/schemas/usage', $props['applications']['items']['$ref']);
		$this->assertSame('#/components/schemas/element', $props['elements']['items']['$ref']);
		$this->assertSame('#/components/schemas/architectureDecision', $props['supersedes']['$ref']);
		$this->assertSame('#/components/schemas/architectureDecision', $props['supersededBy']['$ref']);
		$this->assertSame('date', $props['decidedOn']['format']);
		$this->assertSame('string', $props['reviewer']['type']);
	}//end testTheRecordHoldsTheDecisionFields()

	/**
	 * Every rule reads only the organisation's own decisions.
	 *
	 * @return void
	 */
	public function testReadRulesMatchOnTheOrganisation(): void {
		$read = $this->schema()['authorization']['read'];

		$this->assertNotEmpty($read);
		foreach ($read as $rule) {
			$this->assertSame(['_organisation' => '$organisation'], $rule['match'], $rule['group']);
			$this->assertArrayNotHasKey('crossOrganisation', $rule, $rule['group']);
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

		$this->assertSame('status', $lifecycle['field']);
		$this->assertContains($lifecycle['initial'], $statuses);
		$this->assertSame(
			['submit', 'accept', 'reject', 'rework', 'supersede', 'deprecate'],
			array_keys($lifecycle['transitions'])
		);
		foreach ($lifecycle['final'] as $final) {
			$this->assertContains($final, $statuses);
		}

		$guarded = [];
		foreach ($lifecycle['transitions'] as $action => $transition) {
			$this->assertContains($transition['to'], $statuses, $action);
			foreach ($transition['from'] as $from) {
				$this->assertContains($from, $statuses, $action);
			}

			if (isset($transition['requires']) === true) {
				$this->assertSame(ArchitectureDecisionReviewGuard::class, $transition['requires'], $action);
				$guarded[] = $action;
			}
		}

		$this->assertSame(['submit', 'accept', 'reject', 'supersede'], $guarded);
		$this->assertTrue(class_exists($lifecycle['transitions']['submit']['requires']));
	}//end testTheLifecycleUsesEnumValuesAndTheGuardClass()

	/**
	 * The reviewer is told when a decision enters review, the owners when it is concluded.
	 *
	 * @return void
	 */
	public function testNotificationsGoToTheReviewerAndTheOwners(): void {
		$rules = $this->schema()['configuration']['x-openregister-notifications'];

		$this->assertSame(['review-requested', 'review-accepted', 'review-rejected'], array_keys($rules));
		$this->assertSame([['kind' => 'field', 'field' => 'reviewer']], $rules['review-requested']['recipients']);
		$this->assertSame('in review', $rules['review-requested']['trigger']['condition']['value']);
		foreach (['accepted', 'rejected'] as $status) {
			$rule = $rules['review-' . $status];
			$this->assertSame([['kind' => 'object-acl', 'permission' => 'manage']], $rule['recipients']);
			$this->assertSame($status, $rule['trigger']['condition']['value']);
			$this->assertSame('in review', $rule['trigger']['condition']['from']);
		}

		foreach ($rules as $key => $rule) {
			$this->assertSame('updated', $rule['trigger']['type'], $key);
			$this->assertSame('status', $rule['trigger']['condition']['field'], $key);
			$this->assertSame(['nc-notification', 'email'], $rule['channels'], $key);
			$this->assertSame(['nl', 'en'], array_keys($rule['subject']), $key);
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
			$this->assertSame($contract[$key], $board[$key], $key);
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

		$this->assertSame(['seed-ab-zaakgericht-werken', 'seed-ab-api-first'], array_keys($seeded));
		$this->assertSame('accepted', $seeded['seed-ab-zaakgericht-werken']['status']);
		$this->assertSame('in review', $seeded['seed-ab-api-first']['status']);
		$this->assertSame(['@ref:usage:gebruik-suite4-gem-delft-eigenaar'], $seeded['seed-ab-zaakgericht-werken']['applications']);
		$this->assertContains('gebruik-suite4-gem-delft-eigenaar', $usages);
	}//end testTheTwoDemoDecisionsAreSeeded()
}//end class
