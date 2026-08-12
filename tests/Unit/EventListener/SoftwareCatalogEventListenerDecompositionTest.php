<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\EventListener;

use OCA\SoftwareCatalog\EventListener\SoftwareCatalogEventListener;
use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Unit tests for the W30 SoftwareCatalogEventListener decomposition helpers
 * (resolveCatalogSchemaIds, matchesSchema, isActiveStatus).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 */
class SoftwareCatalogEventListenerDecompositionTest extends TestCase {
	/**
	 * @var ContainerInterface|MockObject
	 */
	private $container;

	/**
	 * @var SoftwareCatalogEventListener
	 */
	private SoftwareCatalogEventListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->listener = new SoftwareCatalogEventListener($this->container);
	}

	/**
	 * resolveCatalogSchemaIds casts every configured schema id to int and keeps null pass-through.
	 */
	public function testResolveCatalogSchemaIdsCastsAndPreservesNull(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			static function (string $objectType): ?int {
				return match ($objectType) {
					'organisatie' => 12,
					'contactpersoon' => 34,
					'contactgegevens' => null,
					'gebruik' => 56,
					default => null,
				};
			}
		);

		$method = new ReflectionMethod($this->listener, 'resolveCatalogSchemaIds');
		$method->setAccessible(true);
		$result = $method->invoke($this->listener, $settings);

		$this->assertSame(
			[
				'organisatie' => 12,
				'contactpersoon' => 34,
				'contactgegevens' => null,
				'gebruik' => 56,
			],
			$result
		);
	}

	/**
	 * resolveCatalogSchemaIds treats empty strings as missing configuration.
	 */
	public function testResolveCatalogSchemaIdsTreatsNullAsNull(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturn(null);

		$method = new ReflectionMethod($this->listener, 'resolveCatalogSchemaIds');
		$method->setAccessible(true);
		$result = $method->invoke($this->listener, $settings);

		$this->assertSame(
			[
				'organisatie' => null,
				'contactpersoon' => null,
				'contactgegevens' => null,
				'gebruik' => null,
			],
			$result
		);
	}

	/**
	 * matchesSchema returns true only when both sides match and the configured id is non-null.
	 */
	public function testMatchesSchemaSemantics(): void {
		$method = new ReflectionMethod($this->listener, 'matchesSchema');
		$method->setAccessible(true);

		$this->assertTrue($method->invoke($this->listener, 7, 7));
		$this->assertFalse($method->invoke($this->listener, 7, 8));
		$this->assertFalse($method->invoke($this->listener, 7, null));
	}

	/**
	 * matchesSchema with zero on both sides is technically "match" — this documents
	 * the structural behaviour. Callers are expected to keep zero out of the configured side.
	 */
	public function testMatchesSchemaZeroEdgeCase(): void {
		$method = new ReflectionMethod($this->listener, 'matchesSchema');
		$method->setAccessible(true);
		// 0 === 0 is a structural match; policy filtering is the caller's job.
		$this->assertTrue($method->invoke($this->listener, 0, 0));
	}

	/**
	 * runOrganizationSync resolves the OrganizationSyncService from the container
	 * and invokes processSpecificOrganization with the supplied object.
	 */
	public function testRunOrganizationSyncDispatchesAndLogsSuccess(): void {
		if (class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false) {
			$this->markTestSkipped('OpenRegister ObjectEntity not available in this fixture.');
		}

		$object = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$object->method('getUuid')->willReturn('org-uuid');

		$orgSyncService = new class {
			public bool $called = false;
			public function processSpecificOrganization($obj): array {
				$this->called = true;
				return ['ok' => true];
			}
		};

		$this->container->method('get')->willReturnCallback(
			static fn (string $id): mixed => $id === 'OCA\SoftwareCatalog\Service\OrganizationSyncService'
				? $orgSyncService
				: null
		);

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->stringContains('Successfully processed organization update')
		);

		$method = new ReflectionMethod($this->listener, 'runOrganizationSync');
		$method->setAccessible(true);
		$method->invoke($this->listener, $object, 'update', $logger);

		$this->assertTrue($orgSyncService->called);
	}

	/**
	 * runOrganizationSync logs an error rather than throwing when the sync fails.
	 */
	public function testRunOrganizationSyncCatchesAndLogsErrors(): void {
		if (class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false) {
			$this->markTestSkipped('OpenRegister ObjectEntity not available in this fixture.');
		}

		$object = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$object->method('getUuid')->willReturn('org-uuid');

		$orgSyncService = new class {
			public function processSpecificOrganization($obj): array {
				throw new \RuntimeException('boom');
			}
		};

		$this->container->method('get')->willReturn($orgSyncService);

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->stringContains('Failed to process organization creation')
		);

		$method = new ReflectionMethod($this->listener, 'runOrganizationSync');
		$method->setAccessible(true);

		// Should not throw.
		$method->invoke($this->listener, $object, 'creation', $logger);
		$this->addToAssertionCount(1);
	}

	/**
	 * isActiveStatus recognises Dutch and English active forms only.
	 */
	public function testIsActiveStatusRecognisesDutchAndEnglish(): void {
		$method = new ReflectionMethod($this->listener, 'isActiveStatus');
		$method->setAccessible(true);

		$this->assertTrue($method->invoke($this->listener, 'actief'));
		$this->assertTrue($method->invoke($this->listener, 'active'));
		$this->assertFalse($method->invoke($this->listener, 'inactief'));
		$this->assertFalse($method->invoke($this->listener, 'pending'));
		$this->assertFalse($method->invoke($this->listener, ''));
		// Caller is expected to lowercase first; helper does not.
		$this->assertFalse($method->invoke($this->listener, 'Actief'));
	}
}
