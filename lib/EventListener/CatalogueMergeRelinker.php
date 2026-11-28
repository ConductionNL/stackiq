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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
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
 * @spec openspec/changes/archive/2026-11-25-operations-record-reconciliation/tasks.md#task-3-re-point-references-after-openregister-merges-applications-or-services
 */
class CatalogueMergeRelinker implements IEventListener {

	/**
	 * The schemas whose merges this listener follows.
	 *
	 * @var array<int, string>
	 */
	public const RELINKED_SCHEMAS = ['module', 'catalogService'];

	/**
	 * Objects of one referencing schema read per page; the walk pages on
	 * until a page comes back short, so no reference is left behind.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param RegisterConfigReaderInterface $settings The register and schema ids, and OpenRegister's object service.
	 * @param LoggerInterface               $logger   The audit log and the errors.
	 *
	 * @spec openspec/changes/archive/2026-11-25-operations-record-reconciliation/tasks.md#task-3-re-point-references-after-openregister-merges-applications-or-services
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
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
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

		$this->relink(run: ['objects' => $objects, 'event' => $event], schema: $schema);
	}//end handle()

	/**
	 * Re-point every reference to the merged records, then mark them merged.
	 * A failure is logged; OpenRegister's merge itself already stands.
	 *
	 * @param array{objects: ObjectServiceInterface, event: ObjectsMergedEvent} $run    The object service and the merge.
	 * @param string $schema The merged schema.
	 *
	 * @return void
	 */
	private function relink(array $run, string $schema): void {
		$event = $run['event'];
		try {
			foreach (CatalogueReferenceMap::referencesTo(schema: $schema) as $reference) {
				$this->relinkReference(run: $run, reference: $reference);
			}

			$this->markMerged(run: $run, schema: $schema);
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
	 * @param array{objects: ObjectServiceInterface, event: ObjectsMergedEvent} $run       The object service and the merge.
	 * @param array{schema: string, field: string, list: bool} $reference The reference.
	 *
	 * @return void
	 */
	private function relinkReference(array $run, array $reference): void {
		$register = $this->settings->getRegisterIdForObjectType(objectType: $reference['schema']);
		$schema = $this->settings->getSchemaIdForObjectType(objectType: $reference['schema']);
		if ($register === null || $schema === null) {
			return;
		}

		$offset = 0;
		do {
			$entities = $run['objects']->findAll(
				config: ['_register' => $register, '_schema' => $schema, 'limit' => self::PAGE_SIZE, 'offset' => $offset],
				_rbac: false,
				_multitenancy: false
			);
			$this->relinkPage(
				run: $run,
				page: ['entities' => $entities, 'register' => $register, 'schema' => $schema, 'reference' => $reference]
			);
			$offset += self::PAGE_SIZE;
		} while (count($entities) === self::PAGE_SIZE);
	}//end relinkReference()

	/**
	 * Re-point one reference on each object of one page that holds a merged record.
	 * Only the reference field changes, so the walk's order and paging hold.
	 *
	 * @param array{objects: ObjectServiceInterface, event: ObjectsMergedEvent} $run  The object service and the merge.
	 * @param array<string, mixed> $page The page: `entities` (ObjectEntityInterface[]),
	 *                                   `register`, `schema` and `reference`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	private function relinkPage(array $run, array $page): void {
		$objects   = $run['objects'];
		$event     = $run['event'];
		$register  = $page['register'];
		$schema    = $page['schema'];
		$reference = $page['reference'];
		$merge     = ['losers' => self::losers(event: $event), 'survivor' => $event->getSurvivorUuid()];
		foreach ($page['entities'] as $entity) {
			$data = self::repointed(
				data: $entity->getObject(),
				reference: $reference,
				merge: $merge
			);
			if ($data === null) {
				continue;
			}

			$objects->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $register, schema: $schema),
					uuid: $entity->getUuid()
				),
				_rbac: false,
				_multitenancy: false
			);
			$this->audit(event: $event, moved: ['reference' => $reference, 'object' => (string)$entity->getUuid()]);
		}
	}//end relinkPage()

	/**
	 * The object's data with the merged records replaced by the survivor in
	 * one field, or null when the field holds none of them. A list keeps the
	 * survivor once.
	 *
	 * @param array<string, mixed>                             $data      The object's data.
	 * @param array{schema: string, field: string, list: bool} $reference The reference.
	 * @param array{losers: array<int, string>, survivor: string} $merge The merged records and the survivor.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public static function repointed(array $data, array $reference, array $merge): ?array {
		$value = ($data[$reference['field']] ?? null);
		if ($reference['list'] === true) {
			$value = self::repointedList(value: $value, merge: $merge);
		} else {
			$value = self::repointedScalar(value: $value, merge: $merge);
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
	 * @param mixed                                               $value The stored list.
	 * @param array{losers: array<int, string>, survivor: string} $merge The merged records and the survivor.
	 *
	 * @return array<int, mixed>|null
	 */
	private static function repointedList(mixed $value, array $merge): ?array {
		if (is_array($value) === false || self::holdsAny(list: $value, losers: $merge['losers']) === false) {
			return null;
		}

		$replaced = array_map(
			static fn (mixed $entry): mixed => self::replacement(entry: $entry, merge: $merge),
			$value
		);

		return self::survivorOnce(list: $replaced, survivor: $merge['survivor']);
	}//end repointedList()

	/**
	 * The survivor for a merged record, else the entry itself.
	 *
	 * @param mixed                                               $entry A list entry.
	 * @param array{losers: array<int, string>, survivor: string} $merge The merged records and the survivor.
	 *
	 * @return mixed
	 */
	private static function replacement(mixed $entry, array $merge): mixed {
		if (in_array($entry, $merge['losers'], true) === true) {
			return $merge['survivor'];
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
	 * @param mixed                                               $value The stored value.
	 * @param array{losers: array<int, string>, survivor: string} $merge The merged records and the survivor.
	 *
	 * @return string|null
	 */
	private static function repointedScalar(mixed $value, array $merge): ?string {
		if (in_array($value, $merge['losers'], true) === false) {
			return null;
		}

		return $merge['survivor'];
	}//end repointedScalar()

	/**
	 * Record on each merged record which record it was merged into.
	 *
	 * @param array{objects: ObjectServiceInterface, event: ObjectsMergedEvent} $run    The object service and the merge.
	 * @param string $schema The merged schema.
	 *
	 * @return void
	 */
	private function markMerged(array $run, string $schema): void {
		$objects      = $run['objects'];
		$survivorUuid = $run['event']->getSurvivorUuid();
		$target       = new ObjectFindTarget(
			register: $this->settings->getRegisterIdForObjectType(objectType: $schema),
			schema: $this->settings->getSchemaIdForObjectType(objectType: $schema)
		);
		foreach (self::losers(event: $run['event']) as $loserUuid) {
			$loser = $this->findQuietly(objects: $objects, uuid: $loserUuid);
			if ($loser === null) {
				continue;
			}

			$data = $loser->getObject();
			$data['mergedInto'] = $survivorUuid;
			$objects->saveObject(
				object: $data,
				options: new ObjectSaveOptions(target: $target, uuid: $loserUuid),
				_rbac: false,
				_multitenancy: false
			);
		}
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
			return $objects->find(
				id: $uuid,
				options: new ObjectFindOptions(render: false, audit: false),
				_rbac: false,
				_multitenancy: false
			);
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
	 * @param ObjectsMergedEvent $event The merge.
	 * @param array<string, mixed> $moved The reference (`reference`) and the
	 *                                    object whose reference moved (`object`).
	 *
	 * @return void
	 */
	private function audit(ObjectsMergedEvent $event, array $moved): void {
		$reference  = $moved['reference'];
		$objectUuid = $moved['object'];
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
