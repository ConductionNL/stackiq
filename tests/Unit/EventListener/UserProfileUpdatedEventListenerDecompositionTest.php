<?php

/**
 * Unit tests for the decomposed UserProfileUpdatedEventListener helpers.
 *
 * Covers method-decomposition task 8.4 — split the long
 * `syncToContactpersoon()` body into `buildContactPatch()` +
 * `persistContactpersoonPatch()`.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-4
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\EventListener;

use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\SoftwareCatalog\EventListener\UserProfileUpdatedEventListener;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the private helpers extracted from
 * UserProfileUpdatedEventListener::syncToContactpersoon.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\EventListener
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-4
 */
class UserProfileUpdatedEventListenerDecompositionTest extends TestCase
{

    /**
     * Build a listener with a stub container, skipping the test when the
     * OpenRegister event class isn't autoloadable in this environment.
     *
     * @return UserProfileUpdatedEventListener
     */
    private function makeListener(): UserProfileUpdatedEventListener
    {
        if (class_exists('OCA\\OpenRegister\\Event\\UserProfileUpdatedEvent') === false) {
            $this->markTestSkipped('OCA\\OpenRegister\\Event\\UserProfileUpdatedEvent is not autoloadable in this environment.');
        }

        return new UserProfileUpdatedEventListener($this->createMock(ContainerInterface::class));

    }//end makeListener()


    /**
     * buildContactPatch maps changed fields via FIELD_MAP and returns only
     * the relevant entries.
     *
     * @return void
     */
    public function testBuildContactPatchMapsChangedFields(): void
    {
        $listener = $this->makeListener();

        $event = $this->createMock(UserProfileUpdatedEvent::class);
        $event->method('getNewData')->willReturn(
            [
                'firstName' => 'Alice',
                'lastName'  => 'Doe',
                'email'     => 'alice@example.com',
                'unrelated' => 'ignored',
            ]
        );
        $event->method('getChanges')->willReturn(['firstName', 'lastName']);

        $reflection = new \ReflectionMethod($listener, 'buildContactPatch');
        $reflection->setAccessible(true);

        $patch = $reflection->invoke(
            $listener,
            $event,
            'alice',
            ['username' => 'alice'],
            'contact-uuid',
            new NullLogger()
        );

        $this->assertSame(['voornaam' => 'Alice', 'achternaam' => 'Doe'], $patch);

    }//end testBuildContactPatchMapsChangedFields()


    /**
     * buildContactPatch falls back to empty string when a changed field
     * has a null new-value.
     *
     * @return void
     */
    public function testBuildContactPatchCoercesNullToEmptyString(): void
    {
        $listener = $this->makeListener();

        $event = $this->createMock(UserProfileUpdatedEvent::class);
        $event->method('getNewData')->willReturn(['functie' => null]);
        $event->method('getChanges')->willReturn(['functie']);

        $reflection = new \ReflectionMethod($listener, 'buildContactPatch');
        $reflection->setAccessible(true);

        $patch = $reflection->invoke(
            $listener,
            $event,
            'bob',
            ['username' => 'bob'],
            'contact-uuid',
            new NullLogger()
        );

        $this->assertSame(['functie' => ''], $patch);

    }//end testBuildContactPatchCoercesNullToEmptyString()


    /**
     * buildContactPatch backfills the username when the contactpersoon
     * was found via the email-fallback and currently has no username.
     *
     * @return void
     */
    public function testBuildContactPatchBackfillsUsername(): void
    {
        $listener = $this->makeListener();

        $event = $this->createMock(UserProfileUpdatedEvent::class);
        $event->method('getNewData')->willReturn([]);
        $event->method('getChanges')->willReturn([]);

        $reflection = new \ReflectionMethod($listener, 'buildContactPatch');
        $reflection->setAccessible(true);

        $patch = $reflection->invoke(
            $listener,
            $event,
            'carol',
            [],
            'contact-uuid',
            new NullLogger()
        );

        $this->assertSame(['username' => 'carol'], $patch);

    }//end testBuildContactPatchBackfillsUsername()


    /**
     * buildContactPatch returns an empty array when no fields changed and
     * the contactpersoon already has a username.
     *
     * @return void
     */
    public function testBuildContactPatchEmptyWhenNothingToChange(): void
    {
        $listener = $this->makeListener();

        $event = $this->createMock(UserProfileUpdatedEvent::class);
        $event->method('getNewData')->willReturn([]);
        $event->method('getChanges')->willReturn([]);

        $reflection = new \ReflectionMethod($listener, 'buildContactPatch');
        $reflection->setAccessible(true);

        $patch = $reflection->invoke(
            $listener,
            $event,
            'dave',
            ['username' => 'dave'],
            'contact-uuid',
            new NullLogger()
        );

        $this->assertSame([], $patch);

    }//end testBuildContactPatchEmptyWhenNothingToChange()


}//end class
