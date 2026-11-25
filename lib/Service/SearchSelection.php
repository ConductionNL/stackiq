<?php

/**
 * Search Selection.
 *
 * The object selection of an OpenRegister search (the ids to read, or the
 * object whose uses to list) as the named arguments the installed
 * OpenRegister's search method takes. A search that takes an `options`
 * parameter gets the selection inside one SearchOptions argument; one that
 * does not gets `ids` and `uses` directly. Both answer the same search.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Adapter between two OpenRegister search signatures, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use ReflectionMethod;

/**
 * Builds the selection arguments of an OpenRegister search.
 *
 * @spec exclude Adapter between two OpenRegister search signatures, no functional requirement.
 */
final class SearchSelection {

	/**
	 * The OpenRegister contract that carries the selection as one argument.
	 */
	public const OPTIONS_CLASS = 'OCA\\OpenRegister\\Contract\\SearchOptions';

	/**
	 * The OpenRegister contract for the selection inside the options.
	 */
	public const SELECTION_CLASS = 'OCA\\OpenRegister\\Contract\\ObjectSelection';

	/**
	 * The (options, selection) class pairs OpenRegister has shipped, newest first.
	 *
	 * OpenRegister main moved both from Kernel\Contracts to Contract without
	 * leaving the old names behind, so an install has one pair or the other.
	 */
	public const CLASS_PAIRS = [
		[self::OPTIONS_CLASS, self::SELECTION_CLASS],
		['OCA\\OpenRegister\\Kernel\\Contracts\\SearchOptions', 'OCA\\OpenRegister\\Kernel\\Contracts\\ObjectSelection'],
	];

	/**
	 * The named arguments that select objects by id.
	 *
	 * @param object             $service The object service the search is called on.
	 * @param string             $method  The search method.
	 * @param array<int, string> $ids     The object ids.
	 *
	 * @return array<string, mixed> `options`, or `ids`, to merge into the search arguments.
	 *
	 * @spec exclude Adapter between two OpenRegister search signatures, no functional requirement.
	 */
	public static function ids(object $service, string $method, array $ids): array {
		return self::arguments(takesOptions: self::takesOptions(service: $service, method: $method), selection: ['ids' => $ids]);
	}//end ids()

	/**
	 * The named arguments that select the objects using an object.
	 *
	 * @param object $service The object service the search is called on.
	 * @param string $method  The search method.
	 * @param string $uses    The uuid the objects refer to.
	 * @param array<string, mixed> $older Arguments only the older search takes, such as `deleted`.
	 *
	 * @return array<string, mixed> `options`, or `uses`, to merge into the search arguments.
	 *
	 * @spec exclude Adapter between two OpenRegister search signatures, no functional requirement.
	 */
	public static function uses(object $service, string $method, string $uses, array $older=[]): array {
		$takesOptions = self::takesOptions(service: $service, method: $method);
		$arguments    = self::arguments(takesOptions: $takesOptions, selection: ['uses' => $uses]);
		if (array_key_exists('options', $arguments) === true) {
			return $arguments;
		}

		return array_merge($older, $arguments);
	}//end uses()

	/**
	 * The selection as one `options` argument for a search that takes one,
	 * else as its own named arguments.
	 *
	 * @param bool                            $takesOptions Whether the search takes an `options` parameter.
	 * @param array<string, mixed>            $selection    The selection's named values.
	 * @param array<int, array{string,string}> $pairs        The (options, selection) classes to look for, preferred first.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude Adapter between two OpenRegister search signatures, no functional requirement.
	 */
	public static function arguments(bool $takesOptions, array $selection, array $pairs=self::CLASS_PAIRS): array {
		$classes = self::installedPair(pairs: $pairs);
		if ($takesOptions === false || $classes === null) {
			return $selection;
		}

		[$optionsClass, $selectionClass] = $classes;

		return ['options' => new $optionsClass(selection: new $selectionClass(...$selection))];
	}//end arguments()

	/**
	 * The first (options, selection) class pair this install has.
	 *
	 * @param array<int, array{string,string}> $pairs The pairs, preferred first.
	 *
	 * @return array{string,string}|null The pair, or null when OpenRegister has neither.
	 *
	 * @spec exclude Adapter between two OpenRegister search signatures, no functional requirement.
	 */
	private static function installedPair(array $pairs): ?array {
		foreach ($pairs as $pair) {
			if (class_exists($pair[0]) === true && class_exists($pair[1]) === true) {
				return $pair;
			}
		}

		return null;
	}//end installedPair()

	/**
	 * Whether the search method takes an `options` parameter.
	 *
	 * @param object $service The object service.
	 * @param string $method  The search method.
	 *
	 * @return bool
	 */
	private static function takesOptions(object $service, string $method): bool {
		if (method_exists($service, $method) === false) {
			return false;
		}

		foreach ((new ReflectionMethod($service, $method))->getParameters() as $parameter) {
			if ($parameter->getName() === 'options') {
				return true;
			}
		}

		return false;
	}//end takesOptions()
}//end class
