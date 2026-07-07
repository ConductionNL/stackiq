<?php

/**
 * Test stub for OCA\OpenRegister\Db\Organisation.
 *
 * The real Organisation is an NC AppFramework Entity whose accessors are
 * partly magic. This stub declares the concrete surface the SoftwareCatalog
 * unit tests exercise (uuid + parent + a couple of read accessors).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for the OpenRegister Organisation entity.
 */
class Organisation
{

    /**
     * @var int|null
     */
    private ?int $id = null;

    /**
     * @var string
     */
    private string $uuid = '';

    /**
     * @var string|null
     */
    private ?string $parent = null;

    /**
     * @var bool
     */
    private bool $active = true;

    /**
     * @param int|null $id The id.
     *
     * @return void
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }//end setId()

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }//end getId()

    /**
     * @param string $uuid The uuid.
     *
     * @return void
     */
    public function setUuid(string $uuid): void
    {
        $this->uuid = $uuid;
    }//end setUuid()

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }//end getUuid()

    /**
     * @param string|null $parent The parent uuid.
     *
     * @return static
     */
    public function setParent(?string $parent): static
    {
        $this->parent = $parent;
        return $this;
    }//end setParent()

    /**
     * @return string|null
     */
    public function getParent(): ?string
    {
        return $this->parent;
    }//end getParent()

    /**
     * @param bool $active Active flag.
     *
     * @return void
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;
    }//end setActive()

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }//end isActive()

}//end class
