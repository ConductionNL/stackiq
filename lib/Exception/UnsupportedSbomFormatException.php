<?php
/**
 * UnsupportedSbomFormatException.
 *
 * Thrown by {@see \OCA\SoftwareCatalog\Service\SbomParserService} when an
 * uploaded document's `bomFormat`/`specVersion` (CycloneDX) or `spdxVersion`
 * (SPDX) is not one this app supports. Carries the offending format/version
 * in its message so the controller can surface a precise 422 rather than a
 * generic parse failure — no partial component list is ever returned when
 * this is thrown (fail-fast, not a silent partial parse).
 *
 * @category  Exception
 * @package   OCA\SoftwareCatalog\Exception
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-cyclonedx-sbom-files-are-parsed-into-a-normalized-component-list
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Exception;

/**
 * Thrown when an uploaded SBOM document's format/spec-version is not supported.
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-cyclonedx-sbom-files-are-parsed-into-a-normalized-component-list
 */
class UnsupportedSbomFormatException extends \RuntimeException
{
}//end class
