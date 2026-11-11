<?php

/**
 * Tests for the inline contact persons the organisation sync processes.
 *
 * @category  Tests
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An inline contact person that names an existing account outside the organisation is skipped.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 */
final class OrganizationSyncNestedContactsTest extends TestCase {
	/**
	 * Logged messages, in order.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	public function testAnInlineContactNamingAnAccountOutsideTheOrganisationIsSkipped(): void {
		foreach ([['email' => 'outsider'], ['e-mailadres' => 'Outsider '], ['email' => 'new@example.org', 'username' => 'outsider']] as $contact) {
			$this->logged = [];

			$this->process(contacts: [$contact]);

			self::assertContains('[FLOW] Nested contact person names an account outside the organisation, skipped', $this->logged, json_encode($contact));
			self::assertNotContains('[FLOW] Processing nested contact person', $this->logged, json_encode($contact));
		}
	}//end testAnInlineContactNamingAnAccountOutsideTheOrganisationIsSkipped()

	public function testAnInlineContactForANewOrAMemberAccountIsProcessed(): void {
		foreach ([['email' => 'new@example.org'], ['email' => 'member']] as $contact) {
			$this->logged = [];

			$this->process(contacts: [$contact]);

			self::assertContains('[FLOW] Processing nested contact person', $this->logged, json_encode($contact));
		}
	}//end testAnInlineContactForANewOrAMemberAccountIsProcessed()

	/**
	 * Run the nested contact processing for an organisation whose only member is `member`.
	 *
	 * @param array<int, mixed> $contacts The inline contact persons.
	 *
	 * @return void
	 */
	private function process(array $contacts): void {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function (string|\Stringable $message): void {
				$this->logged[] = (string)$message;
			});
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => 'reg', 'contactpersoon_schema' => 'contact']);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $name): ?IUser {
				$uid = strtolower($name);
				if (in_array($uid, ['outsider', 'member'], true) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCP\IUserManager')->willReturn($users);

		// Processing past the check is not under test; it stops at the first downstream lookup.
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method($this->anything())->willThrowException(new \RuntimeException('not under test'));

		$reflection = new \ReflectionClass(OrganizationSyncService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (['logger' => $logger, 'settingsService' => $settings, 'container' => $container, 'objectService' => $this->createMock(ObjectServiceInterface::class), 'organisationMapper' => $mapper, 'contactpersonHandler' => $this->createMock(ContactPersonHandler::class)] as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		$organisatie = $this->createMock(ObjectEntity::class);
		$organisatie->method('getObject')->willReturn(['contactpersonen' => $contacts]);
		$organisatie->method('getUuid')->willReturn('o-1');

		$organisation = new Organisation();
		$organisation->setUuid('o-1');
		$organisation->setUsers(['member']);

		$stats = ['errors' => [], 'contactPersonsProcessed' => 0, 'usersCreated' => 0, 'usersUpdated' => 0, 'contactPersonsCreated' => 0];
		(new \ReflectionMethod(OrganizationSyncService::class, 'processNestedContactPersons'))
			->invokeArgs($service, [$organisatie, $organisation, &$stats]);
	}//end process()
}//end class
