<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\SymfonyEmailService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the SymfonyEmailService settings surface: the sender/transport getters
 * that read EmailSettings (with their defaults), the catalogue of transports,
 * and the setters that validate their input and write back through
 * EmailSettings, resetting the cached mailer.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
#[CoversClass(SymfonyEmailService::class)]
class SymfonyEmailServiceSettingsTest extends TestCase {
	private EmailSettings $emailSettings;

	private SymfonyEmailService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->emailSettings = $this->createMock(EmailSettings::class);

		$reflection = new ReflectionClass(SymfonyEmailService::class);
		$this->service = $reflection->newInstanceWithoutConstructor();
		$property      = $reflection->getProperty('emailSettings');
		$property->setAccessible(true);
		$property->setValue($this->service, $this->emailSettings);
	}

	/**
	 * Read the private cached-mailer property.
	 *
	 * @return mixed
	 */
	private function mailerValue() {
		$property = new \ReflectionProperty($this->service, 'mailer');
		$property->setAccessible(true);
		return $property->getValue($this->service);
	}

	public function testGettersReadEmailSettingsOrFallBackToTheDefaults(): void {
		$this->emailSettings->method('getEmailSettings')->willReturn([
			'senderEmail' => 'desk@example.org',
			'senderName'  => 'Service Desk',
		]);

		$this->assertSame('desk@example.org', $this->service->getSenderEmail());
		$this->assertSame('Service Desk', $this->service->getSenderName());
	}

	public function testGettersFallBackWhenTheSettingsAreEmpty(): void {
		$this->emailSettings->method('getEmailSettings')->willReturn([]);

		$this->assertSame('noreply@softwarecatalogus.nl', $this->service->getSenderEmail());
		$this->assertSame('Software Catalogus', $this->service->getSenderName());
	}

	public function testGetEmailSettingsAddsTheTransportCatalogueAndDefaultsTheType(): void {
		$this->emailSettings->method('getEmailSettings')->willReturn(['senderEmail' => 'x@y.z']);

		$settings = $this->service->getEmailSettings();

		$this->assertSame('smtp', $settings['transportType']);
		$this->assertSame($this->service->getAvailableTransports(), $settings['availableTransports']);
		$this->assertArrayHasKey('ses', $settings['availableTransports']);
	}

	public function testGetAvailableTransportsListsEveryKnownTransport(): void {
		$transports = $this->service->getAvailableTransports();

		$this->assertSame('SMTP Server', $transports['smtp']);
		$this->assertSame('Amazon SES', $transports['ses']);
		$this->assertCount(9, $transports);
	}

	public function testSetSenderEmailValidatesWritesAndResetsTheMailer(): void {
		$this->emailSettings->expects($this->once())->method('updateEmailSettings')
			->with(['senderEmail' => 'new@example.org']);

		$this->service->setSenderEmail('new@example.org');

		$this->assertNull($this->mailerValue());
	}

	public function testSetSenderEmailRejectsAnInvalidAddress(): void {
		$this->emailSettings->expects($this->never())->method('updateEmailSettings');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setSenderEmail('not-an-email');
	}

	public function testSetSenderNameWritesAndResetsTheMailer(): void {
		$this->emailSettings->expects($this->once())->method('updateEmailSettings')
			->with(['senderName' => 'New Name']);

		$this->service->setSenderName('New Name');

		$this->assertNull($this->mailerValue());
	}

	public function testSetTransportConfigurationMergesTheTypeWithTheConfig(): void {
		$this->emailSettings->expects($this->once())->method('updateEmailSettings')
			->with(['transportType' => 'ses', 'sesAccessKey' => 'AK']);

		$this->service->setTransportConfiguration('ses', ['sesAccessKey' => 'AK']);
	}

	public function testSetTransportConfigurationRejectsAnUnknownTransport(): void {
		$this->emailSettings->expects($this->never())->method('updateEmailSettings');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setTransportConfiguration('carrier-pigeon');
	}

	public function testTestReceiverOverrideRejectsAnInvalidAddressButAllowsEmpty(): void {
		$this->emailSettings->expects($this->once())->method('updateEmailSettings')
			->with(['testReceiverOverride' => '']);
		$this->service->setTestReceiverOverride('');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setTestReceiverOverride('bogus');
	}

	public function testBooleanFlagSettersWriteTheirSingleKey(): void {
		$calls = [];
		$this->emailSettings->method('updateEmailSettings')
			->willReturnCallback(function (array $settings) use (&$calls) {
				$calls[] = $settings;
				return $settings;
			});

		$this->service->setEnabled(true);
		$this->service->setOrganizationRegistrationEnabled(false);
		$this->service->setOrganizationActivationEnabled(true);
		$this->service->setUserCreationEnabled(false);

		$this->assertSame([
			['enabled' => true],
			['organizationRegistrationEnabled' => false],
			['organizationActivationEnabled' => true],
			['userCreationEnabled' => false],
		], $calls);
	}
}
