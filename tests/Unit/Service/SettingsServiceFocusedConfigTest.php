<?php

/**
 * Unit tests for the focused configuration endpoints of SettingsService.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\RegisterService;
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
 * The per-section read and write endpoints of the admin settings page:
 * AMEF (with schema validation against the chosen register), ArchiMate,
 * Voorzieningen and email.
 */
final class SettingsServiceFocusedConfigTest extends TestCase {

	/**
	 * Backing key/value store of the IAppConfig fake.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * AMEF config as the locator holds it.
	 *
	 * @var array
	 */
	private array $amef = [];

	/**
	 * @var CatalogueLocator&MockObject
	 */
	private CatalogueLocator $catalogue;

	/**
	 * @var EmailSettings&MockObject
	 */
	private EmailSettings $emailSettings;

	/**
	 * @var RoleGroupSettings&MockObject
	 */
	private RoleGroupSettings $roleGroups;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Create the shared doubles; the locator keeps the AMEF config in memory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->amef = [];
		$this->catalogue = $this->createMock(CatalogueLocator::class);
		$this->catalogue->method('getAmefConfig')->willReturnCallback(fn (): array => $this->amef);
		$this->catalogue->method('setAmefConfig')->willReturnCallback(
			function (array $config): void {
				$this->amef = $config;
			}
		);
		$this->emailSettings = $this->createMock(EmailSettings::class);
		$this->roleGroups = $this->createMock(RoleGroupSettings::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the service over an in-memory IAppConfig.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default=''): string {
				return $this->store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		return new SettingsService(
			catalogue: $this->catalogue,
			config: $config,
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->roleGroups,
			container: $this->container,
			appManager: $this->createMock(IAppManager::class),
			logger: $this->logger,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->emailSettings
		);

	}//end service()

	/**
	 * Make OpenRegister report the given registers.
	 *
	 * @param array $registers Serialised registers.
	 *
	 * @return void
	 */
	private function registers(array $registers): void {
		$entities = array_map(
			fn (array $data): object => new class($data) implements \JsonSerializable {

				/**
				 * @param array $data The serialised register.
				 */
				public function __construct(private array $data) {
				}

				/**
				 * @return array
				 */
				public function jsonSerialize(): array {
					return $this->data;
				}
			},
			$registers
		);
		$this->catalogue->method('getObjectService')->willReturn($this->createMock(ObjectServiceInterface::class));
		$registerService = $this->createMock(RegisterService::class);
		$registerService->method('findAll')->willReturn($entities);
		$this->catalogue->method('getRegisterService')->willReturn($registerService);

	}//end registers()

	/**
	 * Only schema ids that belong to the chosen register are stored; a
	 * schema of another register and an unknown key are dropped, and every
	 * known key is present afterwards.
	 *
	 * @return void
	 */
	public function testUpdateAmefConfigKeepsOnlySchemasOfTheChosenRegister(): void {
		$this->registers(
			[
				['id' => 1, 'schemas' => [['id' => 10], 11]],
				['id' => 2, 'schemas' => [20]],
			]
		);
		$this->logger->expects($this->once())->method('warning')
			->with('SettingsService: Ignored AMEF config key, invalid schema/register combo', $this->anything());

		$result = $this->service()->updateAmefConfig(
			[
				'_route' => 'settings#amef',
				'register' => 1,
				'element_schema' => 10,
				'view_schema' => '11',
				'model_schema' => 20,
				'colour' => 'red',
			]
		);

		$this->assertTrue($result['success']);
		$this->assertSame('1', $this->amef['register']);
		$this->assertSame('10', $this->amef['element_schema']);
		$this->assertSame('11', $this->amef['view_schema']);
		$this->assertSame('', $this->amef['model_schema'], 'A schema of register 2 is refused for register 1.');
		$this->assertArrayNotHasKey('colour', $this->amef);
		$this->assertArrayNotHasKey('_route', $this->amef);
		$this->assertSame('', $this->amef['property_definition_schema']);
		$this->assertSame($this->amef, $result['config']);

	}//end testUpdateAmefConfigKeepsOnlySchemasOfTheChosenRegister()

