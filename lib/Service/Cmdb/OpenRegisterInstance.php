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
use Throwable;

/**
 * Builds an OpenRegister class with the constructor arguments it declares.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 */
class OpenRegisterInstance {
	/**
	 * Replaces the translations lookup; for unit tests that run without a Nextcloud server.
	 *
	 * @var (callable(): IL10N)|null
	 */
	public static $l10nOverride = null;

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
			$constructor = (new ReflectionClass($class))->getConstructor();
			$arguments = [];
			foreach (($constructor?->getParameters() ?? []) as $parameter) {
				if ($parameter->isOptional() === true) {
					break;
				}

				$type = $parameter->getType();
				if ($type instanceof \ReflectionNamedType && $type->getName() === IL10N::class) {
					$arguments[] = $this->l10n();
					continue;
				}

				return null;
			}

			return new $class(...$arguments);
		} catch (Throwable $e) {
			return null;
		}
	}//end build()

	/**
	 * OpenRegister's translations.
	 *
	 * @return IL10N
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	private function l10n(): IL10N {
		if (self::$l10nOverride !== null) {
			return (self::$l10nOverride)();
		}

		$factory = $this->container->get(IFactory::class);

		return $factory->get('openregister');
	}//end l10n()
}//end class
