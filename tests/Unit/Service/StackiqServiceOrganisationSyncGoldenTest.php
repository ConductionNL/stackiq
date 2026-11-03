<?php

/**
 * Golden test for StackiqService's organisation sync with OpenRegister.
 *
 * Records, per case, every container, mapper, organisation entity, object
 * service, settings and logger call that syncOrganizationWithOpenRegister and
 * createOrganisationInOpenRegister make, plus their result or the error that
 * escapes. Mock and anonymous class names are masked, as they depend on the
 * order tests run in. Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/softwarecatalogue-orchestration/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCA\Stackiq\Service\StackiqService;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for the organisation sync with OpenRegister.
 *
 * @covers \OCA\Stackiq\Service\StackiqService
 *
 * @spec openspec/specs/softwarecatalogue-orchestration/spec.md
 */
class StackiqServiceOrganisationSyncGoldenTest extends TestCase {

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
		return __DIR__ . '/golden/stackiqServiceOrganisationSync.json';

	}//end golden()

	/**
	 * The cases: entry point, organisation data and the doubles' behaviour.
	 *
	 * @return array<string, array{0: string, 1: array, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		$org = [
			'id'              => 'org-1',
			'name'            => 'Gemeente A',
			'website'         => 'https://a.nl',
			'beoordeling'     => 'actief',
			'contactpersonen' => [['email' => 'a@a.nl'], ['naam' => 'zonder mail'], 'c-2'],
		];
		return [
			'sync, OpenRegister disabled'       => ['sync', $org, ['disabled' => true]],
			'sync, existing organisation'       => ['sync', $org, ['exists' => true]],
			'sync, existing, save fails'        => ['sync', $org, ['exists' => true, 'saveThrows' => true]],
			'sync, new, signed in'              => ['sync', $org, []],
			'sync, new, signed in with schema'  => ['sync', $org, ['schema' => true, 'contacts' => [['email' => 'b@a.nl', 'organization' => 'org-1'], ['email' => 'c@a.nl', 'organization' => 'org-9']]]],
			'sync, new, anonymous'              => ['sync', $org, ['anonymous' => true]],
			'create, no id'                     => ['create', ['name' => 'X'], []],
			'create, signed in'                 => ['create', $org, []],
			'create, organisation service down' => ['create', $org, ['serviceThrows' => true]],
			'create, anonymous, no mapper'      => ['create', $org, ['anonymous' => true, 'noMapper' => true]],
		];

	}//end cases()

	/**
	 * Each case matches its recording.
	 *
	 * @param string               $entry Which entry point runs.
	 * @param array                $data  The organisation object data.
	 * @param array<string, mixed> $plan  The behaviour of the doubles.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testSyncMatchesTheRecording(string $entry, array $data, array $plan): void {
		$this->plan  = $plan;
		$this->trace = [];
		$service     = $this->service();
		try {
			if ($entry === 'sync') {
				$result = $service->syncOrganizationWithOpenRegister($this->stored(data: $data, id: 17));
			} else {
				$result = $service->createOrganisationInOpenRegister($data);
				if (is_object($result) === true) {
					$result = 'entity:' . $result->getUuid();
				}
			}
		} catch (\Throwable $e) {
			$result = ['escaped' => get_class($e), 'message' => preg_replace('/Mock\w+/', 'Mock', $e->getMessage())];
		}

		$this->check(key: $entry . ': ' . $this->dataName(), value: ['result' => $result, 'calls' => $this->trace]);

	}//end testSyncMatchesTheRecording()

	/**
	 * The service with recording doubles.
	 *
	 * @return StackiqService
	 */
	private function service(): StackiqService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(isset($this->plan['disabled']) === false);

		$mapper = $this->mapper();
		$objects = $this->objectService();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function (): array {
				$this->trace[] = ['settings.getVoorzieningenConfig'];
				return isset($this->plan['schema']) === true ? ['contactpersoon_schema' => '9'] : [];
			}
		);
		$organisations = $this->createMock(OrganisationService::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $objects, $settings, $organisations) {
				$this->trace[] = ['container.get', $id];
				if ($id === 'OCA\OpenRegister\Service\OrganisationService' && isset($this->plan['serviceThrows']) === true) {
					throw new \RuntimeException('organisation service down');
				}

				if ($id === 'OCA\OpenRegister\Db\OrganisationMapper' && isset($this->plan['noMapper']) === true) {
					throw new \RuntimeException('no mapper');
				}

				$answers = [
					'OCA\OpenRegister\Db\OrganisationMapper'       => $mapper,
					'OCA\OpenRegister\Service\ObjectService'       => $objects,
					'OCA\Stackiq\Service\SettingsService'          => $settings,
					'OCA\OpenRegister\Service\OrganisationService' => $organisations,
				];
				return $answers[$id] ?? null;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('signed-in');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(isset($this->plan['anonymous']) === true ? null : $user);

		return new StackiqService(
			_organizationHandler: $this->createMock(OrganizationHandler::class),
			_contactPersonHandler: $this->createMock(ContactPersonHandler::class),
			_groupHandler: $this->createMock(GroupHandler::class),
			_hierarchyHandler: $this->createMock(HierarchyHandler::class),
			_emailService: $this->createMock(SymfonyEmailService::class),
			_logger: $this->logger(),
			_container: $container,
			_appManager: $appManager,
			_userSession: $userSession,
			_userManager: $this->createMock(IUserManager::class),
			_groupManager: $this->createMock(IGroupManager::class)
		);

	}//end service()

	/**
	 * An organisation mapper that records lookups and saves.
	 *
	 * @return OrganisationMapper
	 */
	private function mapper(): OrganisationMapper {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid) {
				$this->trace[] = ['mapper.findByUuid', $uuid];
				if (isset($this->plan['exists']) === false) {
					throw new DoesNotExistException('missing');
				}

				return $this->organisation(uuid: $uuid);
			}
		);
		$mapper->method('save')->willReturnCallback(
			function ($organisation) {
				$this->trace[] = ['mapper.save', $organisation->getUuid()];
				if (isset($this->plan['saveThrows']) === true) {
					throw new \RuntimeException('save failed');
				}

				return $organisation;
			}
		);

		return $mapper;

	}//end mapper()

	/**
	 * An organisation entity double that records its writes.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return Organisation
	 */
	private function organisation(string $uuid): Organisation {
		$trace = &$this->trace;
		$organisation = new class($trace) extends Organisation {
			/**
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * @param string $name The method.
			 * @param array  $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args) {
				$this->trace[] = ['organisation.' . $name, $args];
				return null;
			}
		};
		$organisation->setUuid($uuid);
		return $organisation;

	}//end organisation()

	/**
	 * An object service that answers the contact person lookups.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) {
				$this->trace[] = ['objects.findAll', $config];
				$found = [];
				foreach ($this->plan['contacts'] ?? [] as $i => $data) {
					$found[] = $this->stored(data: $data, id: 100 + $i);
				}

				return $found;
			}
		);

		return $objects;

	}//end objectService()

	/**
	 * A stored object double with an id and its data.
	 *
	 * @param array $data The object data.
	 * @param int   $id   The object id.
	 *
	 * @return object
	 */
	private function stored(array $data, int $id): object {
		return new class($data, $id) {
			/**
			 * @param array $data The object data.
			 * @param int   $id   The object id.
			 */
			public function __construct(private array $data, private int $id) {
			}

			/**
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}
		};

	}//end stored()

	/**
	 * A logger that records every line, without stack traces.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$trace = &$this->trace;
		return new class($trace) extends AbstractLogger {
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
			public function log($level, string|\Stringable $message, array $context = []): void {
				foreach (['trace', 'file', 'line'] as $volatile) {
					if (array_key_exists($volatile, $context) === true) {
						$context[$volatile] = '(' . $volatile . ')';
					}
				}

				array_walk_recursive(
					$context,
					function (&$value) {
						if (is_string($value) === true) {
							$value = preg_replace(['/Mock\w+/', '/@anonymous.*/s'], ['Mock', '@anonymous'], $value);
						}
					}
				);
				$this->trace[] = ['log.' . $level, preg_replace('/Mock\w+/', 'Mock', (string) $message), $context];
			}
		};

	}//end logger()

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
			file_put_contents($file, json_encode($saved, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: $json);

	}//end check()

}//end class
