<?php

/**
 * Contract of ReferenceComponentCoverageService
 *
 * The public surface of ReferenceComponentCoverageService that other components call, so they depend
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

use Exception;

/**
 * Contract of ReferenceComponentCoverageService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReferenceComponentCoverageServiceInterface {

	/**
	 * Build the coverage of one organisation.
	 *
	 * @param string $organisationUuid The consuming organisation.
	 *
	 * @return array<string,mixed> organisation, summary, components and the bounds.
	 *
	 * @throws Exception When OpenRegister or the register configuration is unavailable.
	 *
	 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
	 */
	public function build(string $organisationUuid): array;

	/**
	 * The same coverage as CSV, one row per reference component.
	 *
	 * @param string $organisationUuid The consuming organisation.
	 *
	 * @return string The CSV document.
	 *
	 * @throws Exception When OpenRegister or the register configuration is unavailable.
	 *
	 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
	 */
	public function buildCsv(string $organisationUuid): string;
}//end interface
