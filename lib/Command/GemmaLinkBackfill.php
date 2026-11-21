<?php

/**
 * Stackiq GEMMA link back-fill run
 *
 * The state of one run of the GEMMA standard links command, built and read
 * only by GemmaStandardLinksCommand.
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

use Symfony\Component\Console\Input\InputInterface;

/**
 * One back-fill run: where it reads and writes, what changes and how the writes went.
 *
 * @category Command
 * @package  OCA\Stackiq\Command
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
final class GemmaLinkBackfill {

	/**
	 * Console input, for the register and schema slugs and the dry-run flag.
	 *
	 * @var InputInterface
	 */
	public InputInterface $input;

	/**
	 * The OpenRegister ObjectService.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * Whether nothing is written.
	 *
	 * @var bool
	 */
	public bool $dryRun = false;

	/**
	 * The changed link properties by object id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $changes = [];

	/**
	 * The objects checked, the changes needed, and the writes that landed or failed.
	 *
	 * @var array{checked: int, needed: int, written: int, failed: int}
	 */
	public array $counts = ['checked' => 0, 'needed' => 0, 'written' => 0, 'failed' => 0];
}//end class
