<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The first contact of an organisation is not added to the organisation
 * admin groups automatically (stackiq#1136).
 *
 * Commit bc4dc9ea switched that automatic assignment off on purpose ("users
 * should be assigned groups explicitly via the admin UI") by making
 * SettingsService::getOrganizationAdminGroups() return an empty list. That
 * also discarded the saved setting everywhere else. With the getter reading
 * the saved list again, this test pins the deliberate choice: even when
 * groups are saved, a first contact is not put into them.
 *
 * Spies count the calls instead of expects($this->never()), because
 * assignUserGroups() wraps its body in catch (\Exception) and would swallow
 * a PHPUnit expectation failure.
 *
 * @spec openspec/specs/sc-handlers/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ContactPersonHandlerFirstContactTest extends TestCase {

	/**
	 * A first contact is added to no organisation admin group, even when groups are saved.
	 *
	 * @return void
	 */
	public function testFirstContactIsNotAddedToSavedOrganisationAdminGroups(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getOrganizationAdminGroups')->willReturn(['org-admins']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($settingsService) {
				if ($id === 'OCA\Stackiq\Service\SettingsService') {
					return $settingsService;
				}

				throw new \RuntimeException('Unexpected container lookup: ' . $id);
			}
		);

		$addedTo = [];
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(false);
		$group->method('addUser')->willReturnCallback(
			static function () use (&$addedTo): void {
				$addedTo[] = 'org-admins';
			}
		);

		$lookedUp = [];
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			static function (string $gid) use (&$lookedUp, $group) {
				$lookedUp[] = $gid;
				return $group;
			}
		);

		$handler = new ContactPersonHandler(
			$this->createMock(IUserManager::class),
			$this->createMock(ISecureRandom::class),
			$groupManager,
			$this->createMock(IAppConfig::class),
			$container,
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SymfonyEmailService::class),
			$this->createMock(IConfig::class)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('first-contact');

		$method = new ReflectionMethod($handler, 'assignUserGroups');
		$method->setAccessible(true);
		$method->invoke($handler, $user, ['roles' => []], true);

		$this->assertSame(
			[],
			$addedTo,
			'A first contact must not be added to the saved organisation admin groups automatically.'
		);
		$this->assertNotContains('org-admins', $lookedUp);
	}//end testFirstContactIsNotAddedToSavedOrganisationAdminGroups()
}//end class
