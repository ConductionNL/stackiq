<?php

/**
 * ServiceFactories registers each hand-wired service under its own name and
 * builds it from the container it is handed.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service\Registration
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

namespace OCA\Stackiq\Tests\Unit\Service\Registration;

use Closure;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ArchiMateExportService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\ConnectionReportService;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\ContractApprovalService;
use OCA\Stackiq\Service\ContractStatusService;
use OCA\Stackiq\Service\EolMatcherService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\FacetService;
use OCA\Stackiq\Service\Federation\FederationConfig;
use OCA\Stackiq\Service\Federation\FederationMerger;
use OCA\Stackiq\Service\Federation\FederationService;
use OCA\Stackiq\Service\GebruikSyncService;
use OCA\Stackiq\Service\IntakeService;
use OCA\Stackiq\Service\MergeOrganisatieService;
use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\ModuleComplianceService;
use OCA\Stackiq\Service\ModuleRegistrationService;
use OCA\Stackiq\Service\ModuleVersionService;
use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressStore;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\PublicationService;
use OCA\Stackiq\Service\Registration\ServiceFactories;
use OCA\Stackiq\Service\ReviewAggregateService;
use OCA\Stackiq\Service\ReviewService;
use OCA\Stackiq\Service\SbomImportService;
use OCA\Stackiq\Service\SbomParserService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCA\Stackiq\Service\ViewService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Tests for ServiceFactories.
 *
 * Every factory closure is run against a container that hands out one stub
 * per id and records which ids were asked for, so each test sees exactly what
 * a factory pulled from the container and where it put it.
 */
#[CoversClass(ServiceFactories::class)]
#[UsesClass(ArchiMateExportService::class)]
#[UsesClass(ArchiMateImportService::class)]
#[UsesClass(ArchiMateService::class)]
#[UsesClass(ContactpersoonService::class)]
#[UsesClass(ContractApprovalService::class)]
#[UsesClass(ContractStatusService::class)]
#[UsesClass(EolMatcherService::class)]
#[UsesClass(EolSyncService::class)]
#[UsesClass(FacetService::class)]
#[UsesClass(FederationConfig::class)]
#[UsesClass(FederationMerger::class)]
#[UsesClass(FederationService::class)]
#[UsesClass(GebruikSyncService::class)]
#[UsesClass(IntakeService::class)]
#[UsesClass(MergeOrganisatieService::class)]
#[UsesClass(ModerationService::class)]
#[UsesClass(ModuleComplianceService::class)]
#[UsesClass(ModuleRegistrationService::class)]
#[UsesClass(ModuleVersionService::class)]
#[UsesClass(OrganisatieService::class)]
#[UsesClass(OrganizationSyncService::class)]
#[UsesClass(ProgressStore::class)]
#[UsesClass(ProgressTracker::class)]
#[UsesClass(PublicationService::class)]
#[UsesClass(ReviewAggregateService::class)]
#[UsesClass(ReviewService::class)]
#[UsesClass(SbomImportService::class)]
#[UsesClass(SbomParserService::class)]
#[UsesClass(SettingsService::class)]
#[UsesClass(ContactPersonHandler::class)]
#[UsesClass(GroupHandler::class)]
#[UsesClass(HierarchyHandler::class)]
#[UsesClass(OrganizationHandler::class)]
#[UsesClass(StackiqContactSyncService::class)]
#[UsesClass(SymfonyEmailService::class)]
#[UsesClass(ViewService::class)]
class ServiceFactoriesTest extends TestCase {

	/**
	 * The domain services, in the order registerDomainServices() registers them.
	 */
	private const DOMAIN_SERVICES = [
		OrganisatieService::class,
		MergeOrganisatieService::class,
		ContactpersoonService::class,
		SymfonyEmailService::class,
		SettingsService::class,
		OrganizationSyncService::class,
		StackiqContactSyncService::class,
		GebruikSyncService::class,
		ModuleComplianceService::class,
		ModuleRegistrationService::class,
		PublicationService::class,
		IntakeService::class,
		ModerationService::class,
		ReviewService::class,
		ReviewAggregateService::class,
		ModuleVersionService::class,
		SbomParserService::class,
		SbomImportService::class,
		ArchiMateImportService::class,
		ArchiMateExportService::class,
		ArchiMateService::class,
		ViewService::class,
		FacetService::class,
		ProgressTracker::class,
		ContractStatusService::class,
		ContractApprovalService::class,
		FederationConfig::class,
		FederationMerger::class,
		FederationService::class,
		EolMatcherService::class,
		EolSyncService::class,
	];

