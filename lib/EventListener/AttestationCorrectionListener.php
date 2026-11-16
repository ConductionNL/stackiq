<?php

/**
 * Attestation Correction Listener
 *
 * An owner who edits their own entry answers their confirmation request: corrected.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 * @spec      openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\Kernel\Contracts\AttestationAnswerServiceInterface;
use OCA\Stackiq\Kernel\Contracts\AttestationServiceInterface;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * On an update of a usage or a module by a signed-in user that changed a field
 * by hand, that user's open requests for the entry read corrected. Only the
 * saving user's requests move: a colleague's edit answers nobody else's request
 * (landscape-owner-attestation D3). A save that changed only derived fields,
 * such as a confirmation, answers nothing.
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 */
class AttestationCorrectionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Handle an updated object.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false || $event->getOldObject() === null) {
			return;
		}

		$object      = $event->getNewObject();
		$entrySchema = $this->entrySchema(schemaId: $object->getSchema());
		if ($entrySchema === null) {
			return;
		}

		$answers = $this->container->get(AttestationAnswerServiceInterface::class);
		if ($answers->isChangedByHand(before: (array) $event->getOldObject()->getObject(), after: (array) $object->getObject()) === false) {
			return;
		}

		$user = $this->container->get(IUserSession::class)->getUser();
		if ($user === null) {
			return;
		}

		try {
			$answers->markCorrected(
				entrySchema: $entrySchema,
				entryId: (string) $object->getUuid(),
				uid: $user->getUID()
			);
		} catch (\Throwable $exception) {
			$this->container->get(LoggerInterface::class)->error(
				'AttestationCorrectionListener: could not mark the request corrected',
				['objectId' => $object->getUuid(), 'exception' => $exception->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The entry type of a schema id, when it is one a round asks about.
	 *
	 * @param mixed $schemaId The object's schema id.
	 *
	 * @return string|null `usage`, `module` or null.
	 */
	private function entrySchema(mixed $schemaId): ?string {
		$locator = $this->container->get(ObjectTypeLocatorInterface::class);
		foreach (array_keys(AttestationServiceInterface::SCOPES) as $type) {
			$id = $locator->getSchemaIdForObjectType($type);
			if ($id !== null && (int) $id === (int) $schemaId) {
				return $type;
			}
		}

		return null;
	}//end entrySchema()
}//end class
