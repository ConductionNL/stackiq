<?php

/**
 * Contract of ModuleVersionPublicationService
 *
 * The public surface of ModuleVersionPublicationService that other components call, so they depend
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * Contract of ModuleVersionPublicationService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ModuleVersionPublicationServiceInterface {

	/**
	 * Copy a module's publication onto its versions and say what could not be copied.
	 *
	 * @param ObjectEntityInterface $module The module.
	 *
	 * @return array{written: int, failed: int} The versions written, and the versions or searches that failed.
	 *
	 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function backfillModule(ObjectEntityInterface $module): array;

	/**
	 * Take every version of a deleted module out of public view.
	 *
	 * @param string $moduleUuid The module.
	 *
	 * @return array{written: int, failed: int} The versions written, and the versions or searches that failed.
	 *
	 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function clearVersions(string $moduleUuid): array;

	/**
	 * React to a deleted object: the versions of a deleted module stop following a publication.
	 *
	 * @param ObjectEntityInterface $object The deleted object.
	 *
	 * @return integer The number of versions written in this request: always 0, the job writes them.
	 *
	 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function objectDeleted(ObjectEntityInterface $object): int;

	/**
	 * React to a saved object: a module queues the update of its versions, a version reads its module.
	 *
	 * A module update that leaves its publication date and registrant as they
	 * were has nothing to copy, so nothing is queued.
	 *
	 * @param ObjectEntityInterface      $object   The saved object.
	 * @param ObjectEntityInterface|null $previous The object before an update, or null for a new one.
	 *
	 * @return integer The number of versions written in this request: 0 for a module, whose versions the job writes.
	 *
	 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function objectSaved(ObjectEntityInterface $object, ?ObjectEntityInterface $previous = null): int;
}//end interface
