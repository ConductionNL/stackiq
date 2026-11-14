<?php

/**
 * Tests for TechnologyComponentEolStamper.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-004-the-end-of-life-sync-shall-stamp-a-components-end-of-support-from-the-software-version-it-is
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\TechnologyComponentEolStamper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Stamping a version's end of support on its components.
 *
 * @covers \OCA\Stackiq\Service\TechnologyComponentEolStamper
 */
class TechnologyComponentEolStamperTest extends TestCase {

	/**
	 * A stamped version.
	 */
	private const VERSION = ['id' => 'mv-1', 'dateEndSupport' => '2026-03-03', 'eolSource' => 'endoflife.date', 'eolUpdatedOn' => '2026-07-23T12:00:00+00:00'];

	/**
	 * A settings double that knows the technologyComponent schema, or not.
	 *
	 * @param bool $configured Whether the schema is configured.
	 *
	 * @return SettingsService
	 */
	private function settings(bool $configured = true): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterIdForObjectType')->willReturn($configured ? 10 : null);
		$settings->method('getSchemaIdForObjectType')->willReturn($configured ? 22 : null);
		return $settings;
	}//end settings()

	/**
	 * Without the schema configured, or without a version id, nothing is read.
	 *
	 * @return void
	 */
	public function testNothingIsReadWithoutTheSchemaOrAVersion(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('searchObjects');

		$this->assertSame(0, (new TechnologyComponentEolStamper($this->settings(false), $this->createMock(LoggerInterface::class)))->stamp($objects, self::VERSION));
		$this->assertSame(0, (new TechnologyComponentEolStamper($this->settings(), $this->createMock(LoggerInterface::class)))->stamp($objects, ['dateEndSupport' => '2026-03-03']));
	}//end testNothingIsReadWithoutTheSchemaOrAVersion()

	/**
	 * An entity row is read through its object; a failed save is logged and not counted.
	 *
	 * @return void
	 */
	public function testEntityRowsAndFailedSaves(): void {
		$entity = new class {
			/**
			 * The object data.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['name' => 'Database 01', 'software' => 'mv-1', 'endOfSupport' => '2025-01-01'];
			}

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return 'db-1';
			}
		};
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturn([$entity, ['id' => 'db-2', 'software' => 'mv-1']]);
		$saved = [];
		$objects->method('saveObject')->willReturnCallback(function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved) {
			if ($uuid === 'db-2') {
				throw new RuntimeException('locked');
			}

			$saved[$uuid] = $object;
			return $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$count = (new TechnologyComponentEolStamper($this->settings(), $logger))->stamp($objects, self::VERSION);

		$this->assertSame(1, $count);
		$this->assertSame(['name' => 'Database 01', 'software' => 'mv-1', 'endOfSupport' => '2026-03-03', 'id' => 'db-1', 'eolSource' => 'endoflife.date', 'eolUpdatedOn' => '2026-07-23T12:00:00+00:00'], $saved['db-1']);
	}//end testEntityRowsAndFailedSaves()

	/**
	 * A failing lookup stamps nothing and does not throw.
	 *
	 * @return void
	 */
	public function testAFailingLookupStampsNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willThrowException(new RuntimeException('down'));
		$objects->expects($this->never())->method('saveObject');

		$this->assertSame(0, (new TechnologyComponentEolStamper($this->settings(), $this->createMock(LoggerInterface::class)))->stamp($objects, self::VERSION));
	}//end testAFailingLookupStampsNothing()
}//end class
