<?php

/**
 * Full sync run
 *
 * The state of one full or incremental organisation sync, built and read
 * only by OrganizationSyncService.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * One full sync: its time window, the configured register and schemas, and its statistics.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class FullSyncRun {

	/**
	 * Number of minutes to look back for changes (0 = all objects).
	 *
	 * @var int
	 */
	public int $minutesBack = 10;

	/**
	 * The configured register.
	 *
	 * @var mixed
	 */
	public mixed $register = '';

	/**
	 * The configured organisatie schema.
	 *
	 * @var mixed
	 */
	public mixed $organizationSchema = '';

	/**
	 * The configured contactpersoon schema.
	 *
	 * @var mixed
	 */
	public mixed $contactSchema = '';

	/**
	 * The synchronization statistics.
	 *
	 * @var array<string, mixed>
	 */
	public array $stats = [];
}//end class
