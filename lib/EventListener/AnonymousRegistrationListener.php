<?php

/**
 * Keeps an organisation registered without an account pending until reviewed.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-registration/spec.md#requirement-req-orr-001-an-organisation-registered-without-an-account-is-pending-until-reviewed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * On OpenRegister's creating event: an organisation created with nobody signed in
 * is stored as `pending` and unpublished, whatever the request carried.
 *
 * It holds for portaliq's registration form, stackiq's own intake endpoint and a
 * direct call to OpenRegister alike, because the organisation schema allows an
 * anonymous create. A signed-in create keeps its values: moderators and
 * administrators set them on purpose. So does a create from the command line
 * (`occ`, cron), where installs, imports and seed data run without a session.
 *
 * An anonymous create that carries the uuid of an existing OpenRegister organisation
 * is refused, because the sync links an organisatie to the organisation with its uuid.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/organisation-registration/spec.md#requirement-req-orr-001-an-organisation-registered-without-an-account-is-pending-until-reviewed
 */
class AnonymousRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Tells whether someone is signed in.
	 * @param SettingsService $settingsService Names the organisation schema.
	 * @param OrganisationMapper $organisationMapper Tells whether an OpenRegister organisation holds a uuid.
	 * @param bool|null $commandLine Whether this runs from the command line; null reads PHP_SAPI.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly SettingsService $settingsService,
		private readonly OrganisationMapper $organisationMapper,
		private ?bool $commandLine = null,
	) {
		$this->commandLine ??= (PHP_SAPI === 'cli');
	}//end __construct()

	/**
	 * Stamp an anonymous organisation create as pending and unpublished, or refuse
	 * it when it carries the uuid of an existing OpenRegister organisation.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-registration/spec.md#requirement-req-orr-001-an-organisation-registered-without-an-account-is-pending-until-reviewed
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false || $this->isAnonymousRequest() === false) {
			return;
		}

		if ($this->isOrganisationCreate(event: $event) === false) {
			return;
		}

		if ($this->organisationHolds(uuid: $event->getObject()->getUuid()) === true) {
			$this->refuse(event: $event);
			return;
		}

		$this->markPending(event: $event);
	}//end handle()

	/**
	 * Whether the request comes from no signed-in user and not from the command line.
	 *
	 * @return bool
	 */
	private function isAnonymousRequest(): bool {
		return $this->commandLine !== true && $this->userSession->getUser() === null;
	}//end isAnonymousRequest()

	/**
	 * Whether the event creates an object of the configured organisation schema.
	 *
	 * @param ObjectCreatingEvent $event The dispatched event.
	 *
	 * @return bool
	 */
	private function isOrganisationCreate(ObjectCreatingEvent $event): bool {
		$schema = (string)($this->settingsService->getVoorzieningenConfig()['organisatie_schema'] ?? '');
		return $schema !== '' && (string)$event->getObject()->getSchema() === $schema;
	}//end isOrganisationCreate()

	/**
	 * Refuse the create, saying only that the organisation could not be registered.
	 *
	 * @param ObjectCreatingEvent $event The dispatched event.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent $event): void {
		$event->setErrors(['message' => 'This organisation could not be registered.']);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * Stamp the create as pending and unpublished.
	 *
	 * @param ObjectCreatingEvent $event The dispatched event.
	 *
	 * @return void
	 */
	private function markPending(ObjectCreatingEvent $event): void {
		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['registrationStatus' => 'pending', 'publicationDate' => null]
			)
		);
	}//end markPending()

	/**
	 * Whether an OpenRegister organisation holds this uuid.
	 *
	 * @param string|null $uuid The uuid the new object carries, when it has one yet.
	 *
	 * @return bool
	 */
	private function organisationHolds(?string $uuid): bool {
		if ($uuid === null || $uuid === '') {
			return false;
		}

		try {
			$this->organisationMapper->findByUuid($uuid);
			return true;
		} catch (DoesNotExistException $e) {
			return false;
		}
	}//end organisationHolds()
}//end class
