<?php

/**
 * Golden test for the organisation entity sync of OrganizationSyncService.
 *
 * Records, per case, every logger, object service, organisation mapper,
 * organisation entity, e-mail and settings call that ensureOrganisationEntityPublic
 * makes, plus the stats and the entity it returns. Recorded with RECORD_GOLDEN=1.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

if (class_exists('OCA\\OpenRegister\\Exception\\OrganisationSlugConflictException') === false) {
	require_once __DIR__ . '/../../Stubs/OpenRegister/Exception/OrganisationSlugConflictException.php';
}
use Psr\Log\AbstractLogger;

/**
 * Golden cases for the organisation entity sync.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 * @uses   \OCA\Stackiq\Service\OrganisationSlug
 *
 * @spec openspec/specs/organization-sync/spec.md
 */
class OrganizationSyncEntityGoldenTest extends TestCase {

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
		return __DIR__ . '/golden/organizationSyncEntity.json';

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
			 * When the object was created.
			 *
			 * @var \DateTimeInterface|null
			 */
			public ?\DateTimeInterface $createdAt = null;

			/**
			 * Build the double.
			 *
			 * @param array  $object The object data.
			 * @param string $uuid   The uuid.
			 * @param array  $trace  The shared trace.
			 */
			public function __construct(private array $object, private string $uuid, private array &$trace) {
				$this->createdAt = new \DateTimeImmutable('2020-06-01 10:00:00 UTC');
			}

