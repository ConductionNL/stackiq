<?php

/**
 * Keeps a usage's hidden provider equal to its application's supplier.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The supplier reads the usages of its products through the usage read rule
 * on `provider` (`aanbod-beheerder`, provider = $organisation). The usage
 * form hides `provider`, so a usage a municipality adds there carries none
 * and its supplier never sees it. The provider follows the usage's module:
 * UsageProviderListener sets it on every save, BackfillUsageProvider on the
 * usages saved before.
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
 */
class UsageProviderSync {

	/**
	 * The module providers read in this request, by module uuid.
	 *
	 * @var array<string, string|null>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookup         $lookup The OpenRegister object service.
	 * @param ObjectTypeLocatorInterface $types  The configured registers and schemas.
	 * @param LoggerInterface            $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OpenRegisterLookup $lookup,
		private readonly ObjectTypeLocatorInterface $types,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The change that makes a usage's provider its module's supplier, or [].
	 *
	 * A module without a known supplier changes nothing, so a provider set by
	 * hand on such a usage stays.
	 *
	 * @param array<string, mixed> $usage    The usage.
	 * @param string|null          $supplier The module's supplier uuid.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
	 */
	public static function patch(array $usage, ?string $supplier): array {
		if ($supplier === null || $supplier === '' || RelationRefs::ref(value: ($usage['provider'] ?? null)) === $supplier) {
			return [];
		}

		return ['provider' => $supplier];
	}//end patch()

	/**
	 * The supplier of the module a usage names, or null when it has none or cannot be read.
	 *
	 * @param array<string, mixed> $usage The usage.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
	 */
	public function supplierFor(array $usage): ?string {
		$moduleId = RelationRefs::ref(value: ($usage['module'] ?? null));
		$target   = $this->target(objectType: 'module');
		if ($moduleId === null || $target === null) {
			return null;
		}

		if (array_key_exists($moduleId, $this->providers) === false) {
			$this->providers[$moduleId] = $this->readSupplier(moduleId: $moduleId, target: $target);
		}

		return $this->providers[$moduleId];
	}//end supplierFor()

	/**
	 * Set the provider on every stored usage whose provider is not its module's supplier.
	 *
	 * @return int The number of usages rewritten.
	 *
	 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
	 */
	public function backfill(): int {
		$target = $this->target(objectType: 'usage');
		if ($target === null) {
			return 0;
		}

		$objects = $this->lookup->objectService();
		$found   = $objects->searchObjects(query: SearchBounds::bounded(query: ['@self' => $target]), _rbac: false, _multitenancy: false);
		$written = 0;
		if (is_array($found) === false) {
			return 0;
		}

		foreach (($found['results'] ?? $found) as $usage) {
			if (is_object($usage) === true && method_exists($usage, 'jsonSerialize') === true) {
				$usage = $usage->jsonSerialize();
			}

			if (is_array($usage) === false || is_string($usage['id'] ?? $usage['@self']['id'] ?? null) === false) {
				continue;
			}

			$uuid = (string)($usage['id'] ?? $usage['@self']['id']);

			$patch = self::patch(usage: $usage, supplier: $this->supplierFor(usage: $usage));
			if ($patch === []) {
				continue;
			}

			try {
				$record = $usage;
				unset($record['@self']);
				$objects->saveObject(
					object: array_merge($record, $patch),
					options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $target['register'], schema: $target['schema']), uuid: $uuid, extend: []),
					_rbac: false,
					_multitenancy: false
				);
				$written++;
			} catch (Throwable $exception) {
				$this->logger->warning('UsageProviderSync: could not set the provider of a usage', ['usage' => $uuid, 'exception' => $exception->getMessage()]);
			}
		}//end foreach

		return $written;
	}//end backfill()

	/**
	 * Read a module's provider with RBAC off; null when absent or unreadable.
	 *
	 * @param string                            $moduleId The module uuid.
	 * @param array{register: int, schema: int} $target   The module register and schema.
	 *
	 * @return string|null
	 */
	private function readSupplier(string $moduleId, array $target): ?string {
		try {
			$module = $this->lookup->objectService()->find(
				id: $moduleId,
				options: new ObjectFindOptions(register: $target['register'], schema: $target['schema'], render: false, audit: false),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('UsageProviderSync: could not read the module', ['module' => $moduleId, 'exception' => $exception->getMessage()]);
			return null;
		}

		if ($module === null) {
			return null;
		}

		return RelationRefs::ref(value: (((array)$module->getObject())['provider'] ?? null));
	}//end readSupplier()

	/**
	 * The configured register and schema of an object type, or null when either is missing.
	 *
	 * @param string $objectType The object type.
	 *
	 * @return array{register: int, schema: int}|null
	 */
	private function target(string $objectType): ?array {
		$register = $this->types->getRegisterIdForObjectType($objectType);
		$schema   = $this->types->getSchemaIdForObjectType($objectType);
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end target()
}//end class
