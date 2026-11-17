<?php
/**
 * Unit tests for the hand-wired background job factories.
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
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\Stackiq\BackgroundJob\ContractStatusJob;
use OCA\Stackiq\BackgroundJob\EolSyncJob;
use OCA\Stackiq\BackgroundJob\FederationSyncJob;
use OCA\Stackiq\BackgroundJob\JobRegistrar;
use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Kernel\Contracts\ContractStatusServiceInterface;
use OCA\Stackiq\Kernel\Contracts\CronjobSettingsInterface;
use OCA\Stackiq\Kernel\Contracts\EolSyncServiceInterface;
use OCA\Stackiq\Kernel\Contracts\FederationConfigInterface;
use OCA\Stackiq\Kernel\Contracts\FederationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\OrganizationSyncServiceInterface;
use OCA\Stackiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Stackiq\Kernel\Contracts\StackiqContactSyncServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for JobRegistrar.
 *
 * @covers \OCA\Stackiq\BackgroundJob\JobRegistrar
 * @uses   \OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob
 * @uses   \OCA\Stackiq\BackgroundJob\ContractStatusJob
 * @uses   \OCA\Stackiq\BackgroundJob\FederationSyncJob
 * @uses   \OCA\Stackiq\BackgroundJob\EolSyncJob
 */
class JobRegistrarTest extends TestCase {

	/**
	 * The factories registered, by service name.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * Services the container was asked for.
	 *
	 * @var list<string>
	 */
	private array $requested = [];

	/**
	 * Register the jobs and keep their factories.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->factories[$name] = $factory;
			}
		);

		(new JobRegistrar())->register($context);
	}//end setUp()

	/**
	 * A container that answers a mock for every service id it is asked for.
	 *
	 * @param array<string, object> $overrides Service id => instance to answer instead.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $overrides = []): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($overrides): object {
				$this->requested[] = $id;
				if (isset($overrides[$id]) === true) {
					return $overrides[$id];
				}

				return $this->createMock($id);
			}
		);

		return $container;
	}//end container()

	/**
	 * Every hand-wired job gets a factory, and only those.
	 *
	 * @return void
	 */
	public function testTheFourHandWiredJobsAreRegistered(): void {
		$this->assertSame(
			[OrganizationContactSyncJob::class, ContractStatusJob::class, FederationSyncJob::class, EolSyncJob::class],
			array_keys($this->factories)
		);
	}//end testTheFourHandWiredJobsAreRegistered()

	/**
	 * The contact sync job is built from its seven collaborators.
	 *
	 * @return void
	 */
	public function testTheContactSyncJobIsBuiltFromItsCollaborators(): void {
		$job = ($this->factories[OrganizationContactSyncJob::class])($this->container());

		$this->assertInstanceOf(OrganizationContactSyncJob::class, $job);
		$this->assertSame(
			[
				ITimeFactory::class,
				OrganizationSyncServiceInterface::class,
				StackiqContactSyncServiceInterface::class,
				SettingsServiceInterface::class,
				CronjobSettingsInterface::class,
				IAppManager::class,
				LoggerInterface::class,
			],
			$this->requested
		);
	}//end testTheContactSyncJobIsBuiltFromItsCollaborators()

	/**
	 * The contract status job runs daily.
	 *
	 * @return void
	 */
	public function testTheContractStatusJobIsBuiltAndRunsDaily(): void {
		$job = ($this->factories[ContractStatusJob::class])($this->container());

		$this->assertInstanceOf(ContractStatusJob::class, $job);
		$this->assertSame(86400, $job->getInterval());
		$this->assertSame(
			[ITimeFactory::class, ContractStatusServiceInterface::class, IAppManager::class, LoggerInterface::class],
			$this->requested
		);
	}//end testTheContractStatusJobIsBuiltAndRunsDaily()

	/**
	 * The federation sync job takes its interval from the federation config.
	 *
	 * @return void
	 */
	public function testTheFederationSyncJobTakesItsIntervalFromTheConfig(): void {
		$config = $this->createMock(FederationConfigInterface::class);
		$config->method('getSyncInterval')->willReturn(7200);

		$job = ($this->factories[FederationSyncJob::class])($this->container([FederationConfigInterface::class => $config]));

		$this->assertInstanceOf(FederationSyncJob::class, $job);
		$this->assertSame(7200, $job->getInterval());
		$this->assertSame(
			[ITimeFactory::class, FederationServiceInterface::class, FederationConfigInterface::class, LoggerInterface::class],
			$this->requested
		);
	}//end testTheFederationSyncJobTakesItsIntervalFromTheConfig()

	/**
	 * The EOL sync job is built with its service, which sets its interval.
	 *
	 * @return void
	 */
	public function testTheEolSyncJobIsBuiltWithItsService(): void {
		$eol = $this->createMock(EolSyncServiceInterface::class);
		$eol->method('getConfig')->willReturn(['intervalSeconds' => 3600]);

		$job = ($this->factories[EolSyncJob::class])($this->container([EolSyncServiceInterface::class => $eol]));

		$this->assertInstanceOf(EolSyncJob::class, $job);
		$this->assertSame(3600, $job->getInterval());
		$this->assertSame([ITimeFactory::class, EolSyncServiceInterface::class, LoggerInterface::class], $this->requested);
	}//end testTheEolSyncJobIsBuiltWithItsService()
}//end class