			/**
			 * @return \DateTimeInterface|null
			 */
			public function getCreated(): ?\DateTimeInterface {
				return $this->createdAt;
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
		$mapper->method('findBySlug')->willReturnCallback(
			function (string $slug) {
				$this->trace[] = ['mapper.findBySlug', $slug];
				if (array_key_exists('slugIndex', $this->plan) === true) {
					return $this->plan['slugIndex'][$slug] ?? throw new \OCP\AppFramework\Db\DoesNotExistException('no slug');
				}

				$answer        = array_shift($this->plan['slug']);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$mapper->method('save')->willReturnCallback(
			function ($entity) {
				$this->trace[] = ['mapper.save', $entity->getUuid(), $entity->getActive()];
				return $entity;
			}
		);
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
		$service    = $reflection->newInstanceWithoutConstructor();
		$properties = [
			'logger'               => $logger,
			'settingsService'      => $settings,
			'contactpersonHandler' => $handler,
			'objectService'        => $objects,
			'organisationMapper'   => $mapper,
			'organisationService'  => $this->organisationService(),
			'emailService'         => $this->emailService(),
			'container'            => $this->container(),
			'config'               => $this->appConfig(),
		];
		foreach ($properties as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		return $service;

	}//end service()

	/**
	 * An app config double whose stored values are the plan's `appConfig`.
	 *
	 * @return \OCP\IAppConfig
	 */
	private function appConfig(): \OCP\IAppConfig {
		$config = $this->createMock(originalClassName: \OCP\IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default='', bool $lazy=false) {
				$this->trace[] = ['config.get', $app, $key, $lazy];
				return ($this->plan['appConfig'][$app . '/' . $key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy=false) {
				$this->trace[] = ['config.set', $app, $key, $lazy];
				return true;
			}
		);
		return $config;

	}//end appConfig()

	/**
	 * A container that answers OpenRegister's OrganisationService, whose default
	 * organisation is the plan's `default` (o-default when not set).
	 *
	 * @return \Psr\Container\ContainerInterface
	 */
	private function container(): \Psr\Container\ContainerInterface {
		$organisations = $this->createMock(originalClassName: \OCA\OpenRegister\Service\OrganisationService::class);
		$organisations->method('getDefaultOrganisationUuid')->willReturnCallback(
			function () {
				$this->trace[] = ['organisations.getDefaultOrganisationUuid'];
				return array_key_exists('default', $this->plan) === true ? $this->plan['default'] : 'o-default';
			}
		);
		$container = $this->createMock(originalClassName: \Psr\Container\ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\OrganisationService')->willReturn($organisations);
		return $container;

	}//end container()

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
				if (($this->plan['created'] ?? null) instanceof \Throwable) {
					throw $this->plan['created'];
				}

				return array_key_exists('created', $this->plan) === true ? $this->plan['created'] : $this->orgEntity(uuid: 'new-entity', active: true);
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
	 * An e-mail service double that records the mails and answers per plan.
	 *
	 * @return SymfonyEmailService
	 */
	private function emailService(): SymfonyEmailService {
		$mail = $this->createMock(originalClassName: SymfonyEmailService::class);
		foreach (['sendOrganizationRegistrationEmail', 'sendOrganizationActivationEmail'] as $method) {
			$mail->method($method)->willReturnCallback(
				function (array $data) use ($method) {
					$this->trace[] = ['mail.' . $method, $data['name'] ?? null];
					$answer        = ($this->plan['mail'] ?? true);
					if ($answer instanceof \Throwable) {
						throw $answer;
					}

					return $answer;
				}
			);
		}

		return $mail;

	}//end emailService()

	/**
	 * An organisation entity double that records its writes.
	 *
	 * @param string $uuid   The entity uuid.
	 * @param bool   $active Whether it is active.
	 *
	 * @return Organisation
	 */
	public function orgEntity(string $uuid, bool $active): Organisation {
		$trace = &$this->trace;
		return new class($uuid, $active, $trace) extends Organisation {
			/**
			 * When the organisation was created.
			 *
			 * @var \DateTimeInterface|null
			 */
			public ?\DateTimeInterface $createdAt = null;

			/**
			 * @param string $entityUuid The uuid.
			 * @param bool   $isActive   Whether it is active.
			 * @param array  $trace      The shared trace.
			 */
			public function __construct(private string $entityUuid, private bool $isActive, private array &$trace) {
				$this->createdAt = new \DateTimeImmutable('2020-06-01 10:00:05 UTC');
			}

			/**
			 * @return \DateTimeInterface|null
			 */
			public function getCreated(): ?\DateTimeInterface {
				return $this->createdAt;
			}

			/**
			 * @return int|null
			 */
			public function getId(): ?int {
				return 41;
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return $this->entityUuid;
			}

			/**
			 * @param string $uuid The uuid.
			 *
			 * @return void
			 */
			public function setUuid(string $uuid): void {
				$this->trace[]    = ['entity.setUuid', $this->entityUuid, $uuid];
				$this->entityUuid = $uuid;
			}

			/**
			 * @return bool
			 */
			public function getActive(): bool {
				return $this->isActive;
			}

			/**
			 * @param bool $active Whether it is active.
			 *
			 * @return void
			 */
			public function setActive(bool $active): void {
				$this->trace[]  = ['entity.setActive', $this->entityUuid, $active];
				$this->isActive = $active;
			}

			/**
			 * @return string
			 */
			public function getName(): string {
				return 'Entity ' . $this->entityUuid;
			}
		};

	}//end orgEntity()

	/**
	 * The cases: the organisatie object, the e-mail flag and the doubles' answers.
	 *
	 * @return array<string, array{0: array, 1: bool, 2: \Closure}>
	 */
	public static function entityCases(): array {
		$missing = fn () => new \OCP\AppFramework\Db\DoesNotExistException('missing');
		return [
			'full fetch fails, entity in sync'          => [['name' => 'Gemeente A', 'status' => 'actief'], true, fn ($t) => ['find' => [new \RuntimeException('find failed')], 'org' => [$t->orgEntity('o-1', true)]]],
			'full object replaces the given one'        => [['name' => 'A'], true, fn ($t) => ['find' => [$t->entity(['name' => 'Gemeente B', 'status' => 'Active', '@self' => ['owner' => 'o-1'], 'organisation' => 'o-1'], 'o-1')], 'org' => [$t->orgEntity('o-1', true)]]],
			'existing entity deactivated'               => [['name' => 'Gemeente C', 'status' => 'inactief'], true, fn ($t) => ['org' => [$t->orgEntity('o-1', true)]]],
			'existing entity activated, mail sent'      => [['name' => 'Gemeente D'], true, fn ($t) => ['org' => [$t->orgEntity('o-1', false)]]],
			'existing entity activated, mail not sent'  => [['name' => 'Gemeente E', 'status' => 'ACTIVE'], true, fn ($t) => ['org' => [$t->orgEntity('o-1', false)], 'mail' => false]],
			'existing entity activated, mail throws'    => [['name' => 'Gemeente E2'], true, fn ($t) => ['org' => [$t->orgEntity('o-1', false)], 'mail' => new \RuntimeException('smtp down')]],
			'existing entity activated, no mails'       => [['name' => 'Gemeente F'], false, fn ($t) => ['org' => [$t->orgEntity('o-1', false)]]],
			'adopted by slug'                           => [['name' => 'Gemeente  G & Zn!', 'status' => 'actief'], true, fn ($t) => ['org' => [$missing()], 'slug' => [$t->orgEntity('o-old', true)]]],
			'not found, created with mail'              => [['name' => 'Gemeente H'], true, fn ($t) => ['org' => [$missing()], 'slug' => [$missing()]]],
			'not found, created, mail not sent'         => [['name' => 'Gemeente H2'], true, fn ($t) => ['org' => [$missing()], 'slug' => [$missing()], 'mail' => false]],
			'not found, created without mail'           => [['name' => 'Gemeente I'], false, fn ($t) => ['org' => [$missing()], 'slug' => [$missing()]]],
			'no name, created'                          => [['status' => 'actief'], true, fn ($t) => ['org' => [$missing()], 'slug' => [$missing()]]],
			'creation fails'                            => [['name' => 'Gemeente J'], true, fn ($t) => ['org' => [$missing()], 'slug' => [$missing()], 'created' => null]],
			'mapper fails'                              => [['name' => 'Gemeente K'], true, fn ($t) => ['org' => [new \RuntimeException('db down')]]],
			'owner save fails'                          => [['name' => 'Gemeente L'], true, fn ($t) => ['org' => [$t->orgEntity('o-1', true)], 'save' => [new \RuntimeException('save failed')]]],
			'settings fail'                             => [['name' => 'Gemeente M'], true, fn ($t) => ['config' => new \RuntimeException('settings down')]],
			'same name as another organisation'         => [['name' => 'Gemeente A', 'status' => 'actief'], true, fn ($t) => ['org' => [$missing()], 'slugIndex' => ['gemeente-a' => $t->orgEntity('o-other', true)]]],
			'long name, slug cut at 100'                => [['name' => 'Gemeente aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'status' => 'actief'], true, fn ($t) => ['org' => [$missing()], 'slugIndex' => ['gemeente-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => $t->orgEntity('o-other', true)]]],
			'long name, slug free'                      => [['name' => 'Gemeente aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'status' => 'actief'], true, fn ($t) => ['org' => [$missing()], 'slugIndex' => []]],
			'OpenRegister refuses the slug'             => [['name' => 'Gemeente P', 'status' => 'actief'], true, fn ($t) => ['org' => [$missing()], 'slugIndex' => [], 'created' => new \OCA\OpenRegister\Exception\OrganisationSlugConflictException(slug: 'gemeente-p')]],
			'named Unknown'                             => [['name' => 'Unknown', 'status' => 'actief'], true, fn ($t) => ['org' => [$missing()], 'slugIndex' => ['unknown' => $t->orgEntity('o-other', true)]]],
		];

	}//end entityCases()

	/**
	 * Each organisation entity case matches its recording.
	 *
	 * @param array    $object     The organisatie object data.
	 * @param bool     $sendEmails Whether e-mails are sent.
	 * @param \Closure $plan       Builds the doubles' answers.
	 *
	 * @return void
	 *
	 * @dataProvider entityCases
	 */
	public function testEnsureEntity(array $object, bool $sendEmails, \Closure $plan): void {
		$this->trace = [];
		$this->plan  = array_merge(['find' => [null], 'save' => [], 'org' => [], 'slug' => []], $plan($this));
		$service     = $this->service();
		$stats       = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
		$entity      = $service->ensureOrganisationEntityPublic($this->entity(object: $object, uuid: 'o-1'), $stats, $sendEmails);
		$returned    = null;
		if ($entity !== null) {
			$returned = [$entity->getUuid(), $entity->getActive()];
		}

		$this->check(key: 'entity: ' . $this->dataName(), value: ['returned' => $returned, 'stats' => $stats, 'trace' => $this->trace]);

	}//end testEnsureEntity()

	/**
	 * A registration named like an existing organisation neither takes over that
	 * organisation (its uuid, its owner link) nor creates a second one.
	 *
	 * @return void
	 */
	public function testASameNamedRegistrationDoesNotCaptureTheExistingOrganisation(): void {
		foreach (['Gemeente A' => 'gemeente-a', 'Gemeente ' . str_repeat('a', 120) => substr('gemeente-' . str_repeat('a', 120), 0, 100)] as $name => $slug) {
			$this->trace = [];
			$this->plan  = [
				'find'      => [null],
				'save'      => [],
				'org'       => [new \OCP\AppFramework\Db\DoesNotExistException('missing')],
				'slugIndex' => [$slug => $this->orgEntity(uuid: 'o-other', active: true)],
			];
			$stats  = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
			$entity = $this->service()->ensureOrganisationEntityPublic($this->entity(object: ['name' => $name, 'status' => 'actief'], uuid: 'o-1'), $stats, true);

			$writes = array_values(array_filter($this->trace, fn (array $call) => in_array($call[0], ['entity.setUuid', 'mapper.save', 'organisations.create', 'objects.saveObject', 'entity.setOwner', 'entity.setOrganisation'], true)));
			self::assertNull($entity, $name);
			self::assertSame([], $writes, $name);
			self::assertSame(['o-1'], $stats['slugConflicts'] ?? null, $name);
		}
	}//end testASameNamedRegistrationDoesNotCaptureTheExistingOrganisation()

	/**
	 * A registration with an empty, "Unknown" or "0" name reaches OpenRegister as
	 * "Organisation <first 8 characters of its id>". When another organisation
	 * already has that name's slug, nothing is created or taken over.
	 *
	 * @return void
	 */
	public function testAFallbackNamedRegistrationDoesNotCaptureTheExistingOrganisation(): void {
		$id = 'abcd1234-5678-4abc-8def-0123456789ab';
		foreach (['no name' => [], 'empty' => ['name' => ''], 'Unknown' => ['name' => 'Unknown'], 'zero' => ['name' => '0']] as $case => $name) {
			$this->trace = [];
			$this->plan  = [
				'find'      => [null],
				'save'      => [],
				'org'       => [new \OCP\AppFramework\Db\DoesNotExistException('missing')],
				'slugIndex' => ['organisation-abcd1234' => $this->orgEntity(uuid: 'o-other', active: true)],
			];
			$stats  = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
			$object = array_merge(['id' => $id, 'status' => 'actief'], $name);
			$entity = $this->service()->ensureOrganisationEntityPublic($this->entity(object: $object, uuid: 'o-1'), $stats, true);

			$writes = array_values(array_filter($this->trace, fn (array $call) => in_array($call[0], ['entity.setUuid', 'mapper.save', 'organisations.create', 'objects.saveObject', 'entity.setOwner', 'entity.setOrganisation'], true)));
			self::assertNull($entity, $case);
			self::assertSame([], $writes, $case);
			self::assertSame(['o-1'], $stats['slugConflicts'] ?? null, $case);
		}
	}//end testAFallbackNamedRegistrationDoesNotCaptureTheExistingOrganisation()

	/**
	 * A fallback name whose slug is free is created, with the data as given.
	 *
	 * @return void
	 */
	public function testAFallbackNameWithAFreeSlugIsCreated(): void {
		$this->trace = [];
		$this->plan  = [
			'find'      => [null],
			'save'      => [],
			'org'       => [new \OCP\AppFramework\Db\DoesNotExistException('missing')],
			'slugIndex' => ['unknown' => $this->orgEntity(uuid: 'o-other', active: true)],
		];
		$stats  = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
		$object = ['id' => 'abcd1234-5678-4abc-8def-0123456789ab', 'name' => 'Unknown', 'status' => 'actief'];
		$entity = $this->service()->ensureOrganisationEntityPublic($this->entity(object: $object, uuid: 'o-1'), $stats, false);

		self::assertNotNull($entity);
		self::assertContains(['mapper.findBySlug', 'organisation-abcd1234'], $this->trace);
		self::assertContains(['organisations.create', $object], $this->trace);
		self::assertArrayNotHasKey('slugConflicts', $stats);
	}//end testAFallbackNameWithAFreeSlugIsCreated()

	/**
	 * An organisation older than the organisatie that carries its uuid was not made
	 * from that organisatie: nothing is bound, whatever status the organisatie claims.
	 *
	 * @return void
	 */
	public function testAnOrganisatieDoesNotBindAnOlderOrganisationWithItsUuid(): void {
		foreach (['Active', 'Inactive', 'Draft'] as $status) {
			$stats  = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
			$entity = $this->ensureWithDates(status: $status, stats: $stats, dates: ['2020-06-01 10:00:01 UTC', '2020-06-01 10:00:00 UTC']);

			self::assertNull($entity, $status);
			self::assertSame([], $this->writes(), $status);
			self::assertSame(['o-1'], $stats['organisationIdConflicts'] ?? null, $status);
			self::assertSame(0, $stats['entitiesUpdated'], $status);
			self::assertArrayNotHasKey('slugConflicts', $stats, $status);
			self::assertSame([], array_values(array_filter($this->trace, fn (array $call) => str_starts_with($call[0], 'mail.'))), $status);
			$warnings = array_values(array_filter($this->trace, fn (array $call) => $call[0] === 'log.warning'));
			self::assertCount(1, $warnings, $status);
			self::assertSame('o-1', $warnings[0][2]['organisatieId']);
		}
	}//end testAnOrganisatieDoesNotBindAnOlderOrganisationWithItsUuid()

	/**
	 * The organisation the sync made from this organisatie is at least as new, even
	 * when the object in hand still carries the sub-second part of its creation.
	 *
	 * @return void
	 */
	public function testAnOrganisationMadeFromTheOrganisatieStaysBound(): void {
		foreach ([['2020-06-01 10:00:00.700 UTC', '2020-06-01 10:00:00 UTC'], ['2020-06-01 10:00:00 UTC', '2020-06-01 10:00:09 UTC']] as $dates) {
			$stats  = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
			$entity = $this->ensureWithDates(status: 'Inactive', stats: $stats, dates: $dates);

			self::assertNotNull($entity, $dates[0]);
			self::assertContains(['mapper.save', 'o-1', false], $this->trace, $dates[0]);
			self::assertArrayNotHasKey('organisationIdConflicts', $stats, $dates[0]);
		}
	}//end testAnOrganisationMadeFromTheOrganisatieStaysBound()

	/**
	 * Without both creation dates nothing shows the two belong together, so nothing is bound.
	 *
	 * @return void
	 */
	public function testAnUnknownCreationDateDoesNotBind(): void {
		foreach ([[null, '2020-06-01 10:00:05 UTC'], ['2020-06-01 10:00:00 UTC', null], [null, null]] as $dates) {
			$stats  = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
			$entity = $this->ensureWithDates(status: 'Active', stats: $stats, dates: $dates);

			self::assertNull($entity);
			self::assertSame([], $this->writes());
			self::assertSame(['o-1'], $stats['organisationIdConflicts'] ?? null);
		}
	}//end testAnUnknownCreationDateDoesNotBind()

	/**
	 * An organisation an administrator linked may be older than the organisatie.
	 *
	 * @return void
	 */
	public function testAnAdministratorsLinkKeepsAnOlderOrganisationBound(): void {
		$stats  = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
		$entity = $this->ensureWithDates(
			status: 'Inactive',
			stats: $stats,
			dates: ['2020-06-01 10:00:01 UTC', '2020-06-01 10:00:00 UTC'],
			appConfig: ['stackiq/linked_organisation_o-1' => '2026-11-03 10:00:00']
		);

		self::assertNotNull($entity);
		self::assertContains(['config.get', 'stackiq', 'linked_organisation_o-1', true], $this->trace);
		self::assertContains(['mapper.save', 'o-1', false], $this->trace);
	}//end testAnAdministratorsLinkKeepsAnOlderOrganisationBound()

	/**
	 * A link made before this organisatie object existed was made for an earlier
	 * object with the same uuid, so it does not bind this one.
	 *
	 * @return void
	 */
	public function testALinkOlderThanTheOrganisatieDoesNotBind(): void {
		foreach (['2020-05-31 23:59:59', '2020-06-01T09:59:59+00:00', 'not a date'] as $linkedAt) {
			$stats  = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
			$entity = $this->ensureWithDates(
				status: 'Active',
				stats: $stats,
				dates: ['2020-06-01 10:00:01 UTC', '2020-06-01 10:00:00 UTC'],
				appConfig: ['stackiq/linked_organisation_o-1' => $linkedAt]
			);

			self::assertNull($entity, $linkedAt);
			self::assertSame(['o-1'], $stats['organisationIdConflicts'] ?? null, $linkedAt);
		}
	}//end testALinkOlderThanTheOrganisatieDoesNotBind()

	/**
	 * Another organisatie's link says nothing about this one.
	 *
	 * @return void
	 */
	public function testAnotherOrganisatiesLinkDoesNotBind(): void {
		$stats  = ['entitiesCreated' => 0, 'entitiesUpdated' => 0];
		$entity = $this->ensureWithDates(
			status: 'Active',
			stats: $stats,
			dates: ['2020-06-01 10:00:01 UTC', '2020-06-01 10:00:00 UTC'],
			appConfig: ['stackiq/linked_organisation_o-2' => '2026-11-03 10:00:00', 'other/linked_organisation_o-1' => '2026-11-03 10:00:00']
		);

		self::assertNull($entity);
	}//end testAnotherOrganisatiesLinkDoesNotBind()

	/**
	 * A refused organisatie's contact persons, nested or listed, reach neither a
	 * user account nor the organisation's members, on the event path.
	 *
	 * @return void
	 */
	public function testARefusedOrganisatieProcessesNoContactPersons(): void {
		$object = $this->entity(
			object: ['name' => 'Chosen', 'status' => 'Active', 'contactpersonen' => [['email' => 'someone@example.org'], 'c-listed']],
			uuid: 'o-1'
		);
		$older            = $this->orgEntity(uuid: 'o-1', active: true);
		$older->createdAt = new \DateTimeImmutable('2020-01-01 00:00:00 UTC');
		$this->trace      = [];
		$this->plan       = ['find' => [null], 'save' => [], 'org' => [$older], 'slug' => []];

		$stats = $this->service()->processSpecificOrganization($object);

		self::assertSame(['o-1'], $stats['organisationIdConflicts'] ?? null);
		self::assertSame(0, $stats['contactPersonsProcessed']);
		self::assertSame(0, $stats['organizationsProcessed']);
		self::assertSame([], $this->writes());
		self::assertSame([], array_values(array_filter($this->trace, fn (array $call) => str_starts_with($call[0], 'handler.'))));
		self::assertSame(['objects.find'], array_values(array_unique(array_column(array_filter($this->trace, fn (array $call) => str_starts_with($call[0], 'objects.')), 0))));
	}//end testARefusedOrganisatieProcessesNoContactPersons()

	/**
	 * The scheduled sync goes no further than a refused organisatie either.
	 *
	 * @return void
	 */
	public function testTheScheduledSyncProcessesNoContactPersonsOfARefusedOrganisatie(): void {
		$older            = $this->orgEntity(uuid: 'o-1', active: true);
		$older->createdAt = new \DateTimeImmutable('2020-01-01 00:00:00 UTC');
		$this->trace      = [];
		$this->plan       = ['find' => [null], 'save' => [], 'org' => [$older], 'slug' => []];
		$stats            = ['entitiesCreated' => 0, 'entitiesUpdated' => 0, 'usersUpdated' => 0];
		$method           = new \ReflectionMethod(OrganizationSyncService::class, 'processOrganisationObject');

		$method->invokeArgs($this->service(), [$this->entity(object: ['name' => 'Chosen', 'status' => 'Active'], uuid: 'o-1'), 'reg', 'contact-schema', &$stats]);

		self::assertSame(['o-1'], $stats['organisationIdConflicts'] ?? null);
		self::assertSame([], $this->writes());
		self::assertSame(['objects.find'], array_values(array_unique(array_column(array_filter($this->trace, fn (array $call) => str_starts_with($call[0], 'objects.')), 0))));
	}//end testTheScheduledSyncProcessesNoContactPersonsOfARefusedOrganisatie()

	/**
	 * Run the ensure with an organisation that has the organisatie's uuid.
	 *
	 * @param string                $status    The organisatie's status.
	 * @param array                 $stats     The stats, written by reference.
	 * @param array                 $dates     When the object and the organisation were created (null: unknown).
	 * @param array<string, string> $appConfig The stored app config, keyed app/key.
	 *
	 * @return object|null What the ensure returned.
	 */
	private function ensureWithDates(string $status, array &$stats, array $dates, array $appConfig=[]): ?object {
		$object            = $this->entity(object: ['name' => 'Chosen', 'status' => $status], uuid: 'o-1');
		$object->createdAt = $dates[0] === null ? null : new \DateTimeImmutable($dates[0]);
		$entity            = $this->orgEntity(uuid: 'o-1', active: true);
		$entity->createdAt = $dates[1] === null ? null : new \DateTimeImmutable($dates[1]);
		$this->trace       = [];
		$this->plan        = ['find' => [null], 'save' => [], 'org' => [$entity], 'slug' => [], 'appConfig' => $appConfig];

		return $this->service()->ensureOrganisationEntityPublic($object, $stats, true);
	}//end ensureWithDates()

	/**
	 * The writes in the trace.
	 *
	 * @return array<int, array>
	 */
	private function writes(): array {
		return array_values(array_filter($this->trace, fn (array $call) => in_array($call[0], ['entity.setUuid', 'entity.setActive', 'mapper.save', 'organisations.create', 'objects.saveObject', 'entity.setOwner', 'entity.setOrganisation', 'entity.setObject'], true)));
	}//end writes()

	/**
	 * A link names what it moved, reads the organisatie and its would-be holder past
	 * access control and organisation scope, and is remembered before anything moves.
	 *
	 * @return void
	 */
	public function testALinkIsAuditedAndRemembered(): void {
		$this->trace = [];
		$this->plan  = [
			'find' => [$this->entity(object: ['name' => 'Gemeente A'], uuid: 'o-1'), null],
			'save' => [],
			'org'  => [new \OCP\AppFramework\Db\DoesNotExistException('missing'), $this->orgEntity('o-old', true), $this->orgEntity('o-old', true)],
			'slug' => [],
		];

		self::assertSame('linked', $this->service()->linkOrganisationEntity('o-1', 'o-old'));

		$finds = array_values(array_filter($this->trace, fn (array $call) => $call[0] === 'objects.find'));
		self::assertSame(['id' => 'o-1', 'register' => 'reg-v', 'schema' => 'org-schema', '_rbac' => false, '_multitenancy' => false], $finds[0][1]);
		self::assertSame(['id' => 'o-old', 'register' => 'reg-v', 'schema' => 'org-schema', '_rbac' => false, '_multitenancy' => false], $finds[1][1]);

		$logged = array_values(array_filter($this->trace, fn (array $call) => $call[0] === 'log.info' && str_contains($call[1], 'an administrator linked')));
		self::assertCount(1, $logged);
		self::assertSame(['app' => 'stackiq', 'organisatieId' => 'o-1', 'previousOrganisationUuid' => 'o-old'], $logged[0][2]);

		$calls = array_column($this->trace, 0);
		self::assertContains(['config.set', 'stackiq', 'linked_organisation_o-1', true], $this->trace);
		self::assertLessThan(array_search('entity.setUuid', $calls, true), array_search('config.set', $calls, true));
	}//end testALinkIsAuditedAndRemembered()

	/**
	 * An administrator can confirm an organisatie the sync refused because it
	 * carries the uuid of an older organisation; the organisation keeps that uuid.
	 *
	 * @return void
	 */
	public function testAnAdministratorCanConfirmARefusedPair(): void {
		$object           = $this->entity(object: ['name' => 'Gemeente A'], uuid: 'o-1');
		$older            = $this->orgEntity(uuid: 'o-1', active: true);
		$older->createdAt = new \DateTimeImmutable('2020-01-01 00:00:00 UTC');
		$this->trace      = [];
		$this->plan       = ['find' => [$object, $object], 'save' => [], 'org' => [$older, $older, $older], 'slug' => []];

		self::assertSame('linked', $this->service()->linkOrganisationEntity('o-1', 'o-1'));
		self::assertContains(['config.set', 'stackiq', 'linked_organisation_o-1', true], $this->trace);
		self::assertContains(['entity.setUuid', 'o-1', 'o-1'], $this->trace);
		self::assertContains('objects.saveObject', array_column($this->trace, 0));
	}//end testAnAdministratorCanConfirmARefusedPair()

	/**
	 * A pair that already holds is not linked again, and the default organisation is never confirmed.
	 *
	 * @return void
	 */
	public function testAPairThatHoldsOrTheDefaultOrganisationIsNotConfirmed(): void {
		$object       = $this->entity(object: ['name' => 'Gemeente A'], uuid: 'o-1');
		$this->trace  = [];
		$this->plan   = ['find' => [$object], 'save' => [], 'org' => [$this->orgEntity('o-1', true)], 'slug' => []];
		self::assertSame('already-linked', $this->service()->linkOrganisationEntity('o-1', 'o-1'));
		self::assertSame([], $this->writes());

		$older            = $this->orgEntity(uuid: 'o-1', active: true);
		$older->createdAt = new \DateTimeImmutable('2020-01-01 00:00:00 UTC');
		$this->trace      = [];
		$this->plan       = ['find' => [$object], 'save' => [], 'org' => [$older, $older], 'slug' => [], 'default' => 'o-1'];
		self::assertSame('default-organisation', $this->service()->linkOrganisationEntity('o-1', 'o-1'));
		self::assertSame([], $this->writes());
		self::assertNotContains('config.set', array_column($this->trace, 0));

		$this->trace = [];
		$this->plan  = ['find' => [$object], 'save' => [], 'org' => [$older], 'slug' => []];
		self::assertSame('already-linked', $this->service()->linkOrganisationEntity('o-1', 'o-old'));
		self::assertSame([], $this->writes());
	}//end testAPairThatHoldsOrTheDefaultOrganisationIsNotConfirmed()

	/**
	 * Checking a link answers what the link would, and writes nothing.
	 *
	 * @param string             $outcome The expected outcome.
	 * @param \Closure           $plan    Builds the doubles' answers.
	 * @param array<int, string> $writes  The writes a link would make; a check makes none.
	 *
	 * @return void
	 *
	 * @dataProvider linkCases
	 */
	public function testCheckOrganisationLink(string $outcome, \Closure $plan, array $writes): void {
		$this->trace = [];
		$this->plan  = array_merge(['find' => [], 'save' => [], 'org' => [], 'slug' => []], $plan($this));

		$expected = $outcome;
		if ($outcome === 'linked') {
			$expected = null;
		}

		self::assertSame($expected, $this->service()->checkOrganisationLink('o-1', 'o-old'));
		$made = array_filter($this->trace, fn (array $call) => in_array($call[0], ['entity.setUuid', 'mapper.save', 'objects.saveObject'], true));
		self::assertSame([], array_values($made));
	}//end testCheckOrganisationLink()

	/**
	 * The link outcomes, and what a link writes.
	 *
	 * @return array<string, array{0: string, 1: \Closure, 2: array<int, string>}>
	 */
	public static function linkCases(): array {
		$missing = fn () => new \OCP\AppFramework\Db\DoesNotExistException('missing');
		$object  = fn ($t, string $uuid) => $t->entity(object: ['name' => 'Gemeente A'], uuid: $uuid);
		return [
			'linked'              => ['linked', fn ($t) => ['find' => [$object($t, 'o-1'), null], 'org' => [$missing(), $t->orgEntity('o-old', true), $t->orgEntity('o-old', true)]], ['entity.setUuid', 'mapper.save', 'objects.saveObject']],
			'no organisatie'      => ['no-organisatie', fn ($t) => ['find' => [null]], []],
			'already linked'      => ['already-linked', fn ($t) => ['find' => [$object($t, 'o-1')], 'org' => [$t->orgEntity('o-1', true)]], []],
			'no organisation'     => ['no-organisation', fn ($t) => ['find' => [$object($t, 'o-1')], 'org' => [$missing(), $missing()]], []],
			'organisation in use' => ['organisation-in-use', fn ($t) => ['find' => [$object($t, 'o-1'), $object($t, 'o-old')], 'org' => [$missing(), $t->orgEntity('o-old', true)]], []],
			'default organisation' => ['default-organisation', fn ($t) => ['find' => [$object($t, 'o-1')], 'org' => [$missing(), $t->orgEntity('o-old', true)], 'default' => 'o-old'], []],
			'no default set'      => ['default-unknown', fn ($t) => ['find' => [$object($t, 'o-1')], 'org' => [$missing(), $t->orgEntity('o-old', true)], 'default' => null], []],
			'empty default set'   => ['default-unknown', fn ($t) => ['find' => [$object($t, 'o-1')], 'org' => [$missing(), $t->orgEntity('o-old', true)], 'default' => ''], []],
		];
	}//end linkCases()

	/**
	 * An administrator's link writes only when every check passes.
	 *
	 * @param string             $outcome The expected outcome.
	 * @param \Closure           $plan    Builds the doubles' answers.
	 * @param array<int, string> $writes  The writes expected, in order.
	 *
	 * @return void
	 *
	 * @dataProvider linkCases
	 */
	public function testLinkOrganisationEntity(string $outcome, \Closure $plan, array $writes): void {
		$this->trace = [];
		$this->plan  = array_merge(['find' => [], 'save' => [], 'org' => [], 'slug' => []], $plan($this));

		self::assertSame($outcome, $this->service()->linkOrganisationEntity('o-1', 'o-old'));

		$made = array_values(array_filter($this->trace, fn (array $call) => in_array($call[0], ['entity.setUuid', 'mapper.save', 'objects.saveObject'], true)));
		self::assertSame($writes, array_column($made, 0));
		if ($outcome === 'linked') {
			self::assertContains(['entity.setUuid', 'o-old', 'o-1'], $this->trace);
		}
	}//end testLinkOrganisationEntity()

	/**
	 * The batch back-fill creates missing organisation entities without mailing them.
	 *
	 * @return void
	 */
	public function testTheBackfillSendsNoMail(): void {
		$this->trace = [];
		$missing     = new \OCP\AppFramework\Db\DoesNotExistException('missing');
		$this->plan  = [
			'config' => ['register' => '6', 'organisatie_schema' => '9'],
			'find'   => [$this->entity(object: ['name' => 'Gemeente N', 'status' => 'actief'], uuid: 'o-1'), null],
			'save'   => [],
			'org'    => [$missing],
			'slug'   => [$missing],
		];
		$service = $this->service();
		(new \ReflectionProperty(OrganizationSyncService::class, 'db'))->setValue($service, $this->batchDatabase(rows: [['uuid' => 'o-1', 'status' => 'actief']]));

		$stats = $service->performOrganizationsSync();

		self::assertSame(1, $stats['entitiesCreated'], 'the back-fill must reach the entity creation');
		self::assertSame([], array_values(array_filter($this->trace, fn (array $call) => str_starts_with($call[0], 'mail.'))));
	}//end testTheBackfillSendsNoMail()

	/**
	 * A created organisation processed on its own gets its registration mail.
	 *
	 * @return void
	 */
	public function testProcessSpecificOrganizationMailsOnCreate(): void {
		$this->trace = [];
		$missing     = new \OCP\AppFramework\Db\DoesNotExistException('missing');
		$this->plan  = ['find' => [null], 'save' => [], 'org' => [$missing], 'slug' => [$missing]];

		$this->service()->processSpecificOrganization($this->entity(object: ['name' => 'Gemeente O', 'status' => 'actief'], uuid: 'o-1'));

		self::assertContains(['mail.sendOrganizationRegistrationEmail', 'Gemeente O'], $this->trace);
	}//end testProcessSpecificOrganizationMailsOnCreate()

	/**
	 * A database whose count and batch queries answer the given rows.
	 *
	 * @param array<int, array<string, string>> $rows The organisations without an entity.
	 *
	 * @return \OCP\IDBConnection
	 */
	private function batchDatabase(array $rows): \OCP\IDBConnection {
		$result = $this->createMock(originalClassName: \OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn(count($rows));
		$result->method('fetchAll')->willReturn($rows);

		$query = $this->createMock(originalClassName: \OCP\DB\QueryBuilder\IQueryBuilder::class);
		foreach (['select', 'from', 'leftJoin', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $method) {
			$query->method($method)->willReturnSelf();
		}

		$query->method('createFunction')->willReturn($this->createMock(originalClassName: \OCP\DB\QueryBuilder\IQueryFunction::class));
		$query->method('createNamedParameter')->willReturn(':status');
		$query->method('executeQuery')->willReturn($result);

		$database = $this->createMock(originalClassName: \OCP\IDBConnection::class);
		$database->method('getQueryBuilder')->willReturn($query);
		return $database;
	}//end batchDatabase()

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
