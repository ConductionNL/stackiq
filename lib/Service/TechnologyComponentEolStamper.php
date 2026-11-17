<?php

/**
 * Technology Component EOL Stamper.
 *
 * When the end-of-life sync stamps a software version with its end of support,
 * the technology components that are that version get the same date with its
 * provenance (operations-technology-components, REQ-TCO-004). A component
 * without `software` is never read here, so a typed hardware date stays.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-004-the-end-of-life-sync-shall-stamp-a-components-end-of-support-from-the-software-version-it-is
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps a version's end of support on the components that are that version.
 *
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-004-the-end-of-life-sync-shall-stamp-a-components-end-of-support-from-the-software-version-it-is
 */
class TechnologyComponentEolStamper {

	/**
	 * The most components read for one version; an explicit bound on the lookup.
	 */
	public const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the technologyComponent register and schema.
	 * @param LoggerInterface $logger          Notes a component that could not be saved.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the end of support of a stamped version on every component that is it.
	 *
	 * @param ObjectServiceInterface $objectService  The OpenRegister object service.
	 * @param array                  $stampedVersion The stamped `moduleVersion`: its id, `dateEndSupport`, `eolSource` and `eolUpdatedOn`.
	 *
	 * @return int How many components were stamped.
	 *
	 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-004-the-end-of-life-sync-shall-stamp-a-components-end-of-support-from-the-software-version-it-is
	 */
	public function stamp(ObjectServiceInterface $objectService, array $stampedVersion): int {
		$versionUuid = (string) ($stampedVersion['id'] ?? '');
		$ids         = $this->componentIds();
		if ($versionUuid === '' || $ids === null) {
			return 0;
		}

		$stamp   = self::stampOf(stampedVersion: $stampedVersion);
		$stamped = 0;
		$query   = [
			'@self' => $ids,
			'software' => $versionUuid,
			'_limit' => self::LIMIT,
		];
		foreach ($this->components(objectService: $objectService, query: $query) as $component) {
			$write = ['ids' => $ids, 'component' => array_merge($component, $stamp)];
			if ($this->save(objectService: $objectService, write: $write) === true) {
				$stamped++;
			}
		}

		return $stamped;
	}//end stamp()

	/**
	 * The technologyComponent register and schema, or null when either is not configured.
	 *
	 * @return array{register: int, schema: int}|null
	 */
	private function componentIds(): ?array {
		$registerId = $this->settingsService->getRegisterIdForObjectType('technologyComponent');
		$schemaId   = $this->settingsService->getSchemaIdForObjectType('technologyComponent');
		if ($registerId === null || $schemaId === null) {
			return null;
		}

		return ['register' => $registerId, 'schema' => $schemaId];
	}//end componentIds()

	/**
	 * The fields a stamped version puts on each of its components.
	 *
	 * @param array $stampedVersion The stamped `moduleVersion`.
	 *
	 * @return array{endOfSupport: string, eolSource: string, eolUpdatedOn: string}
	 */
	private static function stampOf(array $stampedVersion): array {
		return [
			'endOfSupport' => substr((string) ($stampedVersion['dateEndSupport'] ?? ''), 0, 10),
			'eolSource' => (string) ($stampedVersion['eolSource'] ?? ''),
			'eolUpdatedOn' => (string) ($stampedVersion['eolUpdatedOn'] ?? ''),
		];
	}//end stampOf()

	/**
	 * The components whose `software` is the version, read as the system; a row without an id is left out.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param array                  $query         The search: `@self` register and schema, `software` the version, `_limit`.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function components(ObjectServiceInterface $objectService, array $query): array {
		try {
			$rows = $objectService->searchObjects(
				query: $query,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[TechnologyComponentEolStamper] Failed to read the components of a version — left untouched',
				['version' => $query['software'], 'error' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$components = [];
		foreach ($rows as $row) {
			$component = $this->normalise(row: $row);
			if ((string) ($component['id'] ?? '') !== '') {
				$components[] = $component;
			}
		}

		return $components;
	}//end components()

	/**
	 * Save one stamped component, PUT-semantic, as the system.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param array                  $write         `ids`: the technologyComponent register and schema;
	 *                                              `component`: the complete component with its stamp and `id`.
	 *
	 * @return bool Whether it was saved.
	 */
	private function save(ObjectServiceInterface $objectService, array $write): bool {
		$ids       = $write['ids'];
		$component = $write['component'];
		$uuid      = (string) $component['id'];
		try {
			$objectService->saveObject(
				object: $component,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $ids['register'], schema: $ids['schema']),
					uuid: $uuid
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[TechnologyComponentEolStamper] Failed to save a stamped component — left untouched',
				['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end save()

	/**
	 * A search row as a plain array with an `id`.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function normalise(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return self::entityData(row: $row);
		}

		$data = (array) $row;
		if (empty($data['id']) === true && isset($data['@self']['id']) === true) {
			$data['id'] = (string) $data['@self']['id'];
		}

		return $data;
	}//end normalise()

	/**
	 * An entity row's data, with its uuid as `id` when the data has none.
	 *
	 * @param object $row The entity, which has getObject().
	 *
	 * @return array<string, mixed>
	 */
	private static function entityData(object $row): array {
		$data = (array) $row->getObject();
		if (method_exists($row, 'getUuid') === true && empty($data['id']) === true) {
			$data['id'] = (string) $row->getUuid();
		}

		return $data;
	}//end entityData()
}//end class
