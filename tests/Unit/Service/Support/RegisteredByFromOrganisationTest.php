<?php

/**
 * Unit tests for RegisteredByFromOrganisation.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Support
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Support;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Support\RegisteredByFromOrganisation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The registeredBy value set from the accepting organisation's type.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service\Support
 *
 * @covers \OCA\Stackiq\Service\Support\RegisteredByFromOrganisation
 */
class RegisteredByFromOrganisationTest extends TestCase {

	private RegisterConfigReaderInterface&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Register 2, organisation schema 20.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(RegisterConfigReaderInterface::class);
		$this->settings->method('getSchemaIdForObjectType')->with('organization')->willReturn(20);
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => 2]);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The organisation found in register 2, schema 20, without RBAC or multitenancy.
	 *
	 * @param string|null $type The organisation's type.
	 *
	 * @return void
	 */
	private function organisationOfType(?string $type): void {
		$organisation = $this->createMock(ObjectEntity::class);
		$organisation->method('getObject')->willReturn(['type' => $type]);
		$this->objects->expects($this->once())->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true) use ($organisation) {
				$this->assertSame('org-1', $id);
				$this->assertSame([2, 20], [$options?->register, $options?->schema]);
				$this->assertSame([false, false], [$_rbac, $_multitenancy]);
				return $organisation;
			}
		);
	}//end organisationOfType()

	/**
	 * The service under test.
	 *
	 * @return RegisteredByFromOrganisation
	 */
	private function subject(): RegisteredByFromOrganisation {
		return new RegisteredByFromOrganisation(settingsService: $this->settings, logger: $this->logger);
	}//end subject()

	/**
	 * A known organisation type sets registeredBy and keeps the other fields.
	 *
	 * @return void
	 */
	public function testAKnownTypeSetsRegisteredBy(): void {
		$this->organisationOfType('Supplier');
		$this->logger->expects($this->once())->method('info')->with(
			'Updated registeredBy during transfer',
			['organisationUuid' => 'org-1', 'orgType' => 'Supplier', 'registeredBy' => 'Supplier']
		);

		$result = $this->subject()->apply(objectService: $this->objects, objectData: ['name' => 'x'], organisationUuid: 'org-1');

		$this->assertSame(['name' => 'x', 'registeredBy' => 'Supplier'], $result);
	}//end testAKnownTypeSetsRegisteredBy()

	/**
	 * An unknown type leaves the data as it was.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeLeavesTheDataAlone(): void {
		$this->organisationOfType('Hobbyclub');
		$this->logger->expects($this->never())->method('info');

		$result = $this->subject()->apply(objectService: $this->objects, objectData: ['registeredBy' => 'Community'], organisationUuid: 'org-1');

		$this->assertSame(['registeredBy' => 'Community'], $result);
	}//end testAnUnknownTypeLeavesTheDataAlone()

	/**
	 * Without a configured register nothing is looked up.
	 *
	 * @return void
	 */
	public function testWithoutARegisterNothingIsLookedUp(): void {
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getSchemaIdForObjectType')->willReturn(20);
		$settings->method('getVoorzieningenConfig')->willReturn([]);
		$this->objects->expects($this->never())->method('find');

		$subject = new RegisteredByFromOrganisation(settingsService: $settings, logger: $this->logger);

		$this->assertSame(['a' => 1], $subject->apply(objectService: $this->objects, objectData: ['a' => 1], organisationUuid: 'org-1'));
	}//end testWithoutARegisterNothingIsLookedUp()

	/**
	 * A missing organisation leaves the data as it was.
	 *
	 * @return void
	 */
	public function testAMissingOrganisationLeavesTheDataAlone(): void {
		$this->objects->expects($this->once())->method('find')->willReturn(null);

		$this->assertSame(['a' => 1], $this->subject()->apply(objectService: $this->objects, objectData: ['a' => 1], organisationUuid: 'org-1'));
	}//end testAMissingOrganisationLeavesTheDataAlone()

	/**
	 * A failed lookup is logged as a warning and leaves the data as it was.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsLoggedAndSwallowed(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Failed to update registeredBy during transfer',
			['organisationUuid' => 'org-1', 'error' => 'down']
		);

		$this->assertSame(['a' => 1], $this->subject()->apply(objectService: $this->objects, objectData: ['a' => 1], organisationUuid: 'org-1'));
	}//end testAFailedLookupIsLoggedAndSwallowed()
}//end class
