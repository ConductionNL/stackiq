<?php

/**
 * The lifecycle state from which the service desk exchange sends an application.
 *
 * The usage schema's `status` enum is the lifecycle, in order; the outbound
 * flow sends an application whose status is the chosen state or a later one.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Itsm
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Itsm;

use InvalidArgumentException;

/**
 * The usage lifecycle and the states sent from a chosen one on.
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
 */
final class ItsmSendFrom {
	/**
	 * The usage lifecycle states in their order (the usage schema's `status` enum).
	 */
	public const STATES = ['Acquisition', 'Planned', 'In production', 'To be phased out', 'Phased out'];

	/**
	 * The state applications are sent from when none is chosen.
	 */
	public const DEFAULT = 'In production';

	/**
	 * Whether a state is a usage lifecycle state.
	 *
	 * @param string $state The state.
	 *
	 * @return bool True for a lifecycle state.
	 *
	 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
	 */
	public static function isState(string $state): bool {
		return in_array($state, self::STATES, true);
	}//end isState()

	/**
	 * The lifecycle states from a state on, in lifecycle order.
	 *
	 * @param string $state The first state to send from.
	 *
	 * @return array<int, string> That state and every later one.
	 *
	 * @throws InvalidArgumentException When the state is not a usage lifecycle state.
	 *
	 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
	 */
	public static function statesFrom(string $state): array {
		$index = array_search($state, self::STATES, true);
		if ($index === false) {
			throw new InvalidArgumentException(self::unknown(state: $state));
		}

		return array_slice(self::STATES, $index);
	}//end statesFrom()

	/**
	 * Why a state is refused.
	 *
	 * @param string $state The unknown state.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
	 */
	public static function unknown(string $state): string {
		return 'Unknown lifecycle state "' . $state . '". Choose one of: ' . implode(', ', self::STATES) . '.';
	}//end unknown()
}//end class
