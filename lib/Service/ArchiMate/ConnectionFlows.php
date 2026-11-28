<?php

/**
 * Writes an organisation's connections as ArchiMate flow relationships.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\ArchiMate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\ArchiMate;

/**
 * Turns connections into flow relationships between the exported elements.
 *
 * A connection between two exported applications becomes a flow from the
 * sending application to the receiving one; a bi-directional connection
 * becomes two flows. A connection to a national provision targets that
 * provision's element. Each flow carries the transport type, direction and
 * status as properties. A connection with an end that is not in the exported
 * model is skipped and counted.
 *
 * @spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-004-the-organisation-archimate-export-can-carry-the-organisations-connections
 */
class ConnectionFlows {

	/**
	 * The property definitions the flows use: identifier to name.
	 */
	public const PROPERTY_DEFINITIONS = [
		'id-swc-propdef-connection-type' => 'Transporttype',
		'id-swc-propdef-connection-direction' => 'Richting',
		'id-swc-propdef-connection-status' => 'Status',
	];

	/**
	 * The flows of the connections, and how many connections were skipped.
	 *
	 * @param array<int, mixed> $connections The connection objects (arrays or serialisable entities).
	 * @param array<string, string> $elements The element identifier of every exported application or provision, by object id.
	 * @param string $sourcePropDefId The identifier of the Bron property definition.
	 *
	 * @return array{relationships: array<int, array<string, mixed>>, skipped: int} The flows and the skipped count.
	 *
	 * @spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-004-the-organisation-archimate-export-can-carry-the-organisations-connections
	 */
	public function flows(array $connections, array $elements, string $sourcePropDefId): array {
		$relationships = [];
		$skipped = 0;
		foreach ($connections as $connection) {
			$connection = self::asArray(value: $connection);
			$ends = $this->ends(connection: $connection, elements: $elements);
			if ($ends === null) {
				$skipped++;
				continue;
			}

			foreach ($this->directed(connection: $connection, ends: $ends) as [$source, $target]) {
				$relationships[] = [
					'identifier' => 'id-swc-flow-' . self::idOf(value: $connection) . '-' . str_replace('id-', '', $source),
					'xsi_type' => 'FlowRelationship',
					'source' => $source,
					'target' => $target,
					'bronPropDefId' => $sourcePropDefId,
					'properties' => self::properties(connection: $connection),
				];
			}
		}

		return ['relationships' => $relationships, 'skipped' => $skipped];
	}//end flows()

	/**
	 * The element identifiers of the two ends, or null when one is not in the model.
	 *
	 * @param array<string, mixed> $connection The connection.
	 * @param array<string, string> $elements The exported elements by object id.
	 *
	 * @return array{0: string, 1: string}|null End A and end B.
	 */
	private function ends(array $connection, array $elements): ?array {
		$endA = self::idOf(value: $connection['moduleA'] ?? null);
		$endB = self::idOf(value: $connection['moduleB'] ?? null);
		if ($endB === '') {
			$endB = self::idOf(value: $connection['nonMunicipalProvision'] ?? null);
		}

		if (isset($elements[$endA], $elements[$endB]) === false) {
			return null;
		}

		return [$elements[$endA], $elements[$endB]];
	}//end ends()

	/**
	 * The source and target of each flow the connection writes, by its direction.
	 *
	 * @param array<string, mixed> $connection The connection.
	 * @param array{0: string, 1: string} $ends End A and end B.
	 *
	 * @return array<int, array{0: string, 1: string}> The flows.
	 */
	private function directed(array $connection, array $ends): array {
		return match ((string)($connection['dataExchangeDirection'] ?? '')) {
			'AtoB' => [[$ends[0], $ends[1]]],
			'BtoA' => [[$ends[1], $ends[0]]],
			default => [[$ends[0], $ends[1]], [$ends[1], $ends[0]]],
		};
	}//end directed()

	/**
	 * The transport type, direction and status of a connection as flow properties.
	 *
	 * @param array<string, mixed> $connection The connection.
	 *
	 * @return array<int, array{ref: string, value: string}> The properties with a value.
	 */
	private static function properties(array $connection): array {
		$values = [
			'id-swc-propdef-connection-type' => $connection['type'] ?? null,
			'id-swc-propdef-connection-direction' => $connection['dataExchangeDirection'] ?? null,
			'id-swc-propdef-connection-status' => $connection['status'] ?? null,
		];
		$properties = [];
		foreach ($values as $ref => $value) {
			if (is_string($value) === true && $value !== '') {
				$properties[] = ['ref' => $ref, 'value' => $value];
			}
		}

		return $properties;
	}//end properties()

	/**
	 * The id of an object or a reference to one.
	 *
	 * @param mixed $value A uuid, or an object with `id`, `uuid` or `@self.id`.
	 *
	 * @return string The id, or '' for none.
	 */
	private static function idOf(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		$value = self::asArray(value: $value);

		return trim((string)($value['id'] ?? $value['uuid'] ?? $value['@self']['id'] ?? ''));
	}//end idOf()

	/**
	 * A value as an array: a serialisable entity's data, an array, or none.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed> The array.
	 */
	private static function asArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			return (array)$value->jsonSerialize();
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end asArray()
}//end class
