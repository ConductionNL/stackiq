<?php

/**
 * The account-creation mail sent when a contact person of an organisation gets a new account.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * createUserAccount() for a new account of an organisation's contact: the
 * mail carries the organisation that OpenRegister holds, a refused or failing
 * mail is logged without losing the account, and without a configured
 * register no mail is sent.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerCreationMailTest extends TestCase {

	/**
	 * The organisation as OpenRegister stores it.
	 *
	 * @var array<string, mixed>
	 */
	private const ORGANISATION = ['id' => 'org-7', 'name' => 'Gemeente Voorbeeld', 'type' => 'supplier'];

	private SymfonyEmailService&MockObject $mail;

	private ObjectServiceInterface&MockObject $objects;

	private RegisterConfigReaderInterface&MockObject $registers;

	private LoggerInterface&MockObject $logger;

	/**
	 * Every log line, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The new account.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $anna;

	/**
	 * Wire a handler whose user backend creates `anna@gemeente.nl` and whose
	 * OpenRegister holds the organisation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mail      = $this->createMock(SymfonyEmailService::class);
		$this->objects   = $this->createMock(ObjectServiceInterface::class);
		$this->registers = $this->createMock(RegisterConfigReaderInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);

		foreach (['debug', 'info', 'warning', 'error', 'critical'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		$this->registers->method('getVoorzieningenConfig')->willReturn(['register' => 5, 'organisatie_schema' => 7]);
		$this->registers->method('getSchemaIdForObjectType')->willReturn(7);

		$this->anna = $this->createMock(IUser::class);
		$this->anna->method('getUID')->willReturn('anna@gemeente.nl');
		$this->anna->method('getEMailAddress')->willReturn('anna@gemeente.nl');
		$this->anna->method('getDisplayName')->willReturn('Anna Bakker');
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return ContactPersonHandler
	 */
	private function handler(): ContactPersonHandler {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(false);
		$users->method('get')->willReturn(null);
		$users->method('createUser')->willReturn($this->anna);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('Ab1!');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				['OCA\OpenRegister\Service\ObjectService', $this->objects],
				[RegisterConfigReaderInterface::class, $this->registers],
			]
		);

		// No role groups exist: assigning the organisation-type role is skipped.
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn(null);

		return new ContactPersonHandler(
			_userManager: $users,
			_secureRandom: $random,
			_groupManager: $groups,
			_config: $this->createMock(IAppConfig::class),
			_container: $container,
			_appManager: $apps,
			_logger: $this->logger,
			_emailService: $this->mail,
			config: $this->createMock(IConfig::class),
		);
	}//end handler()

	/**
	 * A contact of organisation org-7.
	 *
	 * @return object
	 */
	private static function contact(): object {
		return new class() {
			public ?array $written = null;

			public function getId(): int {
				return 12;
			}

			public function getObject(): array {
				return ['email' => 'anna@gemeente.nl', 'voornaam' => 'Anna', 'achternaam' => 'Bakker', 'organisation' => 'org-7'];
			}

			public function setObject(array $data): void {
				$this->written = $data;
			}
		};
	}//end contact()

	/**
	 * An OpenRegister object holding this data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function stored(array $data): ObjectEntityInterface {
		$object = $this->createMock(ObjectEntityInterface::class);
		$object->method('getObject')->willReturn($data);
		return $object;
	}//end stored()

	/**
	 * The log lines at this level whose message starts with the prefix.
	 *
	 * @param string $level  The level.
	 * @param string $prefix The message prefix.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function loggedAt(string $level, string $prefix): array {
		return array_values(
			array_filter(
				$this->logged,
				static fn (array $line): bool => $line[0] === $level && str_starts_with($line[1], $prefix)
			)
		);
	}//end loggedAt()

	public function testTheMailCarriesTheOrganisationFromOpenRegister(): void {
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->objects->method('find')->willReturn($this->stored(self::ORGANISATION));
		$sent = [];
		$this->mail->expects($this->once())->method('sendUserCreationEmail')->willReturnCallback(
			static function (array $user, array $organisation) use (&$sent): bool {
				$sent = [$user, $organisation];
				return true;
			}
		);
		$contact = self::contact();

		$this->assertSame($this->anna, $this->handler()->createUserAccount($contact));

		// The roles are the ones the organisation type assigned and wrote back to the contact.
		$this->assertSame(
			[
				'username' => 'anna@gemeente.nl',
				'email' => 'anna@gemeente.nl',
				'displayName' => 'Anna Bakker',
				'voornaam' => 'Anna',
				'achternaam' => 'Bakker',
				'roles' => $contact->written['roles'],
			],
			$sent[0]
		);
		$this->assertNotSame([], $contact->written['roles']);
		$this->assertSame(self::ORGANISATION, $sent[1]);

		$retrieved = $this->loggedAt('info', 'Retrieved organization data for email');
		$this->assertCount(1, $retrieved);
		$this->assertSame(
			['organizationId' => 'org-7', 'organizationUuid' => 'org-7', 'organizationName' => 'Gemeente Voorbeeld'],
			$retrieved[0][2]
		);
		$this->assertCount(1, $this->loggedAt('info', 'User creation email sent successfully'));
		$this->assertSame([], $this->loggedAt('warning', 'Failed to send user creation email'));
	}//end testTheMailCarriesTheOrganisationFromOpenRegister()

	public function testTheOrganisationIsLookedUpInTheConfiguredRegisterAndSchema(): void {
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(5);
		$lookups = [];
		$this->objects->method('find')->willReturnCallback(
			function (...$args) use (&$lookups): ObjectEntityInterface {
				$lookups[] = $args;
				return $this->stored(self::ORGANISATION);
			}
		);
		$this->mail->method('sendUserCreationEmail')->willReturn(true);

		$this->handler()->createUserAccount(self::contact());

		// The organisation-type lookup and the mail lookup both read org-7 from register 5, schema 7.
		$this->assertNotEmpty($lookups);
		foreach ($lookups as $args) {
			$this->assertSame('org-7', $args[0]);
			$this->assertSame(5, $args[3]);
			$this->assertSame(7, $args[4]);
		}
	}//end testTheOrganisationIsLookedUpInTheConfiguredRegisterAndSchema()

	public function testARefusedMailIsLoggedAndTheAccountKept(): void {
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->objects->method('find')->willReturn($this->stored(self::ORGANISATION));
		$this->mail->expects($this->once())->method('sendUserCreationEmail')->willReturn(false);

		$this->assertSame($this->anna, $this->handler()->createUserAccount(self::contact()));

		$refused = $this->loggedAt('warning', 'Failed to send user creation email');
		$this->assertCount(1, $refused);
		$this->assertSame(['username' => 'anna@gemeente.nl', 'email' => 'anna@gemeente.nl'], $refused[0][2]);
		$this->assertSame([], $this->loggedAt('info', 'User creation email sent successfully'));
	}//end testARefusedMailIsLoggedAndTheAccountKept()

	public function testAnUnreadableOrganisationStillSendsTheMailWithoutIt(): void {
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->objects->method('find')->willThrowException(new RuntimeException('register offline'));
		$this->mail->expects($this->once())->method('sendUserCreationEmail')->with($this->anything(), [])->willReturn(true);

		$this->assertSame($this->anna, $this->handler()->createUserAccount(self::contact()));

		$failed = $this->loggedAt('warning', 'Failed to get organization data for email: register offline');
		$this->assertCount(1, $failed);
		$this->assertSame(['organizationId' => 'org-7'], $failed[0][2]);
	}//end testAnUnreadableOrganisationStillSendsTheMailWithoutIt()

	public function testAnUnknownOrganisationSendsTheMailWithoutIt(): void {
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->objects->method('find')->willReturn(null);
		$this->mail->expects($this->once())->method('sendUserCreationEmail')->with($this->anything(), [])->willReturn(true);

		$this->handler()->createUserAccount(self::contact());

		$this->assertSame([], $this->loggedAt('info', 'Retrieved organization data for email'));
	}//end testAnUnknownOrganisationSendsTheMailWithoutIt()

	public function testWithoutAConfiguredRegisterNoMailIsSent(): void {
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(null);
		$this->objects->method('find')->willReturn($this->stored(self::ORGANISATION));
		$this->mail->expects($this->never())->method('sendUserCreationEmail');

		$this->assertSame($this->anna, $this->handler()->createUserAccount(self::contact()));

		$this->assertCount(1, $this->loggedAt('warning', 'Register or schema ID not configured for organisatie'));
	}//end testWithoutAConfiguredRegisterNoMailIsSent()

	public function testAThrowingMailServiceIsLoggedAndTheAccountKept(): void {
		$this->registers->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->objects->method('find')->willReturn($this->stored(self::ORGANISATION));
		$this->mail->method('sendUserCreationEmail')->willThrowException(new RuntimeException('smtp down'));

		$this->assertSame($this->anna, $this->handler()->createUserAccount(self::contact()));

		$errors = $this->loggedAt('error', 'Exception sending user creation email: smtp down');
		$this->assertCount(1, $errors);
		$this->assertSame('anna@gemeente.nl', $errors[0][2]['username']);
	}//end testAThrowingMailServiceIsLoggedAndTheAccountKept()
}//end class
