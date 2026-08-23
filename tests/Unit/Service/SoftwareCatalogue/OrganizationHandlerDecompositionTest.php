<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service\SoftwareCatalogue;

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for the W30 OrganizationHandler decomposition helper
 * (buildContactpersoonTitle).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8
 */
class OrganizationHandlerDecompositionTest extends TestCase {
	private function makeHandler(): OrganizationHandler {
		$reflection = new ReflectionClass(OrganizationHandler::class);
		return $reflection->newInstanceWithoutConstructor();
	}

	private function call(OrganizationHandler $h, array $row): string {
		$ref = new ReflectionMethod($h, 'buildContactPersonTitle');
		$ref->setAccessible(true);
		return $ref->invokeArgs($h, [$row]);
	}

	public function testTitleFromFullName(): void {
		$h = $this->makeHandler();
		$this->assertSame(
			'Jan van der Berg',
			$this->call($h, ['voornaam' => 'Jan', 'tussenvoegsel' => 'van der', 'achternaam' => 'Berg'])
		);
	}

	public function testTitleSkipsEmptyTussenvoegsel(): void {
		$h = $this->makeHandler();
		$this->assertSame(
			'Alice Smith',
			$this->call($h, ['voornaam' => 'Alice', 'tussenvoegsel' => '', 'achternaam' => 'Smith'])
		);
	}

	public function testTitleFallsBackToEmail(): void {
		$h = $this->makeHandler();
		$this->assertSame(
			'alice@example.org',
			$this->call($h, ['email' => 'alice@example.org'])
		);
	}

	public function testTitleFallsBackToLiteralWhenAllAbsent(): void {
		$h = $this->makeHandler();
		$this->assertSame('Contact Person', $this->call($h, []));
	}

	public function testTitlePrefersNameOverEmail(): void {
		$h = $this->makeHandler();
		$this->assertSame(
			'Bob Builder',
			$this->call($h, [
				'voornaam' => 'Bob',
				'achternaam' => 'Builder',
				'email' => 'bob@example.org',
			])
		);
	}
}
