<?php

/**
 * CatalogueMergeRelinker
 *
 * After OpenRegister merges two applications (`module`) or two services
 * (`catalogService`), re-points every catalogue reference to a merged record
 * onto the survivor and records `mergedInto` on the merged record
 * (operations-record-reconciliation D3). OpenRegister's merge relinks one
 * reverse reference per schema; which fields of the catalogue point at an
 * application is stackiq's knowledge, kept in CatalogueReferenceMap. Each
 * moved reference is written to the audit log with the merge operation id, so
 * a steward can put it back by hand after a reversal.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Stackiq\Service\CatalogueReferenceMap;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-points catalogue references after OpenRegister merges applications or services.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/operations-record-reconciliation/tasks.md#task-3-re-point-references-after-openregister-merges-applications-or-services
 */
class CatalogueMergeRelinker implements IEventListener {

	/**
	 * The schemas whose merges this listener follows.
	 *
	 * @var array<int, string>
	 */
	public const RELINKED_SCHEMAS = ['module', 'catalogService'];

	/**
	 * Most objects of one referencing schema read in one walk.
	 *
	 * @var int
	 */
	private const WALK_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param RegisterConfigReaderInterface $settings The register and schema ids, and OpenRegister's object service.
	 * @param LoggerInterface               $logger   The audit log and the errors.
	 *
	 * @spec openspec/changes/operations-record-reconciliation/tasks.md#task-3-re-point-references-after-openregister-merges-applications-or-services
	 */
	public function __construct(
		private readonly RegisterConfigReaderInterface $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Follow a merge of applications or services; ignore every other event,
	 * a reversal, and a merge of any other schema.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectsMergedEvent === false || $event->isReversal() === true) {
			return;
		}

		$objects = $this->settings->getObjectService();
		if ($objects === null) {
			return;
		}

		$schema = $this->mergedSchema(objects: $objects, survivorUuid: $event->getSurvivorUuid());
		if ($schema === null) {
			return;
		}

