<?php

/**
 * Golden test for the related contact person sync of OrganizationSyncService.
 *
 * Records, per case, every logger, object service, settings and
 * processSpecificContactPerson call that processRelatedContactPersons makes,
 * plus the stats it leaves. Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organization-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/FindCallShape.php';

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCA\Stackiq\Tests\Unit\Support\FindCallShape;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for the related contact person sync.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 * @uses   \OCA\Stackiq\Service\OrganisationSlug
 *
 * @spec openspec/specs/organization-sync/spec.md
 */
class OrganizationSyncRelatedContactsGoldenTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Per-case behaviour of the doubles.
	 *
	 * @var array<string, mixed>
	 */
	private array $plan = [];

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/organizationSyncRelatedContacts.json';

	}//end golden()

	/**
	 * A contact or organisation object double that records its writes.
	 *
	 * @param array  $object The object data.
	 * @param string $uuid   The uuid.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $object, string $uuid): ObjectEntityInterface {
		$trace = &$this->trace;
		return new class($object, $uuid, $trace) implements ObjectEntityInterface {
			/**
			 * The owner set on the entity.
			 *
			 * @var string|null
			 */
			private ?string $owner = null;

			/**
			 * The organisation set on the entity.
			 *
			 * @var string|null
			 */
			private ?string $organisation = 'org-of-entity';

			/**
			 * Build the double.
			 *
			 * @param array  $object The object data.
			 * @param string $uuid   The uuid.
			 * @param array  $trace  The shared trace.
			 */
			public function __construct(private array $object, private string $uuid, private array &$trace) {
			}

			/**
			 * @return mixed
			 */
			public function getId() {
				return 7;
			}

			/**
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return $this->uuid;
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return $this->object;
			}

			/**
			 * @return string|null
			 */
			public function getRegister(): ?string {
				return 'reg-entity';
			}

			/**
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return 'schema-entity';
			}

			/**
			 * @return string|null
			 */
			public function getOrganisation(): ?string {
				return $this->organisation;
			}

			/**
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return $this->owner;
			}

			/**
			 * @param mixed $object The data.
			 *
			 * @return void
			 */
			public function setObject($object=null) {
				$this->trace[] = ['entity.setObject', $this->uuid, $object];
				$this->object  = $object;
			}

			/**
			 * @param string|null $owner The owner.
			 *
			 * @return void
			 */
			public function setOwner(?string $owner): void {
				$this->trace[] = ['entity.setOwner', $this->uuid, $owner];
				$this->owner   = $owner;
			}

			/**
			 * @param string|null $organisation The organisation.
			 *
			 * @return void
			 */
			public function setOrganisation(?string $organisation): void {
				$this->trace[]      = ['entity.setOrganisation', $this->uuid, $organisation];
				$this->organisation = $organisation;
			}

			/**
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->object;
			}

			/**
			 * @param string $name The method.
			 * @param array  $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args) {
				$this->trace[] = ['entity.' . $name, $this->uuid, $args];
				return null;
			}
		};

	}//end entity()

	/**
	 * Arguments by parameter name, leaving out the ones left at their default.
	 *
	 * @param string $method The ObjectServiceInterface method.
	 * @param array  $args   The positional arguments the double received.
	 *
	 * @return array<string, mixed>
	 */
	private static function named(string $method, array $args): array {
		if ($method === 'find') {
			return FindCallShape::named(args: $args);
		}

		$named = [];
		foreach ((new \ReflectionMethod(ObjectServiceInterface::class, $method))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
				continue;
			}

			$value = $args[$i];
			if ($value instanceof ObjectEntityInterface) {
				$value = 'entity:' . $value->getUuid();
			}

			$named[$param->getName()] = $value;
		}

		return $named;

	}//end named()

	/**
	 * Build the service with recording doubles driven by $this->plan.
	 *
	 * @return OrganizationSyncService
	 */
	private function service(): OrganizationSyncService {
		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				foreach (['trace', 'file', 'line'] as $volatile) {
					if (array_key_exists($volatile, $context) === true) {
						$context[$volatile] = '(' . $volatile . ')';
					}
				}

				$this->trace[] = ['log.' . $level, (string) $message, $context];
			}
		};

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (...$args) {
				$this->trace[] = ['objects.find', self::named(method: 'find', args: $args)];
				$answer        = array_shift($this->plan['find']);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$objects->method('searchObjects')->willReturnCallback(
			function (...$args) {
				$this->trace[] = ['objects.searchObjects', self::named(method: 'searchObjects', args: $args)];
				$answer        = array_shift($this->plan['search']);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) {
				$this->trace[] = ['objects.saveObject', self::named(method: 'saveObject', args: $args)];
				$answer        = array_shift($this->plan['save']);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return ($answer ?? $this->entity(object: ['saved' => true], uuid: 'saved-uuid'));
			}
		);

		$mapper = $this->createMock(originalClassName: OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid) {
				$this->trace[] = ['mapper.findByUuid', $uuid];
				$answer        = array_shift($this->plan['org']);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		$handler = $this->createMock(originalClassName: ContactPersonHandler::class);
		$handler->method('isFirstContactForOrganization')->willReturnCallback(
			function ($contact, $data) {
				$this->trace[] = ['handler.isFirst', $contact->getUuid(), $data];
				return $this->plan['first'] ?? false;
			}
		);
		$handler->method('createUserAccount')->willReturnCallback(
			function ($contact, $isFirst) {
				$this->trace[] = ['handler.createUserAccount', $contact->getUuid(), $isFirst];
				return ($this->plan['user'] ?? null);
			}
		);
		$handler->method('addUserToOrganizationEntity')->willReturnCallback(
			function (...$args) {
				$args[0]       = $args[0]->getUuid();
				$this->trace[] = ['handler.addUserToOrganizationEntity', $args];
				return true;
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function () {
				$this->trace[] = ['settings.getVoorzieningenConfig'];
				$answer        = ($this->plan['config'] ?? ['register' => 'reg-v', 'organisatie_schema' => 'org-schema']);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		// Built without the constructor: it type-hints IDBConnection, whose
		// mock needs Doctrine, and the methods under test never touch it.
		$reflection = new \ReflectionClass(OrganizationSyncService::class);
		$service    = $this->getMockBuilder(OrganizationSyncService::class)
			->disableOriginalConstructor()
			->onlyMethods(['processSpecificContactPerson'])
			->getMock();
		$service->method('processSpecificContactPerson')->willReturnCallback(
			function ($contactObject) {
				$this->trace[] = ['service.processSpecificContactPerson', $contactObject->getUuid(), $contactObject->getObject()];
				$answer        = array_shift($this->plan['process']);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return ($answer ?? ['contactPersonsProcessed' => 1, 'usersCreated' => 0, 'usersUpdated' => 1, 'errors' => []]);
			}
		);
		$properties = [
			'logger'               => $logger,
			'settingsService'      => $settings,
			'contactpersonHandler' => $handler,
			'objectService'        => $objects,
			'organisationMapper'   => $mapper,
			'organisationService'  => $this->organisationService(),
			'emailService'         => $this->createMock(originalClassName: SymfonyEmailService::class),
		];
		foreach ($properties as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		return $service;

	}//end service()

	/**
	 * An organisation service double that records the organisations it creates.
	 *
	 * @return OrganisatieService
	 */
	private function organisationService(): OrganisatieService {
		$service = $this->createMock(originalClassName: OrganisatieService::class);
		$service->method('createOrganisationInOpenRegister')->willReturnCallback(
			function (array $data) {
				$this->trace[] = ['organisations.create', $data];
				return self::organisation(active: true);
			}
		);
		return $service;

	}//end organisationService()

	/**
	 * An organisation entity double.
	 *
	 * @param mixed $active What getActive() answers, or a Throwable it throws.
	 *
	 * @return Organisation
	 */
	private static function organisation(mixed $active): Organisation {
		return new class($active) extends Organisation {
			/**
			 * @param mixed $active The answer.
			 */
			public function __construct(private mixed $active) {
			}

			/**
			 * @return mixed
			 */
			public function getActive(): mixed {
				if ($this->active instanceof \Throwable) {
					throw $this->active;
				}

				return $this->active;
			}

			/**
			 * @return string
			 */
			public function getName(): string {
				return 'Org';
			}

			/**
			 * @param string $name The method.
			 * @param array  $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args) {
				return null;
			}
		};

	}//end organisation()

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;

	}//end user()

	/**
	 * The cases: organisation uuid and the doubles' answers.
	 *
	 * @return array<string, array{0: string, 1: \Closure}>
	 */
	public static function relatedCases(): array {
		$config = ['register' => '5', 'contactpersoon_schema' => '9', 'organisatie_schema' => '7'];
		return [
			'no contact schema'                     => ['org-1', fn ($t) => ['config' => ['register' => '5']]],
			'no register'                           => ['org-1', fn ($t) => ['config' => ['contactpersoon_schema' => '9']]],
			'found by organization field'           => [
				'org-1',
				fn ($t) => [
					'config'  => $config,
					'search'  => [[
						$t->entity(['email' => 'a@x.nl', 'organization' => 'org-1'], 'c-a'),
						$t->entity(['e-mailadres' => 'b@x.nl'], 'c-b'),
						$t->entity(['voornaam' => 'C', 'organization' => 'org-1'], 'c-c'),
					],
					],
					'find'    => [$t->entity(['email' => 'c@x.nl', 'organization' => 'org-1'], 'c-c')],
					'process' => [null, ['contactPersonsProcessed' => 1, 'usersCreated' => 1, 'usersUpdated' => 0, 'errors' => ['user b failed']], []],
				],
			],
			'found by organisation field'           => [
				'org-2',
				fn ($t) => [
					'config' => $config,
					'search' => [[], [$t->entity(['email' => 'd@x.nl', 'organization' => 'org-2'], 'c-d')]],
				],
			],
			'fallback to the contactpersonen array' => [
				'org-3',
				fn ($t) => [
					'config' => $config,
					'search' => [[], []],
					'find'   => [
						$t->entity(['contactpersonen' => ['c-e', '', 42, 'c-gone', 'c-null', 'c-f']], 'org-3'),
						$t->entity(['email' => 'e@x.nl'], 'c-e'),
						new \RuntimeException('contact gone'),
						null,
						$t->entity(['email' => 'f@x.nl', 'organization' => 'org-other'], 'c-f'),
					],
				],
			],
			'fallback org has no contacts'          => [
				'org-4',
				fn ($t) => [
					'config' => $config,
					'search' => [[], []],
					'find'   => [$t->entity(['naam' => 'Org 4'], 'org-4')],
				],
			],
			'fallback org missing'                  => [
				'org-5',
				fn ($t) => [
					'config' => $config,
					'search' => [[], []],
					'find'   => [null],
				],
			],
			'fallback org lookup throws'            => [
				'org-6',
				fn ($t) => [
					'config' => $config,
					'search' => [[], []],
					'find'   => [new \RuntimeException('org gone')],
				],
			],
			'refetch throws, process throws'        => [
				'org-7',
				fn ($t) => [
					'config'  => $config,
					'search'  => [[$t->entity(['organization' => 'org-7'], 'c-g'), $t->entity(['email' => 'h@x.nl'], 'c-h')]],
					'find'    => [new \RuntimeException('refetch failed')],
					'process' => [new \RuntimeException('process failed'), null],
				],
			],
			'refetch returns null'                  => [
				'org-8',
				fn ($t) => [
					'config' => $config,
					'search' => [[$t->entity(['organization' => 'org-8'], 'c-i')]],
					'find'   => [null],
				],
			],
			'save of the organisation field throws' => [
				'org-9',
				fn ($t) => [
					'config' => $config,
					'search' => [[$t->entity(['email' => 'j@x.nl'], 'c-j')]],
					'save'   => [new \RuntimeException('save failed')],
				],
			],
			'search throws'                         => [
				'org-10',
				fn ($t) => [
					'config' => $config,
					'search' => [new \RuntimeException('search failed')],
				],
			],
			'settings throw'                        => ['org-11', fn ($t) => ['config' => new \RuntimeException('settings down')]],
		];

	}//end relatedCases()

	/**
	 * Each related contact case matches its recording.
	 *
	 * @param string   $organizationUuid The organisation uuid.
	 * @param \Closure $plan             Builds the doubles' answers.
	 *
	 * @return void
	 *
	 * @dataProvider relatedCases
	 */
	public function testRelatedContacts(string $organizationUuid, \Closure $plan): void {
		$this->trace = [];
		$this->plan  = array_merge(['find' => [], 'save' => [], 'org' => [], 'search' => [], 'process' => []], $plan($this));
		$service     = $this->service();
		$stats       = ['contactPersonsProcessed' => 0, 'usersCreated' => 0, 'usersUpdated' => 0, 'errors' => ['earlier error']];
		$this->callRelated(service: $service, organizationUuid: $organizationUuid, stats: $stats);
		$this->check(key: 'related: ' . $this->dataName(), value: ['stats' => $stats, 'trace' => $this->trace]);

	}//end testRelatedContacts()

	/**
	 * Call processRelatedContactPersons with the organisation uuid and the stats.
	 *
	 * @param OrganizationSyncService $service          The service.
	 * @param string                  $organizationUuid The organisation uuid.
	 * @param array                   $stats            The stats, updated in place.
	 *
	 * @return void
	 */
	private function callRelated(OrganizationSyncService $service, string $organizationUuid, array &$stats): void {
		$method = new \ReflectionMethod(OrganizationSyncService::class, 'processRelatedContactPersons');
		$args   = [$organizationUuid];
		if ($method->getNumberOfParameters() === 3) {
			$args[] = $this->entity(object: ['naam' => 'Org'], uuid: $organizationUuid);
		}

		$args[] = &$stats;
		$method->invokeArgs($service, $args);

	}//end callRelated()

	/**
	 * Compare a case with the golden file, or record it.
	 *
	 * @param string $key   The case.
	 * @param array  $value What the case observed.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$json  = json_decode(json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		$file  = self::golden();
		$saved = [];
		if (file_exists($file) === true) {
			$saved = json_decode((string) file_get_contents($file), true);
		}

		if (getenv('RECORD_GOLDEN') !== false) {
			$saved[$key] = $json;
			if (is_dir(dirname($file)) === false) {
				mkdir(dirname($file), 0777, true);
			}

			file_put_contents($file, json_encode($saved, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: $json);

	}//end check()

}//end class
