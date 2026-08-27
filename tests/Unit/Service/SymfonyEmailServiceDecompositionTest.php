<?php

/**
 * Unit tests for the decomposed SymfonyEmailService helpers.
 *
 * Covers method-decomposition task 7.5 (extract renderTemplate /
 * resolveSender from sendTemplatedEmail / sendEmail).
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7-5
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the private helpers extracted from SymfonyEmailService.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7-5
 */
class SymfonyEmailServiceDecompositionTest extends TestCase {

	/**
	 * Build a service with stub collaborators. SettingsService is a real
	 * partial-mock so the helpers can ask it for email settings.
	 *
	 * @param array $emailSettings Settings returned from getEmailSettings()
	 *
	 * @return SymfonyEmailService
	 */
	private function makeService(array $emailSettings): SymfonyEmailService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getEmailSettings')->willReturn($emailSettings);

		return new SymfonyEmailService(
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$settings,
		);

	}//end makeService()

	/**
	 * renderTemplate prefers a custom template override from email
	 * settings over the built-in default and substitutes variables in.
	 *
	 * @return void
	 */
	public function testRenderTemplateUsesCustomOverride(): void {
		$service = $this->makeService(
			[
				'templates' => [
					'organization_registration' => '<p>Welcome, {{ organization.name }}.</p>',
				],
			]
		);

		$reflection = new \ReflectionMethod($service, 'renderTemplate');
		$reflection->setAccessible(true);

		$rendered = $reflection->invoke(
			$service,
			'organization_registration',
			['organization' => ['name' => 'Acme BV']]
		);

		$this->assertStringContainsString('Acme BV', $rendered);
		$this->assertStringStartsWith('<p>Welcome,', $rendered);

	}//end testRenderTemplateUsesCustomOverride()

	/**
	 * renderTemplate falls through to the built-in default template when
	 * no override is configured.
	 *
	 * @return void
	 */
	public function testRenderTemplateFallsBackToDefault(): void {
		$service = $this->makeService(['templates' => []]);
		$reflection = new \ReflectionMethod($service, 'renderTemplate');
		$reflection->setAccessible(true);

		$rendered = $reflection->invoke($service, 'organization_registration', []);

		// The default templates are non-empty HTML — just assert we got
		// something resembling HTML back, not an exception.
		$this->assertNotSame('', $rendered);
		$this->assertIsString($rendered);

	}//end testRenderTemplateFallsBackToDefault()

	/**
	 * resolveSender returns the configured sender details and transport
	 * type as a tuple.
	 *
	 * @return void
	 */
	public function testResolveSenderReturnsConfiguredTuple(): void {
		$service = $this->makeService(
			[
				'senderEmail' => 'no-reply@example.org',
				'senderName' => 'Catalog Bot',
				'transportType' => 'sendgrid',
			]
		);

		$reflection = new \ReflectionMethod($service, 'resolveSender');
		$reflection->setAccessible(true);

		[$senderEmail, $senderName, $transport] = $reflection->invoke($service);

		$this->assertSame('no-reply@example.org', $senderEmail);
		$this->assertSame('Catalog Bot', $senderName);
		$this->assertSame('sendgrid', $transport);

	}//end testResolveSenderReturnsConfiguredTuple()

	/**
	 * resolveSender falls back to defaults when settings are empty.
	 *
	 * @return void
	 */
	public function testResolveSenderUsesDefaultsWhenSettingsEmpty(): void {
		$service = $this->makeService([]);
		$reflection = new \ReflectionMethod($service, 'resolveSender');
		$reflection->setAccessible(true);

		[$senderEmail, $senderName, $transport] = $reflection->invoke($service);

		$this->assertNotSame('', $senderEmail);
		$this->assertNotSame('', $senderName);
		$this->assertSame('smtp', $transport);

	}//end testResolveSenderUsesDefaultsWhenSettingsEmpty()

	/**
	 * ensureEmailDeliveryReady returns null when the configured
	 * isEmailSystemConfigured() check fails.
	 *
	 * @return void
	 */
	public function testEnsureEmailDeliveryReadySkipsWhenUnconfigured(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getEmailSettings')->willReturn([]);

		$service = new SymfonyEmailService(
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$settings,
		);

		// Override isEmailSystemConfigured via a subclass partial mock would
		// require generator surgery; instead we accept that an unconfigured
		// service (no DSN, no credentials) reports `configured: false` and
		// returns null straight away.
		$ref = new \ReflectionMethod($service, 'ensureEmailDeliveryReady');
		$ref->setAccessible(true);

		$result = $ref->invokeArgs(
			$service,
			['OrganizationRegistrationEmail', 'organizationRegistrationEnabled', ['organizationName' => 'Acme']]
		);

		$this->assertNull($result);

	}//end testEnsureEmailDeliveryReadySkipsWhenUnconfigured()

	/**
	 * ensureEmailDeliveryReady returns null + emits an info log when the
	 * configured per-type "enabled" flag is false.
	 *
	 * @return void
	 */
	public function testEnsureEmailDeliveryReadySkipsWhenTypeDisabled(): void {
		// We mock the call to isEmailSystemConfigured by making the
		// upstream configuration sufficient to pass it. The simplest path:
		// verify that even when isEmailSystemConfigured passes, a false
		// settings flag short-circuits. Since wiring full configuration is
		// expensive, instead invoke the helper with a partial mock that
		// returns `configured: true` directly.
		$service = $this->getMockBuilder(SymfonyEmailService::class)
			->setConstructorArgs([
				$this->createMock(IAppConfig::class),
				$this->createMock(LoggerInterface::class),
				$this->makeSettingsReturning(['organizationRegistrationEnabled' => false]),
			])
			->onlyMethods(['isEmailSystemConfigured'])
			->getMock();
		$service->method('isEmailSystemConfigured')->willReturn([
			'configured' => true,
			'reason' => null,
			'hasCredentials' => true,
			'hasTemplates' => true,
			'transportType' => 'smtp',
		]);

		$ref = new \ReflectionMethod($service, 'ensureEmailDeliveryReady');
		$ref->setAccessible(true);

		$result = $ref->invokeArgs(
			$service,
			['OrganizationRegistrationEmail', 'organizationRegistrationEnabled', ['organizationName' => 'Acme']]
		);

		$this->assertNull($result);

	}//end testEnsureEmailDeliveryReadySkipsWhenTypeDisabled()

	/**
	 * ensureEmailDeliveryReady returns the configStatus when both checks pass.
	 *
	 * @return void
	 */
	public function testEnsureEmailDeliveryReadyPasses(): void {
		$service = $this->getMockBuilder(SymfonyEmailService::class)
			->setConstructorArgs([
				$this->createMock(IAppConfig::class),
				$this->createMock(LoggerInterface::class),
				$this->makeSettingsReturning(['organizationRegistrationEnabled' => true]),
			])
			->onlyMethods(['isEmailSystemConfigured'])
			->getMock();
		$service->method('isEmailSystemConfigured')->willReturn([
			'configured' => true,
			'reason' => null,
			'hasCredentials' => true,
			'hasTemplates' => true,
			'transportType' => 'smtp',
		]);

		$ref = new \ReflectionMethod($service, 'ensureEmailDeliveryReady');
		$ref->setAccessible(true);

		$result = $ref->invokeArgs(
			$service,
			['OrganizationRegistrationEmail', 'organizationRegistrationEnabled', []]
		);

		$this->assertIsArray($result);
		$this->assertSame('smtp', $result['transportType']);

	}//end testEnsureEmailDeliveryReadyPasses()

	/**
	 * Internal helper: build a SettingsService mock returning the supplied
	 * email settings.
	 */
	private function makeSettingsReturning(array $emailSettings): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getEmailSettings')->willReturn($emailSettings);
		return $settings;
	}

}//end class
