<?php

/**
 * Contract of PublicCatalogueRecords
 *
 * The public surface of PublicCatalogueRecords that other components call, so they depend
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
 * Contract of PublicCatalogueRecords.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicCatalogueRecordsInterface {

	/**
	 * The published applications as `{id, title, subtitle}`.
	 *
	 * @return list<array<string, string>> The entries.
	 *
	 * @spec openspec/changes/portal-public-software-catalogue/specs/portal-contribution/spec.md#requirement-req-port-005-stackiq-offers-the-published-applications-as-a-public-record-list
	 */
	public function entries(): array;

	/**
	 * One published application, or null when it is not published.
	 *
	 * @param string $id The application id.
	 *
	 * @return array<string, mixed>|null `{title, subtitle, summary, columns, rows}`.
	 *
	 * @spec openspec/changes/portal-public-software-catalogue/specs/portal-contribution/spec.md#requirement-req-port-005-stackiq-offers-the-published-applications-as-a-public-record-list
	 */
	public function record(string $id): ?array;
}//end interface
