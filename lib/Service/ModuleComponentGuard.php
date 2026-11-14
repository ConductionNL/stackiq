<?php

/**
 * Stackiq Module Component Guard
 *
 * Refuses an application that would be part of itself or of one of its own components.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Walks the `partOf` chain upward from the application a module names. When
 * the chain reaches the module itself, the module would become part of one of
 * its own components, which is refused; a module naming itself is refused
 * before any read. The walk stops at the first application without a parent,
 * at one it cannot read, at a loop elsewhere in the chain, or after
 * {@see self::MAX_DEPTH} steps.
 *
 * @spec openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
 */
class ModuleComponentGuard {

	/**
	 * The most parents followed.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 25;

	/**
	 * @param ContainerInterface         $container Resolves OpenRegister's object service.
	 * @param ObjectTypeLocatorInterface $locator   The register and schema of `module`.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectTypeLocatorInterface $locator,
	) {
	}//end __construct()

	/**
	 * Why a module may not be saved with this `partOf`, or null when it may.
	 *
	 * @param array<string,mixed> $data       The module as it would be saved.
	 * @param string|null         $moduleUuid The module's own uuid; null for a new module, which no application can name yet.
	 *
	 * @return string|null The message, or null.
	 *
	 * @spec openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
	 */
	public function refusal(array $data, ?string $moduleUuid): ?string {
		$parent = self::reference(value: ($data['partOf'] ?? null));
		if ($parent === null || $moduleUuid === null || $moduleUuid === '') {
			return null;
		}

		if ($parent === $moduleUuid) {
			return 'An application cannot be part of itself.';
		}

		$seen = [];
		for ($depth = 0; $depth < self::MAX_DEPTH && $parent !== null && isset($seen[$parent]) === false; $depth++) {
			$seen[$parent] = true;
			$parent = $this->parentOf(moduleUuid: $parent);
			if ($parent === $moduleUuid) {
				return 'An application cannot be part of one of its own components.';
			}
		}

		return null;
	}//end refusal()

	/**
	 * The uuid a `partOf` value names: a plain uuid, or an object carrying `id` or `uuid`.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
	 */
	public static function reference(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end reference()

	/**
	 * The application a module is part of, read as the system.
	 *
	 * @param string $moduleUuid The module.
	 *
	 * @return string|null Its parent, or null when it has none or cannot be read.
	 */
	private function parentOf(string $moduleUuid): ?string {
		try {
			$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			if (($objects instanceof ObjectServiceInterface) === false) {
				return null;
			}

			$entity = $objects->find(
				id: $moduleUuid,
				register: $this->locator->getRegisterIdForObjectType('module'),
				schema: $this->locator->getSchemaIdForObjectType('module'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return self::reference(value: ($entity->getObject()['partOf'] ?? null));
	}//end parentOf()
}//end class
