<?php

/**
 * Unit tests for SettingsService::testEmailConnection().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The connection test builds a real Symfony transport from the settings
 * (no message is sent, so nothing leaves the process) and reports what
 * it found, never the secrets.
 */
final class SettingsServiceEmailConnectionTest extends TestCase {

	/**
	 * @var EmailSettings&MockObject
	 */
	private EmailSettings $emailSettings;

	/**
	 * Messages the logger received, keyed by level.
	 *
	 * @var array<string, list<array{0: string, 1: array}>>
	 */
	private array $logged = [];

	/**
	 * Build the service with a logger that records every call.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$this->emailSettings = $this->createMock(EmailSettings::class);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->logged[$level][] = [$message, $context];
				}
			);
		}

		return new SettingsService(
			catalogue: $this->createMock(CatalogueLocator::class),
			config: $this->createMock(IAppConfig::class),
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->createMock(RoleGroupSettings::class),
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			logger: $logger,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->emailSettings
		);

	}//end service()

	/**
	 * Every context array the logger received, flattened to one string.
	 *
	 * @return string
	 */
	private function loggedText(): string {
		return json_encode($this->logged);

	}//end loggedText()

	/**
	 * A complete SMTP configuration passes and reports host, port and
	 * encryption, but only whether a username is set.
	 *
	 * @return void
	 */
	public function testAnSmtpConfigurationPassesAndHidesTheCredentials(): void {
		$result = $this->service()->testEmailConnection(
			[
				'enabled' => true,
				'transportType' => 'smtp',
				'senderEmail' => 'noreply@example.org',
				'smtpHost' => 'mail.example.org',
				'smtpPort' => 465,
				'smtpEncryption' => 'ssl',
				'smtpUsername' => 'mailer',
				'smtpPassword' => 'pa ss/word',
			]
		);

		$this->assertTrue($result['success'], $result['message']);
		$this->assertSame('Email connection test successful for smtp', $result['message']);
		$this->assertSame(
			['type' => 'SMTP', 'host' => 'mail.example.org', 'port' => 465, 'encryption' => 'ssl', 'username' => 'configured'],
			$result['details']
		);
		$this->assertStringNotContainsString('pa ss/word', $this->loggedText());
		$this->assertStringContainsString('smtp:\/\/***:***@mail.example.org:465?encryption=ssl', $this->loggedText());

	}//end testAnSmtpConfigurationPassesAndHidesTheCredentials()

	/**
	 * Without a username the details say so, and encryption "none" adds no
	 * encryption suffix to the logged DSN pattern.
	 *
	 * @return void
	 */
	public function testAnSmtpConfigurationWithoutCredentialsOrEncryption(): void {
		$result = $this->service()->testEmailConnection(
			[
				'enabled' => true,
				'senderEmail' => 'noreply@example.org',
				'smtpHost' => 'localhost',
				'smtpPort' => 25,
				'smtpEncryption' => 'none',
			]
		);

		$this->assertTrue($result['success'], $result['message']);
		$this->assertSame('none', $result['details']['username']);
		$this->assertStringContainsString('smtp:\/\/***:***@localhost:25"', $this->loggedText());

	}//end testAnSmtpConfigurationWithoutCredentialsOrEncryption()

	/**
	 * With no settings passed in, the stored settings are used.
	 *
	 * @return void
	 */
	public function testStoredSettingsAreUsedWhenNoneArePassed(): void {
		$service = $this->service();
		$this->emailSettings->expects($this->once())->method('getEmailSettings')->willReturn(['enabled' => false]);

		$result = $service->testEmailConnection();

		$this->assertSame(['success' => false, 'message' => 'Email notifications are disabled'], $result);

	}//end testStoredSettingsAreUsedWhenNoneArePassed()

	/**
	 * An enabled configuration without a sender address is refused before
	 * any transport is built.
	 *
	 * @return void
	 */
	public function testASenderAddressIsRequired(): void {
		$result = $this->service()->testEmailConnection(['enabled' => true, 'smtpHost' => 'h', 'smtpPort' => 25]);

		$this->assertSame(['success' => false, 'message' => 'Sender email address is required'], $result);

	}//end testASenderAddressIsRequired()

	/**
	 * Mailjet needs both keys; a missing one fails the test with that reason.
	 *
	 * @return void
	 */
	public function testMailjetWithoutASecretKeyFails(): void {
		$result = $this->service()->testEmailConnection(
			[
				'enabled' => true,
				'transportType' => 'mailjet',
				'senderEmail' => 'a@example.org',
				'mailjetApiKey' => 'key',
			]
		);

		$this->assertFalse($result['success']);
		$this->assertSame(
			'Email connection test failed: Mailjet API key and secret key are required',
			$result['message']
		);

	}//end testMailjetWithoutASecretKeyFails()

	/**
	 * With both Mailjet keys the transport is built and the details report
	 * only that the keys are present.
	 *
	 * @return void
	 */
	public function testMailjetWithBothKeysPasses(): void {
		$result = $this->service()->testEmailConnection(
			[
				'enabled' => true,
				'transportType' => 'mailjet',
				'senderEmail' => 'a@example.org',
				'mailjetApiKey' => 'public-key',
				'mailjetSecretKey' => 'secret-key',
			]
		);

		$this->assertTrue($result['success'], $result['message']);
		$this->assertSame(['type' => 'Mailjet API', 'has_api_key' => true, 'has_secret_key' => true], $result['details']);
		$this->assertStringNotContainsString('secret-key', $this->loggedText());

	}//end testMailjetWithBothKeysPasses()

	/**
	 * A transport type the service cannot build is reported as unsupported.
	 *
	 * @return void
	 */
	public function testAnUnsupportedTransportFails(): void {
		$result = $this->service()->testEmailConnection(
			['enabled' => true, 'transportType' => 'sendgrid', 'senderEmail' => 'a@example.org', 'sendgridApiKey' => 'k']
		);

		$this->assertSame(
			['success' => false, 'message' => 'Email connection test failed: Unsupported transport type: sendgrid'],
			$result
		);

	}//end testAnUnsupportedTransportFails()
}//end class
