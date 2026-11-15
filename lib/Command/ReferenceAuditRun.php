<?php

/**
 * Stackiq reference audit run
 *
 * The state of one run of the references audit command, built and read only
 * by ReferencesAuditCommand.
 *
 * @category Command
 * @package  OCA\Stackiq\Command
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

namespace OCA\Stackiq\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * One audit run: whether it may write, where it reads and reports, the rows, the owner index and the tally.
 *
 * @category Command
 * @package  OCA\Stackiq\Command
 *
 * @spec openspec/specs/contract-administration/spec.md
 */
final class ReferenceAuditRun {

	/**
	 * Whether this run may write.
	 *
	 * @var bool
	 */
	public bool $write = false;

	/**
	 * Console output.
	 *
	 * @var OutputInterface
	 */
	public OutputInterface $output;

	/**
	 * The OpenRegister object service.
	 *
	 * @var mixed
	 */
	public mixed $objectService = null;

	/**
	 * The satellite rows.
	 *
	 * @var array<int, mixed>
	 */
	public array $satellites = [];

	/**
	 * Identity key to owner ids.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $owners = [];

	/**
	 * Every known owner id.
	 *
	 * @var array<string, bool>
	 */
	public array $ownerIds = [];

	/**
	 * The per-outcome tally.
	 *
	 * @var array<string, int>
	 */
	public array $counts = [];
}//end class
