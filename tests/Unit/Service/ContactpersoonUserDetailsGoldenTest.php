<?php

/**
 * Golden tests for ContactpersoonService::getContactPersonsWithUserDetailsForOrganization().
 *
 * Pins the organisation query, the user details spliced into each contact
 * person (with, without and with an unknown username), the fallback to the
 * unchanged contact person when reading one fails, the log calls and the
 * rethrow of a failure outside the per-contact loop, so splitting the method
 * keeps every outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IImage;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An account that also reports its free quota.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 */
interface ContactpersoonUserWithFreeQuota extends IUser {

	/**
	 * The free quota of the account.
	 *
	 * @return mixed
	 */
	public function getFreeQuota();

}//end interface

/**
 * Golden tests for the contact persons with user details of an organisation.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ContactpersoonUserDetailsGoldenTest extends TestCase {

	/**
	 * Every logger call as [level, message, context].
	 *
	 * @var array<int, array>
	 */
	private array $logs = [];

	/**
	 * Every query passed to searchObjects().
	 *
	 * @var array<int, array>
	 */
	private array $queries = [];

	/**
	 * The contact persons searchObjects() returns.
	 *
	 * @var array<int, object>
	 */
	private array $contactPersons = [];

	/**
	 * Whether resolving the user manager fails.
	 *
	 * @var boolean
	 */
	private bool $userManagerFails = false;

	/**
	 * Build a contact person double.
	 *
	 * @param string $id The contact person id.
	 * @param array $data The contact person data.
	 * @param boolean $failsOnSet Whether setObject() throws.
	 *
	 * @return object The contact person.
	 */
	private function contactPerson(string $id, array $data, bool $failsOnSet = false): object {
		return new class($id, $data, $failsOnSet) {
			/**
			 * Build the double.
			 *
			 * @param string $id The id.
			 * @param array $data The data.
			 * @param boolean $failsOnSet Whether setObject() throws.
			 */
			public function __construct(private string $id, private array $data, private bool $failsOnSet) {
			}

			/**
			 * The id.
			 *
			 * @return string
			 */
			public function getId(): string {
				return $this->id;
			}

			/**
			 * The data.
			 *
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * Replace the data.
			 *
			 * @param array $data The new data.
			 *
			 * @return void
			 */
			public function setObject(array $data): void {
				if ($this->failsOnSet === true) {
					throw new \RuntimeException('cannot set ' . $this->id);
				}

				$this->data = $data;
			}
		};
	}//end contactPerson()

	/**
	 * Build a user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid): IUser {
		$avatar = $this->createMock(IImage::class);
		$avatar->method('data')->willReturn('avatar-' . $uid);

		// The code under test also reads getFreeQuota(), which the published
		// IUser interface does not declare, so the double declares it.
		$user = $this->createMock(ContactpersoonUserWithFreeQuota::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($uid . '@example.org');
		$user->method('getDisplayName')->willReturn('Name ' . $uid);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getLastLogin')->willReturn(42);
		$user->method('getBackendClassName')->willReturn('Database');
		$user->method('getHome')->willReturn('/home/' . $uid);
		$user->method('getAvatarImage')->willReturnCallback(
			function (int $size) use ($avatar): IImage {
				$this->assertSame(64, $size);
				return $avatar;
			}
		);
		$user->method('getQuota')->willReturn('1 GB');
		$user->method('getFreeQuota')->willReturn('512 MB');
		return $user;
	}//end user()

	/**
	 * Build the service under test.
	 *
	 * @return ContactpersoonService
	 */
	private function service(): ContactpersoonService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$this->queries[] = $query;
				return $this->contactPersons;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => $uid === 'known' ? $this->user(uid: 'known') : null
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $userManager) {
				if ($id === 'OCP\IUserManager') {
					if ($this->userManagerFails === true) {
						throw new \RuntimeException('no user manager');
					}

					return $userManager;
				}

				return $objectService;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => '4', 'contactpersoon_schema' => '9']);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['trace']);
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new ContactpersoonService(
			contactPersonHandler: $this->createMock(ContactPersonHandler::class),
			groupHandler: $this->createMock(GroupHandler::class),
			hierarchyHandler: $this->createMock(HierarchyHandler::class),
			logger: $logger,
			container: $container,
			appManager: $appManager,
			config: $this->createMock(IAppConfig::class),
			settingsService: $settings
		);
	}//end service()

	/**
	 * No contact persons: an empty list, and no user lookup.
	 *
	 * @return void
	 */
	public function testNoContactPersonsGivesAnEmptyList(): void {
		$this->userManagerFails = true;

		$result = $this->service()->getContactPersonsWithUserDetailsForOrganization('org-1');

		$this->assertSame([], $result);
		$this->assertSame(
			[['@self' => ['register' => 4, 'schema' => 9], 'organisation' => 'org-1', '_limit' => 500]],
			$this->queries
		);
		$this->assertSame(
			[
				['info', 'ContactpersoonService: Getting contact persons with user details for organization', ['organizationUuid' => 'org-1']],
				['info', 'ContactpersoonService: No contact persons found for organization', ['organizationUuid' => 'org-1']],
			],
			$this->logs
		);
	}//end testNoContactPersonsGivesAnEmptyList()

	/**
	 * Each contact person gets its user details, or null without a (known) user;
	 * a contact person that cannot be enhanced is kept unchanged.
	 *
	 * @return void
	 */
	public function testUserDetailsAreSplicedIntoEachContactPerson(): void {
		$known = $this->contactPerson(id: 'c1', data: ['username' => 'known', 'naam' => 'A']);
		$nameless = $this->contactPerson(id: 'c2', data: ['naam' => 'B']);
		$unknown = $this->contactPerson(id: 'c3', data: ['username' => 'ghost']);
		$failing = $this->contactPerson(id: 'c4', data: ['username' => 'known', 'userDetails' => 'stale'], failsOnSet: true);
		$this->contactPersons = [$known, $nameless, $unknown, $failing];

		$result = $this->service()->getContactPersonsWithUserDetailsForOrganization('org-1');

		$this->assertCount(4, $result);
		$this->assertNotSame($known, $result[0]);
		$this->assertSame(['username' => 'known', 'naam' => 'A'], $known->getObject());
		$this->assertSame(
			[
				'username' => 'known',
				'naam' => 'A',
				'userDetails' => [
					'uid' => 'known',
					'email' => 'known@example.org',
					'displayName' => 'Name known',
					'enabled' => true,
					'lastLogin' => 42,
					'backend' => 'Database',
					'home' => '/home/known',
					'avatarImage' => 'avatar-known',
					'quota' => '1 GB',
					'freeQuota' => '512 MB',
				],
			],
			$result[0]->getObject()
		);
		$this->assertSame(['naam' => 'B', 'userDetails' => null], $result[1]->getObject());
		$this->assertSame(['username' => 'ghost', 'userDetails' => null], $result[2]->getObject());
		$this->assertSame($failing, $result[3]);

		$this->assertSame(
			[
				['info', 'ContactpersoonService: Getting contact persons with user details for organization', ['organizationUuid' => 'org-1']],
				['info', 'ContactpersoonService: Found contact persons, fetching user details', ['organizationUuid' => 'org-1', 'contactPersonCount' => 4]],
				['debug', 'ContactpersoonService: Fetched user details', ['contactPersonId' => 'c1', 'username' => 'known', 'userEnabled' => true]],
				['debug', 'ContactpersoonService: No username found for contact person', ['contactPersonId' => 'c2']],
				['warning', 'ContactpersoonService: User not found for username', ['contactPersonId' => 'c3', 'username' => 'ghost']],
				['debug', 'ContactpersoonService: Fetched user details', ['contactPersonId' => 'c4', 'username' => 'known', 'userEnabled' => true]],
				['error', 'ContactpersoonService: Failed to process contact person', ['contactPersonId' => 'c4', 'organizationUuid' => 'org-1', 'error' => 'cannot set c4']],
				['info', 'ContactpersoonService: Successfully enhanced contact persons with user details', ['organizationUuid' => 'org-1', 'totalContactPersons' => 4, 'contactPersonsWithUserDetails' => 2]],
			],
			$this->logs
		);
	}//end testUserDetailsAreSplicedIntoEachContactPerson()

	/**
	 * A failure outside the per-contact loop is logged and rethrown.
	 *
	 * @return void
	 */
	public function testAFailureOutsideTheLoopIsLoggedAndRethrown(): void {
		$this->contactPersons = [$this->contactPerson(id: 'c1', data: ['username' => 'known'])];
		$this->userManagerFails = true;
		$service = $this->service();

		try {
			$service->getContactPersonsWithUserDetailsForOrganization('org-2');
			$this->fail('Expected the failure to be rethrown');
		} catch (\RuntimeException $e) {
			$this->assertSame('no user manager', $e->getMessage());
		}

		$this->assertSame(
			['error', 'ContactpersoonService: Failed to get contact persons with user details for organization', ['organizationUuid' => 'org-2', 'error' => 'no user manager']],
			$this->logs[2]
		);
		$this->assertCount(3, $this->logs);
	}//end testAFailureOutsideTheLoopIsLoggedAndRethrown()

}//end class
