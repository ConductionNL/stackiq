<?php

/**
 * The CMDB import packs in OpenRegister's migration-pack store.
 *
 * The four sheet packs (module, manufacturer, usage, business owner) are
 * seeded into OpenRegister's `MigrationPackService` as built-in packs, so an
 * administrator can view and edit them in OpenRegister. Each seeded definition
 * carries the marker of CmdbImportPackMarker: the version and the hash of the
 * shipped pack it was seeded from. A stored pack that still matches its marker
 * is unedited: a later release replaces it with the new shipped pack. An
 * edited pack is never overwritten.
 *
 * `MigrationPackService` is not part of OpenRegister's `Contract` namespace,
 * so it is resolved defensively, like the validator in CmdbImportProfile.
 * The store counts as available only on an OpenRegister whose pack writes
 * require a CSRF token and lock a built-in pack's slug: an edited pack drives
 * writes with RBAC off, so packs behind write routes without those guards
 * are not run. Older OpenRegister versions keep the shipped files.
 * The row ids this store resolved are kept in stackiq's app config, so a
 * store that disappears after a seed, or a pack that was renamed, fails
 * closed instead of quietly running the shipped files.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Exception\CmdbImportException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Seeds, finds and updates the CMDB import packs in OpenRegister.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 */
class CmdbImportPackStore {
	/**
	 * OpenRegister's migration-pack service (not a public contract).
	 */
	public const SERVICE_CLASS = 'OCA\OpenRegister\Service\MigrationPackService';

	/**
	 * App config key: the OpenRegister row id per pack slug, as JSON.
	 */
	public const CONFIG_KEY = 'cmdb_import_pack_ids';

	/**
	 * The store resolved the pack as it was.
	 */
	public const FOUND = 'found';

	/**
	 * The store created the pack from the shipped file.
	 */
	public const SEEDED = 'seeded';

	/**
	 * The store replaced an unedited pack with a newer shipped one.
	 */
	public const UPDATED = 'updated';

	/**
	 * The methods this store calls on OpenRegister's service.
	 *
	 * @var array<int, string>
	 */
	private const SERVICE_METHODS = ['findByPackSlug', 'find', 'create', 'update'];

	/**
	 * A class only an OpenRegister with the hardened pack store has.
	 *
	 * It ships with the CSRF-protected, admin-only pack writes and the lock on
	 * a built-in pack's slug.
	 */
	public const HARDENED_CLASS = 'OCA\OpenRegister\Exception\BuiltinPackSlugLockedException';

	/**
	 * OpenRegister's service, once resolved; false when it is not available.
	 *
	 * @var object|false|null
	 */
	private object|false|null $service = null;