	/**
	 * Without a register in the request the stored one is used, and an
	 * existing schema value survives an update that does not mention it.
	 *
	 * @return void
	 */
	public function testUpdateAmefConfigFallsBackToTheStoredRegister(): void {
		$this->amef = ['register' => '2', 'element_schema' => '20'];
		$this->registers([['id' => 2, 'schemas' => [20, 21]]]);

		$this->service()->updateAmefConfig(['view_schema' => '21']);

		$this->assertSame('2', $this->amef['register']);
		$this->assertSame('20', $this->amef['element_schema']);
		$this->assertSame('21', $this->amef['view_schema']);

	}//end testUpdateAmefConfigFallsBackToTheStoredRegister()

	/**
	 * When OpenRegister cannot list registers nothing validates, so no
	 * schema id is stored.
	 *
	 * @return void
	 */
	public function testUpdateAmefConfigStoresNoSchemaWhenRegistersCannotBeListed(): void {
		$this->catalogue->method('getObjectService')->willReturn($this->createMock(ObjectServiceInterface::class));
		$registerService = $this->createMock(RegisterService::class);
		$registerService->method('findAll')->willThrowException(new \Exception('down'));
		$this->catalogue->method('getRegisterService')->willReturn($registerService);

		$result = $this->service()->updateAmefConfig(['register' => '1', 'element_schema' => '10']);

		$this->assertTrue($result['success']);
		$this->assertSame('1', $this->amef['register']);
		$this->assertSame('', $this->amef['element_schema']);

	}//end testUpdateAmefConfigStoresNoSchemaWhenRegistersCannotBeListed()

	/**
	 * A failing write becomes a success:false envelope.
	 *
	 * @return void
	 */
	public function testUpdateAmefConfigReportsAFailedWrite(): void {
		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('getAmefConfig')->willReturn([]);
		$catalogue->method('setAmefConfig')->willThrowException(new \Exception('locked'));
		$this->catalogue = $catalogue;

		$result = $this->service()->updateAmefConfig([]);

		$this->assertSame(['success' => false, 'message' => 'Failed to update AMEF config: locked'], $result);

	}//end testUpdateAmefConfigReportsAFailedWrite()

	/**
	 * The focused getters wrap the locator's config in a success envelope.
	 *
	 * @return void
	 */
	public function testFocusedGettersWrapTheLocatorConfig(): void {
		$this->amef = ['register' => '4'];
		$this->catalogue->method('getVoorzieningenConfig')->willReturn(['register' => '8']);

		$service = $this->service();
		$amef = $service->getAmefConfigFocused();
		$voorzieningen = $service->getVoorzieningenConfigFocused();

		$this->assertTrue($amef['success']);
		$this->assertSame(['register' => '4'], $amef['config']);
		$this->assertTrue($voorzieningen['success']);
		$this->assertSame(['register' => '8'], $voorzieningen['config']);

	}//end testFocusedGettersWrapTheLocatorConfig()

	/**
	 * A locator failure in a focused getter becomes a success:false envelope.
	 *
	 * @return void
	 */
	public function testFocusedGettersReportALocatorFailure(): void {
		$this->catalogue = $this->createMock(CatalogueLocator::class);
		$this->catalogue->method('getAmefConfig')->willThrowException(new \Exception('a'));
		$this->catalogue->method('getVoorzieningenConfig')->willThrowException(new \Exception('v'));

		$service = $this->service();

		$this->assertSame('Failed to get AMEF config: a', $service->getAmefConfigFocused()['message']);
		$this->assertSame('Failed to get Voorzieningen config: v', $service->getVoorzieningenConfigFocused()['message']);
		$this->assertSame('Failed to get ArchiMate config: a', $service->getArchiMateConfig()['message']);

	}//end testFocusedGettersReportALocatorFailure()

