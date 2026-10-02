<?php

/**
 * Keeps a module version as public as its application, and no more.
 *
 * OpenRegister decides read access per object, and a read rule can only match
 * fields of the object itself. A version's public rule therefore needs its
 * application's publication on the version: `modulePublicationDate` and
 * `moduleRegisteredBy`, copied from the module. This service copies them when
 * a module is saved (onto all its versions) and when a version is saved (from
 * its module). It writes only when a value differs, so the writes it causes
 * end at the next event.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies a module's publication onto its versions.
 *
 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 */
class ModuleVersionPublicationService {

	/**
	 * How many versions one search reads; a module with more is read page by page.
	 *
	 * @var integer
	 */
	public const VERSION_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Resolves the module and moduleVersion schemas.
	 * @param ContainerInterface $container       Resolves OpenRegister's object service.
	 * @param LoggerInterface    $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The two fields a version mirrors from its module.
	 *
	 * @param array<string, mixed> $module The module's data.
	 *
	 * @return array{modulePublicationDate: string|null, moduleRegisteredBy: string|null}
	 *
	 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public static function mirrorOf(array $module): array {
		return [
			'modulePublicationDate' => self::text(value: ($module['publicationDate'] ?? null)),
			'moduleRegisteredBy'    => self::text(value: ($module['registeredBy'] ?? null)),
		];
	}//end mirrorOf()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string, or null when it is empty or not a string.
	 */
	private static function text(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end text()

	/**
	 * React to a saved object: a module updates its versions, a version reads its module.
	 *
	 * A module update that leaves its publication date and registrant as they
	 * were has nothing to copy, so its versions are not searched.
	 *
	 * @param ObjectEntityInterface      $object   The saved object.
	 * @param ObjectEntityInterface|null $previous The object before an update, or null for a new one.
	 *
	 * @return integer The number of versions written.
	 *
	 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function objectSaved(ObjectEntityInterface $object, ?ObjectEntityInterface $previous = null): int {
		$schema = (string) $object->getSchema();
		if ($schema === (string) $this->settingsService->getSchemaIdForObjectType('module')) {
			if ($previous !== null
				&& self::mirrorOf(module: (array) $previous->getObject()) === self::mirrorOf(module: (array) $object->getObject())
			) {
				return 0;
			}

			return $this->moduleSaved(module: $object);
		}

		if ($schema === (string) $this->settingsService->getSchemaIdForObjectType('moduleVersion')) {
			return $this->versionSaved(version: $object);
		}

		return 0;
	}//end objectSaved()

	/**
	 * React to a deleted object: the versions of a deleted module stop following a publication.
	 *
	 * @param ObjectEntityInterface $object The deleted object.
	 *
	 * @return integer The number of versions written.
	 *
	 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function objectDeleted(ObjectEntityInterface $object): int {
		if ((string) $object->getSchema() !== (string) $this->settingsService->getSchemaIdForObjectType('module')) {
			return 0;
		}

		return $this->copyOntoVersions(moduleUuid: (string) $object->getUuid(), mirror: self::mirrorOf(module: []))['written'];
	}//end objectDeleted()

	/**
	 * Copy a module's publication onto every version of it that differs.
	 *
	 * @param ObjectEntityInterface $module The saved module.
	 *
	 * @return integer The number of versions written.
	 *
	 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function moduleSaved(ObjectEntityInterface $module): int {
		return $this->backfillModule(module: $module)['written'];
	}//end moduleSaved()

	/**
	 * Copy a module's publication onto its versions and say what could not be copied.
	 *
	 * @param ObjectEntityInterface $module The module.
	 *
	 * @return array{written: int, failed: int} The versions written, and the versions or searches that failed.
	 *
	 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function backfillModule(ObjectEntityInterface $module): array {
		return $this->copyOntoVersions(moduleUuid: (string) $module->getUuid(), mirror: self::mirrorOf(module: (array) $module->getObject()));
	}//end backfillModule()

	/**
	 * Copy a mirror onto every version of a module that differs, page by page.
	 *
	 * @param string                                                                    $moduleUuid The module.
	 * @param array{modulePublicationDate: string|null, moduleRegisteredBy: string|null} $mirror     The values to hold.
	 *
	 * @return array{written: int, failed: int} The versions written, and the versions or searches that failed.
	 */
	private function copyOntoVersions(string $moduleUuid, array $mirror): array {
		$objects  = $this->objectService();
		$register = $this->settingsService->getRegisterIdForObjectType('moduleVersion');
		$schema   = $this->settingsService->getSchemaIdForObjectType('moduleVersion');
		$result   = ['written' => 0, 'failed' => 0];
		if ($objects === null || $register === null || $schema === null) {
			return $result;
		}

		$offset = 0;
		do {
			try {
				$versions = (array) $objects->searchObjects(
					query: [
						'register' => $register,
						'schema'   => $schema,
						'module'   => $moduleUuid,
						'_limit'   => self::VERSION_LIMIT,
						'_offset'  => $offset,
					],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$this->logFailure(
					message: 'ModuleVersionPublicationService: could not read the versions',
					context: ['module' => $moduleUuid, 'error' => $e->getMessage()],
					depublishes: (self::isPublicNow(mirror: $mirror) === false)
				);
				$result['failed']++;
				return $result;
			}

			$page = $this->writeVersions(objects: $objects, versions: $versions, mirror: $mirror);
			$result['written'] += $page['written'];
			$result['failed']  += $page['failed'];

			$offset   += self::VERSION_LIMIT;
			$pageSize  = count($versions);
		} while ($pageSize === self::VERSION_LIMIT);

		return $result;
	}//end copyOntoVersions()

	/**
	 * Write a mirror onto each version of one page.
	 *
	 * @param ObjectServiceInterface                                                    $objects  The object service.
	 * @param array<int, mixed>                                                         $versions The page.
	 * @param array{modulePublicationDate: string|null, moduleRegisteredBy: string|null} $mirror   The values to hold.
	 *
	 * @return array{written: int, failed: int} The versions written and the writes that failed.
	 */
	private function writeVersions(ObjectServiceInterface $objects, array $versions, array $mirror): array {
		$result = ['written' => 0, 'failed' => 0];
		foreach ($versions as $version) {
			if (($version instanceof ObjectEntityInterface) === false) {
				continue;
			}

			$outcome = $this->write(objects: $objects, version: $version, mirror: $mirror);
			if ($outcome === true) {
				$result['written']++;
			}

			if ($outcome === null) {
				$result['failed']++;
			}
		}

		return $result;
	}//end writeVersions()

	/**
	 * Whether a version holding this mirror is public now, by the moduleVersion read rule.
	 *
	 * @param array<string, mixed> $mirror The mirrored fields.
	 *
	 * @return boolean True when an anonymous reader may read it.
	 */
	private static function isPublicNow(array $mirror): bool {
		if (($mirror['moduleRegisteredBy'] ?? null) === 'Supplier') {
			return true;
		}

		$date = ($mirror['modulePublicationDate'] ?? null);
		if (is_string($date) === false || $date === '') {
			return false;
		}

		$time = strtotime($date);

		return $time !== false && $time <= time();
	}//end isPublicNow()

	/**
	 * Log a mirror that could not be written: critical when it leaves a version public that should not be.
	 *
	 * @param string               $message     The message.
	 * @param array<string, mixed> $context     The context.
	 * @param boolean              $depublishes Whether the write would have taken a version out of public view.
	 *
	 * @return void
	 */
	private function logFailure(string $message, array $context, bool $depublishes): void {
		if ($depublishes === true) {
			$this->logger->critical($message . '; the version stays public until it is saved again or the backfill runs', $context);
			return;
		}

		$this->logger->error($message, $context);
	}//end logFailure()

	/**
	 * Copy the module's publication onto a saved version, when it differs.
	 *
	 * @param ObjectEntityInterface $version The saved version.
	 *
	 * @return integer 1 when the version was written, else 0.
	 *
	 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function versionSaved(ObjectEntityInterface $version): int {
		$objects  = $this->objectService();
		$moduleId = self::referenceOf(value: (((array) $version->getObject())['module'] ?? null));
		if ($objects === null || $moduleId === null) {
			return 0;
		}

		try {
			$module = $objects->find(
				id: $moduleId,
				register: $this->settingsService->getRegisterIdForObjectType('module'),
				schema: $this->settingsService->getSchemaIdForObjectType('module'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$module = null;
		}

		$data = [];
		if ($module !== null) {
			$data = (array) $module->getObject();
		}

		if ($this->write(objects: $objects, version: $version, mirror: self::mirrorOf(module: $data)) === true) {
			return 1;
		}

		return 0;
	}//end versionSaved()

	/**
	 * The id a reference holds: a uuid string, or an object with `id` or `uuid`.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string|null The id, or null when there is none.
	 */
	private static function referenceOf(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		return self::text(value: $value);
	}//end referenceOf()

	/**
	 * Write the mirror onto a version when it differs from what the version holds.
	 *
	 * @param ObjectServiceInterface                                                   $objects The object service.
	 * @param ObjectEntityInterface                                                    $version The version.
	 * @param array{modulePublicationDate: string|null, moduleRegisteredBy: string|null} $mirror  The values to hold.
	 *
	 * The version is saved without validation: only the two mirrored fields
	 * change, and a version holding older data the schema no longer accepts
	 * must still follow its module.
	 *
	 * @return boolean|null True when it was written, false when it was in step, null when the write failed.
	 */
	private function write(ObjectServiceInterface $objects, ObjectEntityInterface $version, array $mirror): ?bool {
		$data = (array) $version->getObject();
		if (($data['modulePublicationDate'] ?? null) === $mirror['modulePublicationDate']
			&& ($data['moduleRegisteredBy'] ?? null) === $mirror['moduleRegisteredBy']
		) {
			return false;
		}

		try {
			$objects->saveObject(
				object: array_merge($data, $mirror),
				extend: [],
				register: $version->getRegister(),
				schema: $version->getSchema(),
				uuid: $version->getUuid(),
				_rbac: false,
				_multitenancy: false,
				_validation: false
			);
		} catch (Throwable $e) {
			$this->logFailure(
				message: 'ModuleVersionPublicationService: could not copy the publication onto a version',
				context: ['uuid' => $version->getUuid(), 'error' => $e->getMessage()],
				depublishes: (self::isPublicNow(mirror: $data) === true && self::isPublicNow(mirror: $mirror) === false)
			);
			return null;
		}

		return true;
	}//end write()

	/**
	 * Lazily resolve OpenRegister's object service.
	 *
	 * @return ObjectServiceInterface|null The service, or null when OpenRegister is absent.
	 */
	private function objectService(): ?ObjectServiceInterface {
		try {
			$service = $this->container->get(ObjectServiceInterface::class);
			if ($service instanceof ObjectServiceInterface) {
				return $service;
			}
		} catch (Throwable $e) {
			$this->logger->debug('ModuleVersionPublicationService: ObjectService not resolvable', ['error' => $e->getMessage()]);
		}

		return null;
	}//end objectService()
}//end class
