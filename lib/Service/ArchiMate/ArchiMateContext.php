<?php

/**
 * ArchiMate Context for SoftwareCatalog
 *
 * Groups shared infrastructure dependencies for the three ArchiMate services,
 * reducing CouplingBetweenObjects and ExcessiveParameterList on each service.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\ArchiMate;

use OCA\OpenRegister\Service\ObjectService;
use OCA\SoftwareCatalog\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Value object that carries the shared infrastructure dependencies for all
 * three ArchiMate services (ArchiMateService, ArchiMateImportService, ArchiMateExportService).
 *
 * Services inject ArchiMateContext instead of each individual dependency,
 * shrinking their constructor parameter lists below the CouplingBetweenObjects threshold.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-4
 */
class ArchiMateContext
{
    /**
     * Constructor.
     *
     * @param ObjectService   $objectService   The OpenRegister object service.
     * @param SettingsService $settingsService The SoftwareCatalog settings service.
     * @param LoggerInterface $logger          The application logger.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-4
     */
    public function __construct(
        public readonly ObjectService $objectService,
        public readonly SettingsService $settingsService,
        public readonly LoggerInterface $logger
    ) {
    }//end __construct()
}//end class
