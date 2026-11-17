<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-OAT-002: the confirmation round and request schemas, their lifecycle and the owner's notifications.
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-002-each-owner-is-notified-and-reminded
 */
final class OwnerAttestationFragmentTest extends TestCase {
	/**
	 * The register with every fragment merged the way the loader merges them.
	 *
	 * @return array<string,mixed>
	 */
	private function merged(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true));
			$merged = $keep->invoke(null, $before, $merged);
		}

		return $merged;
	}//end merged()

	/**
	 * Both schemas exist and the stackiq register lists them, once each.
	 *
	 * @return void
	 */
	public function testBothSchemasAreInTheStackiqRegister(): void {
		$merged = $this->merged();
		$listed = $merged['components']['registers']['stackiq']['schemas'];

		foreach (['attestationRound', 'attestationRequest'] as $slug) {
			self::assertArrayHasKey($slug, $merged['components']['schemas']);
			self::assertSame($slug, $merged['components']['schemas'][$slug]['slug']);
			self::assertSame(1, count(array_keys($listed, $slug, true)), $slug . ' must be listed once');
		}
	}//end testBothSchemasAreInTheStackiqRegister()

	/**
	 * The round closes through its lifecycle, from open only.
	 *
	 * @return void
	 */
	public function testTheRoundDeclaresItsLifecycle(): void {
		$round = $this->merged()['components']['schemas']['attestationRound'];
		$lifecycle = $round['configuration']['x-openregister-lifecycle'];

		self::assertSame('status', $lifecycle['field']);
		self::assertSame('open', $lifecycle['initial']);
		self::assertSame(['closed'], $lifecycle['final']);
		self::assertSame(['from' => ['open'], 'to' => 'closed'], array_intersect_key($lifecycle['transitions']['close'], ['from' => 1, 'to' => 1]));
		self::assertSame(['open', 'closed'], $round['properties']['status']['enum']);
		self::assertSame(['usage', 'module'], $round['properties']['scopeSchema']['enum']);
	}//end testTheRoundDeclaresItsLifecycle()

	/**
	 * A created request notifies its owner in Nextcloud and by e-mail, through the assignee field.
	 *
	 * @return void
	 */
	public function testACreatedRequestNotifiesItsOwner(): void {
		$rule = $this->merged()['components']['schemas']['attestationRequest']['x-openregister-notifications']['request-created'];

		self::assertSame(['type' => 'created'], $rule['trigger']);
		self::assertTrue($rule['enabled']);
		self::assertSame(['nc-notification', 'email'], $rule['channels']);
		self::assertSame([['kind' => 'field', 'field' => 'assigneeUserId']], $rule['recipients']);
	}//end testACreatedRequestNotifiesItsOwner()

	/**
	 * A pending request is recalled daily from three days before its deadline.
	 *
	 * @return void
	 */
	public function testAPendingRequestIsRemindedThreeDaysBeforeTheDeadline(): void {
		$rule = $this->merged()['components']['schemas']['attestationRequest']['x-openregister-notifications']['deadline-near'];

		self::assertSame('scheduled', $rule['trigger']['type']);
		self::assertSame(86400, $rule['trigger']['intervalSec']);
		self::assertSame(['operator' => 'withinNext', 'value' => 'P3D'], $rule['trigger']['filter']['deadline']);
		self::assertSame(['operator' => 'equals', 'value' => 'pending'], $rule['trigger']['filter']['status']);
		self::assertSame([['kind' => 'field', 'field' => 'assigneeUserId']], $rule['recipients']);
	}//end testAPendingRequestIsRemindedThreeDaysBeforeTheDeadline()

	/**
	 * Every field a rule filters on, sends to or names in its subject is a property of the request.
	 *
	 * @return void
	 */
	public function testEveryRuleNamesFieldsTheRequestHas(): void {
		$request = $this->merged()['components']['schemas']['attestationRequest'];
		$properties = $request['properties'];

		foreach ($request['x-openregister-notifications'] as $name => $rule) {
			foreach (array_keys($rule['trigger']['filter'] ?? []) as $field) {
				self::assertArrayHasKey($field, $properties, $name . ' filters on ' . $field);
			}

			foreach ($rule['recipients'] as $recipient) {
				self::assertArrayHasKey($recipient['field'], $properties, $name . ' sends to ' . $recipient['field']);
			}

			foreach (['nl', 'en'] as $language) {
				preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $rule['subject'][$language], $matches);
				self::assertNotEmpty($matches[1]);
				foreach ($matches[1] as $field) {
					self::assertArrayHasKey($field, $properties, $name . ' names ' . $field);
				}
			}
		}

		self::assertSame(['pending', 'confirmed', 'corrected', 'overdue'], $properties['status']['enum']);
	}//end testEveryRuleNamesFieldsTheRequestHas()

	/**
	 * Only the assignee answers a request; creating one is left to the service.
	 *
	 * @return void
	 */
	public function testOnlyTheAssigneeAnswersARequest(): void {
		$authorization = $this->merged()['components']['schemas']['attestationRequest']['authorization'];
		$assignee = ['group' => 'authenticated', 'match' => ['assigneeUserId' => '$userId'], 'crossOrganisation' => true];

		self::assertSame(['admin'], $authorization['create']);
		self::assertContains($assignee, $authorization['read']);
		self::assertSame(['admin', $assignee], $authorization['update']);
		self::assertSame(['admin'], $authorization['delete']);
	}//end testOnlyTheAssigneeAnswersARequest()
}//end class
