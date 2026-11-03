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
use Psr\Log\AbstractLogger;

/**
 * Golden cases for the organisation entity sync.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
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
			 * @param string $entityUuid The uuid.
			 * @param bool   $isActive   Whether it is active.
			 * @param array  $trace      The shared trace.
			 */
			public function __construct(private string $entityUuid, private bool $isActive, private array &$trace) {
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
			'no name, created'                          => [['status' => 'actief'], true, fn ($t) => ['org' => [$missing()]]],
			'creation fails'                            => [['name' => 'Gemeente J'], true, fn ($t) => ['org' => [$missing()], 'slug' => [$missing()], 'created' => null]],
			'mapper fails'                              => [['name' => 'Gemeente K'], true, fn ($t) => ['org' => [new \RuntimeException('db down')]]],
			'owner save fails'                          => [['name' => 'Gemeente L'], true, fn ($t) => ['org' => [$t->orgEntity('o-1', true)], 'save' => [new \RuntimeException('save failed')]]],
			'settings fail'                             => [['name' => 'Gemeente M'], true, fn ($t) => ['config' => new \RuntimeException('settings down')]],
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
