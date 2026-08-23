<?php

/**
 * Unit tests for the W22 RegisterResolverService wiring in SettingsService.
 *
 * Covers stackiq-adopt-or-abstractions Phase 2.5 (resolver-injection unit tests).
 * Confirms that getSchemaIdForObjectType / getRegisterIdForObjectType route the
 * generic-fallback path through `RegisterResolverService::resolveSchemaId` /
 * `resolveRegisterId` when the resolver is available, and gracefully degrade to
 * the legacy `IAppConfig::getValueString` read when it is not.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\RegisterResolverService;
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
 * Tests for SettingsService getSchemaIdForObjectType / getRegisterIdForObjectType
 * routing through the RegisterResolverService.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 */
class SettingsServiceResolverWiringTest extends TestCase {

	/** @var IAppConfig|MockObject */
	private IAppConfig|MockObject $config;

	/** @var IRequest|MockObject */
	private IRequest|MockObject $request;

	/** @var ContainerInterface|MockObject */
	private ContainerInterface|MockObject $container;

	/** @var IAppManager|MockObject */
	private IAppManager|MockObject $appManager;

	/** @var LoggerInterface|MockObject */
	private LoggerInterface|MockObject $logger;

	/** @var IGroupManager|MockObject */
	private IGroupManager|MockObject $groupManager;

	/** @var IL10N|MockObject */
	private IL10N|MockObject $l10n;

	/**
	 * Build collaborator mocks shared across cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IAppConfig::class);
		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->l10n = $this->createMock(IL10N::class);

	}//end setUp()

	/**
	 * Build a SettingsService under test wired against the mocks.
	 *
	 * @return SettingsService
	 */
	private function makeService(): SettingsService {
		return new SettingsService(
			$this->config,
			$this->request,
			$this->container,
			$this->appManager,
			$this->logger,
			$this->groupManager,
			$this->l10n,
		);

	}//end makeService()

	/**
	 * Stub voorzieningen + amef config to a known empty shape so the schema/register
	 * lookup falls through to the resolver branch.
	 *
	 * @return void
	 */
	private function expectEmptyKnownConfigs(): void {
		$emptyVoorzieningen = json_encode(
			[
				'register' => null,
				'organisatie_schema' => null,
				'contactpersoon_schema' => null,
				'module_schema' => null,
				'compliancy_schema' => null,
				'moduleVersie_schema' => null,
			]
		);

		$this->config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($emptyVoorzieningen): string {
				if ($key === 'voorzieningen_config') {
					return $emptyVoorzieningen;
				}

				if ($key === 'amef_config') {
					return '{}';
				}

				if ($key === 'amef_organization_schema') {
					return '';
				}

				// Legacy fallback reads — return empty so the resolver branch is the
				// visible signal, unless a test overrides via partial mock.
				return $default;
			}
		);

	}//end expectEmptyKnownConfigs()

	/**
	 * Resolver returns a value → that value is preferred over the legacy
	 * IAppConfig fallback for getSchemaIdForObjectType.
	 *
	 * @return void
	 */
	public function testGetSchemaIdRoutesThroughResolverWhenAvailable(): void {
		$this->expectEmptyKnownConfigs();

		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$resolver = $this->createMock(RegisterResolverService::class);
		$resolver->expects($this->once())
			->method('resolveSchemaId')
			->willReturnCallback(
				function (string $appId, string $configKey, ?string $default = null, ?string $organisationUuid = null): string {
					$this->assertSame('stackiq', $appId);
					$this->assertSame('wijktypeXyz_schema', $configKey);
					return '42';
				}
			);

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($resolver) {
				if ($id === 'OCA\OpenRegister\Service\RegisterResolverService') {
					return $resolver;
				}

				throw new \RuntimeException('unexpected container id: ' . $id);
			}
		);

		$service = $this->makeService();
		$this->assertSame(42, $service->getSchemaIdForObjectType('wijktypeXyz'));

	}//end testGetSchemaIdRoutesThroughResolverWhenAvailable()

	/**
	 * Resolver returns empty → falls back to legacy IAppConfig read.
	 *
	 * @return void
	 */
	public function testGetSchemaIdFallsBackToLegacyConfigWhenResolverEmpty(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$resolver = $this->createMock(RegisterResolverService::class);
		$resolver->method('resolveSchemaId')->willReturn('');

		$this->container->method('get')->willReturn($resolver);

		// Legacy fallback path: getValueString('stackiq', 'legacyOnly_schema', '') returns "99".
		$emptyVoorzieningen = '{}';
		$this->config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($emptyVoorzieningen): string {
				if ($key === 'voorzieningen_config') {
					return $emptyVoorzieningen;
				}

				if ($key === 'amef_config') {
					return '{}';
				}

				if ($key === 'legacyOnly_schema') {
					return '99';
				}

				return $default;
			}
		);

		$service = $this->makeService();
		$this->assertSame(99, $service->getSchemaIdForObjectType('legacyOnly'));

	}//end testGetSchemaIdFallsBackToLegacyConfigWhenResolverEmpty()

	/**
	 * Resolver returns a value → that value is preferred over the legacy
	 * IAppConfig fallback for getRegisterIdForObjectType.
	 *
	 * @return void
	 */
	public function testGetRegisterIdRoutesThroughResolverWhenAvailable(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$resolver = $this->createMock(RegisterResolverService::class);
		$resolver->expects($this->once())
			->method('resolveRegisterId')
			->willReturnCallback(
				function (string $appId, string $configKey, ?string $default = null, ?string $organisationUuid = null): string {
					$this->assertSame('stackiq', $appId);
					$this->assertSame('wijktype_register', $configKey);
					return '7';
				}
			);

		$this->container->method('get')->willReturn($resolver);

		$this->config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($key === 'voorzieningen_config') {
					return '{}';
				}

				if ($key === 'amef_config') {
					return '{}';
				}

				return $default;
			}
		);

		$service = $this->makeService();
		$this->assertSame(7, $service->getRegisterIdForObjectType('wijktype'));

	}//end testGetRegisterIdRoutesThroughResolverWhenAvailable()

	/**
	 * OR not installed → resolver is never consulted, legacy fallback owns the read.
	 *
	 * @return void
	 */
	public function testGetSchemaIdSkipsResolverWhenOpenRegisterAbsent(): void {
		$this->appManager->method('getInstalledApps')->willReturn([]);

		// container->get must NEVER be called when OR is absent.
		$this->container->expects($this->never())->method('get');

		$this->config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($key === 'voorzieningen_config') {
					return '{}';
				}

				if ($key === 'amef_config') {
					return '{}';
				}

				if ($key === 'noOr_schema') {
					return '13';
				}

				return $default;
			}
		);

		$service = $this->makeService();
		$this->assertSame(13, $service->getSchemaIdForObjectType('noOr'));

	}//end testGetSchemaIdSkipsResolverWhenOpenRegisterAbsent()

}//end class
