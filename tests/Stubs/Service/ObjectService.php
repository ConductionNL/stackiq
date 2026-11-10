<?php

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * The real ObjectService lives in the OpenRegister app which is not available
 * as a Composer dependency in the test environment. This stub declares the
 * methods used by Stackiq unit tests so PHPUnit can create mocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectSelection;
use OCA\OpenRegister\Kernel\Contracts\SearchOptions;

/**
 * Stub for ObjectService with the surface used by Stackiq tests.
 */
abstract class ObjectService {

	/**
	 * Find a single object.
	 *
	 * @param string|int $id Object id or uuid.
	 * @param array|null $_extend Optional extend directives.
	 * @param bool $files Include files.
	 * @param string|int|null $register Register slug or id.
	 * @param string|int|null $schema Schema slug or id.
	 * @param bool $_rbac Apply RBAC.
	 * @param bool $_multitenancy Apply multitenancy.
	 *
	 * @return ObjectEntity|null
	 */
	abstract public function find(
		string|int $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
	): ?ObjectEntity;

	/**
	 * Find all objects matching a config bag (register/schema/filters/limit/...).
	 *
	 * @param array $config Configuration bag (`_register`, `_schema`, `filters`, `limit`, ...).
	 * @param bool $_rbac Apply RBAC.
	 * @param bool $_multitenancy Apply multitenancy.
	 *
	 * @return array<int, ObjectEntity>
	 */
	abstract public function findAll(
		array $config = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array;

	/**
	 * Search objects with pagination.
	 *
	 * @param array         $query         Search query.
	 * @param bool          $_rbac         Apply RBAC.
	 * @param bool          $_multitenancy Apply multitenancy.
	 * @param SearchOptions $options       Ids/uses selection, views, view scope and deleted.
	 *
	 * @return array
	 */
	abstract public function searchObjectsPaginated(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		SearchOptions $options = new SearchOptions(),
	): array;

	/**
	 * Search objects (non-paginated).
	 *
	 * @param array         $query         Search query.
	 * @param bool          $_rbac         Apply RBAC.
	 * @param bool          $_multitenancy Apply multitenancy.
	 * @param SearchOptions $options       Ids/uses selection, views, view scope and deleted.
	 *
	 * @return array|int
	 */
	abstract public function searchObjects(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		SearchOptions $options = new SearchOptions(),
	): array|int;

	/**
	 * Count objects matching a query (true SQL COUNT, no hydration).
	 *
	 * @param array           $query         Search query.
	 * @param bool            $_rbac         Apply RBAC.
	 * @param bool            $_multitenancy Apply multitenancy.
	 * @param ObjectSelection $selection     Ids/uses selection.
	 *
	 * @return int
	 */
	abstract public function countSearchObjects(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		ObjectSelection $selection = new ObjectSelection(),
	): int;

	/**
	 * Persist an object.
	 *
	 * `$register`/`$schema`/`$uuid` keep their original stub positions
	 * (position-bound `willReturnCallback` closures in existing tests rely
	 * on that order); `$extend`/`$silent`/`$uploadedFiles`/`$currentUser`
	 * are appended so production code that calls `saveObject()` with named
	 * arguments (the convention throughout this codebase — see
	 * PublicationService/IntakeService/FederationService) resolves
	 * correctly against the mock regardless of declared position. `$object`
	 * is widened to `array|ObjectEntity` to match the real
	 * `OCA\OpenRegister\Service\ObjectService::saveObject()` signature.
	 *
	 * @param array|ObjectEntity $object The object data bag or entity.
	 * @param int|string $register Register slug or id.
	 * @param int|string $schema Schema slug or id.
	 * @param string $uuid Object uuid.
	 * @param bool $_rbac Apply RBAC.
	 * @param bool $_multitenancy Apply multitenancy.
	 * @param array|null $extend Properties to extend the object with.
	 * @param bool $silent Skip audit trail creation and events.
	 * @param array|null $uploadedFiles Uploaded files.
	 * @param mixed $currentUser Explicit acting user.
	 *
	 * @return ObjectEntity
	 */
	abstract public function saveObject(
		array|ObjectEntity $object = [],
		int|string $register = '',
		int|string $schema = '',
		string $uuid = '',
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $extend = [],
		bool $silent = false,
		?array $uploadedFiles = null,
		mixed $currentUser = null,
	): ObjectEntity;

	/**
	 * Build a base search query from request-shaped options.
	 *
	 * @param array $options Request-shaped options (limit, offset, extend, etc.).
	 *
	 * @return array
	 */
	abstract public function buildSearchQuery(array $options = []): array;

	/**
	 * Delete an object.
	 *
	 * @param string $uuid Object uuid.
	 * @param bool $_rbac Apply RBAC.
	 * @param bool $_multitenancy Apply multitenancy.
	 *
	 * @return bool
	 */
	abstract public function deleteObject(
		string $uuid,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): bool;

	/**
	 * Bulk-persist a batch of objects (used by bounded-batch bulk-save paths,
	 * e.g. `SbomImportService`).
	 *
	 * @param array $objects Array of object data bags.
	 * @param string|int|null $register Register slug or id.
	 * @param string|int|null $schema Schema slug or id.
	 * @param bool $_rbac Apply RBAC.
	 * @param bool $_multitenancy Apply multitenancy.
	 * @param bool $validation Run validation.
	 * @param bool $events Dispatch events.
	 * @param bool $deduplicateIds Deduplicate ids across the batch.
	 * @param bool $enrich Enrich saved objects.
	 *
	 * @return array
	 */
	abstract public function saveObjects(
		array $objects,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $validation = false,
		bool $events = false,
		bool $deduplicateIds = true,
		bool $enrich = true,
	): array;

	/**
	 * Bulk soft/hard-delete objects by uuid (used by bounded-batch
	 * replace-on-reimport paths, e.g. `SbomImportService`).
	 *
	 * @param array $uuids Array of object uuids.
	 * @param bool $_rbac Apply RBAC.
	 * @param bool $_multitenancy Apply multitenancy.
	 *
	 * @return array{deleted_uuids: array, skipped_uuids: array, cascade_count: int}
	 */
	abstract public function deleteObjects(
		array $uuids = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array;

	/**
	 * Set the active register context.
	 *
	 * @param mixed $register Register slug or id.
	 *
	 * @return void
	 */
	abstract public function setRegister($register): void;

	/**
	 * Set the active schema context.
	 *
	 * @param mixed $schema Schema slug or id.
	 *
	 * @return void
	 */
	abstract public function setSchema($schema): void;

}//end class
