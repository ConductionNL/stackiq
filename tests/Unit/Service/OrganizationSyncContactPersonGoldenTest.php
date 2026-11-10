<?php

/**
 * Golden test for the contact person sync of OrganizationSyncService.
 *
 * Records, per case, every logger, object service, organisation mapper,
 * contact person handler and settings call that createOrUpdateContactPersonObject
 * and updateContactPersonObjectOwner make, plus the stats and the exception
 * that escapes. Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organization-sync/spec.md
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
 * Golden cases for the contact person object sync.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 * @uses   \OCA\Stackiq\Service\OrganisationSlug
 *
 * @spec openspec/specs/organization-sync/spec.md
 */
class OrganizationSyncContactPersonGoldenTest extends TestCase {

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
		return __DIR__ . '/golden/organizationSyncContactPerson.json';

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

		$mapper->method('findBySlug')->willReturnCallback(
			function (string $slug) {
				$this->trace[] = ['mapper.findBySlug', $slug];
				return ($this->plan['slugIndex'][$slug] ?? throw new DoesNotExistException('no slug'));
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
		$service    = $reflection->newInstanceWithoutConstructor();
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
	 * The cases: contact data, organisation uuid and the doubles' answers.
	 *
	 * @return array<string, array{0: array, 1: string, 2: \Closure}>
	 */
	public static function contactCases(): array {
		return [
			'no email'                               => [['voornaam' => 'A'], 'org-1', fn ($t) => []],
			'new contact, active org, user created'  => [
				['e-mailadres' => 'a@x.nl', 'voornaam' => 'A', 'achternaam' => 'B'],
				'org-1',
				fn ($t) => [
					'save'  => [$t->entity(['email' => 'a@x.nl'], 'c-1'), null, null, null],
					'org'   => [self::organisation(active: true)],
					'first' => true,
					'user'  => $t->user('u-1'),
				],
			],
			'existing contact with org and username' => [
				['email' => 'b@x.nl', 'id' => 'c-2'],
				'org-1',
				fn ($t) => ['find' => [$t->entity(['organization' => 'org-9', 'username' => 'bee'], 'c-2')]],
			],
			'uuid fetch fails, create restores org'  => [
				['email' => 'c@x.nl', 'uuid' => 'c-3', 'organization' => 'org-3', 'id' => ''],
				'org-3',
				fn ($t) => [
					'find' => [new \RuntimeException('gone')],
					'save' => [$t->entity(['email' => 'c@x.nl', 'username' => 'cee'], 'c-3b'), null],
				],
			],
			'org entity missing, backup finds none'  => [
				['email' => 'd@x.nl', 'id' => 'c-4'],
				'org-4',
				fn ($t) => [
					'find' => [$t->entity(['organization' => 'org-4'], 'c-4'), null],
					'org'  => [new DoesNotExistException('none')],
				],
			],
			'org entity missing, backup lookup fails' => [
				['email' => 'e@x.nl', 'id' => 'c-5'],
				'org-5',
				fn ($t) => [
					'find'   => [$t->entity(['organization' => 'org-5'], 'c-5')],
					'org'    => [new DoesNotExistException('none')],
					'config' => new \RuntimeException('no config'),
				],
			],
			'org entity missing, backup builds one'  => [
				['email' => 'f@x.nl', 'id' => 'c-6'],
				'org-6',
				fn ($t) => [
					'find' => [
						$t->entity(['organization' => 'org-6'], 'c-6'),
						$t->entity(['naam' => 'Org six'], 'org-6'),
						new \RuntimeException('full fetch fails'),
					],
					'org'  => [new DoesNotExistException('none'), new DoesNotExistException('none')],
				],
			],
			'org inactive'                           => [
				['email' => 'g@x.nl', 'id' => 'c-7'],
				'org-7',
				fn ($t) => [
					'find' => [$t->entity(['organization' => 'org-7'], 'c-7')],
					'org'  => [self::organisation(active: false)],
				],
			],
			'org active is not true'                 => [
				['email' => 'g2@x.nl', 'id' => 'c-7b'],
				'org-7',
				fn ($t) => [
					'find' => [$t->entity(['organization' => 'org-7'], 'c-7b')],
					'org'  => [self::organisation(active: 1)],
				],
			],
			'org active check throws'                => [
				['email' => 'h@x.nl', 'id' => 'c-8'],
				'org-8',
				fn ($t) => [
					'find' => [$t->entity(['organization' => 'org-8'], 'c-8')],
					'org'  => [self::organisation(active: new \RuntimeException('db down'))],
				],
			],
			'org lookup throws generic'              => [
				['email' => 'i@x.nl', 'id' => 'c-9'],
				'org-9',
				fn ($t) => [
					'find' => [$t->entity(['organization' => 'org-9'], 'c-9')],
					'org'  => [new \RuntimeException('mapper broke')],
				],
			],
			'user creation fails'                    => [
				['email' => 'j@x.nl', 'id' => 'c-10'],
				'org-10',
				fn ($t) => [
					'find' => [$t->entity(['organization' => 'org-10'], 'c-10')],
					'org'  => [self::organisation(active: true)],
				],
			],
			'username save fails'                    => [
				['email' => 'k@x.nl', 'id' => 'c-11'],
				'',
				fn ($t) => [
					'find' => [$t->entity(['organisation' => 'org-11b'], 'c-11')],
					'org'  => [self::organisation(active: true)],
					'save' => [new \RuntimeException('save broke'), null],
					'user' => $t->user('u-11'),
				],
			],
			'owner update save fails'                => [
				['email' => 'l@x.nl', 'id' => 'c-12'],
				'org-12',
				fn ($t) => [
					'find' => [$t->entity(['organization' => 'org-12', '@self' => ['owner' => 'old', 'x' => 1]], 'c-12')],
					'org'  => [self::organisation(active: true)],
					'save' => [null, new \RuntimeException('owner save broke')],
					'user' => $t->user('u-12'),
				],
			],
			'create fails'                           => [
				['email' => 'm@x.nl'],
				'org-13',
				fn ($t) => ['save' => [new \RuntimeException('create broke')]],
			],
			'no org uuid and none on contact'        => [
				['email' => 'n@x.nl', 'id' => 'c-14'],
				'',
				fn ($t) => [
					'find' => [$t->entity([], 'c-14')],
					'org'  => [self::organisation(active: true)],
					'user' => $t->user('u-14'),
				],
			],
		];

	}//end contactCases()

	/**
	 * Run one case of createOrUpdateContactPersonObject and compare it with the golden file.
	 *
	 * @param array    $contactData      The contact data.
	 * @param string   $organizationUuid The organisation uuid.
	 * @param \Closure $plan             The doubles' answers.
	 *
	 * @dataProvider contactCases
	 *
	 * @return void
	 */
	public function testContactPerson(array $contactData, string $organizationUuid, \Closure $plan): void {
		$this->plan = array_merge(['find' => [], 'save' => [], 'org' => []], $plan($this));
		$service    = $this->service();
		$stats      = ['contactPersonsProcessed' => 0, 'usersCreated' => 0, 'errors' => []];
		$escaped    = null;
		try {
			$this->callContactPerson(service: $service, contactData: $contactData, organizationUuid: $organizationUuid, stats: $stats);
		} catch (\Throwable $e) {
			$escaped = get_class($e) . ': ' . $e->getMessage();
		}

		$this->check(
			key: 'contact: ' . $this->dataName(),
			value: ['trace' => $this->trace, 'stats' => $stats, 'escaped' => $escaped]
		);

	}//end testContactPerson()

	/**
	 * Event path cases: the doubles' answers, and whether a user account is made.
	 *
	 * @return array<string, array{0: \Closure, 1: bool}>
	 */
	public static function eventCases(): array {
		$missing = fn () => new DoesNotExistException('none');
		return [
			'entity missing, no organisatie object'    => [fn ($t) => ['org' => [$missing()], 'find' => [null]], false],
			'entity missing, backup lookup fails'      => [fn ($t) => ['org' => [$missing()], 'find' => [new \RuntimeException('find failed')]], false],
			'entity missing, slug taken, none created' => [
				fn ($t) => [
					'org'       => [$missing(), $missing()],
					'find'      => [$t->entity(['id' => 'org-e', 'name' => 'Gemeente A'], 'org-e'), null],
					'slugIndex' => ['gemeente-a' => self::organisation(active: true)],
				],
				false,
			],
			'entity inactive'                          => [fn ($t) => ['org' => [self::organisation(active: false)]], false],
			'entity active'                            => [fn ($t) => ['org' => [self::organisation(active: true)]], true],
		];

	}//end eventCases()

	/**
	 * The event path makes a user account only for an existing, active organisation
	 * entity, and an organisation without one ends the event normally.
	 *
	 * @param \Closure $plan    The doubles' answers.
	 * @param bool     $created Whether a user account is made.
	 *
	 * @dataProvider eventCases
	 *
	 * @return void
	 */
	public function testTheEventPathNeedsAnActiveOrganisationEntity(\Closure $plan, bool $created): void {
		$this->plan  = array_merge(['find' => [], 'save' => [], 'org' => []], $plan($this));
		$this->trace = [];
		$stats       = $this->service()->processSpecificContactPerson($this->entity(['organization' => 'org-e', 'email' => 'e@x.nl'], 'c-e'));

		self::assertSame(1, $stats['contactPersonsProcessed']);
		self::assertSame([], $stats['errors']);
		self::assertSame($created, in_array('handler.createUserAccount', array_column($this->trace, 0), true));
		$skipped = array_filter($this->trace, fn (array $call) => is_string($call[1] ?? null) === true && str_contains($call[1], 'Skipping user creation'));
		self::assertSame($created === false, $skipped !== []);
		self::assertNotContains('organisations.create', array_column($this->trace, 0));

	}//end testTheEventPathNeedsAnActiveOrganisationEntity()

	/**
	 * The owner-update cases: object data, user, register, schema and override.
	 *
	 * @return array<string, array{0: array, 1: array, 2: array}>
	 */
	public static function ownerCases(): array {
		return [
			'override set'               => [['organization' => 'org-a', '@self' => ['owner' => 'x']], ['u-1', 'r', 's', 'org-o'], []],
			'organisation from object'   => [['organisation' => 'org-b'], ['u-2', 'r', 's', null], []],
			'organization from object'   => [['organization' => 'org-c'], ['u-3', 'r', 's'], []],
			'empty override falls back'  => [['organization' => 'org-d'], ['u-4', 'r', 's', ''], []],
			'no organisation anywhere'   => [[], ['u-5', 'r', 's', null], []],
			'override fills missing org' => [['organization' => ''], ['u-6', 'r', 's', 'org-f'], []],
			'save fails'                 => [['organization' => 'org-g'], ['u-7', 'r', 's', null], [new \RuntimeException('nope')]],
		];

	}//end ownerCases()

	/**
	 * Run one case of updateContactPersonObjectOwner and compare it with the golden file.
	 *
	 * @param array $object The contact object data.
	 * @param array $args   userUID, register, schema and, when given, the override.
	 * @param array $save   The save answers.
	 *
	 * @dataProvider ownerCases
	 *
	 * @return void
	 */
	public function testOwnerUpdate(array $object, array $args, array $save): void {
		$this->plan = ['find' => [], 'save' => $save, 'org' => []];
		$service    = $this->service();
		$contact    = $this->entity(object: $object, uuid: 'c-owner');
		$escaped    = null;
		try {
			$this->callOwnerUpdate(service: $service, contact: $contact, args: $args);
		} catch (\Throwable $e) {
			$escaped = get_class($e) . ': ' . $e->getMessage();
		}

		$this->check(
			key: 'owner: ' . $this->dataName(),
			value: ['trace' => $this->trace, 'owner' => $contact->getOwner(), 'organisation' => $contact->getOrganisation(), 'escaped' => $escaped]
		);

	}//end testOwnerUpdate()

	/**
	 * Call createOrUpdateContactPersonObject through reflection.
	 *
	 * @param OrganizationSyncService $service          The service.
	 * @param array                   $contactData      The contact data.
	 * @param string                  $organizationUuid The organisation uuid.
	 * @param array                   $stats            The stats.
	 *
	 * @return void
	 */
	private function callContactPerson(OrganizationSyncService $service, array $contactData, string $organizationUuid, array &$stats): void {
		$method = new \ReflectionMethod($service, 'createOrUpdateContactPersonObject');
		$method->setAccessible(true);
		$run = [
			'organizationUuid' => $organizationUuid,
			'register' => 'reg-1',
			'contactSchema' => 'contact-schema',
			'stats' => &$stats,
		];
		$method->invokeArgs($service, [$contactData, $run]);

	}//end callContactPerson()

	/**
	 * Call updateContactPersonObjectOwner through reflection.
	 *
	 * @param OrganizationSyncService $service The service.
	 * @param ObjectEntityInterface   $contact The contact.
	 * @param array                   $args    userUID, register, schema and, when given, the override.
	 *
	 * @return void
	 */
	private function callOwnerUpdate(OrganizationSyncService $service, ObjectEntityInterface $contact, array $args): void {
		$method = new \ReflectionMethod($service, 'updateContactPersonObjectOwner');
		$method->setAccessible(true);
		$owner = [
			'userUID' => $args[0],
			'register' => $args[1],
			'contactSchema' => $args[2],
		];
		if (array_key_exists(3, $args) === true) {
			$owner['organizationUuidOverride'] = $args[3];
		}

		$method->invokeArgs($service, [$contact, $owner]);

	}//end callOwnerUpdate()

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
