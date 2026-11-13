<?php

/**
 * Refuses a contact person written by someone who may not add members to its organisation.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard;
use OCA\Stackiq\Service\Contactpersoon\SystemContactWrites;
use OCA\Stackiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * On OpenRegister's creating and updating events for a contact person: the write is
 * refused unless {@see ContactPersonOrganisationGuard} allows it. An update that keeps
 * every key the guard reads is not checked again. Writes stackiq's own contact sync
 * and migration make, marked through {@see SystemContactWrites}, are not checked.
 * Other command line writes, such as OpenRegister bulk jobs, are.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
 */
class ContactPersonOrganisationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ContactPersonOrganisationGuard $guard           Decides whether the caller may write the contact person.
	 * @param SettingsService                $settingsService Names the contact person schema.
	 * @param SystemContactWrites            $systemWrites    Tells whether stackiq itself is writing.
	 */
	public function __construct(
		private readonly ContactPersonOrganisationGuard $guard,
		private readonly SettingsService $settingsService,
		private readonly SystemContactWrites $systemWrites,
	) {
	}//end __construct()

	/**
	 * Refuse a contact person write the caller may not make.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
	 */
	public function handle(Event $event): void {
		if ($this->systemWrites->isActive() === true) {
			return;
		}

		if ($event instanceof ObjectCreatingEvent) {
			$this->check(event: $event, oldData: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->check(event: $event, oldData: $event->getOldObject()?->getObject());
		}
	}//end handle()

	/**
	 * Stop the write when it is a contact person the guard does not allow.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The pre-save event.
	 * @param array<string, mixed>|null               $oldData The stored data, or null on a create.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, ?array $oldData): void {
		$object = $this->writtenObject(event: $event);
		if ($this->isContactPerson(object: $object) === false) {
			return;
		}

		$data = array_merge(($object->getObject() ?? []), $event->getModifiedData());
		if ($this->mayWrite(contactData: $data, oldData: $oldData) === true) {
			return;
		}

		$event->setErrors(['message' => 'This contact person could not be saved.']);
		$event->stopPropagation();
	}//end check()

	/**
	 * The object being written: the new object of an update, the object of a create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return ObjectEntity
	 */
	private function writtenObject(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end writtenObject()

	/**
	 * Whether the object is of the configured contact person schema.
	 *
	 * @param ObjectEntity $object The object being written.
	 *
	 * @return bool
	 */
	private function isContactPerson(ObjectEntity $object): bool {
		$configured = (string)($this->settingsService->getVoorzieningenConfig()['contactpersoon_schema'] ?? '');
		return $configured !== '' && (string)$object->getSchema() === $configured;
	}//end isContactPerson()

	/**
	 * Whether the write may go ahead: it needs no check, or the guard allows it.
	 *
	 * @param array<string, mixed>      $contactData The contact person's data as it will be saved.
	 * @param array<string, mixed>|null $oldData     The stored data, or null on a create.
	 *
	 * @return bool
	 */
	private function mayWrite(array $contactData, ?array $oldData): bool {
		return $this->guard->needsCheck(contactData: $contactData, oldData: $oldData) === false
			|| $this->guard->allows(contactData: $contactData, oldData: $oldData) === true;
	}//end mayWrite()
}//end class
