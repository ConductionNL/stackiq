<?php

/**
 * Compliance Verification Guard
 *
 * Keeps a claim's verification out of ordinary writes and resets it when the evidence changes.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Stackiq\Service\ComplianceVerification;
use OCA\Stackiq\Service\ComplianceVerificationService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * On every compliancy create and update that is not the verify action: a
 * write that sets or changes verifiedBy, verifiedAt or verifiedByOrganisation
 * is refused, so a supplier cannot mark its own claim verified through the
 * object API; and a write that changes the evidence of a verified claim clears
 * those fields, so the claim reads claimed again.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 * @SuppressWarnings(PHPMD.StaticAccess) ComplianceVerification holds the pure rules; isWriting() is the service's own re-entrancy flag.
*/
class ComplianceVerificationGuard implements IEventListener {

	/**
	 * The message of a refused write.
	 *
	 * @var string
	 */
	public const REFUSED_MESSAGE = 'Only the verify action marks a claim verified';

	/**
	 * Constructor.
	 *
	 * @param ObjectTypeLocatorInterface $types The configured schemas.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectTypeLocatorInterface $types,
	) {
	}//end __construct()

	/**
	 * Handle a pre-save event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->check(event: $event, object: $event->getObject(), old: []);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->check(event: $event, object: $event->getNewObject(), old: (array)($event->getOldObject()?->getObject() ?? []));
		}
	}//end handle()

	/**
	 * Refuse or reset a compliancy write.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-save event.
	 * @param ObjectEntity                            $object The object being written.
	 * @param array<string, mixed>                    $old    The stored data ([] on create).
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object, array $old): void {
		if (ComplianceVerificationService::isWriting() === true || $this->isCompliancy(object: $object) === false) {
			return;
		}

		$new = array_merge((array)($object->getObject() ?? []), $event->getModifiedData());
		$reset = ComplianceVerification::resetOnEvidenceChange(new: $new, old: $old);
		if ($reset !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $reset));
			return;
		}

		if (ComplianceVerification::changesVerification(new: $new, old: $old) === true) {
			$event->setErrors(['message' => self::REFUSED_MESSAGE]);
			$event->stopPropagation();
		}
	}//end check()

	/**
	 * Whether the object is a compliancy record.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function isCompliancy(ObjectEntity $object): bool {
		$schemaId = $this->types->getSchemaIdForObjectType('compliancy');

		return $schemaId !== null && ((int)$object->getSchema()) === $schemaId;
	}//end isCompliancy()
}//end class
