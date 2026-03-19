<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\EventListener;

use OCA\SoftwareCatalog\EventListener\SoftwareCatalogEventListener;
use PHPUnit\Framework\TestCase;

/**
 * Test class for SoftwareCatalogEventListener
 *
 * The event listener resolves its dependencies from the Nextcloud server container
 * at runtime (via \OC::$server->get()), so comprehensive unit testing requires
 * a running Nextcloud environment. These basic tests verify constructor and class
 * structure only.
 *
 * @category Tests
 * @package  OCA\SoftwareCatalog\Tests\Unit\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */
class SoftwareCatalogEventListenerTest extends TestCase
{
    /**
     * Test that the event listener can be instantiated
     *
     * @return void
     */
    public function testCanBeInstantiated(): void
    {
        $listener = new SoftwareCatalogEventListener();
        $this->assertInstanceOf(SoftwareCatalogEventListener::class, $listener);
    }

    /**
     * Test that the event listener implements IEventListener
     *
     * @return void
     */
    public function testImplementsIEventListener(): void
    {
        $listener = new SoftwareCatalogEventListener();
        $this->assertInstanceOf(
            \OCP\EventDispatcher\IEventListener::class,
            $listener
        );
    }
}
