<?php

/**
 * Golden test for the contact person sync of UserProfileUpdatedEventListener.
 *
 * Pins, per case, which contactpersoon a profile update is matched to (and
 * when none is), what is written to it, and every container, settings,
 * user, object service and logger call, in order.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

require_once __DIR__ . '/../Support/SaveCallShape.php';

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\Stackiq\EventListener\UserProfileUpdatedEventListener;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Tests\Unit\Support\SaveCallShape;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Golden cases for syncing a profile update onto its contactpersoon.
 *
 * @covers \OCA\Stackiq\EventListener\UserProfileUpdatedEventListener
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class UserProfileContactSyncGoldenTest extends TestCase {

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
	 * The default plan, overridden per case.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaultPlan(): array {
		return [
			'config'     => ['register' => '7', 'contactpersoon_schema' => '8'],
			'email'      => 'Alice@Example.com',
			'byUsername' => [],
			'bySearch'   => [],
			'changes'    => ['firstName', 'email'],
			'newData'    => ['firstName' => 'Alice', 'email' => null, 'lastName' => 'Doe'],
		];

	}//end defaultPlan()

	/**
	 * A contactpersoon double.
	 *
	 * @param array  $data The contact data.
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntityInterface
	 */
	private function contact(array $data, string $uuid): ObjectEntityInterface {
		$trace = &$this->trace;
		return new class($data, $uuid, $trace) implements ObjectEntityInterface {
			/**
			 * Build the double.
			 *
			 * @param array  $data  The contact data.
			 * @param string $uuid  The uuid.
			 * @param array  $trace The shared trace.
			 */
			public function __construct(private array $data, private string $uuid, private array &$trace) {
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
				$this->trace[] = ['contact.getObject', $this->uuid];
				return $this->data;
			}

			/**
			 * @return string|null
			 */
			public function getRegister(): ?string {
				return 'reg-c';
			}

			/**
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return 'schema-c';
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

	}//end contact()

	/**
	 * The logger double.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$trace = &$this->trace;
		return new class($trace) extends AbstractLogger {
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

	}//end logger()

	/**
	 * Run the listener on a profile update of `alice`.
	 *
	 * @param array $plan   Overrides of the default plan.
	 * @param bool  $direct Call the private sync step directly instead of handle().
	 *
	 * @return array The trace.
	 */
	private function runCase(array $plan, bool $direct=false): array {
		$this->trace = [];
		$this->plan  = array_merge(self::defaultPlan(), $plan);

		$logger   = $this->logger();
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function () {
				$this->trace[] = ['getVoorzieningenConfig'];
				return $this->plan['config'];
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($logger, $settings) {
				$this->trace[] = ['container.get', $id];
				if ($id === LoggerInterface::class) {
					return $logger;
				}

				return $settings;
			}
		);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy) {
				$this->trace[] = ['searchObjects', $query, $_rbac, $_multitenancy];
				if (isset($query['username']) === true) {
					return $this->plan['byUsername'];
				}

				return ($this->plan['bySearch'][$query['_search']] ?? []);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) {
				[$object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation] = SaveCallShape::flat(args: $args);
				$this->trace[] = ['saveObject', $object, $register, $schema, $uuid, $silent, $_validation];
				return $this->contact(data: $object, uuid: (string) $uuid);
			}
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturnCallback(
			function () {
				$this->trace[] = ['user.getUID'];
				return 'alice';
			}
		);
		$user->method('getEMailAddress')->willReturnCallback(
			function () {
				$this->trace[] = ['user.getEMailAddress'];
				return $this->plan['email'];
			}
		);

		$event    = new UserProfileUpdatedEvent($user, [], $this->plan['newData'], $this->plan['changes']);
		$listener = new UserProfileUpdatedEventListener($container, $objects);
		if ($direct === true) {
			(new \ReflectionMethod($listener, 'syncToContactPerson'))->invoke($listener, $event, $logger);
			return $this->trace;
		}

		$listener->handle($event);

		return $this->trace;

	}//end runCase()

	/**
	 * The calls up to and including the username search.
	 *
	 * @return array
	 */
	private static function upToUsernameSearch(): array {
		return [
			['container.get', LoggerInterface::class],
			['user.getUID'],
			['log', 'info', '[UserProfileUpdatedEventListener] Syncing user profile changes to contactpersoon', ['userId' => 'alice', 'relevantChanges' => ['firstName', 'email']]],
			['container.get', SettingsService::class],
			['getVoorzieningenConfig'],
			['user.getUID'],
			['searchObjects', ['@self' => ['register' => 7, 'schema' => 8], 'username' => 'alice', '_limit' => 5], false, true],
		];

	}//end upToUsernameSearch()

	/**
	 * The calls of one e-mail fallback search.
	 *
	 * @param string $candidate The e-mail candidate.
	 *
	 * @return array
	 */
	private static function emailSearch(string $candidate): array {
		return [
			['log', 'debug', '[UserProfileUpdatedEventListener] Username lookup failed, trying email fallback', ['userId' => 'alice', 'emailCandidate' => $candidate]],
			['searchObjects', ['@self' => ['register' => 7, 'schema' => 8], '_search' => $candidate, '_limit' => 5], false, true],
		];

	}//end emailSearch()

	/**
	 * The calls of a patch that is written.
	 *
	 * @param string $uuid   The contactpersoon uuid.
	 * @param array  $patch  The patch.
	 * @param array  $stored The full stored payload.
	 *
	 * @return array
	 */
	private static function written(string $uuid, array $patch, array $stored): array {
		return [
			['log', 'info', '[UserProfileUpdatedEventListener] Patching contactpersoon object', ['userId' => 'alice', 'contactpersoonId' => $uuid, 'patch' => $patch]],
			['saveObject', $stored, 'reg-c', 'schema-c', $uuid, true, false],
			['log', 'info', '[UserProfileUpdatedEventListener] Successfully synced user profile to contactpersoon', ['userId' => 'alice', 'contactpersoonId' => $uuid, 'patchedFields' => array_keys($patch)]],
		];

	}//end written()

	/**
	 * A contactpersoon found by username gets the changed fields, the first result wins.
	 *
	 * @return void
	 */
	public function testContactFoundByUsernameIsPatched(): void {
		$trace = $this->runCase(
			plan: [
				'byUsername' => [
					$this->contact(data: ['username' => 'alice', 'voornaam' => 'Old', 'notificaties' => 'x'], uuid: 'c-1'),
					$this->contact(data: ['username' => 'alice'], uuid: 'c-2'),
				],
			]
		);

		$patch = ['voornaam' => 'Alice', 'e-mailadres' => ''];
		$this->assertSame(
			array_merge(
				self::upToUsernameSearch(),
				[['contact.getObject', 'c-1']],
				self::written(uuid: 'c-1', patch: $patch, stored: ['username' => 'alice', 'voornaam' => 'Alice', 'notificaties' => 'x', 'e-mailadres' => ''])
			),
			$trace
		);

	}//end testContactFoundByUsernameIsPatched()

	/**
	 * The e-mail fallback matches the stored address case-insensitively and backfills the username.
	 *
	 * @return void
	 */
	public function testContactFoundByEmailIsPatchedWithUsername(): void {
		$trace = $this->runCase(
			plan: [
				'bySearch' => [
					'Alice@Example.com' => [
						$this->contact(data: ['e-mailadres' => 'someone-else@example.com'], uuid: 'c-other'),
						$this->contact(data: ['email' => 'alice@example.COM'], uuid: 'c-mail'),
					],
				],
			]
		);

		$patch = ['voornaam' => 'Alice', 'e-mailadres' => '', 'username' => 'alice'];
		$this->assertSame(
			array_merge(
				self::upToUsernameSearch(),
				[['user.getEMailAddress']],
				self::emailSearch(candidate: 'Alice@Example.com'),
				[['contact.getObject', 'c-other'], ['contact.getObject', 'c-mail'], ['contact.getObject', 'c-mail']],
				[['log', 'info', '[UserProfileUpdatedEventListener] Backfilling username on contactpersoon', ['userId' => 'alice', 'contactpersoonId' => 'c-mail']]],
				self::written(uuid: 'c-mail', patch: $patch, stored: ['email' => 'alice@example.COM', 'voornaam' => 'Alice', 'e-mailadres' => '', 'username' => 'alice'])
			),
			$trace
		);

	}//end testContactFoundByEmailIsPatchedWithUsername()

	/**
	 * Every candidate is tried; `e-mailadres` wins over `email`; no match is logged and nothing is written.
	 *
	 * @return void
	 */
	public function testNoMatchingContactWritesNothing(): void {
		$trace = $this->runCase(
			plan: [
				'bySearch' => [
					'Alice@Example.com' => [$this->contact(data: ['e-mailadres' => 'other@example.com', 'email' => 'alice@example.com'], uuid: 'c-x')],
					'alice'             => 3,
				],
			]
		);

		$this->assertSame(
			array_merge(
				self::upToUsernameSearch(),
				[['user.getEMailAddress']],
				self::emailSearch(candidate: 'Alice@Example.com'),
				[['contact.getObject', 'c-x']],
				self::emailSearch(candidate: 'alice'),
				[['log', 'info', '[UserProfileUpdatedEventListener] No contactpersoon found for user', ['userId' => 'alice', 'register' => '7', 'schema' => '8']]]
			),
			$trace
		);

	}//end testNoMatchingContactWritesNothing()

	/**
	 * A user without an address only tries the user id, once when the address equals it.
	 *
	 * @return void
	 */
	public function testEmptyOrDuplicateCandidatesAreSkipped(): void {
		foreach ([null, '', 'alice'] as $email) {
			$trace = $this->runCase(plan: ['email' => $email]);
			$this->assertSame(
				array_merge(
					self::upToUsernameSearch(),
					[['user.getEMailAddress']],
					self::emailSearch(candidate: 'alice'),
					[['log', 'info', '[UserProfileUpdatedEventListener] No contactpersoon found for user', ['userId' => 'alice', 'register' => '7', 'schema' => '8']]]
				),
				$trace,
				var_export($email, true)
			);
		}

	}//end testEmptyOrDuplicateCandidatesAreSkipped()

	/**
	 * Only the changed mapped fields are patched, a changed field without a new value as ''.
	 *
	 * @return void
	 */
	public function testOnlyChangedFieldsArePatched(): void {
		$trace = $this->runCase(
			plan: [
				'byUsername' => [$this->contact(data: ['username' => 'alice'], uuid: 'c-1')],
				'changes'    => ['role'],
				'newData'    => [],
			]
		);

		$expected    = self::upToUsernameSearch();
		$expected[2] = ['log', 'info', '[UserProfileUpdatedEventListener] Syncing user profile changes to contactpersoon', ['userId' => 'alice', 'relevantChanges' => ['role']]];
		$this->assertSame(
			array_merge(
				$expected,
				[['contact.getObject', 'c-1']],
				[
					['log', 'info', '[UserProfileUpdatedEventListener] Patching contactpersoon object', ['userId' => 'alice', 'contactpersoonId' => 'c-1', 'patch' => ['role' => '']]],
					['saveObject', ['username' => 'alice', 'role' => ''], 'reg-c', 'schema-c', 'c-1', true, false],
					['log', 'info', '[UserProfileUpdatedEventListener] Successfully synced user profile to contactpersoon', ['userId' => 'alice', 'contactpersoonId' => 'c-1', 'patchedFields' => ['role']]],
				]
			),
			$trace
		);

		$trace = $this->runCase(
			plan: [
				'byUsername' => [$this->contact(data: ['username' => 'alice'], uuid: 'c-1')],
				'changes'    => ['firstName', 'unrelated'],
				'newData'    => ['firstName' => 'Alice'],
			]
		);
		$this->assertSame(['log', 'info', '[UserProfileUpdatedEventListener] Patching contactpersoon object', ['userId' => 'alice', 'contactpersoonId' => 'c-1', 'patch' => ['voornaam' => 'Alice']]], $trace[8]);

	}//end testOnlyChangedFieldsArePatched()

	/**
	 * A count answered by the username search is not a contactpersoon: the type error escapes the listener.
	 *
	 * @return void
	 */
	public function testCountAnsweredByUsernameSearchEscapes(): void {
		$this->expectException(\TypeError::class);
		$this->runCase(plan: ['byUsername' => 3]);

	}//end testCountAnsweredByUsernameSearchEscapes()

	/**
	 * A contactpersoon with nothing to patch is left alone.
	 *
	 * @return void
	 */
	public function testNothingToPatchWritesNothing(): void {
		$trace = $this->runCase(
			plan: [
				'byUsername' => [$this->contact(data: ['username' => 'alice'], uuid: 'c-1')],
				'changes'    => ['unrelated'],
			],
			direct: true
		);

		$this->assertSame(
			array_merge(
				array_slice(self::upToUsernameSearch(), 3),
				[['contact.getObject', 'c-1']],
				[['log', 'debug', '[UserProfileUpdatedEventListener] No fields to patch on contactpersoon', ['userId' => 'alice']]]
			),
			$trace
		);

	}//end testNothingToPatchWritesNothing()

	/**
	 * A missing register or contact schema stops the sync before any search.
	 *
	 * @return void
	 */
	public function testMissingConfigurationStopsTheSync(): void {
		foreach ([['register' => '7'], ['contactpersoon_schema' => '8'], ['register' => '0', 'contactpersoon_schema' => '8']] as $config) {
			$trace = $this->runCase(plan: ['config' => $config]);
			$this->assertSame(
				array_merge(
					array_slice(self::upToUsernameSearch(), 0, 5),
					[['log', 'warning', '[UserProfileUpdatedEventListener] Voorzieningen config missing register or contactpersoon_schema', []]]
				),
				$trace
			);
		}

	}//end testMissingConfigurationStopsTheSync()
}//end class
