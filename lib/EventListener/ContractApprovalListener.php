<?php

/**
 * Refuses a contract write that makes the contract Active without an approved decision.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Stackiq\Service\ContractApprovalGuard;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * On OpenRegister's creating and updating events for a contract: the write is
 * refused when {@see ContractApprovalGuard} says it makes the contract Active
 * without an approved decision.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/contract-decision-delegation/spec.md
 */
class ContractApprovalListener implements IEventListener {
	/**
	 * The message a refused write carries.
	 */
	public const REFUSED_MESSAGE = 'A contract becomes Active only through an approved decision. Submit it for approval first.';

	/**
	 * Constructor.
	 *
	 * @param ContractApprovalGuard         $guard           Decides whether the write may make the contract Active.
	 * @param RegisterConfigReaderInterface $settingsService Names the contract schema.
	 */
	public function __construct(
		private readonly ContractApprovalGuard $guard,
		private readonly RegisterConfigReaderInterface $settingsService,
	) {
	}//end __construct()

	/**
	 * Refuse a contract write that makes it Active without an approved decision.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/contract-decision-delegation/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->check(event: $event, object: $event->getObject(), oldData: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->check(event: $event, object: $event->getNewObject(), oldData: ($event->getOldObject()?->getObject() ?? []));
		}
	}//end handle()

	/**
	 * Stop the write when it is a contract the guard refuses.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The pre-save event.
	 * @param ObjectEntity                            $object  The object being written.
	 * @param array<string, mixed>|null               $oldData The stored data, or null on a create.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object, ?array $oldData): void {
		if ($this->isContract(object: $object) === false) {
			return;
		}

		$data = array_merge(($object->getObject() ?? []), $event->getModifiedData());
		if ($this->guard->refuses(data: $data, oldData: $oldData) === false) {
			return;
		}

		$event->setErrors(['message' => self::REFUSED_MESSAGE]);
		$event->stopPropagation();
	}//end check()

	/**
	 * Whether the object is of the configured contract schema.
	 *
	 * @param ObjectEntity $object The object being written.
	 *
	 * @return bool
	 */
	private function isContract(ObjectEntity $object): bool {
		$configured = (string)($this->settingsService->getVoorzieningenConfig()['contract_schema'] ?? '');
		return $configured !== '' && (string)$object->getSchema() === $configured;
	}//end isContract()
}//end class
