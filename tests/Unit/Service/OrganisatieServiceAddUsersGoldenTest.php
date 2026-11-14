<?php

/**
 * Golden test for OrganisatieService::addUsersToOrganization().
 *
 * Records, per case, the organisation lookup, the users looked up and
 * mailed, the users saved on the organisation, the log lines and the
 * result or the error that escaped.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins what adding users to an organisation does.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
final class OrganisatieServiceAddUsersGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/organisatie/add-users.json';

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		return [
			'new users'        => ['current' => ['anne'], 'add' => ['bob', 'carla']],
			'overlap'          => ['current' => ['anne', 'bob'], 'add' => ['bob', 'dirk', 'dirk']],
			'no current users' => ['current' => null, 'add' => ['bob']],
			'nothing to add'   => ['current' => ['anne'], 'add' => []],
			'unknown user'     => ['current' => ['anne'], 'add' => ['bob', 'ghost']],
			'no organisation'  => ['current' => ['anne'], 'add' => ['bob'], 'findFails' => true],
			'mail fails'       => ['current' => ['anne'], 'add' => ['bob', 'carla'], 'mailFails' => 'carla'],
			'save fails'       => ['current' => ['anne'], 'add' => ['bob'], 'saveFails' => true],
			'other organisation' => ['current' => ['anne'], 'add' => ['bob'], 'organisation' => 'org-2'],
		];
	}//end cases()

	/**
	 * Run one case and record what happened.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $case): array {
		$trace  = [];
		$entity = new class ($case['current'], $trace) {
			/**
			 * Keep the users and the trace.
			 *
			 * @param array<int, string>|null $users The current users.
			 * @param array<int, mixed>       $trace The trace.
			 */
			public function __construct(private ?array $users, private array &$trace) {
			}

			/**
			 * The users.
			 *
			 * @return array<int, string>|null
			 */
			public function getUsers(): ?array {
				return $this->users;
			}

			/**
			 * Set the users.
			 *
			 * @param array<int|string, string> $users The users.
			 *
			 * @return void
			 */
			public function setUsers(array $users): void {
				$this->trace[] = ['setUsers', $users];
				$this->users   = $users;
			}

			/**
			 * Serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'org-1', 'users' => $this->users];
			}
		};
		$mapper = new class ($entity, $case, $trace) {
			/**
			 * Keep the entity, the case and the trace.
			 *
			 * @param object               $entity The entity.
			 * @param array<string, mixed> $case   The case.
			 * @param array<int, mixed>    $trace  The trace.
			 */
			public function __construct(private object $entity, private array $case, private array &$trace) {
			}

			/**
			 * Find by uuid.
			 *
			 * @param string $uuid The uuid.
			 *
			 * @return object
			 */
			public function findByUuid(string $uuid): object {
				$this->trace[] = ['findByUuid', $uuid];
				if (($this->case['findFails'] ?? false) === true) {
					throw new \RuntimeException('organisation not found');
				}

				return $this->entity;
			}

			/**
			 * Save.
			 *
			 * @param object $entity The entity.
			 *
			 * @return object
			 */
			public function save(object $entity): object {
				$this->trace[] = ['save', $entity === $this->entity];
				if (($this->case['saveFails'] ?? false) === true) {
					throw new \RuntimeException('database down');
				}

				return $entity;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use (&$trace, $mapper): object {
			$trace[] = ['container.get', $id];
			return $mapper;
		});
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use (&$trace): ?IUser {
			$trace[] = ['userManager.get', $uid];
			if ($uid === 'ghost') {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getEMailAddress')->willReturn($uid . '@example.org');
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			return $user;
		});
		$email = $this->createMock(SymfonyEmailService::class);
		$email->method('sendUserUpdateEmail')->willReturnCallback(function (array $user, array $organization = []) use (&$trace, $case): bool {
			$trace[] = ['sendUserUpdateEmail', $user, $organization];
			if (($case['mailFails'] ?? null) === $user['username']) {
				throw new \RuntimeException('mail down');
			}

			return true;
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use (&$trace, $level): void {
				if (isset($context['trace']) === true) {
					$context['trace'] = 'TRACE';
				}

				$trace[] = [$level, $message, $context];
			});
		}

		$service = new OrganisatieService($logger, $container, $this->createMock(IAppManager::class), $users, $email);
		try {
			$outcome = ['result' => $service->addUsersToOrganization(($case['organisation'] ?? 'org-1'), $case['add'])];
		} catch (\Throwable $e) {
			$outcome = ['escaped' => get_class($e)];
		}

		return $outcome + ['trace' => $trace];
	}//end record()

	/**
	 * Every case matches the recorded outcome.
	 *
	 * @return void
	 */
	public function testAddUsersMatchesGolden(): void {
		$actual = [];
		$lines  = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
			$lines[]       = json_encode($name) . ': ' . json_encode($actual[$name], JSON_UNESCAPED_SLASHES);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
		}

		$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame($golden, json_decode((string) json_encode($actual), true));

	}//end testAddUsersMatchesGolden()
}//end class
