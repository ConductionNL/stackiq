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
 * @spec openspec/changes/organisation-registration-through-the-portal/specs/organisation-registration/spec.md#requirement-req-orr-001-an-organisation-registered-without-an-account-is-pending-until-reviewed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Stackiq\Service\SettingsService;
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
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/organisation-registration-through-the-portal/specs/organisation-registration/spec.md#requirement-req-orr-001-an-organisation-registered-without-an-account-is-pending-until-reviewed
 */
class AnonymousRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Tells whether someone is signed in.
	 * @param SettingsService $settingsService Names the organisation schema.
	 * @param bool|null $commandLine Whether this runs from the command line; null reads PHP_SAPI.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly SettingsService $settingsService,
		private ?bool $commandLine = null,
	) {
		$this->commandLine ??= (PHP_SAPI === 'cli');
	}//end __construct()

	/**
	 * Stamp an anonymous organisation create as pending and unpublished.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/organisation-registration-through-the-portal/specs/organisation-registration/spec.md#requirement-req-orr-001-an-organisation-registered-without-an-account-is-pending-until-reviewed
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false || $this->commandLine === true || $this->userSession->getUser() !== null) {
			return;
		}

		$schema = (string)($this->settingsService->getVoorzieningenConfig()['organisatie_schema'] ?? '');
		if ($schema === '' || (string)$event->getObject()->getSchema() !== $schema) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['registrationStatus' => 'pending', 'publicationDate' => null]
			)
		);
	}//end handle()
}//end class
