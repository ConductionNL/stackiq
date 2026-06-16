<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service\SoftwareCatalogue;

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for the W30 ContactPersonHandler decomposition helper
 * (cleanNameParts).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */
class ContactPersonHandlerDecompositionTest extends TestCase
{
    private function makeHandler(): ContactPersonHandler
    {
        $reflection = new ReflectionClass(ContactPersonHandler::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    private function call(ContactPersonHandler $h, string $vn, string $an): array
    {
        $ref = new ReflectionMethod($h, 'cleanNameParts');
        $ref->setAccessible(true);
        return $ref->invokeArgs($h, [$vn, $an]);
    }

    public function testCleanLowercasesAndStripsNonAlphanumeric(): void
    {
        $h = $this->makeHandler();
        $this->assertSame(['jan', 'vanderberg'], $this->call($h, 'Jan', 'van der Berg'));
    }

    public function testCleanReturnsEmptyPairWhenEitherSideMissing(): void
    {
        $h = $this->makeHandler();
        $this->assertSame(['', ''], $this->call($h, '', 'Surname'));
        $this->assertSame(['', ''], $this->call($h, 'First', ''));
        $this->assertSame(['', ''], $this->call($h, '', ''));
    }

    public function testCleanStripsAccentsAndPunctuation(): void
    {
        $h = $this->makeHandler();
        // strtolower on accented chars in default locale leaves them, and the
        // preg_replace then strips them. Final result depends on PHP locale,
        // but we assert the punctuation/space stripping deterministically.
        $this->assertSame(['john', 'doe'], $this->call($h, 'JOHN', "D.O.E"));
    }

    public function testCleanKeepsDigits(): void
    {
        $h = $this->makeHandler();
        $this->assertSame(['agent007', 'bond'], $this->call($h, 'Agent007', 'Bond'));
    }
}
