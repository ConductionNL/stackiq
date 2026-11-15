<?php
/**
 * Unit tests for ContactPersonUserDetails.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Contactpersoon
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Contactpersoon;

use OCA\Stackiq\Service\Contactpersoon\ContactPersonUserDetails;
use OCP\IImage;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A Nextcloud user that also reports its free quota, as the service reads it.
 */
interface UserWithFreeQuota extends IUser {
	/**
	 * The free quota.
	 *
	 * @return mixed
	 */
	public function getFreeQuota();
}//end interface

/**
 * Contact persons get the details of their Nextcloud account, or keep what
 * they had when there is no account to read.
 *
 * @covers \OCA\Stackiq\Service\Contactpersoon\ContactPersonUserDetails
 */
class ContactPersonUserDetailsTest extends TestCase {
	/**
	 * The log lines written, as [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * A contact person object as OpenRegister hands it over.
	 *
	 * @param int                  $id   The object id.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return object
	 */
	private function contactPerson(int $id, array $data): object {
		return new class($id, $data) {
			/**
			 * Constructor.
			 *
			 * @param int                  $id   The object id.
			 * @param array<string, mixed> $data The object data.
			 */
			public function __construct(private int $id, private array $data) {
			}//end __construct()

			/**
			 * The object id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}//end getId()

			/**
			 * The object data.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()

			/**
			 * Replace the object data.
			 *
			 * @param array<string, mixed> $data The data.
			 *
			 * @return void
			 */
			public function setObject(array $data): void {
				$this->data = $data;
			}//end setObject()
		};
	}//end contactPerson()

	/**
	 * The service over a user manager double, with a logger that records.
	 *
	 * @param IUserManager $users The user manager.
	 *
	 * @return ContactPersonUserDetails
	 */
	private function service(IUserManager $users): ContactPersonUserDetails {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new ContactPersonUserDetails(logger: $logger, userManager: $users);
	}//end service()

	/**
	 * A found account adds every user detail to a copy; the original is unchanged.
	 *
	 * @return void
	 */
	public function testAFoundAccountAddsItsDetailsToACopy(): void {
		$avatar = $this->createMock(IImage::class);
		$avatar->method('data')->willReturn('png-bytes');
		$user = $this->createMock(UserWithFreeQuota::class);
		$user->method('getUID')->willReturn('jdoe');
		$user->method('getEMailAddress')->willReturn('jdoe@example.org');
		$user->method('getDisplayName')->willReturn('J. Doe');
		$user->method('isEnabled')->willReturn(true);
		$user->method('getLastLogin')->willReturn(1700000000);
		$user->method('getBackendClassName')->willReturn('Database');
		$user->method('getHome')->willReturn('/data/jdoe');
		$user->expects($this->once())->method('getAvatarImage')->with(64)->willReturn($avatar);
		$user->method('getQuota')->willReturn('5 GB');
		$user->method('getFreeQuota')->willReturn('4 GB');
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->once())->method('get')->with('jdoe')->willReturn($user);
		$original = $this->contactPerson(id: 7, data: ['username' => 'jdoe', 'role' => 'owner']);

		[$enhanced] = $this->service($users)->enhanceAll(contactPersons: [$original], organizationUuid: 'org-1');

		$this->assertNotSame($original, $enhanced);
		$this->assertSame(['username' => 'jdoe', 'role' => 'owner'], $original->getObject());
		$this->assertSame(
			[
				'username' => 'jdoe',
				'role' => 'owner',
				'userDetails' => [
					'uid' => 'jdoe',
					'email' => 'jdoe@example.org',
					'displayName' => 'J. Doe',
					'enabled' => true,
					'lastLogin' => 1700000000,
					'backend' => 'Database',
					'home' => '/data/jdoe',
					'avatarImage' => 'png-bytes',
					'quota' => '5 GB',
					'freeQuota' => '4 GB',
				],
			],
			$enhanced->getObject()
		);
		$this->assertSame(
			[['debug', 'ContactpersoonService: Fetched user details', ['contactPersonId' => 7, 'username' => 'jdoe', 'userEnabled' => true]]],
			$this->logs
		);
	}//end testAFoundAccountAddsItsDetailsToACopy()

	/**
	 * Without a username, or for an unknown one, userDetails is null and the
	 * reason is logged.
	 *
	 * @return void
	 */
	public function testNoUsernameOrAnUnknownUserGivesNullDetails(): void {
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->once())->method('get')->with('ghost')->willReturn(null);

		$enhanced = $this->service($users)->enhanceAll(
			contactPersons: [$this->contactPerson(id: 1, data: ['name' => 'No account']), $this->contactPerson(id: 2, data: ['username' => 'ghost'])],
			organizationUuid: 'org-1'
		);

		$this->assertSame(['name' => 'No account', 'userDetails' => null], $enhanced[0]->getObject());
		$this->assertSame(['username' => 'ghost', 'userDetails' => null], $enhanced[1]->getObject());
		$this->assertSame(
			[
				['debug', 'ContactpersoonService: No username found for contact person', ['contactPersonId' => 1]],
				['warning', 'ContactpersoonService: User not found for username', ['contactPersonId' => 2, 'username' => 'ghost']],
			],
			$this->logs
		);
	}//end testNoUsernameOrAnUnknownUserGivesNullDetails()

	/**
	 * A lookup that throws keeps the contact person as it was and logs the
	 * failure; the others are still enhanced.
	 *
	 * @return void
	 */
	public function testAFailingLookupKeepsTheOriginal(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willThrowException(new RuntimeException('backend down'));
		$failing = $this->contactPerson(id: 3, data: ['username' => 'jdoe']);
		$plain = $this->contactPerson(id: 4, data: []);

		$enhanced = $this->service($users)->enhanceAll(contactPersons: [$failing, $plain], organizationUuid: 'org-9');

		$this->assertSame($failing, $enhanced[0]);
		$this->assertSame(['userDetails' => null], $enhanced[1]->getObject());
		$this->assertContains(
			['error', 'ContactpersoonService: Failed to process contact person', ['contactPersonId' => 3, 'organizationUuid' => 'org-9', 'error' => 'backend down']],
			$this->logs
		);
		$this->assertSame([], $this->service($users)->enhanceAll(contactPersons: [], organizationUuid: 'org-9'));
	}//end testAFailingLookupKeepsTheOriginal()
}//end class
