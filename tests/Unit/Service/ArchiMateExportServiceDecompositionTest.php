<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ArchiMateExportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for the W30 ArchiMateExportService decomposition helper
 * (createFolderNode).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-4
 */
class ArchiMateExportServiceDecompositionTest extends TestCase {
	private function makeService(): ArchiMateExportService {
		$reflection = new ReflectionClass(ArchiMateExportService::class);
		return $reflection->newInstanceWithoutConstructor();
	}

	private function call(
		ArchiMateExportService $svc,
		\SimpleXMLElement $parent,
		string $name,
		string $id,
		string $type,
	): \SimpleXMLElement {
		$ref = new ReflectionMethod($svc, 'createFolderNode');
		$ref->setAccessible(true);
		return $ref->invokeArgs($svc, [$parent, $name, $id, $type]);
	}

	public function testCreateFolderNodeAttachesChildWithStandardAttributes(): void {
		$svc = $this->makeService();
		$parent = new \SimpleXMLElement('<root/>');
		$folder = $this->call($svc, $parent, 'Application', 'folder-elements', 'application');

		$this->assertSame('folder', $folder->getName());
		$this->assertSame('Application', (string)$folder['name']);
		$this->assertSame('folder-elements', (string)$folder['id']);
		$this->assertSame('application', (string)$folder['type']);
	}

	public function testCreateFolderNodeAttachesToParent(): void {
		$svc = $this->makeService();
		$parent = new \SimpleXMLElement('<root/>');
		$this->call($svc, $parent, 'Views', 'folder-views', 'diagrams');
		$this->call($svc, $parent, 'Relations', 'folder-relations', 'relations');

		$this->assertCount(2, $parent->folder);
		$this->assertSame('Views', (string)$parent->folder[0]['name']);
		$this->assertSame('Relations', (string)$parent->folder[1]['name']);
	}

	public function testCreateFolderNodeReturnsTheNewChild(): void {
		$svc = $this->makeService();
		$parent = new \SimpleXMLElement('<root/>');
		$folder = $this->call($svc, $parent, 'Organizations', 'folder-organizations', 'business');

		// Returned node is mutable and additional children attach to it.
		$folder->addChild('item');
		$this->assertCount(1, $parent->folder[0]->item);
	}
}