	/**
	 * The handler services, in the order registerHandlerServices() registers them.
	 */
	private const HANDLER_SERVICES = [
		OrganizationHandler::class,
		ContactPersonHandler::class,
		GroupHandler::class,
		HierarchyHandler::class,
	];

	/**
	 * The ids the container was asked for, in order.
	 *
	 * @var list<string>
	 */
	private array $requested = [];

	/**
	 * The stub handed out per id.
	 *
	 * @var array<string,object>
	 */
	private array $stubs = [];

	/**
	 * Run a registration method and capture what it registered.
	 *
	 * @param string $method The ServiceFactories method to run.
	 *
	 * @return array<string,array{factory:Closure,shared:bool}> Name => registration.
	 */
	private function register(string $method): array {
		$registrations = [];
		$context       = $this->createMock(IRegistrationContext::class);
		$context->expects($this->atLeastOnce())
			->method('registerService')
			->willReturnCallback(
				function (string $name, Closure $factory, bool $shared=true) use (&$registrations): void {
					if (isset($registrations[$name]) === true) {
						throw new RuntimeException('Registered twice: ' . $name);
					}

					$registrations[$name] = [
						'factory' => $factory,
						'shared'  => $shared,
					];
				}
			);

		(new ServiceFactories())->{$method}($context);

		return $registrations;

	}//end register()

	/**
	 * A container that hands out one stub per id and records each request.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$resolve = function (string $id): object {
			$this->requested[] = $id;
			if (isset($this->stubs[$id]) === false) {
				$this->stubs[$id] = $this->createStub($id);
			}

			return $this->stubs[$id];
		};

		return new class ($resolve) implements ContainerInterface {

			/**
			 * The resolver.
			 *
			 * @var Closure
			 */
			private Closure $resolve;

			/**
			 * Constructor.
			 *
			 * @param Closure $resolve The resolver.
			 */
			public function __construct(Closure $resolve) {
				$this->resolve = $resolve;

			}//end __construct()

			/**
			 * Resolve an id.
			 *
			 * @param string $id The id.
			 *
			 * @return mixed
			 */
			public function get(string $id): mixed {
				return ($this->resolve)($id);

			}//end get()

