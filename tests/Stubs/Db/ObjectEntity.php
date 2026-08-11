<?php

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * The real ObjectEntity is an NC AppFramework Entity whose getters are
 * `__call` magic methods, so PHPUnit cannot configure them on a mock. This
 * stub declares the getters/setters the unit tests stub explicitly. Resolved
 * via the `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping.
 *
 * ⚠️ KNOWN UNFAITHFULNESS — read before adding a declaration here.
 * Every accessor below is magic on the REAL ObjectEntity, so declaring it here
 * makes `method_exists()` TRUE in the suite and FALSE in production. A test
 * built on this stub therefore CANNOT detect a `method_exists()` probe against
 * an OpenRegister entity — that is exactly how softwarecatalog#490 (the
 * organisation merge re-pointing nothing while still tombstoning the source)
 * stayed green for its entire life. `getOrganisation()`/`setOrganisation()`
 * were removed from this stub for that reason.
 *
 * If your subject probes for an accessor, do NOT add it here. Declare the
 * attribute as a `protected` PROPERTY instead (as `organisation` is below) and
 * build the double as a concrete subclass of this stub rather than a
 * `createMock()`, so `Entity::__call()` serves the accessor exactly as it does
 * in production. `tests/Unit/Service/MergeOrganisatieServiceTest::entity()` is
 * the worked example.
 *
 * This stub extends the real `OCP\AppFramework\Db\Entity` so that a faithful
 * subclass double is still type-compatible with
 * `ObjectService::find(): ?ObjectEntity`. That matters: a double that is not
 * type-compatible raises a `TypeError` which
 * `MergeOrganisatieService::findOrganisatie()` swallows in a
 * `catch (\Throwable)`, turning a wiring mistake into a plausible
 * `source-not-found` blocker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Stub for ObjectEntity with the surface used by SoftwareCatalog tests.
 */
abstract class ObjectEntity extends Entity
{

    /**
     * The system-level owning organisation (`@self.organisation`).
     *
     * A PROPERTY, not a declared accessor — on the real ObjectEntity this is
     * `protected ?string $organisation` reached through `Entity::__call()`, so
     * `method_exists($entity, 'getOrganisation')` is FALSE and
     * `property_exists($entity, 'organisation')` is TRUE. Declaring it this way
     * is what lets a test tell the two apart. See softwarecatalog#490.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /** @return int */
    abstract public function getId();

    /** @return string */
    abstract public function getUuid();

    /** @return array<string,mixed> */
    abstract public function getObject();

    /** @return mixed */
    abstract public function getRegister();

    /** @return mixed */
    abstract public function getSchema();

    /**
     * @param  array<string,mixed>|null $object
     * @return self
     */
    abstract public function setObject($object=null);

    /** @return array<string,mixed> */
    abstract public function jsonSerialize();

}//end class
