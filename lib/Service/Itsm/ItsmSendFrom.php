<?php

/**
 * The lifecycle state from which the service desk exchange sends an application.
 *
 * The usage schema's `status` enum is the lifecycle, in order; the outbound
 * flow sends an application whose status is the chosen state or a later one.
 * The choice is an app setting.
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

use OCA\Stackiq\Kernel\AppIdentity;
use OCP\IAppConfig;

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
	 * The app setting holding the chosen state.
	 */
	public const KEY = 'itsm_exchange_send_from';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app settings holding the choice.
	 *
	 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The chosen state: the stored one, else the default (also for a stored
	 * value that is no longer a lifecycle state).
	 *
	 * @return string The state.
	 *
	 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
	 */
	public function current(): string {
		$state = $this->appConfig->getValueString(AppIdentity::APP_ID, self::KEY, self::DEFAULT);
		if (in_array($state, self::STATES, true) === false) {
			return self::DEFAULT;
		}

		return $state;
	}//end current()

	/**
	 * Choose the state; empty means the default.
	 *
	 * @param string $state The lifecycle state, or empty.
	 *
	 * @return string|null Why the state is refused, or null when it is stored.
	 *
	 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
	 */
	public function choose(string $state): ?string {
		if ($state === '') {
			$state = self::DEFAULT;
		}

		if (in_array($state, self::STATES, true) === false) {
			return 'Unknown lifecycle state "' . $state . '". Choose one of: ' . implode(', ', self::STATES) . '.';
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::KEY, $state);
		return null;
	}//end choose()

	/**
	 * The states the outbound flow sends: the chosen one and every later one, in lifecycle order.
	 *
	 * @return array<int, string> The states.
	 *
	 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
	 */
	public function states(): array {
		return array_slice(self::STATES, (int) array_search($this->current(), self::STATES, true));
	}//end states()
}//end class
