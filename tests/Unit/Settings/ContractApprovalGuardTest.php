<?php

/**
 * Tests for ContractApprovalGuard and ContractApprovalListener.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Stackiq\EventListener\ContractApprovalListener;
use OCA\Stackiq\Service\ContractApprovalGuard;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCCD-007: a contract becomes Active only through an approved decision.
 *
 * @covers \OCA\Stackiq\Service\ContractApprovalGuard
 * @covers \OCA\Stackiq\EventListener\ContractApprovalListener
 */
final class ContractApprovalGuardTest extends TestCase {
	private const SCHEMA = '12';

	public function testCreatingAnActiveContractIsRefused(): void {
		$event = $this->creating(data: ['title' => 'Hosting', 'status' => 'Active', 'approvalState' => 'none']);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => ContractApprovalListener::REFUSED_MESSAGE], $event->getErrors());
		self::assertStringContainsString('approved decision', ContractApprovalListener::REFUSED_MESSAGE);
	}//end testCreatingAnActiveContractIsRefused()

	public function testCreatingAnActiveContractWithoutApprovalStateIsRefused(): void {
		$event = $this->creating(data: ['status' => 'Active']);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testCreatingAnActiveContractWithoutApprovalStateIsRefused()

	public function testCreatingAContractInNegotiationIsAllowed(): void {
		$event = $this->creating(data: ['status' => 'In negotiation', 'approvalState' => 'none']);

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testCreatingAContractInNegotiationIsAllowed()

	public function testSettingAPendingContractActiveByHandIsRefused(): void {
		$event = $this->updating(
			data: ['status' => 'Active', 'approvalState' => 'pending'],
			oldData: ['status' => 'In negotiation', 'approvalState' => 'pending']
		);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testSettingAPendingContractActiveByHandIsRefused()

	public function testTheApprovedDecisionMakesTheContractActive(): void {
		$event = $this->updating(
			data: ['status' => 'Active', 'approvalState' => 'approved', 'approvalDecisionId' => 'dec-1'],
			oldData: ['status' => 'In negotiation', 'approvalState' => 'pending', 'approvalDecisionId' => 'dec-1']
		);

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testTheApprovedDecisionMakesTheContractActive()

	public function testAnAlreadyActiveContractKeepsItsStatusOnLaterEdits(): void {
		$event = $this->updating(
			data: ['status' => 'Active', 'approvalState' => 'none', 'title' => 'Renamed'],
			oldData: ['status' => 'Active', 'approvalState' => 'none', 'title' => 'Old']
		);

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnAlreadyActiveContractKeepsItsStatusOnLaterEdits()

	public function testAnUpdateWithoutTheStoredContractIsChecked(): void {
		$event = new ObjectUpdatingEvent($this->entity(data: ['status' => 'Active'], schema: self::SCHEMA), null);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnUpdateWithoutTheStoredContractIsChecked()

	public function testDataAnotherListenerChangedIsChecked(): void {
		$event = $this->creating(data: ['status' => 'In negotiation', 'approvalState' => 'none']);
		$event->setModifiedData(['status' => 'Active']);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testDataAnotherListenerChangedIsChecked()

	public function testOtherSchemasAreNotChecked(): void {
		$event = $this->creating(data: ['status' => 'Active'], schema: '99');

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testOtherSchemasAreNotChecked()

	public function testNothingIsCheckedWithoutAConfiguredContractSchema(): void {
		$event = $this->creating(data: ['status' => 'Active']);

		$this->listener(contractSchema: '')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testNothingIsCheckedWithoutAConfiguredContractSchema()

	public function testTheGuardDecidesOnTheStatusTransition(): void {
		$guard = new ContractApprovalGuard();

		self::assertTrue($guard->refuses(data: ['status' => 'Active', 'approvalState' => 'rejected'], oldData: null));
		self::assertFalse($guard->refuses(data: ['status' => 'Active', 'approvalState' => 'approved'], oldData: null));
		self::assertTrue($guard->refuses(data: ['status' => 'Active'], oldData: ['status' => 'Expired']));
		self::assertFalse($guard->refuses(data: ['status' => 'Expired', 'approvalState' => 'none'], oldData: ['status' => 'Active']));
		self::assertFalse($guard->refuses(data: ['status' => 'active'], oldData: null));
	}//end testTheGuardDecidesOnTheStatusTransition()

	/**
	 * @param array<string, mixed> $data   The new object data.
	 * @param string               $schema The schema id.
	 */
	private function creating(array $data, string $schema = self::SCHEMA): ObjectCreatingEvent {
		return new ObjectCreatingEvent($this->entity(data: $data, schema: $schema));
	}//end creating()

	/**
	 * @param array<string, mixed> $data    The new object data.
	 * @param array<string, mixed> $oldData The stored object data.
	 */
	private function updating(array $data, array $oldData): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent($this->entity(data: $data, schema: self::SCHEMA), $this->entity(data: $oldData, schema: self::SCHEMA));
	}//end updating()

	/**
	 * @param array<string, mixed> $data   The object data.
	 * @param string               $schema The schema id.
	 */
	private function entity(array $data, string $schema): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getObject')->willReturn($data);

		return $entity;
	}//end entity()

	private function listener(string $contractSchema = self::SCHEMA): ContractApprovalListener {
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['contract_schema' => $contractSchema, 'contactpersoon_schema' => '41']);

		return new ContractApprovalListener(guard: new ContractApprovalGuard(), settingsService: $settings);
	}//end listener()
}//end class
