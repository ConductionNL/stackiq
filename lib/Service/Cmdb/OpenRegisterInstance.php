<?php

/**
 * Constructs OpenRegister's pack validator and mapping engine across versions.
 *
 * Newer OpenRegister versions give these classes a required `IL10N`
 * constructor parameter, older ones have none. Constructing them by reflection
 * works against both.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionParameter;
use Throwable;

/**
 * Builds an OpenRegister class with the constructor arguments it declares.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 */
class OpenRegisterInstance {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container that resolves the translation factory.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Construct the class: without arguments, or with OpenRegister's translations when its constructor takes an IL10N.
	 *
	 * @param string $class The OpenRegister class.
	 *
	 * @return object|null The instance, or null when it cannot be built.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function build(string $class): ?object {
		try {
			$arguments = [];
			foreach (self::requiredParameters(class: $class) as $parameter) {
				if (self::takesL10n(parameter: $parameter) === false) {
					return null;
				}

				$arguments[] = $this->l10n();
			}

			return new $class(...$arguments);
		} catch (Throwable $e) {
			return null;
		}
	}//end build()

	/**
	 * The constructor parameters of a class up to its first optional one.
	 *
	 * @param string $class The class.
	 *
	 * @return array<int, ReflectionParameter>
	 *
	 * @throws \ReflectionException When the class does not exist.
	 */
	private static function requiredParameters(string $class): array {
		$constructor = (new ReflectionClass($class))->getConstructor();
		$required = [];
		foreach (($constructor?->getParameters() ?? []) as $parameter) {
			if ($parameter->isOptional() === true) {
				break;
			}

			$required[] = $parameter;
		}

		return $required;
	}//end requiredParameters()

	/**
	 * Whether a constructor parameter takes OpenRegister's translations.
	 *
	 * @param ReflectionParameter $parameter The parameter.
	 *
	 * @return bool
	 */
	private static function takesL10n(ReflectionParameter $parameter): bool {
		$type = $parameter->getType();

		return $type instanceof \ReflectionNamedType && $type->getName() === IL10N::class;
	}//end takesL10n()

	/**
	 * OpenRegister's translations.
	 *
	 * @return IL10N
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	private function l10n(): IL10N {
		$factory = $this->container->get(IFactory::class);

		return $factory->get('openregister');
	}//end l10n()
}//end class
