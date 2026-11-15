<?php

/**
 * Read access to the register and schema configuration of the catalogue.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The read-only lookups most services need from the settings service: the
 * voorzieningen and AMEF configuration, the voorzieningen register, the
 * register and schema of an object type, and OpenRegister's object service.
 * Services that only read these depend on this interface instead of the
 * whole settings service.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Settings
 *
 * @spec openspec/specs/settings-service/spec.md
 */
interface RegisterConfigReaderInterface extends ObjectTypeLocatorInterface {

	/**
	 * The voorzieningen configuration.
	 *
	 * @return array The voorzieningen configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVoorzieningenConfig(): array;

	/**
	 * The configured id of the voorzieningen register.
	 *
	 * @return int|null The register id, or null when none is configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVoorzieningenRegisterId(): ?int;

	/**
	 * The AMEF configuration.
	 *
	 * @return array The AMEF configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAmefConfig(): array;

	/**
	 * OpenRegister's object service.
	 *
	 * @return ObjectServiceInterface|null The object service
	 *
	 * @throws \RuntimeException When OpenRegister is not installed
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getObjectService(): ?ObjectServiceInterface;
}//end interface
