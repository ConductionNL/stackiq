<?php

/**
 * Factory of the Service helpers that other components build per use
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
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

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Kernel\Contracts\CmdbImportProfileInterface;
use OCA\Stackiq\Kernel\Contracts\GemmaStandardLinkerInterface;
use OCA\Stackiq\Service\ArchiMate\GemmaStandardLinker;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a fresh helper per call, so callers outside Service depend on the
 * helper's contract only.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural factory, no functional requirement.
 */
final class HelperFactory {

	/**
	 * The shipped CMDB import profile and packs.
	 *
	 * @param ContainerInterface $container The container OpenRegister's pack store is resolved on.
	 *
	 * @return CmdbImportProfileInterface
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function cmdbImportProfile(ContainerInterface $container): CmdbImportProfileInterface {
		return new CmdbImportProfile(container: $container);
	}//end cmdbImportProfile()

	/**
	 * The GEMMA standard linker.
	 *
	 * @param LoggerInterface $logger The logger the linker reports to.
	 *
	 * @return GemmaStandardLinkerInterface
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function gemmaStandardLinker(LoggerInterface $logger): GemmaStandardLinkerInterface {
		return new GemmaStandardLinker(logger: $logger);
	}//end gemmaStandardLinker()
}//end class
