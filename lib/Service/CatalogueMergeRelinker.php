<?php

/**
 * Catalogue Merge Relinker.
 *
 * After OpenRegister merges two applications or two services, moves every
 * catalogue reference to the merged record onto the survivor and records
 * which record it was merged into. OpenRegister's merge relinks one reverse
 * reference per schema, meant for source records; which fields point at an
 * application is the catalogue's own knowledge (CatalogueReferenceMap).
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Re-points catalogue references after a merge.
 */
class CatalogueMergeRelinker {

	/**
	 * The merged schemas this relinker handles.
	 */
	private const MERGED_SCHEMAS = ['module', 'catalogService'];

	/**
	 * Objects read per referencing schema.
	 */
	private const SCAN_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Register and schema id resolution.
	 * @param ContainerInterface $container       Resolves OpenRegister's object service.
	 * @param IEventDispatcher   $eventDispatcher Nextcloud's admin audit event.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Move the references to the merged records onto the survivor.
	 *
	 * @param string             $survivorUuid    The record that survives.
	 * @param array<int, string> $mergedFromUuids The records merged into it.
	 * @param string             $operationId     OpenRegister's merge operation.
	 *
	 * @return int The number of references moved.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public function relink(string $survivorUuid, array $mergedFromUuids, string $operationId): int {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		$type = $this->mergedType(objectService: $objectService, survivorUuid: $survivorUuid);
		if ($type === null) {
			return 0;
		}

		$moved = 0;
		foreach (CatalogueReferenceMap::referencesTo(schema: $type) as $schema => $fields) {
			foreach ($this->objectsOf(objectService: $objectService, schema: $schema) as $object) {
				$moved += $this->relinkObject(
					objectService: $objectService,
					object: $object,
					schema: $schema,
					fields: $fields,
					context: ['survivor' => $survivorUuid, 'mergedFrom' => $mergedFromUuids, 'operation' => $operationId]
				);
			}
		}

		foreach ($mergedFromUuids as $mergedUuid) {
			$this->markMerged(objectService: $objectService, mergedUuid: (string) $mergedUuid, survivorUuid: $survivorUuid);
		}

		return $moved;
	}//end relink()

