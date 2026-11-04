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
use OCA\Stackiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * On OpenRegister's creating and updating events for a contact person: the write is
 * refused unless {@see ContactPersonOrganisationGuard} allows it. An update that keeps
 * the organisation and the e-mail address is not checked again. A write from the
 * command line (`occ`, cron) is not checked: installs, imports and the sync run there.
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
	 * @param bool                           $fromCli         True when PHP runs from the command line.
	 */
	public function __construct(
		private readonly ContactPersonOrganisationGuard $guard,
		private readonly SettingsService $settingsService,
		private readonly bool $fromCli = PHP_SAPI === 'cli',
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
		if ($this->fromCli === true) {
			return;
		}

		if ($event instanceof ObjectCreatingEvent) {
			$this->check(event: $event, object: $event->getObject(), oldData: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->check(event: $event, object: $event->getNewObject(), oldData: $event->getOldObject()?->getObject());
		}
	}//end handle()

	/**
	 * Stop the write when it is a contact person the guard does not allow.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The pre-save event.
	 * @param ObjectEntity                            $object  The object being written.
	 * @param array<string, mixed>|null               $oldData The stored data, or null on a create.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object, ?array $oldData): void {
		$configured = (string)($this->settingsService->getVoorzieningenConfig()['contactpersoon_schema'] ?? '');
		if ($configured === '' || (string)$object->getSchema() !== $configured) {
			return;
		}

		$data = array_merge(($object->getObject() ?? []), $event->getModifiedData());
		if ($this->guard->needsCheck(contactData: $data, oldData: $oldData) === false
			|| $this->guard->allows(contactData: $data) === true
		) {
			return;
		}

		$event->setErrors(['message' => 'This contact person could not be saved.']);
		$event->stopPropagation();
	}//end check()
}//end class
