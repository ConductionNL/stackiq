<?php

/**
 * The contact link refresh reads entity rows through their accessors.
 *
 * OpenRegister hands back ObjectEntity rows as well as plain arrays. For an
 * entity the record data comes from getObject() and the uuid from getUuid(),
 * not from casting the object; these tests pin that, and that the rewritten
 * link is saved under the entity's uuid.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Entity rows in the contact link refresh.
 */
#[CoversClass(OrganizationContactSyncJob::class)]
final class OrganizationContactSyncJobEntityRowTest extends TestCase {

	/**
	 * The contacts bridge double.
	 *
	 * @var StackiqContactSyncService&MockObject
	 */
	private StackiqContactSyncService $contacts;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->contacts = $this->createMock(StackiqContactSyncService::class);
	}//end setUp()

	/**
	 * A stale link on an entity row is resolved from getObject() data and saved under getUuid().
	 *
	 * @return void
	 */
	public function testAnEntityRowIsReadThroughItsAccessors(): void {
		$data = ['id' => 'data-id', 'naam' => 'Gemeente X', 'contactsUid' => 'gone'];
		$this->contacts->expects($this->once())->method('findContactByUid')->with('gone')->willReturn(null);
		$this->contacts->expects($this->once())
			->method('syncToContacts')
			->with('organisatie', $data)
			->willReturn('fresh-uid');

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())
			->method('saveObject')
			->with(['contactsUid' => 'fresh-uid'] + $data, [], 3, 9, 'entity-uuid', false, false);

		$this->refresh($objects, $this->entity($data, 'entity-uuid'));
	}//end testAnEntityRowIsReadThroughItsAccessors()

	/**
	 * An entity whose uuid is empty is saved without one, rather than under the data id.
	 *
	 * @return void
	 */
	public function testAnEntityWithoutAUuidIsSavedWithoutOne(): void {
		$data = ['id' => 'data-id', 'contactsUid' => ''];
		$this->contacts->expects($this->never())->method('findContactByUid');
		$this->contacts->method('syncToContacts')->willReturn('fresh-uid');

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())
			->method('saveObject')
			->with(['id' => 'data-id', 'contactsUid' => 'fresh-uid'], [], 3, 9, null, false, false);

		$this->refresh($objects, $this->entity($data, ''));
	}//end testAnEntityWithoutAUuidIsSavedWithoutOne()

	/**
	 * An entity row whose link is still fresh is left alone.
	 *
	 * @return void
	 */
	public function testAFreshLinkOnAnEntityIsNotSaved(): void {
		$this->contacts->method('findContactByUid')->with('live')->willReturn(['uid' => 'live']);
		$this->contacts->expects($this->never())->method('syncToContacts');

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('saveObject');

		$this->refresh($objects, $this->entity(['contactsUid' => ' live '], 'entity-uuid'));
	}//end testAFreshLinkOnAnEntityIsNotSaved()

	/**
	 * Refresh one organisation row in register 3, schema 9.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 * @param object                 $row     The row.
	 *
	 * @return void
	 */
	private function refresh(ObjectServiceInterface $objects, object $row): void {
		$job = new OrganizationContactSyncJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(OrganizationSyncService::class),
			$this->contacts,
			$this->createMock(SettingsService::class),
			$this->createMock(CronjobSettings::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class)
		);

		(new ReflectionMethod($job, 'refreshOne'))->invoke($job, $objects, $row, 3, 9, 'organisatie');
	}//end refresh()

	/**
	 * An entity-shaped row: data through getObject(), uuid through getUuid(), and a public
	 * property a plain cast would pick up instead.
	 *
	 * @param array<string, mixed> $data The record data.
	 * @param string               $uuid The uuid.
	 *
	 * @return object The row.
	 */
	private function entity(array $data, string $uuid): object {
		return new class ($data, $uuid) {
			/**
			 * What a plain cast would read; must be ignored.
			 *
			 * @var string
			 */
			public string $decoy = 'cast-only';

			/**
			 * Build the row.
			 *
			 * @param array<string, mixed> $data The record data.
			 * @param string               $uuid The uuid.
			 */
			public function __construct(private readonly array $data, private readonly string $uuid) {
			}

			/**
			 * The record data.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end entity()
}//end class
