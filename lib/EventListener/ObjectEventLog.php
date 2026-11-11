<?php

/**
 * Object event log
 *
 * The log lines StackiqEventListener writes while it handles OpenRegister
 * object events, built and used only by that listener.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCP\EventDispatcher\Event;
use Psr\Log\LoggerInterface;

/**
 * Writes the listener's log lines from an object context (which carries the logger).
 *
 * @category EventListener
 * @package  OCA\Stackiq\EventListener
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class ObjectEventLog {

	/**
	 * Log a lock, unlock or revert event, which the listener ignores.
	 *
	 * @param Event           $event  The event.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function ignoredLifecycleEvent(Event $event, LoggerInterface $logger): void {
		if ($event instanceof ObjectLockedEvent
			|| $event instanceof ObjectUnlockedEvent
			|| $event instanceof ObjectRevertedEvent
		) {
			$logger->debug(
				'Stackiq: Ignoring object lifecycle event',
				[
					'eventType' => get_class($event),
				]
			);
		}
	}//end ignoredLifecycleEvent()

	/**
	 * Log the creation being processed.
	 *
	 * @param array<string, mixed> $ctx The object context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function objectCreation(array $ctx): void {
		$ctx['logger']->info(
			'Stackiq: Processing object creation',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'schemaIdInt' => $ctx['schemaIdInt'],
				'registerId' => $ctx['registerId'],
				'objectData' => json_encode($ctx['object']->getObject()),
			]
		);
	}//end objectCreation()

	/**
	 * Log the catalog schema ids looked up for a created object.
	 *
	 * @param array<string, mixed>    $ctx The object context.
	 * @param array<string, int|null> $ids The catalog schema ids.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function catalogSchemaLookup(array $ctx, array $ids): void {
		$ctx['logger']->debug(
			'Stackiq: Configuration lookup results',
			[
				'organisatieSchemaId' => $ids['organization'],
				'contactpersoonSchemaId' => $ids['contactPerson'],
				'contactgegevensSchemaId' => $ids['contactgegevens'],
				'gebruikSchemaId' => $ids['usage'],
				'objectSchemaId' => $ctx['schemaIdInt'],
			]
		);
	}//end catalogSchemaLookup()

	/**
	 * Log a creation of an object that is not a supported catalog type.
	 *
	 * @param array<string, mixed>    $ctx The object context.
	 * @param array<string, int|null> $ids The catalog schema ids.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function unhandledCreation(array $ctx, array $ids): void {
		$ctx['logger']->debug(
			'Stackiq: Object creation not handled - not a supported object type',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaIdInt'],
				'registerId' => $ctx['registerId'],
				'supportedSchemas' => $ids,
			]
		);
	}//end unhandledCreation()

	/**
	 * Log the update being processed.
	 *
	 * @param array<string, mixed> $ctx The object context, with the old object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function objectUpdate(array $ctx): void {
		$ctx['logger']->info(
			'Stackiq: Processing object update',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'schemaIdInt' => $ctx['schemaIdInt'],
				'registerId' => $ctx['registerId'],
				'hasOldObject' => $ctx['oldObject'] !== null,
			]
		);
	}//end objectUpdate()

	/**
	 * Log an updated usage about to be synced.
	 *
	 * @param array<string, mixed> $ctx                The object context.
	 * @param int|null             $configuredSchemaId The configured usage schema id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function usageUpdate(array $ctx, ?int $configuredSchemaId): void {
		$ctx['logger']->info(
			'Stackiq: Matched gebruik schema - processing update',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'configuredSchemaId' => $configuredSchemaId,
			]
		);
	}//end usageUpdate()

	/**
	 * Log an update of an object that is not a handled catalog type.
	 *
	 * @param array<string, mixed>    $ctx The object context, with the organisation schema id.
	 * @param array<string, int|null> $ids The catalog schema ids looked up.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function unhandledUpdate(array $ctx, array $ids): void {
		$ctx['logger']->debug(
			'Stackiq: Object update not handled - focusing only on organisatie, contactpersonen, and gebruik',
			[
				'objectId' => $ctx['objectId'],
				'schemaId' => $ctx['schemaId'],
				'schemaIdInt' => $ctx['schemaIdInt'],
				'schemaIdType' => gettype($ctx['schemaId']),
				'registerId' => $ctx['registerId'],
				'handledSchemas' => ['organization' => $ctx['organisationSchemaId']] + $ids,
			]
		);
	}//end unhandledUpdate()
}//end class
