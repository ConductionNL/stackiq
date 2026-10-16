<?php
/**
 * Unit tests for the RepointDashboardWidgetId repair step.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/app-id-rename/spec.md#requirement-externally-owned-identifiers-stay-frozen
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use Closure;
use OCA\Stackiq\Dashboard\ConceptOrganisatiesWidget;
use OCA\Stackiq\Repair\RepointDashboardWidgetId;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The renamed widget id is rewritten to the frozen one in every user's dashboard layout.
 *
 * @spec openspec/specs/app-id-rename/spec.md#requirement-externally-owned-identifiers-stay-frozen
 */
class RepointDashboardWidgetIdTest extends TestCase {

	private const RENAMED = RepointDashboardWidgetId::RENAMED_WIDGET_ID;
	private const FROZEN  = RepointDashboardWidgetId::FROZEN_WIDGET_ID;
	private const APP     = RepointDashboardWidgetId::DASHBOARD_APP_ID;
	private const KEY     = RepointDashboardWidgetId::LAYOUT_KEY;


	/**
	 * A user manager that hands the given user ids to callForSeenUsers.
	 *
	 * @param array<int,string> $userIds The user ids to yield.
	 *
	 * @return IUserManager
	 */
	private function userManagerYielding(array $userIds): IUserManager {
		$users = [];
		foreach ($userIds as $uid) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn(value: $uid);
			$users[] = $user;
		}

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			callback: static function (Closure $callback) use ($users): void {
				foreach ($users as $user) {
					$callback($user);
				}
			}
		);

		return $userManager;
	}//end userManagerYielding()


	/**
	 * A config whose dashboard layouts are the given map and whose writes are recorded.
	 *
	 * @param array<string,string> $layouts Layout per user id.
	 * @param array<string,string> $written Receives "uid/app/key" => value.
	 *
	 * @return IConfig
	 */
	private function configWith(array $layouts, array &$written): IConfig {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			callback: static function (string $uid, string $app, string $key, string $default = '') use ($layouts): string {
				if ($app !== self::APP || $key !== self::KEY) {
					return $default;
				}

				return $layouts[$uid] ?? $default;
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			callback: static function (string $uid, string $app, string $key, string $value) use (&$written): void {
				$written["$uid/$app/$key"] = $value;
			}
		);

		return $config;
	}//end configWith()


	/**
	 * The step under test over the given config and user ids.
	 *
	 * @param IConfig           $config  The config double.
	 * @param array<int,string> $userIds The user ids to walk.
	 *
	 * @return RepointDashboardWidgetId
	 */
	private function step(IConfig $config, array $userIds): RepointDashboardWidgetId {
		return new RepointDashboardWidgetId(
			config: $config,
			userManager: $this->userManagerYielding(userIds: $userIds),
			logger: new NullLogger()
		);
	}//end step()


	/**
	 * The frozen id the step writes is the id the widget actually registers under.
	 *
	 * @return void
	 */
	public function testFrozenIdMatchesTheWidget(): void {
		$widget = new ConceptOrganisatiesWidget(
			l10n: $this->createMock(originalClassName: IL10N::class),
			url: $this->createMock(originalClassName: IURLGenerator::class)
		);

		$this->assertSame(expected: $widget->getId(), actual: self::FROZEN);
		$this->assertNotSame(expected: self::FROZEN, actual: self::RENAMED);
	}//end testFrozenIdMatchesTheWidget()


	/**
	 * The step reports a name that says what it does.
	 *
	 * @return void
	 */
	public function testGetNameDescribesTheStep(): void {
		$step = $this->step(config: $this->createMock(originalClassName: IConfig::class), userIds: []);

		$this->assertStringContainsString(needle: 'widget', haystack: $step->getName());
	}//end testGetNameDescribesTheStep()


	/**
	 * The renamed id is replaced in place, other widgets and their order untouched.
	 *
	 * @return void
	 */
	public function testRewritesTheRenamedIdInPlace(): void {
		$written = [];
		$config  = $this->configWith(
			layouts: [
				'alice' => 'recommendations,' . self::RENAMED . ',activity',
				'bob'   => self::RENAMED,
			],
			written: $written
		);

		$this->step(config: $config, userIds: ['alice', 'bob'])->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(
			expected: [
				'alice/' . self::APP . '/' . self::KEY => 'recommendations,' . self::FROZEN . ',activity',
				'bob/' . self::APP . '/' . self::KEY   => self::FROZEN,
			],
			actual: $written
		);
	}//end testRewritesTheRenamedIdInPlace()


	/**
	 * A layout that holds both ids ends up with the frozen one once.
	 *
	 * @return void
	 */
	public function testDoesNotDuplicateTheFrozenId(): void {
		$written = [];
		$config  = $this->configWith(layouts: ['alice' => self::FROZEN . ',activity,' . self::RENAMED], written: $written);

		$this->step(config: $config, userIds: ['alice'])->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: ['alice/' . self::APP . '/' . self::KEY => self::FROZEN . ',activity'], actual: $written);
	}//end testDoesNotDuplicateTheFrozenId()


	/**
	 * Layouts without the renamed id, including empty ones, are never written.
	 *
	 * @return void
	 */
	public function testLeavesOtherLayoutsAlone(): void {
		$written = [];
		$config  = $this->configWith(
			layouts: [
				'alice' => 'recommendations,' . self::FROZEN,
				'bob'   => '',
				// A superstring of the renamed id is a different widget.
				'carol' => self::RENAMED . '_v2',
			],
			written: $written
		);

		$this->step(config: $config, userIds: ['alice', 'bob', 'carol'])->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [], actual: $written);
	}//end testLeavesOtherLayoutsAlone()


	/**
	 * A user whose preference cannot be read is skipped without aborting the walk.
	 *
	 * @return void
	 */
	public function testOneUnreadableUserDoesNotAbortTheWalk(): void {
		$written = [];
		$config  = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			callback: static function (string $uid, string $app, string $key, string $default = ''): string {
				if ($uid === 'broken') {
					throw new RuntimeException(message: 'backend down');
				}

				if ($uid === 'alice') {
					return self::RENAMED;
				}

				return $default;
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			callback: static function (string $uid, string $app, string $key, string $value) use (&$written): void {
				$written["$uid/$app/$key"] = $value;
			}
		);

		$this->step(config: $config, userIds: ['broken', 'alice'])->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(
			expected: ['alice/' . self::APP . '/' . self::KEY => self::FROZEN],
			actual: $written,
			message: 'the user after the unreadable one must still be re-pointed'
		);
	}//end testOneUnreadableUserDoesNotAbortTheWalk()


	/**
	 * A failure to enumerate users at all is warned, not thrown.
	 *
	 * @return void
	 */
	public function testUserEnumerationFailureIsWarnedNotThrown(): void {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->expects($this->never())->method('setUserValue');

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('callForSeenUsers')->willThrowException(exception: new RuntimeException(message: 'no backend'));

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		(new RepointDashboardWidgetId(config: $config, userManager: $userManager, logger: new NullLogger()))->run(output: $output);
	}//end testUserEnumerationFailureIsWarnedNotThrown()
}//end class
