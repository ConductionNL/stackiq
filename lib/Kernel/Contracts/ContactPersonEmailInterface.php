<?php

/**
 * Contract of ContactPersonEmail
 *
 * The public surface of ContactPersonEmail that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

/**
 * Contract of ContactPersonEmail.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactPersonEmailInterface {

	/**
	 * The email address of a contact person.
	 *
	 * @param array<string, mixed> $contactData The contact person's properties.
	 *
	 * @return string The email address, or '' when there is none.
	 *
	 * @SuppressWarnings(PHPMD.ShortMethodName) The name of ContactPersonEmail::of(), which callers already use.
	 *
	 * @spec openspec/specs/contactpersoon-sync/spec.md
	 */
	public function of(array $contactData): string;
}//end interface
