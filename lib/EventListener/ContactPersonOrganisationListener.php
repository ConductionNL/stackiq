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
	 * @param bool|null                      $commandLine     Whether this runs from the command line; null reads PHP_SAPI.
	 */
	public function __construct(
		private readonly ContactPersonOrganisationGuard $guard,
		private readonly SettingsService $settingsService,
		private ?bool $commandLine = null,
	) {
		$this->commandLine ??= (PHP_SAPI === 'cli');
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
		if ($this->commandLine === true) {
			return;
		}

		$object  = null;
		$oldData = null;
		if ($event instanceof ObjectCreatingEvent) {
			$object = $event->getObject();
		} else if ($event instanceof ObjectUpdatingEvent) {
			$object  = $event->getNewObject();
			$oldData = $event->getOldObject()?->getObject();
		}

		if ($object === null || $this->isContactPerson(schema: (string)$object->getSchema()) === false) {
			return;
		}

		$data = array_merge(($object->getObject() ?? []), $event->getModifiedData());
		if (ContactPersonOrganisationGuard::needsCheck(contactData: $data, oldData: $oldData) === false
			|| $this->guard->allows(contactData: $data) === true
		) {
			return;
		}

		$event->setErrors(['message' => 'This contact person could not be saved.']);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Whether this schema id is the contact person schema.
	 *
	 * @param string $schema The object's schema id.
	 *
	 * @return bool
	 */
	private function isContactPerson(string $schema): bool {
		$configured = (string)($this->settingsService->getVoorzieningenConfig()['contactpersoon_schema'] ?? '');
		return $configured !== '' && $schema === $configured;
	}//end isContactPerson()
}//end class
