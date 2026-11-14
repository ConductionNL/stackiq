<?php

/**
 * Contract of ConnectionReportService
 *
 * The public surface of ConnectionReportService that other components call, so they depend
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
 * Contract of ConnectionReportService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConnectionReportServiceInterface {

	/**
	 * After an email settings save: refresh, then report what the saved settings say.
	 *
	 * The `null` transport is left to integriq's rule 3, which outranks any
	 * report. Never throws, and does nothing without integriq.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function emailSettingsSaved(): bool;
}//end interface