	/**
	 * Voorzieningen and ArchiMate updates go through the locator and return
	 * what it holds afterwards; a failure is reported, not thrown.
	 *
	 * @return void
	 */
	public function testVoorzieningenAndArchiMateUpdates(): void {
		$stored = [];
		$this->catalogue->method('setVoorzieningenConfig')->willReturnCallback(
			function (array $config) use (&$stored): void {
				$stored = $config;
			}
		);
		$this->catalogue->method('getVoorzieningenConfig')->willReturnCallback(
			function () use (&$stored): array {
				return $stored;
			}
		);

		$service = $this->service();
		$voorzieningen = $service->updateVoorzieningenConfig(['register' => '3']);
		$archimate = $service->updateArchiMateConfig(['register' => '5']);

		$this->assertSame(['register' => '3'], $voorzieningen['config']);
		$this->assertTrue($archimate['success']);
		$this->assertSame(['register' => '5'], $archimate['config']);

	}//end testVoorzieningenAndArchiMateUpdates()

	/**
	 * A failing Voorzieningen or ArchiMate write is reported with its cause.
	 *
	 * @return void
	 */
	public function testVoorzieningenAndArchiMateUpdateFailures(): void {
		$this->catalogue = $this->createMock(CatalogueLocator::class);
		$this->catalogue->method('setVoorzieningenConfig')->willThrowException(new \Exception('v'));
		$this->catalogue->method('setAmefConfig')->willThrowException(new \Exception('a'));

		$service = $this->service();

		$this->assertSame('Failed to update Voorzieningen config: v', $service->updateVoorzieningenConfig([])['message']);
		$this->assertSame('Failed to update ArchiMate config: a', $service->updateArchiMateConfig([])['message']);

	}//end testVoorzieningenAndArchiMateUpdateFailures()

	/**
	 * getArchiMateConfig pairs the AMEF config with the ArchiMate status
	 * from the ArchiMate service.
	 *
	 * @return void
	 */
	public function testArchiMateConfigCarriesTheServiceStatus(): void {
		$this->amef = ['register' => '6'];
		$archiMate = $this->createMock(\OCA\Stackiq\Service\ArchiMateService::class);
		$archiMate->method('getArchiMateStatus')->willReturn(['import' => ['status' => 'done']]);
		$this->container->method('get')->willReturn($archiMate);

		$result = $this->service()->getArchiMateConfig();

		$this->assertTrue($result['success']);
		$this->assertSame(['register' => '6'], $result['config']);
		$this->assertSame(['import' => ['status' => 'done']], $result['status']);

	}//end testArchiMateConfigCarriesTheServiceStatus()

	/**
	 * The email endpoint returns REDACTED settings: the redaction runs on
	 * what getEmailSettings() returns.
	 *
	 * @return void
	 */
	public function testEmailConfigFocusedReturnsRedactedSettings(): void {
		$this->emailSettings->method('getEmailSettings')->willReturn(['smtpPassword' => 'secret']);
		$this->emailSettings->expects($this->once())->method('redactEmailSecrets')
			->with(['smtpPassword' => 'secret'])
			->willReturn(['smtpPassword' => '***']);
		$this->emailSettings->method('getAllEmailTemplates')->willReturn(['user_creation' => 'Hi']);

		$result = $this->service()->getEmailConfigFocused();

		$this->assertTrue($result['success']);
		$this->assertSame(['smtpPassword' => '***'], $result['emailSettings']);
		$this->assertSame(['user_creation' => 'Hi'], $result['emailTemplates']);

	}//end testEmailConfigFocusedReturnsRedactedSettings()

	/**
	 * A failing email read is reported, not thrown.
	 *
	 * @return void
	 */
	public function testEmailConfigFocusedReportsAFailure(): void {
		$this->emailSettings->method('getEmailSettings')->willThrowException(new \Exception('x'));

		$this->assertSame(
			['success' => false, 'message' => 'Failed to get email config: x'],
			$this->service()->getEmailConfigFocused()
		);

	}//end testEmailConfigFocusedReportsAFailure()

	/**
	 * A refused email update is passed through unchanged; an accepted one
	 * returns the stored email config.
	 *
	 * @return void
	 */
	public function testUpdateEmailConfigPassesARefusalThrough(): void {
		$refusal = ['success' => false, 'message' => 'invalid host'];
		$this->emailSettings->method('updateEmailSettings')->willReturnOnConsecutiveCalls($refusal, ['success' => true]);
		$this->store['email_config'] = '{"enabled":true}';

		$service = $this->service();

		$this->assertSame($refusal, $service->updateEmailConfig(['smtpHost' => '']));
		$accepted = $service->updateEmailConfig(['smtpHost' => 'mail']);
		$this->assertTrue($accepted['success']);
		$this->assertSame(['enabled' => true], $accepted['config']);

	}//end testUpdateEmailConfigPassesARefusalThrough()

