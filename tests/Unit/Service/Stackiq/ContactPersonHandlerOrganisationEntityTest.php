<?php

/**
 * Adding a contact person's user to the organisation entity OpenRegister keeps for it.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * addUserToOrganizationEntity(): appends the username to the organisation's
 * users once, refuses without an organisation, and logs instead of throwing
 * when the organisation cannot be found or saved.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerOrganisationEntityTest extends TestCase {

	/**
	 * Every log line, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $logs = [];

	private OrganisationMapper&MockObject $mapper;

	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The container ids whose lookup fails.
	 *
	 * @var array<int, string>
	 */
	private array $unavailable = [];

	/**
	 * Wire the OpenRegister collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper  = $this->createMock(OrganisationMapper::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return ContactPersonHandler
	 */
	private function handler(): ContactPersonHandler {
		$registers = $this->createMock(RegisterConfigReaderInterface::class);
		$registers->method('getVoorzieningenConfig')->willReturn(['register' => '5', 'organisatie_schema' => '1']);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($registers) {
				if (in_array($id, $this->unavailable, true) === true) {
					throw new RuntimeException($id.' unavailable');
				}

				return [
					'OCA\\OpenRegister\\Db\\OrganisationMapper' => $this->mapper,
					'OCA\OpenRegister\Service\ObjectService'   => $this->objects,
					RegisterConfigReaderInterface::class      => $registers,
				][$id] ?? null;
			}
		);

		return new ContactPersonHandler(
			_userManager: $this->createMock(IUserManager::class),
			_secureRandom: $this->createMock(ISecureRandom::class),
			_groupManager: $this->createMock(IGroupManager::class),
			_config: $this->createMock(IAppConfig::class),
			_container: $container,
			_appManager: $apps,
			_logger: $this->logger(),
			_emailService: $this->createMock(SymfonyEmailService::class),
			config: $this->createMock(IConfig::class),
		);
	}//end handler()

	/**
	 * A logger recording into $logs.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class($this) extends AbstractLogger {
			public function __construct(private ContactPersonHandlerOrganisationEntityTest $test) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->logs[] = [(string) $level, (string) $message, $context];
			}
		};
	}//end logger()

	/**
	 * A contact person with this data.
	 *
	 * @param array<string, mixed> $data The contact data.
	 *
	 * @return object
	 */
	private static function contact(array $data): object {
		return new class($data) {
			/**
			 * @param array<string, mixed> $data The contact data.
			 */
			public function __construct(private array $data) {
			}

			public function getId(): string {
				return 'contact-1';
			}

			public function getObject(): array {
				return $this->data;
			}
		};
	}//end contact()

	/**
	 * An organisation entity with these members.
	 *
	 * @param array<int, string> $members The member user ids.
	 *
	 * @return Organisation
	 */
	private static function organisation(array $members): Organisation {
		return new class($members) extends Organisation {
			/**
			 * @param array<int, string> $members The member user ids.
			 */
			public function __construct(public array $members) {
			}

			/**
			 * @param array<int, string> $users The member user ids.
			 */
			public function setUsers(array $users): void {
				$this->members = $users;
			}

			public function __call(string $name, array $args) {
				if ($name === 'getUsers') {
					return $this->members;
				}

				if ($name === 'getName') {
					return 'Gemeente Voorbeeld';
				}

				return null;
			}
		};
	}//end organisation()

	/**
	 * The log lines at this level with exactly this message.
	 *
	 * @param string $level   The level.
	 * @param string $message The message.
	 *
	 * @return array<int, array<string, mixed>> Their contexts.
	 */
	private function logged(string $level, string $message): array {
		$contexts = [];
		foreach ($this->logs as [$lineLevel, $lineMessage, $context]) {
			if ($lineLevel === $level && $lineMessage === $message) {
				$contexts[] = $context;
			}
		}

		return $contexts;
	}//end logged()

	public function testWithoutAnOrganisationNothingIsLookedUp(): void {
		$this->mapper->expects($this->never())->method('findByUuid');

		$this->handler()->addUserToOrganizationEntity(self::contact([]), 'anna');

		$this->assertSame(
			[['objectId' => 'contact-1', 'username' => 'anna']],
			$this->logged('warning', 'ContactPersonHandler: No organization reference found for contact person')
		);
	}//end testWithoutAnOrganisationNothingIsLookedUp()

	public function testTheUserIsAppendedToTheFoundOrganisation(): void {
		$organisation = self::organisation(['bob']);
		$this->mapper->expects($this->once())->method('findByUuid')->with('org-1')->willReturn($organisation);
		$this->mapper->expects($this->once())->method('update')->with($organisation)->willReturn($organisation);

		$this->handler()->addUserToOrganizationEntity(self::contact(['organization' => 'org-1']), 'anna');

		$this->assertSame(['bob', 'anna'], $organisation->members);
		$this->assertSame(
			[['objectId' => 'contact-1', 'username' => 'anna', 'organizationUuid' => 'org-1', 'totalUsers' => 2]],
			$this->logged('info', 'ContactPersonHandler: Successfully added user to organization entity')
		);
	}//end testTheUserIsAppendedToTheFoundOrganisation()

	public function testTheOverrideWinsOverTheContactsOrganisation(): void {
		$organisation = self::organisation([]);
		$this->mapper->expects($this->once())->method('findByUuid')->with('org-override')->willReturn($organisation);
		$this->mapper->method('update')->willReturn($organisation);

		$this->handler()->addUserToOrganizationEntity(self::contact(['organisation' => 'org-1']), 'anna', 'org-override');

		$this->assertSame(['anna'], $organisation->members);
	}//end testTheOverrideWinsOverTheContactsOrganisation()

	public function testAListedUserIsNotAddedTwice(): void {
		$organisation = self::organisation(['anna']);
		$this->mapper->method('findByUuid')->willReturn($organisation);
		$this->mapper->expects($this->never())->method('update');

		$this->handler()->addUserToOrganizationEntity(self::contact(['organisation' => 'org-1']), 'anna');

		$this->assertSame(['anna'], $organisation->members);
		$this->assertCount(1, $this->logged('info', 'ContactPersonHandler: User already in organization entity'));
	}//end testAListedUserIsNotAddedTwice()

	public function testAFailingSaveIsLoggedNotThrown(): void {
		$this->mapper->method('findByUuid')->willReturn(self::organisation([]));
		$this->mapper->method('update')->willThrowException(new RuntimeException('database locked'));

		$this->handler()->addUserToOrganizationEntity(self::contact(['organisation' => 'org-1']), 'anna');

		$errors = $this->logged('error', 'ContactPersonHandler: Failed to add user to organization entity');
		$this->assertCount(1, $errors);
		$this->assertSame('database locked', $errors[0]['error']);
		$this->assertSame('org-1', $errors[0]['organizationUuid']);
	}//end testAFailingSaveIsLoggedNotThrown()

	public function testAnUnavailableMapperIsLoggedNotThrown(): void {
		$this->unavailable = ['OCA\\OpenRegister\\Db\\OrganisationMapper'];

		$this->handler()->addUserToOrganizationEntity(self::contact(['organisation' => 'org-1']), 'anna');

		$errors = $this->logged('error', 'ContactPersonHandler: Failed to add user to organization entity');
		$this->assertCount(1, $errors);
		$this->assertSame('OCA\\OpenRegister\\Db\\OrganisationMapper unavailable', $errors[0]['error']);
	}//end testAnUnavailableMapperIsLoggedNotThrown()

	public function testAnOrganisationThatAppearedMeanwhileIsUsed(): void {
		$organisation = self::organisation([]);
		$lookups      = 0;
		$this->mapper->method('findByUuid')->willReturnCallback(
			static function () use (&$lookups, $organisation): Organisation {
				$lookups++;
				if ($lookups === 1) {
					throw new DoesNotExistException('missing');
				}

				return $organisation;
			}
		);
		$this->mapper->expects($this->once())->method('update')->willReturn($organisation);
		$this->objects->expects($this->never())->method('find');

		$this->handler()->addUserToOrganizationEntity(self::contact(['organisation' => 'org-1']), 'anna');

		$this->assertSame(2, $lookups);
		$this->assertSame(['anna'], $organisation->members);
		$this->assertCount(1, $this->logged('info', 'ContactPersonHandler: Organization entity already exists (found by UUID)'));
	}//end testAnOrganisationThatAppearedMeanwhileIsUsed()

	public function testAnOrganisationMissingFromOpenRegisterAddsNobody(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('missing'));
		$this->objects->expects($this->once())->method('find')->willReturn(null);
		$this->mapper->expects($this->never())->method('update');

		$this->handler()->addUserToOrganizationEntity(self::contact(['organisation' => 'org-1']), 'anna');

		$this->assertSame(
			[['organizationUuid' => 'org-1']],
			$this->logged('error', 'ContactPersonHandler: Organization object not found in OpenRegister')
		);
		$this->assertSame(
			[['organizationUuid' => 'org-1']],
			$this->logged('info', 'ContactPersonHandler: No organization entity, user not added')
		);
	}//end testAnOrganisationMissingFromOpenRegisterAddsNobody()
}//end class
