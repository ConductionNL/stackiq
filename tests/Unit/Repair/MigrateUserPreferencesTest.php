<?php

/**
 * Unit tests for MigrateUserPreferences.
 *
 * The load-bearing assertion here is a NEGATIVE one: the step must enumerate
 * users with `callForSeenUsers()` + `getUserKeys()` and must NOT reach for
 * `getUsersForUserValue()`. That method matches on a VALUE, and this app's
 * `pref_*` keys hold arbitrary user-chosen state — over an open value set it
 * matches nothing, migrates nothing, and reports success. A test that only
 * checked "values were copied" would pass against a broken implementation that
 * was handed a fixture value it happened to match.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use Closure;
use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Repair\MigrateUserPreferences;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Stackiq\Repair\MigrateUserPreferences
 */
class MigrateUserPreferencesTest extends TestCase {
	/**
	 * The legacy app id every stored preference was written under.
	 *
	 * @var string
	 */
	private const LEGACY = 'softwarecatalog';

	/**
	 * Build a user manager whose callForSeenUsers yields the given user ids.
	 *
	 * @param list<string> $userIds The user ids to yield.
	 *
	 * @return IUserManager The stubbed user manager.
	 */
	private function userManagerYielding(array $userIds): IUserManager {
		$users = [];
		foreach ($userIds as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			static function (Closure $callback) use ($users): void {
				foreach ($users as $user) {
					$callback($user);
				}
			}
		);

		return $userManager;
	}//end userManagerYielding()

	/**
	 * The step reports a name that says what it does.
	 *
	 * @return void
	 */
	public function testGetNameDescribesTheMigration(): void {
		$step = new MigrateUserPreferences(
			$this->createMock(IConfig::class),
			$this->createMock(IUserManager::class),
			new NullLogger()
		);

		$this->assertStringContainsString('softwarecatalog', $step->getName());
		$this->assertStringContainsString('stackiq', $step->getName());
	}//end testGetNameDescribesTheMigration()

	/**
	 * The legacy app id is exactly the pre-rename app id.
	 *
	 * @return void
	 */
	public function testLegacyAppIdIsTheOldAppId(): void {
		$this->assertSame(self::LEGACY, MigrateUserPreferences::LEGACY_APP_ID);
		$this->assertNotSame(Application::APP_ID, MigrateUserPreferences::LEGACY_APP_ID);
	}//end testLegacyAppIdIsTheOldAppId()

	/**
	 * Every seen user's legacy preferences land under the new app id.
	 *
	 * @return void
	 */
	public function testCopiesEveryLegacyPreferenceForEverySeenUser(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturnMap(
			[
				['alice', self::LEGACY, ['pref_applications-view', 'pref_theme']],
				['bob', self::LEGACY, ['pref_applications-view']],
			]
		);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $default = ''): string {
				if ($app !== self::LEGACY) {
					// Nothing under the new app id yet.
					return '';
				}

				return match ("$uid/$key") {
					'alice/pref_applications-view' => 'table',
					'alice/pref_theme' => 'dark',
					'bob/pref_applications-view' => 'cards',
					default => '',
				};
			}
		);

		$written = [];
		$config->method('setUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $value) use (&$written): void {
				$written[] = "$uid/$app/$key=$value";
			}
		);

		$step = new MigrateUserPreferences($config, $this->userManagerYielding(['alice', 'bob']), new NullLogger());
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(
			[
				'alice/' . Application::APP_ID . '/pref_applications-view=table',
				'alice/' . Application::APP_ID . '/pref_theme=dark',
				'bob/' . Application::APP_ID . '/pref_applications-view=cards',
			],
			$written
		);
	}//end testCopiesEveryLegacyPreferenceForEverySeenUser()

	/**
	 * `getUsersForUserValue()` is NEVER used to enumerate.
	 *
	 * It needs a VALUE to match, so over the open value set `pref_*` holds it
	 * migrates nothing and still reports success — the exact shape of failure
	 * this whole step exists to avoid.
	 *
	 * @return void
	 */
	public function testDoesNotEnumerateByValue(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn(['pref_applications-view']);
		$config->method('getUserValue')->willReturn('');

		$config->expects($this->never())->method('getUsersForUserValue');

		$step = new MigrateUserPreferences($config, $this->userManagerYielding(['alice']), new NullLogger());
		$step->run($this->createMock(IOutput::class));
	}//end testDoesNotEnumerateByValue()

	/**
	 * A value already present under the new app id is left alone.
	 *
	 * @return void
	 */
	public function testDoesNotClobberAValueTheNewNamespaceAlreadyHolds(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn(['pref_applications-view']);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, string $default = ''): string
				=> $app === self::LEGACY ? 'table' : 'cards'
		);

		$config->expects($this->never())->method('setUserValue');

		$step = new MigrateUserPreferences($config, $this->userManagerYielding(['alice']), new NullLogger());
		$step->run($this->createMock(IOutput::class));
	}//end testDoesNotClobberAValueTheNewNamespaceAlreadyHolds()

	/**
	 * The legacy rows are never deleted.
	 *
	 * @return void
	 */
	public function testIsNonDestructive(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn(['pref_applications-view']);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, string $default = ''): string
				=> $app === self::LEGACY ? 'table' : ''
		);

		$config->expects($this->never())->method('deleteUserValue');

		$step = new MigrateUserPreferences($config, $this->userManagerYielding(['alice']), new NullLogger());
		$step->run($this->createMock(IOutput::class));
	}//end testIsNonDestructive()

	/**
	 * A user with no legacy preferences is skipped without writes.
	 *
	 * @return void
	 */
	public function testSkipsUsersWithNoLegacyPreferences(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn([]);

		$config->expects($this->never())->method('setUserValue');

		$step = new MigrateUserPreferences($config, $this->userManagerYielding(['alice', 'bob']), new NullLogger());
		$step->run($this->createMock(IOutput::class));
	}//end testSkipsUsersWithNoLegacyPreferences()

	/**
	 * A throw NEVER escapes into the installer.
	 *
	 * The step runs under `<install>` — the only hook that fires on the fresh
	 * install an app-id rename performs — so an escaping throw aborts the
	 * install and the app never enables at all.
	 *
	 * @return void
	 */
	public function testAThrowNeverEscapesTheInstaller(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willThrowException(new RuntimeException('database gone'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step = new MigrateUserPreferences($config, $this->userManagerYielding(['alice']), new NullLogger());
		$step->run($output);
	}//end testAThrowNeverEscapesTheInstaller()

	/**
	 * A throw during the WRITE is caught too, not only during the reads.
	 *
	 * @return void
	 */
	public function testAThrowDuringTheWriteIsAlsoCaught(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn(['pref_applications-view']);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, string $default = ''): string
				=> $app === self::LEGACY ? 'table' : ''
		);
		$config->method('setUserValue')->willThrowException(new RuntimeException('write failed'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step = new MigrateUserPreferences($config, $this->userManagerYielding(['alice']), new NullLogger());
		$step->run($output);
	}//end testAThrowDuringTheWriteIsAlsoCaught()
}//end class
