<?php

/**
 * Whether OpenRegister's migration-pack store may run the CMDB import packs.
 *
 * An edited pack drives writes with RBAC off, so the store counts only on an
 * OpenRegister whose pack writes are admin-only and CSRF-protected and lock a
 * built-in pack's slug (openregister#4602): the class that marks that store
 * exists, and no write method of the pack controller carries an attribute or
 * a docblock annotation that skips the CSRF check or admits non-admins.
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

use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Resolves OpenRegister's pack service when the store may be used.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 */
final class CmdbImportPackStoreProbe {
	/**
	 * The methods the store calls on OpenRegister's service.
	 *
	 * @var array<int, string>
	 */
	private const SERVICE_METHODS = ['findByPackSlug', 'find', 'create', 'update'];

	/**
	 * The controller methods that write packs.
	 *
	 * @var array<int, string>
	 */
	private const WRITE_METHODS = ['create', 'update', 'destroy', 'import', 'preview'];

	/**
	 * The attributes and annotations that would open a write route to non-admins or skip its CSRF check.
	 *
	 * @var array<int, string>
	 */
	private const OPENING_MARKERS = ['NoCSRFRequired', 'NoAdminRequired', 'PublicPage', 'AuthorizedAdminSetting'];

	/**
	 * The service, once resolved; false when the store may not be used.
	 *
	 * @var object|false|null
	 */
	private object|false|null $service = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's service.
	 * @param string $serviceClass OpenRegister's pack service.
	 * @param string $hardenedClass The class that marks an OpenRegister with the hardened pack store.
	 * @param string $controllerClass The controller whose write methods must be guarded.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly string $serviceClass,
		private readonly string $hardenedClass,
		private readonly string $controllerClass,
	) {
	}//end __construct()

	/**
	 * OpenRegister's service, resolved once; null when the store may not be used.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function service(): ?object {
		if ($this->service === null) {
			$this->service = false;
			try {
				$this->service = $this->resolve();
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
	 * The service when every condition holds, false otherwise.
	 *
	 * @return object|false
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function resolve(): object|false {
		if (class_exists($this->hardenedClass) === false
			|| self::writesAreGuarded(controllerClass: $this->controllerClass) === false
			|| $this->container->has($this->serviceClass) === false
		) {
			return false;
		}

		$service = $this->container->get($this->serviceClass);
		if (is_object($service) === false || self::hasMethods(service: $service) === false) {
			return false;
		}

		return $service;
	}//end resolve()

	/**
	 * Whether every pack write method of the controller is admin-only and CSRF-protected.
	 *
	 * Nextcloud opens a route with an attribute or a docblock annotation, so
	 * a method that carries none of the opening markers keeps the default:
	 * admins only, with a CSRF token.
	 *
	 * @param string $controllerClass The controller class.
	 *
	 * @return bool False when the class or a write method is missing, or a write method is opened.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private static function writesAreGuarded(string $controllerClass): bool {
		if (class_exists($controllerClass) === false) {
			return false;
		}

		$class = new ReflectionClass($controllerClass);
		foreach (self::WRITE_METHODS as $name) {
			if ($class->hasMethod($name) === false || self::isOpened(method: $class->getMethod($name)) === true) {
				return false;
			}
		}

		return true;
	}//end writesAreGuarded()

	/**
	 * Whether a controller method carries an attribute or annotation that opens its route.
	 *
	 * @param ReflectionMethod $method The method.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private static function isOpened(ReflectionMethod $method): bool {
		$attributes = array_map(
			static fn (ReflectionAttribute $attribute): string => substr((string)strrchr('\\' . $attribute->getName(), '\\'), 1),
			$method->getAttributes()
		);
		$doc = (string)$method->getDocComment();
		foreach (self::OPENING_MARKERS as $marker) {
			if (in_array($marker, $attributes, true) === true || str_contains($doc, '@' . $marker) === true) {
				return true;
			}
		}

		return false;
	}//end isOpened()

	/**
	 * Whether a resolved service has every method the store calls.
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
}//end class
