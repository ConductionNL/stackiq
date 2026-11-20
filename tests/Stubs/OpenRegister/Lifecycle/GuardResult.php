<?php

/**
 * Test stub of OpenRegister's GuardResult.
 *
 * A copy of `lib/Lifecycle/GuardResult.php` in ConductionNL/openregister: the
 * same factories and accessors.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Lifecycle
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * A guard's allow or deny verdict.
 */
final class GuardResult {
	/**
	 * Constructor; use the static factories.
	 *
	 * @param bool $allowed Whether the transition is allowed.
	 * @param string|null $message The deny message.
	 */
	private function __construct(
		private readonly bool $allowed,
		private readonly ?string $message,
	) {
	}//end __construct()

	/**
	 * Allow the transition.
	 *
	 * @return self
	 */
	public static function allow(): self {
		return new self(allowed: true, message: null);
	}//end allow()

	/**
	 * Deny the transition with a message.
	 *
	 * @param string $message The reason.
	 *
	 * @return self
	 */
	public static function deny(string $message): self {
		return new self(allowed: false, message: $message);
	}//end deny()

	/**
	 * Whether the verdict allows the transition.
	 *
	 * @return bool
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	/**
	 * The deny message, if any.
	 *
	 * @return string|null
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()
}//end class
