<?php

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * The real ObjectEntity is an NC AppFramework Entity whose getters are
 * `__call` magic methods, so PHPUnit cannot configure them on a mock. This
 * stub declares the getters/setters the unit tests stub explicitly. Resolved
 * via the `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for ObjectEntity with the surface used by SoftwareCatalog tests.
 */
abstract class ObjectEntity
{

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

    /** @return string|null */
    abstract public function getOrganisation();

    /**
     * @param  string|null $organisation
     * @return void
     */
    abstract public function setOrganisation($organisation=null);

    /**
     * @param  array<string,mixed>|null $object
     * @return self
     */
    abstract public function setObject($object=null);

    /** @return array<string,mixed> */
    abstract public function jsonSerialize();

}//end class
