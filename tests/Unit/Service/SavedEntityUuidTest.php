<?php

/**
 * SavedEntityUuid reads the uuid of whatever a save returned: an entity with getUuid, or an array with id or uuid.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SavedEntityUuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SavedEntityUuid.
 */
#[CoversClass(SavedEntityUuid::class)]
class SavedEntityUuidTest extends TestCase {

	/**
	 * An entity whose getUuid answers the given value.
	 *
	 * @param mixed $uuid The uuid answer.
	 *
	 * @return object
	 */
	private function entity(mixed $uuid): object {
		return new class ($uuid) {
			public function __construct(private mixed $uuid) {
			}

			public function getUuid(): mixed {
				return $this->uuid;
			}
		};
	}//end entity()

	/**
	 * An entity's non-empty string uuid is returned; others are null.
	 *
	 * @return void
	 */
	public function testReadsAnEntityUuid(): void {
		$reader = new SavedEntityUuid($this->createMock(LoggerInterface::class), 'Owner');

		$this->assertSame('uuid-1', $reader->read($this->entity('uuid-1')));
		$this->assertNull($reader->read($this->entity('')));
		$this->assertNull($reader->read($this->entity(42)));
		$this->assertNull($reader->read(new \stdClass()));
	}//end testReadsAnEntityUuid()

	/**
	 * An array answers its id first, then its uuid, string only.
	 *
	 * @return void
	 */
	public function testReadsAnArrayUuid(): void {
		$reader = new SavedEntityUuid($this->createMock(LoggerInterface::class), 'Owner');

		$this->assertSame('a', $reader->read(['id' => 'a', 'uuid' => 'b']));
		$this->assertSame('b', $reader->read(['uuid' => 'b']));
		$this->assertNull($reader->read(['id' => 7]));
		$this->assertNull($reader->read([]));
		$this->assertNull($reader->read('uuid-1'));
		$this->assertNull($reader->read(null));
	}//end testReadsAnArrayUuid()

	/**
	 * A getUuid that throws is logged under the owner and read as null.
	 *
	 * @return void
	 */
	public function testAThrowingGetterIsLoggedAndNull(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'ModuleSync: could not read uuid from saved entity',
			['exception' => 'not loaded']
		);
		$entity = new class {
			public function getUuid(): string {
				throw new RuntimeException('not loaded');
			}
		};

		$this->assertNull((new SavedEntityUuid($logger, 'ModuleSync'))->read($entity));
	}//end testAThrowingGetterIsLoggedAndNull()
}//end class
