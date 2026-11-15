<?php

/**
 * Unit tests for ArchiMateImportScope.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Immutable value object of the ArchiMate import; its copy semantics are pinned here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\ArchiMate;

use OCA\Stackiq\Service\ArchiMate\ArchiMateImportScope;
use PHPUnit\Framework\TestCase;

/**
 * The with-methods return a changed copy and never change the original.
 *
 * @covers \OCA\Stackiq\Service\ArchiMate\ArchiMateImportScope
 *
 * @spec exclude Immutable value object of the ArchiMate import; its copy semantics are pinned here.
 */
class ArchiMateImportScopeTest extends TestCase {

	/**
	 * A new scope carries its model and property definitions and is otherwise empty.
	 *
	 * @return void
	 */
	public function testANewScopeIsEmpty(): void {
		$scope = new ArchiMateImportScope(modelIdentifier: 'model-1', propDefMap: ['pd-1' => 'Status']);

		$this->assertSame(expected: 'model-1', actual: $scope->modelIdentifier());
		$this->assertSame(expected: ['pd-1' => 'Status'], actual: $scope->propDefMap());
		$this->assertSame(expected: [], actual: $scope->lookup('elements'));
		$this->assertSame(expected: [], actual: $scope->elementsLookup());
		$this->assertSame(expected: '', actual: $scope->section());
		$this->assertSame(expected: [], actual: $scope->properties());
	}//end testANewScopeIsEmpty()

	/**
	 * Each with-method changes only the copy.
	 *
	 * @return void
	 */
	public function testWithMethodsLeaveTheOriginalUntouched(): void {
		$scope = new ArchiMateImportScope(modelIdentifier: 'model-1', propDefMap: []);

		$changed = $scope
			->withLookups(['elements' => ['e1' => 'uuid-1']])
			->withElementsLookup(['e1' => ['name' => 'Zaaksysteem']])
			->withSection('relations')
			->withProperties(['Status' => 'Actief']);

		$this->assertNotSame(expected: $scope, actual: $changed);
		$this->assertSame(expected: ['e1' => 'uuid-1'], actual: $changed->lookup('elements'));
		$this->assertSame(expected: [], actual: $changed->lookup('views'));
		$this->assertSame(expected: ['e1' => ['name' => 'Zaaksysteem']], actual: $changed->elementsLookup());
		$this->assertSame(expected: 'relations', actual: $changed->section());
		$this->assertSame(expected: ['Status' => 'Actief'], actual: $changed->properties());
		$this->assertSame(expected: 'model-1', actual: $changed->modelIdentifier());

		$this->assertSame(expected: [], actual: $scope->lookup('elements'));
		$this->assertSame(expected: [], actual: $scope->elementsLookup());
		$this->assertSame(expected: '', actual: $scope->section());
		$this->assertSame(expected: [], actual: $scope->properties());
	}//end testWithMethodsLeaveTheOriginalUntouched()
}//end class
