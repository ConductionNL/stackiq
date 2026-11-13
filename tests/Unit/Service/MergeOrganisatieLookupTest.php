<?php

/**
 * Characterisation tests for two lookups of MergeOrganisatieService.
 *
 * Pins which organisation an object entity names as its owner, and which
 * Nextcloud group a merge moves members into, for every branch, with every
 * group manager, organisation handler and logger call in order.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\MergeOrganisatieService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Cases for the owning organisation and the target group.
 *
 * @covers \OCA\Stackiq\Service\MergeOrganisatieService
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */
class MergeOrganisatieLookupTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Build the service with the given target organisation and groups.
	 *
	 * @param array|null $target   The target organisation's data, or null when it cannot be found.
	 * @param array      $existing The groups that exist.
	 * @param mixed      $ensured  What ensureOrganizationGroup answers.
	 *
	 * @return MergeOrganisatieService
	 */
	private function service(?array $target, array $existing, mixed $ensured): MergeOrganisatieService {
		$this->trace = [];

		$entity = null;
		if ($target !== null) {
			$entity = new class($target) implements ObjectEntityInterface {
				/**
				 * Build the double.
				 *
				 * @param array $data The organisation data.
				 */
				public function __construct(private array $data) {
				}

				/**
				 * @return array
				 */
				public function getObject(): array {
					return $this->data;
				}

				/**
				 * @return string|null
				 */
				public function getUuid(): ?string {
					return 'org-t';
				}

				/**
				 * @return string|null
				 */
				public function getRegister(): ?string {
					return '100';
				}

				/**
				 * @return string|null
				 */
				public function getSchema(): ?string {
					return '1';
				}

				/**
				 * @return string|null
				 */
				public function getOrganisation(): ?string {
					return null;
				}

				/**
				 * @return string|null
				 */
				public function getOwner(): ?string {
					return null;
				}

				/**
				 * @return array
				 */
				public function jsonSerialize(): array {
					return $this->data;
				}
			};
		}

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function ($id) use ($entity) {
				$this->trace[] = ['find', $id];
				if ($entity === null) {
					throw new \RuntimeException('missing');
				}

				return $entity;
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getVoorzieningenRegisterId')->willReturn(100);
		$settings->method('getSchemaIdForObjectType')->willReturn(1);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			function (string $gid) use ($existing) {
				$this->trace[] = ['groups.get', $gid];
				if (in_array($gid, $existing, true) === true) {
					$group = $this->createMock(originalClassName: IGroup::class);
					$group->method('getGID')->willReturn($gid);
					return $group;
				}

				return null;
			}
		);

		$handler = $this->createMock(originalClassName: OrganizationHandler::class);
		$handler->method('ensureOrganizationGroup')->willReturnCallback(
			function ($organizationObject, array $objectData) use ($ensured) {
				$this->trace[] = ['ensureOrganizationGroup', $objectData];
				return $ensured;
			}
		);

		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		$reflection = new \ReflectionClass(MergeOrganisatieService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		$properties = [
			'container'           => $container,
			'appManager'          => $apps,
			'groupManager'        => $groups,
			'logger'              => $logger,
			'settingsService'     => $settings,
			'organizationHandler' => $handler,
		];
		foreach ($properties as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		return $service;

	}//end service()

	/**
	 * The target group's id, or null.
	 *
	 * @param MergeOrganisatieService $service The service.
	 *
	 * @return string|null
	 */
	private static function targetGroup(MergeOrganisatieService $service): ?string {
		return (new \ReflectionMethod(MergeOrganisatieService::class, 'resolveTargetGroup'))->invoke($service, 'org-t')?->getGID();

	}//end targetGroup()

	/**
	 * The target's stored group is used when it exists; otherwise the handler ensures one.
	 *
	 * @return void
	 */
	public function testTheTargetGroupIsTheStoredOneOrAnEnsuredOne(): void {
		$service = $this->service(target: ['group' => 'g-stored'], existing: ['g-stored'], ensured: 'g-new');
		$this->assertSame('g-stored', self::targetGroup(service: $service));
		$this->assertSame([['find', 'org-t'], ['groups.get', 'g-stored']], $this->trace);

		$service = $this->service(target: ['group' => 'g-gone', 'naam' => 'T'], existing: ['g-new'], ensured: 'g-new');
		$this->assertSame('g-new', self::targetGroup(service: $service));
		$this->assertSame([['find', 'org-t'], ['groups.get', 'g-gone'], ['ensureOrganizationGroup', ['group' => 'g-gone', 'naam' => 'T']], ['groups.get', 'g-new']], $this->trace);

		foreach ([[], ['group' => ''], ['group' => null], ['group' => '0']] as $data) {
			$service = $this->service(target: $data, existing: ['g-new'], ensured: 'g-new');
			$this->assertSame('g-new', self::targetGroup(service: $service), json_encode($data));
			$this->assertSame([['find', 'org-t'], ['ensureOrganizationGroup', $data], ['groups.get', 'g-new']], $this->trace);
		}

	}//end testTheTargetGroupIsTheStoredOneOrAnEnsuredOne()

	/**
	 * No target, no ensured group, or an ensured group that does not exist answers null.
	 *
	 * @return void
	 */
	public function testAnUnresolvableTargetGroupIsNull(): void {
		$service = $this->service(target: null, existing: [], ensured: 'g-new');
		$this->assertNull(self::targetGroup(service: $service));
		$this->assertSame([['find', 'org-t'], ['log', 'debug', 'MergeOrganisatieService: organisation not found', ['uuid' => 'org-t', 'error' => 'missing']]], $this->trace);

		$service = $this->service(target: [], existing: [], ensured: null);
		$this->assertNull(self::targetGroup(service: $service));
		$this->assertSame([['find', 'org-t'], ['ensureOrganizationGroup', []]], $this->trace);

		$service = $this->service(target: [], existing: [], ensured: 'g-missing');
		$this->assertNull(self::targetGroup(service: $service));
		$this->assertSame([['find', 'org-t'], ['ensureOrganizationGroup', []], ['groups.get', 'g-missing']], $this->trace);

	}//end testAnUnresolvableTargetGroupIsNull()

	/**
	 * Only a string owning organisation read through the accessor counts; a failing accessor is logged.
	 *
	 * @return void
	 */
	public function testTheOwningOrganisationIsReadFromTheAccessor(): void {
		$service = $this->service(target: null, existing: [], ensured: null);
		$read    = new \ReflectionMethod(MergeOrganisatieService::class, 'readOwningOrganisation');

		$withAccessor = new class {
			/**
			 * The owner to answer.
			 *
			 * @var mixed
			 */
			public mixed $answer = 'org-1';

			/**
			 * @return mixed
			 */
			public function getOrganisation(): mixed {
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
		$this->assertSame('org-1', $read->invoke($service, $withAccessor));

		$withAccessor->answer = ['uuid' => 'org-1'];
		$this->assertNull($read->invoke($service, $withAccessor));

		$withAccessor->answer = null;
		$this->assertNull($read->invoke($service, $withAccessor));

		$withProperty = new class {
			/**
			 * The owner.
			 *
			 * @var string
			 */
			public string $organisation = 'org-2';

			/**
			 * Magic accessors, as OpenRegister's entities have them.
			 *
			 * @param string $method The method.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args): mixed {
				return $this->organisation;
			}
		};
		$this->assertSame('org-2', $read->invoke($service, $withProperty));

		$this->assertNull($read->invoke($service, new \stdClass()));
		$this->assertSame([], $this->trace);

		$withAccessor->answer = new \RuntimeException('not loaded');
		$this->assertNull($read->invoke($service, $withAccessor));
		$this->assertSame(
			[['log', 'warning', 'MergeOrganisatieService: could not read @self.organisation from object entity', ['exception' => 'not loaded', 'entity' => $withAccessor::class]]],
			$this->trace
		);

	}//end testTheOwningOrganisationIsReadFromTheAccessor()
}//end class
