<?php

/**
 * Characterisation test for the admin group members lookup of
 * OrganisatieService and OrganizationSyncService.
 *
 * Pins, for both: the uids of the admin group's members, an empty list
 * when there is no admin group, and an empty list plus each service's own
 * error line when the group manager cannot be resolved.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Characterisation of the admin group members lookup.
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */
class AdminGroupUidsCharacterisationTest extends TestCase {

	/**
	 * The services: class, lookup method and the error line each logs.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: array}>
	 */
	public static function services(): array {
		return [
			'OrganisatieService' => [
				OrganisatieService::class,
				'getAdminGroupUsernames',
				['error', 'OrganisatieService: Failed to get admin users', ['exception' => 'container down']],
			],
			'OrganizationSyncService' => [
				OrganizationSyncService::class,
				'getAdminUsers',
				['error', 'OrganizationSyncService: Failed to get admin users', ['exception' => 'RuntimeException']],
			],
		];

	}//end services()

	/**
	 * The admin group's members come back as uids, in order.
	 *
	 * @param class-string $class  The service.
	 * @param string       $method The lookup.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testTheMembersComeBackAsUids(string $class, string $method): void {
		[$uids, $logged] = $this->lookup(class: $class, method: $method, plan: 'members');
		$this->assertSame(expected: ['admin', 'beheer'], actual: $uids);
		$this->assertSame(expected: [], actual: $logged);

	}//end testTheMembersComeBackAsUids()

	/**
	 * Without an admin group there are no admins.
	 *
	 * @param class-string $class  The service.
	 * @param string       $method The lookup.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testWithoutAnAdminGroupThereAreNone(string $class, string $method): void {
		$this->assertSame(expected: [[], []], actual: $this->lookup(class: $class, method: $method, plan: 'noGroup'));

	}//end testWithoutAnAdminGroupThereAreNone()

	/**
	 * A failing container lookup gives no admins and the service's error line.
	 *
	 * @param class-string $class  The service.
	 * @param string       $method The lookup.
	 * @param array        $error  The error line.
	 *
	 * @return void
	 *
	 * @dataProvider services
	 */
	public function testAFailingLookupGivesNone(string $class, string $method, array $error): void {
		$this->assertSame(expected: [[], [$error]], actual: $this->lookup(class: $class, method: $method, plan: 'throws'));

	}//end testAFailingLookupGivesNone()

	/**
	 * Run one lookup with recording doubles.
	 *
	 * @param class-string $class  The service.
	 * @param string       $method The lookup.
	 * @param string       $plan   members, noGroup or throws.
	 *
	 * @return array{0: array, 1: array}
	 */
	private function lookup(string $class, string $method, string $plan): array {
		$members = [];
		foreach (['admin', 'beheer'] as $uid) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$members[] = $user;
		}

		$group = $this->createMock(originalClassName: IGroup::class);
		$group->method('getUsers')->willReturn($members);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid) => ($gid === 'admin' && $plan === 'members') ? $group : null);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($plan, $groups) {
				if ($plan === 'throws' || $id !== 'OCP\IGroupManager') {
					throw new \RuntimeException('container down');
				}

				return $groups;
			}
		);
		$records = new \ArrayObject();
		$logger  = new class($records) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param \ArrayObject $records The records.
			 */
			public function __construct(private \ArrayObject $records) {
			}

			/**
			 * Record one line, with an exception as its class.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				if (isset($context['exception']) === true && $context['exception'] instanceof \Throwable) {
					$context['exception'] = get_class($context['exception']);
				}

				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};

		$service = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		foreach (['container' => $container, 'logger' => $logger] as $prop => $value) {
			(new \ReflectionProperty(class: $class, property: $prop))->setValue($service, $value);
		}

		$uids = (new \ReflectionMethod(objectOrMethod: $class, method: $method))->invoke($service);
		return [$uids, $records->getArrayCopy()];

	}//end lookup()

}//end class
