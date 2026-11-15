<?php

/**
 * The slug rename plan shared by the register and schema slug migrations.
 *
 * MigrateRegisterSlugDecisions and RenameDutchSchemaSlugDecisions decided their
 * renames with the same rules, written out twice. This holds them once. It takes
 * plain arrays and touches neither database nor logger.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

/**
 * One planning run over a slug map: built per call with the slugs the install
 * holds, which it extends as renames are planned.
 *
 * @spec exclude Internal helper of the slug migrations; the requirements live
 *  with the repair steps that use it.
 */
final class SlugRenamePlanner {

	/**
	 * Renames planned so far, old slug => new slug.
	 *
	 * @var array<string, string>
	 */
	private array $renames = [];

	/**
	 * Renames refused so far, old slug => reason.
	 *
	 * @var array<string, string>
	 */
	private array $refused = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $existing Slugs currently present.
	 */
	public function __construct(
		private array $existing,
	) {
	}//end __construct()

	/**
	 * Decide which slugs may be renamed.
	 *
	 * A slug that is absent is nothing to do; a target that is already present
	 * refuses the rename; otherwise it is planned, and the target counts as
	 * present for every later entry of the map.
	 *
	 * @param array<string, string> $map Old slug => new slug.
	 *
	 * @return array{renames: array<string, string>, refused: array<string, string>}
	 *
	 * @spec exclude Internal helper of the slug migrations; the requirements live
	 *  with the repair steps that use it.
	 */
	public function plan(array $map): array {
		foreach ($map as $old => $new) {
			$this->planRename(old: $old, new: $new);
		}

		return [
			'renames' => $this->renames,
			'refused' => $this->refused,
		];
	}//end plan()

	/**
	 * Plan or refuse one rename.
	 *
	 * @param int|string $old Current slug (a map key).
	 * @param string     $new Replacement slug.
	 *
	 * @return void
	 */
	private function planRename(int|string $old, string $new): void {
		if (in_array($old, $this->existing, true) === false) {
			// Not on this install — not a refusal, just nothing to do.
			return;
		}

		if (in_array($new, $this->existing, true) === true) {
			$this->refused[$old] = sprintf("target slug '%s' already exists", $new);
			return;
		}

		$this->renames[$old] = $new;
		$this->existing[] = $new;
	}//end planRename()
}//end class
