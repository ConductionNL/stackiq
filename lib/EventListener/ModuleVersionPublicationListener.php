<?php

/**
 * Copies a module's publication onto its versions whenever either is saved.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands every created, updated or deleted object to the publication mirror.
 *
 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 *
 * @template-implements IEventListener<Event>
 */
class ModuleVersionPublicationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ModuleVersionPublicationService $publication The mirror.
	 * @param LoggerInterface                 $logger      The logger.
	 */
	public function __construct(
		private readonly ModuleVersionPublicationService $publication,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof ObjectUpdatedEvent) {
				$this->publication->objectSaved(object: $event->getNewObject(), previous: $event->getOldObject());
			}

			if ($event instanceof ObjectCreatedEvent) {
				$this->publication->objectSaved(object: $event->getObject());
			}

			if ($event instanceof ObjectDeletedEvent) {
				$this->publication->objectDeleted(object: $event->getObject());
			}
		} catch (Throwable $e) {
			$this->logger->error('ModuleVersionPublicationListener: could not mirror the publication', ['error' => $e->getMessage()]);
		}
	}//end handle()
}//end class