			/**
			 * Every id resolves.
			 *
			 * @param string $id The id.
			 *
			 * @return bool
			 */
			public function has(string $id): bool {
				return true;

			}//end has()
		};

	}//end container()

	/**
	 * The stub the container handed out for an id; fails when it was never asked for.
	 *
	 * @param string $id The id.
	 *
	 * @return object
	 */
	private function stub(string $id): object {
		$this->assertArrayHasKey($id, $this->stubs, $id . ' was never resolved from the container');
		return $this->stubs[$id];

	}//end stub()

	/**
	 * Read a private property of a built service.
	 *
	 * @param object $service  The service.
	 * @param string $property The property name.
	 *
	 * @return mixed
	 */
	private function property(object $service, string $property): mixed {
		return (new ReflectionProperty($service, $property))->getValue($service);

	}//end property()

	/**
	 * Build one registered service from a fresh container.
	 *
	 * @param array<string,array{factory:Closure,shared:bool}> $registrations The registrations.
	 * @param string                                                $name          The service name.
	 * @param ContainerInterface|null                               $container     The container, or a new one.
	 *
	 * @return object
	 */
	private function build(array $registrations, string $name, ?ContainerInterface $container=null): object {
		$this->requested = [];
		return ($registrations[$name]['factory'])($container ?? $this->container());

	}//end build()

	/**
	 * The four handlers are registered in dependency order, all shared.
	 *
	 * @return void
	 */
	public function testHandlerServicesAreRegisteredInOrderAndShared(): void {
		$registrations = $this->register(method: 'registerHandlerServices');

		$this->assertSame(self::HANDLER_SERVICES, array_keys($registrations));
		foreach ($registrations as $name => $registration) {
			$this->assertTrue($registration['shared'], $name . ' must be registered as shared');
		}

	}//end testHandlerServicesAreRegisteredInOrderAndShared()

	/**
	 * Each handler factory builds the class it is registered under.
	 *
	 * @return void
	 */
	public function testEachHandlerFactoryBuildsItsOwnClass(): void {
		$registrations = $this->register(method: 'registerHandlerServices');

		foreach (self::HANDLER_SERVICES as $name) {
			$this->assertInstanceOf($name, $this->build(registrations: $registrations, name: $name));
		}

	}//end testEachHandlerFactoryBuildsItsOwnClass()

	/**
	 * The hierarchy handler gets the two handlers registered before it, by name.
	 *
	 * @return void
	 */
	public function testHierarchyHandlerIsWiredToTheRegisteredHandlers(): void {
		$registrations = $this->register(method: 'registerHandlerServices');
		$handler       = $this->build(registrations: $registrations, name: HierarchyHandler::class);

		$this->assertSame(
			[
				OrganizationHandler::class,
				ContactPersonHandler::class,
				'Psr\Log\LoggerInterface',
				'OCP\IUserManager',
				'OCP\IGroupManager',
			],
			$this->requested
		);
		$this->assertSame($this->stub(id: OrganizationHandler::class), $this->property($handler, '_organizationHandler'));
		$this->assertSame($this->stub(id: ContactPersonHandler::class), $this->property($handler, '_contactPersonHandler'));

	}//end testHierarchyHandlerIsWiredToTheRegisteredHandlers()

	/**
	 * The contact person handler gets the container it was built from and both configs.
	 *
	 * @return void
	 */
	public function testContactPersonHandlerKeepsItsContainerAndBothConfigs(): void {
		$registrations = $this->register(method: 'registerHandlerServices');
		$container     = $this->container();
		$handler       = $this->build(registrations: $registrations, name: ContactPersonHandler::class, container: $container);

		$this->assertSame($container, $this->property($handler, '_container'));
		$this->assertSame($this->stub(id: 'OCP\IAppConfig'), $this->property($handler, '_config'));
		$this->assertSame($this->stub(id: 'OCP\IConfig'), $this->property($handler, 'config'));
		$this->assertSame($this->stub(id: SymfonyEmailService::class), $this->property($handler, '_emailService'));

	}//end testContactPersonHandlerKeepsItsContainerAndBothConfigs()

	/**
	 * The domain services are registered once each, in the fixed domain order, all shared.
	 *
	 * @return void
	 */
	public function testDomainServicesAreRegisteredInDomainOrderAndShared(): void {
		$registrations = $this->register(method: 'registerDomainServices');

		$this->assertSame(self::DOMAIN_SERVICES, array_keys($registrations));
		foreach ($registrations as $name => $registration) {
			$this->assertTrue($registration['shared'], $name . ' must be registered as shared');
		}

	}//end testDomainServicesAreRegisteredInDomainOrderAndShared()

	/**
	 * Each domain factory builds the class it is registered under.
	 *
	 * @return void
	 */
	public function testEachDomainFactoryBuildsItsOwnClass(): void {
		$registrations = $this->register(method: 'registerDomainServices');

		foreach (self::DOMAIN_SERVICES as $name) {
			$this->assertInstanceOf($name, $this->build(registrations: $registrations, name: $name));
		}

	}//end testEachDomainFactoryBuildsItsOwnClass()

	/**
	 * The dependency-free services are built without touching the container.
	 *
	 * @return void
	 */
	public function testDependencyFreeServicesAskTheContainerForNothing(): void {
		$registrations = $this->register(method: 'registerDomainServices');

		foreach ([SbomParserService::class, FederationMerger::class, EolMatcherService::class] as $name) {
			$this->build(registrations: $registrations, name: $name);
			$this->assertSame([], $this->requested, $name . ' must not resolve anything');
		}

	}//end testDependencyFreeServicesAskTheContainerForNothing()

	/**
	 * Federation gets its config, merger and the connection report passed by name.
	 *
	 * Without the explicit argument the constructor default of null would
	 * switch federation reporting off.
	 *
	 * @return void
	 */
	public function testFederationServiceIsWiredWithItsConnectionReport(): void {
		$registrations = $this->register(method: 'registerDomainServices');
		$container     = $this->container();
		$service       = $this->build(registrations: $registrations, name: FederationService::class, container: $container);

		$this->assertSame($container, $this->property($service, 'container'));
		$this->assertSame($this->stub(id: FederationConfig::class), $this->property($service, 'config'));
		$this->assertSame($this->stub(id: FederationMerger::class), $this->property($service, 'merger'));
		$this->assertSame($this->stub(id: SettingsService::class), $this->property($service, 'settingsService'));
		$this->assertNotNull($this->property($service, 'connectionReports'));
		$this->assertSame($this->stub(id: ConnectionReportService::class), $this->property($service, 'connectionReports'));

	}//end testFederationServiceIsWiredWithItsConnectionReport()

	/**
	 * The EOL sync gets the matcher and the connection report passed by name.
	 *
	 * @return void
	 */
	public function testEolSyncServiceIsWiredWithMatcherAndConnectionReport(): void {
		$registrations = $this->register(method: 'registerDomainServices');
		$service       = $this->build(registrations: $registrations, name: EolSyncService::class);

		$this->assertSame($this->stub(id: EolMatcherService::class), $this->property($service, 'matcher'));
		$this->assertSame($this->stub(id: 'OCP\AppFramework\Utility\ITimeFactory'), $this->property($service, 'timeFactory'));
		$this->assertSame($this->stub(id: ConnectionReportService::class), $this->property($service, 'connectionReports'));

	}//end testEolSyncServiceIsWiredWithMatcherAndConnectionReport()

	/**
	 * The organisation sync resolves OpenRegister through the published contract.
	 *
	 * @return void
	 */
	public function testOrganizationSyncUsesTheObjectServiceContract(): void {
		$registrations = $this->register(method: 'registerDomainServices');
		$service       = $this->build(registrations: $registrations, name: OrganizationSyncService::class);

		$this->assertContains(ObjectServiceInterface::class, $this->requested);
		$this->assertNotContains('OCA\OpenRegister\Service\ObjectService', $this->requested);
		$this->assertSame($this->stub(id: ObjectServiceInterface::class), $this->property($service, 'objectService'));
		$this->assertSame($this->stub(id: 'OCA\OpenRegister\Db\OrganisationMapper'), $this->property($service, 'organisationMapper'));
		$this->assertSame($this->stub(id: ContactPersonHandler::class), $this->property($service, 'contactpersonHandler'));

	}//end testOrganizationSyncUsesTheObjectServiceContract()

	/**
	 * The merge service is built on the organisation service and handler registered for it.
	 *
	 * @return void
	 */
	public function testMergeOrganisatieServiceIsWiredToTheOrganisationServices(): void {
		$registrations = $this->register(method: 'registerDomainServices');
		$service       = $this->build(registrations: $registrations, name: MergeOrganisatieService::class);

		$this->assertSame($this->stub(id: OrganisatieService::class), $this->property($service, 'organisationService'));
		$this->assertSame($this->stub(id: OrganizationHandler::class), $this->property($service, 'organizationHandler'));
		$this->assertSame($this->stub(id: ProgressTracker::class), $this->property($service, 'progressTracker'));
		$this->assertSame($this->stub(id: 'OCP\EventDispatcher\IEventDispatcher'), $this->property($service, 'eventDispatcher'));

	}//end testMergeOrganisatieServiceIsWiredToTheOrganisationServices()

	/**
	 * The SBOM import is built on the registered parser and progress tracker.
	 *
	 * @return void
	 */
	public function testSbomImportServiceIsWiredToTheRegisteredParser(): void {
		$registrations = $this->register(method: 'registerDomainServices');
		$service       = $this->build(registrations: $registrations, name: SbomImportService::class);

		$this->assertSame(
			[SettingsService::class, SbomParserService::class, ProgressTracker::class, 'Psr\Log\LoggerInterface'],
			$this->requested
		);
		$this->assertSame($this->stub(id: SbomParserService::class), $this->property($service, 'parser'));
		$this->assertSame($this->stub(id: ProgressTracker::class), $this->property($service, 'progressTracker'));

	}//end testSbomImportServiceIsWiredToTheRegisteredParser()

	/**
	 * Facet search is built on the registered ArchiMate service and query builder.
	 *
	 * @return void
	 */
	public function testFacetServiceIsWiredToArchiMateAndTheQueryBuilder(): void {
		$registrations = $this->register(method: 'registerDomainServices');
		$service       = $this->build(registrations: $registrations, name: FacetService::class);

		$this->assertSame($this->stub(id: ArchiMateService::class), $this->property($service, 'archiMateService'));
		$this->assertSame($this->stub(id: 'OCA\Stackiq\Service\ViewQueryBuilder'), $this->property($service, 'queryBuilder'));
		$this->assertSame($this->stub(id: 'OCA\Stackiq\Service\Settings\CatalogueLocator'), $this->property($service, 'catalogue'));

	}//end testFacetServiceIsWiredToArchiMateAndTheQueryBuilder()
}//end class
