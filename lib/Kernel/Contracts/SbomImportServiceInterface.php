<?php

/**
 * Contract of SbomImportService
 *
 * The public surface of SbomImportService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of SbomImportService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SbomImportServiceInterface {

	/**
	 * Supported SBOM upload formats.
	 *
	 * @var array<int,string>
	 */
	public const SUPPORTED_FORMATS = ['cyclonedx-json', 'spdx-json'];

	/**
	 * Read SBOM import provenance + optional progress for a `moduleVersie`.
	 *
	 * @param string $moduleVersieUuid The moduleVersie uuid.
	 * @param string|null $operationId Optional progress-tracking operation id.
	 *
	 * @return array<string, mixed> `{sbomLastImportedAt, sbomFormat, sbomFileName, progress}`.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-moduleversie-records-sbom-import-provenance
	 */
	public function getStatus(string $moduleVersieUuid, ?string $operationId = null): array;

	/**
	 * Import an uploaded SBOM for a `moduleVersie`: parse, replace the
	 * previous component set, and record provenance.
	 *
	 * @param string $moduleVersieUuid The target moduleVersie's uuid.
	 * @param string $rawJson The raw uploaded file contents.
	 * @param string $format `cyclonedx-json` or `spdx-json`.
	 * @param string $fileName The uploaded file's original name.
	 *
	 * @return array<string, mixed> Import result summary.
	 *
	 * @throws \OCA\Stackiq\Exception\UnsupportedSbomFormatException When the
	 *                                                               document's format/version is not supported.
	 *                                                               No component is written in that case.
	 * @throws RuntimeException When the target `moduleVersie` cannot be
	 *                          resolved, or required configuration is missing.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-imported-components-persist-as-openregister-objects-scoped-to-a-moduleversie
	 */
	public function importForModuleVersie(
		string $moduleVersieUuid,
		string $rawJson,
		string $format,
		string $fileName,
	): array;

	/**
	 * Resolve the parent `module` uuid of a `moduleVersie` — used by the
	 * controller's manage-ACL authorization guard, which needs to know which
	 * module the caller must be allowed to manage before any write happens.
	 *
	 * @param string $moduleVersieUuid The moduleVersie uuid.
	 *
	 * @return string|null The parent module uuid, or null when not resolvable.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
	 */
	public function resolveParentModuleUuid(string $moduleVersieUuid): ?string;

	/**
	 * Whether the current OR request context can resolve (read) a `module`
	 * object under RBAC — used as the "manage-ACL on the target module"
	 * check alongside a role-group membership check in the controller.
	 *
	 * @param string $moduleUuid The module uuid.
	 *
	 * @return bool True when the module resolves under RBAC for the acting user.
	 *
	 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
	 */
	public function userCanReadModule(string $moduleUuid): bool;
}//end interface
