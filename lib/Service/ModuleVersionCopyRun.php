<?php

/**
 * The state of one copy of a module's publication onto its versions.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Holds the version query, the mirror to copy, the page offset and the counts
 * while ModuleVersionPublicationService copies a mirror page by page. Built
 * and read by that service only.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 */
final class ModuleVersionCopyRun {

	/**
	 * The offset of the next page.
	 *
	 * @var integer
	 */
	public int $offset = 0;

	/**
	 * The versions written, and the versions or searches that failed.
	 *
	 * @var array{written: int, failed: int}
	 */
	public array $result = ['written' => 0, 'failed' => 0];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                                                      $versionQuery The version search, without offset.
	 * @param array{modulePublicationDate: string|null, moduleRegisteredBy: string|null} $mirror       The values to hold.
	 */
	public function __construct(
		public readonly array $versionQuery,
		public readonly array $mirror,
	) {
	}//end __construct()

	/**
	 * The search for the next page of versions.
	 *
	 * @return array<string, mixed> The version query with the page offset.
	 *
	 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function pageQuery(): array {
		return $this->versionQuery + ['_offset' => $this->offset];
	}//end pageQuery()

	/**
	 * Add the counts of one written page.
	 *
	 * @param array{written: int, failed: int} $page The page's counts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
	 */
	public function addPage(array $page): void {
		$this->result['written'] += $page['written'];
		$this->result['failed']  += $page['failed'];
	}//end addPage()
}//end class