	/**
	 * Reads and writes the seed marker.
	 *
	 * @var CmdbImportPackMarker
	 */
	private readonly CmdbImportPackMarker $marker;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's service, and the app config when none is given.
	 * @param IAppConfig|null $appConfig Keeps the row ids; null resolves it from the container.
	 * @param string $hardenedClass The class that marks an OpenRegister with the hardened pack store.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private ?IAppConfig $appConfig = null,
		private readonly string $hardenedClass = self::HARDENED_CLASS,
	) {
		$this->marker = new CmdbImportPackMarker();
	}//end __construct()

	/**
	 * Whether OpenRegister's migration-pack store can be used.
	 *
	 * Only an OpenRegister with the hardened pack store counts (HARDENED_CLASS).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function isAvailable(): bool {
		return $this->service() !== null;
	}//end isAvailable()

	/**
	 * The OpenRegister row ids this store resolved before, per pack slug.
	 *
	 * Not empty means the packs were stored in OpenRegister once, so the
	 * import may no longer fall back to the shipped files.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function recordedPackIds(): array {
		$config = $this->appConfig();
		if ($config === null) {
			return [];
		}

		$decoded = json_decode($config->getValueString(Application::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$ids = [];
		foreach ($decoded as $slug => $id) {
			if (is_string($slug) === true && is_int($id) === true && $id > 0) {
				$ids[$slug] = $id;
			}
		}

		return $ids;
	}//end recordedPackIds()

	/**
	 * Refuse the shipped files once the packs were stored in OpenRegister.
	 *
	 * Called when the store is not available: before any pack was stored
	 * there the import may use the shipped files; afterwards that would
	 * silently drop an administrator's edit.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE naming the packs the store held.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function assertShippedFallbackAllowed(): void {
		$named = [];
		foreach ($this->recordedPackIds() as $slug => $id) {
			$named[] = $slug . ' (id ' . $id . ')';
		}

		if ($named === []) {
			return;
		}

		throw new CmdbImportException(
			errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
			message: 'OpenRegister migration pack store is not available, but it holds the CMDB import packs '
				. implode(', ', $named) . '; the import does not fall back to the shipped files',
			details: ['packs' => $this->recordedPackIds()]
		);
	}//end assertShippedFallbackAllowed()

	/**
	 * The stored pack for a shipped pack: found, seeded when missing, or updated when unedited and outdated.
	 *
	 * The answer holds the stored definition, the pack the engine runs (the
	 * definition without the marker), whether it was edited (null when it
	 * carries no marker) and whether an edit kept a newer shipped pack out.
	 *
	 * @param array<string, mixed> $shipped The shipped pack, already validated.
	 *
	 * @return array<string, mixed> id (int), slug, version, definition (stored, with the marker),
	 *                              pack (without it), edited (bool|null), newerShipped (bool) and outcome.
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the store is missing or fails,
	 *                             or when the pack was renamed in OpenRegister. A create
	 *                             that fails because another request seeded the pack
	 *                             first is no failure: that pack is used.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function resolve(array $shipped): array {
		$service = $this->service();
		if ($service === null) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: 'OpenRegister MigrationPackService is not available'
			);
		}

		$slug = (string)($shipped['id'] ?? '');
		$outcome = self::FOUND;
		$entity = $this->findBySlug(service: $service, slug: $slug);
		if ($entity === null) {
			$this->assertNotRenamed(service: $service, slug: $slug);
			try {
				$entity = $this->write(
					slug: $slug,
					action: 'seeded',
					call: fn (): object => $service->create(definition: $this->marker->seed(shipped: $shipped), ownerUid: null, builtin: true)
				);
				$outcome = self::SEEDED;
			} catch (CmdbImportException $e) {
				// Another request can seed the pack between the lookup and the create; the row it stored is the pack.
				$entity = $this->findBySlug(service: $service, slug: $slug);
				if ($entity === null) {
					throw $e;
				}
			}
		} elseif ($this->marker->isOutdated(definition: self::definitionOf(entity: $entity), shipped: $shipped) === true) {
			$id = (int)$entity->getId();
			$entity = $this->write(
				slug: $slug,
				action: 'updated',
				call: fn (): object => $service->update(id: $id, definition: $this->marker->seed(shipped: $shipped))
			);
			$outcome = self::UPDATED;
		}

		$id = (int)$entity->getId();
		$this->record(slug: $slug, id: $id);

		$definition = self::definitionOf(entity: $entity);
		return [
			'id' => $id,
			'slug' => $slug,
			'version' => (string)($definition['version'] ?? ''),
			'definition' => $definition,
			'pack' => $this->marker->strip(definition: $definition),
			'edited' => $this->marker->isEdited(definition: $definition),
			'newerShipped' => $this->marker->isNewerShipped(definition: $definition, shipped: $shipped),
			'outcome' => $outcome,
		];
	}//end resolve()

	/**
	 * The decoded definition of a stored pack.
	 *
	 * @param object $entity The MigrationPack entity.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private static function definitionOf(object $entity): array {
		$definition = $entity->getDefinitionArray();
		if (is_array($definition) === false) {
			return [];
		}

		return $definition;
	}//end definitionOf()

	/**
	 * Find a stored pack by slug.
	 *
	 * @param object $service OpenRegister's service.
	 * @param string $slug The pack slug.
	 *
	 * @return object|null The entity, or null when no pack has the slug.
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the lookup fails otherwise.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function findBySlug(object $service, string $slug): ?object {
		try {
			return $service->findByPackSlug(packSlug: $slug);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (Throwable $e) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: 'OpenRegister migration pack ' . $slug . ' could not be read: ' . get_class($e),
				details: ['pack' => $slug],
				previous: $e
			);
		}
	}//end findBySlug()

	/**
	 * Refuse when the row recorded for a slug still exists under another slug.
	 *
	 * A renamed pack is one the import no longer reads, so seeding a second
	 * one would let the administrator edit a pack that never runs.
	 *
	 * @param object $service OpenRegister's service.
	 * @param string $slug The pack slug.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the pack was renamed or the lookup fails.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function assertNotRenamed(object $service, string $slug): void {
		$id = ($this->recordedPackIds()[$slug] ?? null);
		if ($id === null) {
			return;
		}

		try {
			$entity = $service->find(id: $id);
		} catch (DoesNotExistException $e) {
			// Deleted: seeding it again restores the shipped pack.
			return;
		} catch (Throwable $e) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: 'OpenRegister migration pack ' . $slug . ' (id ' . $id . ') could not be read: ' . get_class($e),
				details: ['pack' => $slug, 'packId' => $id],
				previous: $e
			);
		}

		throw new CmdbImportException(
			errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
			message: 'OpenRegister migration pack id ' . $id . ' was renamed from ' . $slug . ' to '
				. (string)$entity->getPackSlug() . '; the import reads it by the id ' . $slug . ' only, so rename it back',
			details: ['pack' => $slug, 'packId' => $id]
		);
	}//end assertNotRenamed()

	/**
	 * Create or update a stored pack, turning a failure into MAPPING_UNAVAILABLE.
	 *
	 * @param string $slug The pack slug.
	 * @param string $action What the call does, for the message.
	 * @param callable(): object $call The service call.
	 *
	 * @return object The entity.
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the call fails.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function write(string $slug, string $action, callable $call): object {
		try {
			return $call();
		} catch (Throwable $e) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: 'OpenRegister migration pack ' . $slug . ' could not be ' . $action . ': ' . get_class($e),
				details: ['pack' => $slug],
				previous: $e
			);
		}
	}//end write()

	/**
	 * Remember the row id of a slug in the app config, when it changed.
	 *
	 * @param string $slug The pack slug.
	 * @param int $id The row id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function record(string $slug, int $id): void {
		$config = $this->appConfig();
		if ($config === null) {
			return;
		}

		$ids = $this->recordedPackIds();
		if (($ids[$slug] ?? null) === $id) {
			return;
		}

		$ids[$slug] = $id;
		ksort($ids);
		$config->setValueString(Application::APP_ID, self::CONFIG_KEY, (string)json_encode($ids));
	}//end record()

	/**
	 * OpenRegister's service, resolved once; null on an OpenRegister without the hardened pack store.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function service(): ?object {
		if ($this->service === null) {
			$this->service = false;
			try {
				if (class_exists($this->hardenedClass) === true && $this->container->has(self::SERVICE_CLASS) === true) {
					$service = $this->container->get(self::SERVICE_CLASS);
					if (is_object($service) === true && self::hasMethods(service: $service) === true) {
						$this->service = $service;
					}
				}
			} catch (Throwable $e) {
				// OpenRegister is missing or its service cannot be built: the store is not available.
				$this->service = false;
			}
		}

		if ($this->service === false) {
			return null;
		}

		return $this->service;
	}//end service()

	/**
	 * Whether a resolved service has every method this store calls.
	 *
	 * @param object $service The service.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private static function hasMethods(object $service): bool {
		foreach (self::SERVICE_METHODS as $method) {
			if (method_exists($service, $method) === false) {
				return false;
			}
		}

		return true;
	}//end hasMethods()

	/**
	 * The app config, resolved from the container when it was not given.
	 *
	 * @return IAppConfig|null
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function appConfig(): ?IAppConfig {
		if ($this->appConfig !== null) {
			return $this->appConfig;
		}

		try {
			if ($this->container->has(IAppConfig::class) === true) {
				$config = $this->container->get(IAppConfig::class);
				if ($config instanceof IAppConfig) {
					$this->appConfig = $config;
				}
			}
		} catch (Throwable $e) {
			// No app config in this container: nothing is recorded.
			$this->appConfig = null;
		}

		return $this->appConfig;
	}//end appConfig()
}//end class
