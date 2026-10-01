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
	 * The most versions one module save updates.
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
	 * @param ObjectEntityInterface $object The saved object.
	 *
	 * @return integer The number of versions written.
	 *
	 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function objectSaved(ObjectEntityInterface $object): int {
		$schema = (string) $object->getSchema();
		if ($schema === (string) $this->settingsService->getSchemaIdForObjectType('module')) {
			return $this->moduleSaved(module: $object);
		}

		if ($schema === (string) $this->settingsService->getSchemaIdForObjectType('moduleVersion')) {
			return $this->versionSaved(version: $object);
		}

		return 0;
	}//end objectSaved()

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
		$objects  = $this->objectService();
		$register = $this->settingsService->getRegisterIdForObjectType('moduleVersion');
		$schema   = $this->settingsService->getSchemaIdForObjectType('moduleVersion');
		if ($objects === null || $register === null || $schema === null) {
			return 0;
		}

		try {
			$versions = $objects->searchObjects(
				query: ['register' => $register, 'schema' => $schema, 'module' => $module->getUuid(), '_limit' => self::VERSION_LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('ModuleVersionPublicationService: could not read the versions', ['error' => $e->getMessage()]);
			return 0;
		}

		$mirror  = self::mirrorOf(module: (array) $module->getObject());
		$written = 0;
		foreach ((array) $versions as $version) {
			if (($version instanceof ObjectEntityInterface) === true && $this->write(objects: $objects, version: $version, mirror: $mirror) === true) {
				$written++;
			}
		}

		return $written;
	}//end moduleSaved()

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
	 * @return boolean True when it was written.
	 */
	private function write(ObjectServiceInterface $objects, ObjectEntityInterface $version, array $mirror): bool {
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
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ModuleVersionPublicationService: could not copy the publication onto a version',
				['uuid' => $version->getUuid(), 'error' => $e->getMessage()]
			);
			return false;
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