	/**
	 * The schema slug of the survivor, when it is an application or a service.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param string                 $survivorUuid  The survivor.
	 *
	 * @return string|null module, catalogService, or null for anything else.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	private function mergedType(ObjectServiceInterface $objectService, string $survivorUuid): ?string {
		$survivor = $this->find(objectService: $objectService, uuid: $survivorUuid);
		if ($survivor === null) {
			return null;
		}

		foreach (self::MERGED_SCHEMAS as $type) {
			$schemaId = $this->settingsService->getSchemaIdForObjectType($type);
			if ($schemaId !== null && (string) $schemaId === (string) $survivor->getSchema()) {
				return $type;
			}
		}

		return null;
	}//end mergedType()

	/**
	 * Replace every merged uuid in one object and save it when something moved.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param ObjectEntityInterface  $object        The referencing object.
	 * @param string                 $schema        Its schema slug.
	 * @param array<string, bool>    $fields        The reference fields.
	 * @param array<string, mixed>   $context       survivor, mergedFrom and operation.
	 *
	 * @return int The number of references moved.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	private function relinkObject(ObjectServiceInterface $objectService, ObjectEntityInterface $object, string $schema, array $fields, array $context): int {
		$data  = $object->getObject();
		$moves = [];
		foreach ($context['mergedFrom'] as $mergedUuid) {
			[$data, $moved] = CatalogueReferenceMap::rewrite(data: $data, fields: $fields, from: (string) $mergedUuid, to: $context['survivor']);
			foreach ($moved as $field) {
				$moves[] = [$field, (string) $mergedUuid];
			}
		}

		if ($moves === []) {
			return 0;
		}

		try {
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $object->getRegister(),
				schema: $object->getSchema(),
				uuid: $object->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CatalogueMergeRelinker: could not re-point a reference',
				['uuid' => $object->getUuid(), 'schema' => $schema, 'operation' => $context['operation'], 'error' => $e->getMessage()]
			);
			return 0;
		}

		foreach ($moves as [$field, $mergedUuid]) {
			$this->audit(schema: $schema, field: $field, objectUuid: (string) $object->getUuid(), from: $mergedUuid, context: $context);
		}

		return count($moves);
	}//end relinkObject()

	/**
	 * Record on a merged record which record it was merged into.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param string                 $mergedUuid    The merged record.
	 * @param string                 $survivorUuid  The survivor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	private function markMerged(ObjectServiceInterface $objectService, string $mergedUuid, string $survivorUuid): void {
		$merged = $this->find(objectService: $objectService, uuid: $mergedUuid);
		if ($merged === null) {
			return;
		}

		$data = $merged->getObject();
		$data['mergedInto'] = $survivorUuid;
		try {
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $merged->getRegister(),
				schema: $merged->getSchema(),
				uuid: $mergedUuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('CatalogueMergeRelinker: could not record mergedInto', ['uuid' => $mergedUuid, 'error' => $e->getMessage()]);
		}
	}//end markMerged()

	/**
	 * One audit entry for one moved reference: a structured log line and
	 * Nextcloud's admin audit event, as the organisation merge writes them.
	 *
	 * @param string               $schema     The referencing schema.
	 * @param string               $field      The field that moved.
	 * @param string               $objectUuid The referencing object.
	 * @param string               $from       The merged uuid.
	 * @param array<string, mixed> $context    survivor and operation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	private function audit(string $schema, string $field, string $objectUuid, string $from, array $context): void {
		$entry = [
			'schema' => $schema,
			'field' => $field,
			'object' => $objectUuid,
			'from' => $from,
			'to' => $context['survivor'],
			'mergeOperation' => $context['operation'],
			'audit' => true,
		];
		$this->logger->info('CatalogueMerge audit: reference moved', $entry);
		$this->eventDispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				'CatalogueMerge: %s.%s of %s moved from %s to %s (merge operation %s)',
				[$schema, $field, $objectUuid, $from, $context['survivor'], $context['operation']]
			)
		);
	}//end audit()

	/**
	 * Every object of one referencing schema.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param string                 $schema        The schema slug.
	 *
	 * @return array<int, ObjectEntityInterface> The objects; empty when the schema is not configured.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	private function objectsOf(ObjectServiceInterface $objectService, string $schema): array {
		$register = $this->settingsService->getRegisterIdForObjectType($schema);
		$schemaId = $this->settingsService->getSchemaIdForObjectType($schema);
		if ($register === null || $schemaId === null) {
			return [];
		}

		try {
			return (array) $objectService->searchObjects(
				query: ['register' => $register, 'schema' => $schemaId, '_limit' => self::SCAN_LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('CatalogueMergeRelinker: could not read ' . $schema, ['error' => $e->getMessage()]);
			return [];
		}
	}//end objectsOf()

	/**
	 * Read one object by uuid.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param string                 $uuid          The uuid.
	 *
	 * @return ObjectEntityInterface|null The object.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	private function find(ObjectServiceInterface $objectService, string $uuid): ?ObjectEntityInterface {
		try {
			return $objectService->find(id: $uuid, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('CatalogueMergeRelinker: could not read ' . $uuid, ['error' => $e->getMessage()]);
			return null;
		}
	}//end find()

	/**
	 * OpenRegister's object service, when it is installed.
	 *
	 * @return ObjectServiceInterface|null The service.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			$service = $this->container->get(ObjectServiceInterface::class);
			if ($service instanceof ObjectServiceInterface) {
				return $service;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('CatalogueMergeRelinker: ObjectService not resolvable', ['error' => $e->getMessage()]);
		}

		return null;
	}//end getObjectService()
}//end class
