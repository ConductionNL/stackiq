<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ArchiMateImportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for the W30 ArchiMateImportService decomposition helper
 * (validateArchiMateFile).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-4
 */
class ArchiMateImportServiceDecompositionTest extends TestCase {
	private function makeService(): ArchiMateImportService {
		$reflection = new ReflectionClass(ArchiMateImportService::class);
		return $reflection->newInstanceWithoutConstructor();
	}

	private function call(ArchiMateImportService $svc, array $args): string {
		$ref = new ReflectionMethod($svc, 'validateArchiMateFile');
		$ref->setAccessible(true);
		return $ref->invokeArgs($svc, [$args]);
	}

	public function testValidateAcceptsCamelCaseKey(): void {
		$svc = $this->makeService();
		$tmp = tempnam(sys_get_temp_dir(), 'amef');
		file_put_contents($tmp, '<xml/>');
		try {
			$this->assertSame($tmp, $this->call($svc, ['filePath' => $tmp]));
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateAcceptsSnakeCaseKey(): void {
		$svc = $this->makeService();
		$tmp = tempnam(sys_get_temp_dir(), 'amef');
		file_put_contents($tmp, '<xml/>');
		try {
			$this->assertSame($tmp, $this->call($svc, ['file_path' => $tmp]));
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateThrowsWhenPathMissing(): void {
		$svc = $this->makeService();
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('File path is required for import');
		$this->call($svc, []);
	}

	public function testValidateThrowsWhenPathEmpty(): void {
		$svc = $this->makeService();
		$this->expectException(\InvalidArgumentException::class);
		$this->call($svc, ['filePath' => '']);
	}

	public function testValidateThrowsWhenFileMissing(): void {
		$svc = $this->makeService();
		$missing = sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(6)) . '.xml';
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("File not found: {$missing}");
		$this->call($svc, ['filePath' => $missing]);
	}

	public function testValidatePrefersCamelCaseOverSnakeCase(): void {
		$svc = $this->makeService();
		$tmp1 = tempnam(sys_get_temp_dir(), 'amef1');
		$tmp2 = tempnam(sys_get_temp_dir(), 'amef2');
		file_put_contents($tmp1, '<xml/>');
		file_put_contents($tmp2, '<xml/>');
		try {
			$this->assertSame(
				$tmp1,
				$this->call($svc, ['filePath' => $tmp1, 'file_path' => $tmp2])
			);
		} finally {
			unlink($tmp1);
			unlink($tmp2);
		}
	}
}