		$this->relink(objects: $objects, schema: $schema, event: $event);
	}//end handle()

	/**
	 * Re-point every reference to the merged records, then mark them merged.
	 * A failure is logged; OpenRegister's merge itself already stands.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 * @param string                 $schema  The merged schema.
	 * @param ObjectsMergedEvent     $event   The merge.
	 *
	 * @return void
	 */
	private function relink(ObjectServiceInterface $objects, string $schema, ObjectsMergedEvent $event): void {
		$losers = self::losers(event: $event);
		try {
			foreach (CatalogueReferenceMap::referencesTo(schema: $schema) as $reference) {
				$this->relinkReference(objects: $objects, reference: $reference, losers: $losers, event: $event);
			}

			foreach ($losers as $loser) {
				$this->markMerged(objects: $objects, schema: $schema, loserUuid: $loser, survivorUuid: $event->getSurvivorUuid());
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'CatalogueMergeRelinker: could not re-point the references of merge ' . $event->getMergeOperationId() . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end relink()

	/**
	 * The schema of the survivor when it is one this listener follows.
	 *
	 * @param ObjectServiceInterface $objects      The object service.
	 * @param string                 $survivorUuid The survivor.
	 *
	 * @return string|null `module`, `catalogService`, or null.
	 */
	private function mergedSchema(ObjectServiceInterface $objects, string $survivorUuid): ?string {
		$survivor = $this->findQuietly(objects: $objects, uuid: $survivorUuid);
		if ($survivor === null) {
			return null;
		}

		foreach (self::RELINKED_SCHEMAS as $schema) {
			if ((string)$this->settings->getSchemaIdForObjectType(objectType: $schema) === (string)$survivor->getSchema()) {
				return $schema;
			}
		}

		return null;
	}//end mergedSchema()

	/**
	 * Re-point one reference on every object of its schema that holds a merged record.
	 *
	 * @param ObjectServiceInterface                           $objects   The object service.
	 * @param array{schema: string, field: string, list: bool} $reference The reference.
	 * @param array<int, string>                               $losers    The merged records.
	 * @param ObjectsMergedEvent                               $event     The merge.
	 *
	 * @return void
	 */
	private function relinkReference(ObjectServiceInterface $objects, array $reference, array $losers, ObjectsMergedEvent $event): void {
		$register = $this->settings->getRegisterIdForObjectType(objectType: $reference['schema']);
		$schema = $this->settings->getSchemaIdForObjectType(objectType: $reference['schema']);
		if ($register === null || $schema === null) {
			return;
		}

		$entities = $objects->findAll(
			config: ['_register' => $register, '_schema' => $schema, 'limit' => self::WALK_LIMIT],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($entities as $entity) {
			$data = self::repointed(
				data: $entity->getObject(),
				reference: $reference,
				losers: $losers,
				survivor: $event->getSurvivorUuid()
			);
			if ($data === null) {
				continue;
			}

			$objects->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $entity->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
			$this->audit(reference: $reference, objectUuid: (string)$entity->getUuid(), event: $event);
		}
	}//end relinkReference()

	/**
	 * The object's data with the merged records replaced by the survivor in
	 * one field, or null when the field holds none of them. A list keeps the
	 * survivor once.
	 *
	 * @param array<string, mixed>                             $data      The object's data.
	 * @param array{schema: string, field: string, list: bool} $reference The reference.
	 * @param array<int, string>                               $losers    The merged records.
	 * @param string                                           $survivor  The survivor.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public static function repointed(array $data, array $reference, array $losers, string $survivor): ?array {
		$value = ($data[$reference['field']] ?? null);
		if ($reference['list'] === true) {
			$value = self::repointedList(value: $value, losers: $losers, survivor: $survivor);
		} else {
			$value = self::repointedScalar(value: $value, losers: $losers, survivor: $survivor);
		}

		if ($value === null) {
			return null;
		}

		$data[$reference['field']] = $value;
		return $data;
	}//end repointed()

	/**
	 * A list with the merged records replaced by the survivor, held once; null
	 * when the list holds none of them.
	 *
	 * @param mixed              $value    The stored list.
	 * @param array<int, string> $losers   The merged records.
	 * @param string             $survivor The survivor.
	 *
	 * @return array<int, mixed>|null
	 */
	private static function repointedList(mixed $value, array $losers, string $survivor): ?array {
		if (is_array($value) === false || self::holdsAny(list: $value, losers: $losers) === false) {
			return null;
		}

		$replaced = array_map(
			static fn (mixed $entry): mixed => self::replacement(entry: $entry, losers: $losers, survivor: $survivor),
			$value
		);

		return self::survivorOnce(list: $replaced, survivor: $survivor);
	}//end repointedList()

	/**
	 * The survivor for a merged record, else the entry itself.
	 *
	 * @param mixed              $entry    A list entry.
	 * @param array<int, string> $losers   The merged records.
	 * @param string             $survivor The survivor.
	 *
	 * @return mixed
	 */
	private static function replacement(mixed $entry, array $losers, string $survivor): mixed {
		if (in_array($entry, $losers, true) === true) {
			return $survivor;
		}

		return $entry;
	}//end replacement()

	/**
	 * Whether a list holds one of the merged records.
	 *
	 * @param array<int|string, mixed> $list   The list.
	 * @param array<int, string>       $losers The merged records.
	 *
	 * @return bool
	 */
	private static function holdsAny(array $list, array $losers): bool {
		foreach ($list as $entry) {
			if (in_array($entry, $losers, true) === true) {
				return true;
			}
		}

		return false;
	}//end holdsAny()

	/**
	 * A list with the survivor kept only where it first appears.
	 *
	 * @param array<int|string, mixed> $list     The list.
	 * @param string                   $survivor The survivor.
	 *
	 * @return array<int, mixed>
	 */
	private static function survivorOnce(array $list, string $survivor): array {
		$kept = [];
		$seen = false;
		foreach ($list as $entry) {
			if ($entry === $survivor && $seen === true) {
				continue;
			}

			$seen   = ($seen || $entry === $survivor);
			$kept[] = $entry;
		}

		return $kept;
	}//end survivorOnce()

	/**
	 * The survivor when the value is a merged record, else null.
	 *
	 * @param mixed              $value    The stored value.
	 * @param array<int, string> $losers   The merged records.
	 * @param string             $survivor The survivor.
	 *
	 * @return string|null
	 */
	private static function repointedScalar(mixed $value, array $losers, string $survivor): ?string {
		if (in_array($value, $losers, true) === false) {
			return null;
		}

		return $survivor;
	}//end repointedScalar()

	/**
	 * Record on a merged record which record it was merged into.
	 *
	 * @param ObjectServiceInterface $objects      The object service.
	 * @param string                 $schema       The merged schema.
	 * @param string                 $loserUuid    The merged record.
	 * @param string                 $survivorUuid The survivor.
	 *
	 * @return void
	 */
	private function markMerged(ObjectServiceInterface $objects, string $schema, string $loserUuid, string $survivorUuid): void {
		$loser = $this->findQuietly(objects: $objects, uuid: $loserUuid);
		if ($loser === null) {
			return;
		}

		$data = $loser->getObject();
		$data['mergedInto'] = $survivorUuid;
		$objects->saveObject(
			object: $data,
			extend: [],
			register: $this->settings->getRegisterIdForObjectType(objectType: $schema),
			schema: $this->settings->getSchemaIdForObjectType(objectType: $schema),
			uuid: $loserUuid,
			_rbac: false,
			_multitenancy: false
		);
	}//end markMerged()

	/**
	 * One object by uuid, or null when it cannot be read.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 * @param string                 $uuid    The uuid.
	 *
	 * @return ObjectEntityInterface|null
	 */
	private function findQuietly(ObjectServiceInterface $objects, string $uuid): ?ObjectEntityInterface {
		try {
			return $objects->find(id: $uuid, _rbac: false, _multitenancy: false, _render: false, _audit: false);
		} catch (Throwable $e) {
			$this->logger->debug('CatalogueMergeRelinker: object ' . $uuid . ' not found: ' . $e->getMessage());
			return null;
		}
	}//end findQuietly()

	/**
	 * The merged records of a merge, without the survivor and blanks.
	 *
	 * @param ObjectsMergedEvent $event The merge.
	 *
	 * @return array<int, string>
	 */
	private static function losers(ObjectsMergedEvent $event): array {
		$survivor = $event->getSurvivorUuid();

		return array_values(
			array_filter(
				array_map('strval', $event->getMergedFromUuids()),
				static fn (string $uuid): bool => $uuid !== '' && $uuid !== $survivor
			)
		);
	}//end losers()

	/**
	 * Write one moved reference to the audit log.
	 *
	 * @param array{schema: string, field: string, list: bool} $reference  The reference.
	 * @param string                                           $objectUuid The object whose reference moved.
	 * @param ObjectsMergedEvent                               $event      The merge.
	 *
	 * @return void
	 */
	private function audit(array $reference, string $objectUuid, ObjectsMergedEvent $event): void {
		$this->logger->info(
			'CatalogueMerge audit: reference moved to the survivor',
			[
				'audit' => true,
				'mergeOperationId' => $event->getMergeOperationId(),
				'schema' => $reference['schema'],
				'field' => $reference['field'],
				'object' => $objectUuid,
				'from' => self::losers(event: $event),
				'to' => $event->getSurvivorUuid(),
			]
		);
	}//end audit()
}//end class
