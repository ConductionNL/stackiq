<?php

/**
 * Golden test for the contact person account step of OrganizationSyncService.
 *
 * Pins, per case, which username processContactPerson resolves for a
 * contact person, when it creates an account (and when it does not), the
 * statistics it counts and every user manager, contact person service and
 * logger call, in order.
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

use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for ensuring a contact person has an account.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 *
 * @spec openspec/specs/organization-sync/spec.md
 */
class OrganizationSyncContactAccountTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Run processContactPerson on a contact person.
	 *
	 * @param array $data The contact person data.
	 * @param array $plan users: the accounts that exist; created: what processContactpersoon answers; throws: where it fails.
	 *
	 * @return array{0: string|null, 1: array, 2: array}
	 */
	private function process(array $data, array $plan): array {
		$this->trace = [];
		$plan        = array_merge(['users' => [], 'created' => true, 'throws' => null], $plan);

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
			 * Record a log line, without exception objects.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				if (isset($context['exception']) === true) {
					$context['exception'] = $context['exception']->getMessage();
				}

				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) use ($plan) {
				$this->trace[] = ['users.get', $uid];
				if ($plan['throws'] === 'users') {
					throw new \RuntimeException('user backend down');
				}

				if (in_array($uid, $plan['users'], true) === true) {
					return $this->createMock(originalClassName: IUser::class);
				}

				return null;
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($users) {
				$this->trace[] = ['container.get', $id];
				return $users;
			}
		);

		$contacts = $this->createMock(originalClassName: ContactpersoonService::class);
		$contacts->method('processContactpersoon')->willReturnCallback(
			function ($contactPerson, $flag) use ($plan) {
				$this->trace[] = ['processContactpersoon', $contactPerson->getId(), $flag];
				if ($plan['throws'] === 'create') {
					throw new \RuntimeException('create failed');
				}

				return $plan['created'];
			}
		);

		$contactPerson = new class($data) {
			/**
			 * Build the double.
			 *
			 * @param array $data The contact data.
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
			 * @return int
			 */
			public function getId(): int {
				return 12;
			}
		};

		$reflection = new \ReflectionClass(OrganizationSyncService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (['logger' => $logger, 'container' => $container, 'contactPersonService' => $contacts] as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		$stats  = ['usersCreated' => 1, 'usersUpdated' => 1];
		$result = (new \ReflectionMethod(OrganizationSyncService::class, 'processContactPerson'))->invokeArgs($service, [$contactPerson, &$stats]);

		return [$result, $stats, $this->trace];

	}//end process()

	/**
	 * A contact person without an account gets one under its stored username; nothing else is counted.
	 *
	 * @return void
	 */
	public function testAnAccountIsCreatedUnderTheStoredUsername(): void {
		[$result, $stats, $trace] = $this->process(data: ['email' => 'a@x.nl', 'e-mailadres' => 'b@x.nl', 'username' => 'alice'], plan: []);

		$this->assertSame('alice', $result);
		$this->assertSame(['usersCreated' => 2, 'usersUpdated' => 1], $stats);
		$this->assertSame(
			[
				['container.get', 'OCP\IUserManager'],
				['users.get', 'alice'],
				['log', 'info', 'OrganizationSyncService: Creating user account for contact person', ['contactId' => 12, 'email' => 'a@x.nl', 'username' => 'alice']],
				['processContactpersoon', 12, false],
				['log', 'info', 'OrganizationSyncService: Successfully created user account', ['contactId' => 12, 'username' => 'alice']],
			],
			$trace
		);

	}//end testAnAccountIsCreatedUnderTheStoredUsername()

	/**
	 * Without a stored username the e-mail (else e-mailadres) is the username, and the username is recorded.
	 *
	 * @return void
	 */
	public function testTheEmailIsTheUsernameWhenNoneIsStored(): void {
		[$result, $stats, $trace] = $this->process(data: ['e-mailadres' => 'b@x.nl', 'username' => ''], plan: ['users' => ['b@x.nl']]);

		$this->assertSame('b@x.nl', $result);
		$this->assertSame(['usersCreated' => 1, 'usersUpdated' => 2], $stats);
		$this->assertSame(
			[
				['container.get', 'OCP\IUserManager'],
				['users.get', 'b@x.nl'],
				['log', 'debug', 'OrganizationSyncService: Updating contact person with username', ['contactId' => 12, 'username' => 'b@x.nl']],
			],
			$trace
		);

		[$result, $stats, $trace] = $this->process(data: ['email' => 'a@x.nl'], plan: ['created' => false]);
		$this->assertSame('a@x.nl', $result);
		$this->assertSame(['usersCreated' => 1, 'usersUpdated' => 2], $stats);
		$this->assertSame(
			[
				['container.get', 'OCP\IUserManager'],
				['users.get', 'a@x.nl'],
				['log', 'info', 'OrganizationSyncService: Creating user account for contact person', ['contactId' => 12, 'email' => 'a@x.nl', 'username' => 'a@x.nl']],
				['processContactpersoon', 12, false],
				['log', 'error', 'OrganizationSyncService: Failed to create user account', ['contactId' => 12, 'username' => 'a@x.nl']],
				['log', 'debug', 'OrganizationSyncService: Updating contact person with username', ['contactId' => 12, 'username' => 'a@x.nl']],
			],
			$trace
		);

	}//end testTheEmailIsTheUsernameWhenNoneIsStored()

	/**
	 * A contact person without an e-mail gets no account and no username.
	 *
	 * @return void
	 */
	public function testAContactWithoutEmailIsSkipped(): void {
		foreach ([['username' => 'alice'], ['email' => '', 'e-mailadres' => 'b@x.nl'], ['email' => null, 'e-mailadres' => '0']] as $data) {
			[$result, $stats, $trace] = $this->process(data: $data, plan: []);
			$this->assertNull($result, json_encode($data));
			$this->assertSame(['usersCreated' => 1, 'usersUpdated' => 1], $stats);
			$this->assertSame([['log', 'debug', 'OrganizationSyncService: Contact person has no email, skipping', ['contactId' => 12]]], $trace);
		}

	}//end testAContactWithoutEmailIsSkipped()

	/**
	 * A failure is logged and answers no username; counts made before it stay.
	 *
	 * @return void
	 */
	public function testAFailureAnswersNoUsername(): void {
		foreach (['users' => 'user backend down', 'create' => 'create failed'] as $where => $message) {
			[$result, $stats, $trace] = $this->process(data: ['email' => 'a@x.nl'], plan: ['throws' => $where]);
			$this->assertNull($result);
			$this->assertSame(['usersCreated' => 1, 'usersUpdated' => 1], $stats);
			$this->assertSame(['log', 'error', 'OrganizationSyncService: Failed to process contact person', ['contactId' => 12, 'exception' => $message]], end($trace));
		}

	}//end testAFailureAnswersNoUsername()
}//end class