	/**
	 * A thrown email update logs only the KEYS of the payload, never the secrets.
	 *
	 * @return void
	 */
	public function testUpdateEmailConfigLogsOnlyTheKeysOnFailure(): void {
		$this->emailSettings->method('updateEmailSettings')->willThrowException(new \Exception('boom'));
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to update email config',
			['exception' => 'boom', 'configKeys' => ['smtpPassword']]
		);

		$result = $this->service()->updateEmailConfig(['smtpPassword' => 'secret']);

		$this->assertSame('Failed to update email config: boom', $result['message']);

	}//end testUpdateEmailConfigLogsOnlyTheKeysOnFailure()

	/**
	 * Without a decodable email_config blob, getEmailConfig falls back to
	 * the individual legacy keys with their defaults.
	 *
	 * @return void
	 */
	public function testGetEmailConfigFallsBackToTheLegacyKeys(): void {
		$this->store['email_config'] = 'broken';
		$this->store['email_enabled'] = 'true';
		$this->store['email_smtp_host'] = 'smtp.example';

		$config = $this->service()->getEmailConfig();

		$this->assertTrue($config['enabled']);
		$this->assertSame('smtp.example', $config['smtp_host']);
		$this->assertSame('587', $config['smtp_port']);
		$this->assertSame('tls', $config['smtp_encryption']);
		$this->assertSame('smtp', $config['transport_type']);

	}//end testGetEmailConfigFallsBackToTheLegacyKeys()

	/**
	 * setEmailConfig clears the locator cache and stores the config as JSON.
	 *
	 * @return void
	 */
	public function testSetEmailConfigStoresJsonAndClearsTheCache(): void {
		$this->catalogue->expects($this->once())->method('clearConfigurationCache');

		$service = $this->service();
		$service->setEmailConfig(['enabled' => false, 'smtp_host' => 'h']);

		$this->assertSame(['enabled' => false, 'smtp_host' => 'h'], $service->getEmailConfig());

	}//end testSetEmailConfigStoresJsonAndClearsTheCache()

	/**
	 * The consolidated configuration bundles every section, the three email
	 * templates and the three role group lists.
	 *
	 * @return void
	 */
	public function testConsolidatedConfigurationBundlesEverySection(): void {
		$this->amef = ['register' => '1'];
		$this->catalogue->method('getVoorzieningenConfig')->willReturn(['register' => '2']);
		$this->emailSettings->method('getEmailTemplate')->willReturnCallback(fn (string $templateName): string => 'tpl:' . $templateName);
		$this->roleGroups->method('getGenericUserGroups')->willReturn(['users']);
		$this->roleGroups->method('getOrganizationAdminGroups')->willReturn(['org-admins']);
		$this->roleGroups->method('getSuperUserGroups')->willReturn(['admin']);
		$archiMate = $this->createMock(\OCA\Stackiq\Service\ArchiMateService::class);
		$archiMate->method('getArchiMateStatus')->willReturn(['import' => []]);
		$this->container->method('get')->willReturn($archiMate);
		$this->store['email_config'] = '{"enabled":true}';

		$result = $this->service()->getConsolidatedConfiguration();

		$this->assertSame(['register' => '2'], $result['voorzieningen']);
		$this->assertSame(['register' => '1'], $result['amef']);
		$this->assertTrue($result['email']['enabled']);
		$this->assertSame('tpl:user_creation', $result['email']['templates']['user_creation']);
		$this->assertSame('tpl:organization_activation', $result['email']['templates']['organization_activation']);
		$this->assertSame(['import' => []], $result['archimate']);
		$this->assertSame(
			['generic' => ['users'], 'organizationAdmin' => ['org-admins'], 'superUser' => ['admin']],
			$result['userGroups']
		);

	}//end testConsolidatedConfigurationBundlesEverySection()
}//end class
