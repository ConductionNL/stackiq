<?php

/**
 * Test stub for OCA\OpenRegister\Service\OrganisationService.
 *
 * The real OrganisationService lives in the OpenRegister app which is not
 * available as a Composer dependency in the test environment. This stub
 * declares the single method SoftwareCatalog's
 * AangebodenGebruikService::getCurrentOrganisation() /
 * AanbodService::getCurrentOrganisation() rely on, so PHPUnit can create
 * mocks against it for vendor-visibility-rbac's deny-before-grant tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Organisation;

/**
 * Stub for OrganisationService with the surface used by SoftwareCatalog
 * tests.
 */
abstract class OrganisationService
{

    /**
     * Get the caller's active organisation, or null when none is set /
     * resolvable.
     *
     * @return Organisation|null
     */
    abstract public function getActiveOrganisation(): ?Organisation;

}//end class
