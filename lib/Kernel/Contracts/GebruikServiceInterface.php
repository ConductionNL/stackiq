<?php

/**
 * Contract of GebruikService
 *
 * The public surface of GebruikService that other components call, so they depend
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
 * Contract of GebruikService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GebruikServiceInterface {

	/**
	 * Get application ids for given options.
	 *
	 * @param array $options The options to use while searching.
	 *
	 * @return array The resulting ids.
	 *
	 * @throws \OCP\DB\Exception When database query fails.
	 *
	 * @spec openspec/specs/gebruik-services/spec.md
	 */
	public function getApplicationIds(array $options): array;

	/**
	 * Fetch gebruiken for given options.
	 *
	 * @param array $options The options to use while searching.
	 *
	 * @return array The result set of gebruiken.
	 *
	 * @throws \OCP\DB\Exception When database query fails.
	 *
	 * @spec openspec/specs/gebruik-services/spec.md
	 */
	public function getGebruiken(array $options): array;

	/**
	 * The usages shared with an organisation, each with its `_shared` marker.
	 *
	 * @param array  $options The request options.
	 * @param string $orgUuid The organisation's uuid.
	 *
	 * @return list<array<string, mixed>> The shared usages.
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
	 */
	public function getSharedGebruiken(array $options, string $orgUuid): array;

	/**
	 * The connections of the usages an organisation owns or takes part in, with whom each is shared.
	 *
	 * @param string $orgUuid The organisation's uuid.
	 *
	 * @return array<string, array{sharedWith: list<string>, usages: list<string>}> Keyed by connection uuid.
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
	 */
	public function getSharedConnections(string $orgUuid): array;
}//end interface
