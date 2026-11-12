<?php

/**
 * Tests for the group and contact-person paths of OrganizationHandler.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service\Stackiq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Creating an active organisation's group and turning its contact persons into contactgegevens.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\OrganizationHandler
 */
class OrganizationHandlerGroupAndContactsTest extends TestCase {
	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Logger that records every call.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * Whether OpenRegister is installed.
	 *
	 * @var boolean
	 */
	private bool $openRegister = true;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groupManager  = $this->createMock(IGroupManager::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->settings      = $this->createMock(SettingsService::class);
		$this->logger        = new class extends AbstractLogger {
			/**
			 * Recorded calls.
			 *
			 * @var array<int, array{0: mixed, 1: string, 2: array}>
			 */
			public array $records = [];

			/**
			 * @param mixed $level   The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string)$message, $context];
			}
		};
	}

	/**
	 * The handler under test.
	 *
	 * @return OrganizationHandler
	 */
	private function handler(): OrganizationHandler {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturnCallback(
			function (): array {
				if ($this->openRegister === true) {
					return ['openregister'];
				}

				return ['files'];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
				'OCA\Stackiq\Service\SettingsService' => $this->settings,
			}
		);

		return new OrganizationHandler($this->groupManager, $this->createMock(IUserManager::class), $container, $apps, $this->logger);
	}

	/**
	 * An organisation object as OpenRegister hands it over.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return object The organisation.
	 */
	private function organisation(array $data): object {
		return new class($data) {
			/**
			 * @param array<string, mixed> $data The object data.
			 */
			public function __construct(public array $data) {
			}

			/**
			 * @return int The id.
			 */
			public function getId(): int {
				return 7;
			}

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'org-uuid';
			}

			/**
			 * @return int The register.
			 */
			public function getRegister(): int {
				return 11;
			}

			/**
			 * @return int The schema.
			 */
			public function getSchema(): int {
				return 22;
			}

			/**
			 * @return array<string, mixed> The data.
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * @param array<string, mixed> $data The data.
			 *
			 * @return void
			 */
			public function setObject(array $data): void {
				$this->data = $data;
			}
		};
	}

	/**
	 * A saved or found OpenRegister object.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntityInterface The object.
	 */
	private function entity(string $uuid, array $data = []): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}

	/**
	 * An object a search finds, as the OpenRegister entity exposes it.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return object The object.
	 */
	private function found(string $uuid, array $data): object {
		return new class($uuid, $data) {
			/**
			 * @param string               $uuid The uuid.
			 * @param array<string, mixed> $data The data.
			 */
			public function __construct(private string $uuid, private array $data) {
			}

			/**
			 * @return int The id.
			 */
			public function getId(): int {
				return 99;
			}

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return $this->uuid;
			}

			/**
			 * @return array<string, mixed> The data.
			 */
			public function getObject(): array {
				return $this->data;
			}
		};
	}

	/**
	 * The messages logged at a level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, string> The messages.
	 */
	private function messages(string $level): array {
		$records = array_filter($this->logger->records, static fn (array $r): bool => $r[0] === $level);

		return array_values(array_map(static fn (array $r): string => $r[1], $records));
	}

	/**
	 * An organisation that is not active is left alone.
	 *
	 * @return void
	 */
	public function testAnInactiveOrganisationIsSkipped(): void {
		$this->groupManager->expects($this->never())->method('createGroup');
		$this->objectService->expects($this->never())->method('saveObject');

		self::assertTrue($this->handler()->processOrganization($this->organisation(['beoordeling' => 'Concept', 'name' => 'Acme'])));
		self::assertContains('Organization not active, skipping processing', $this->messages('info'));
	}

	/**
	 * An active organisation that already names its group keeps it.
	 *
	 * @return void
	 */
	public function testAnExistingGroupIsKept(): void {
		$this->groupManager->expects($this->never())->method('createGroup');
		$data = ['beoordeling' => 'actief', 'group' => 'acme'];

		self::assertSame('acme', $this->handler()->ensureOrganizationGroup($this->organisation($data), $data));
		self::assertTrue($this->handler()->processOrganization($this->organisation($data)));
	}

	/**
	 * A new group gets a unique sanitised name and is saved on the organisation in the configured register and schema.
	 *
	 * @return void
	 */
	public function testANewGroupIsCreatedUniquelyAndSaved(): void {
		$taken = $this->createMock(IGroup::class);
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('acme_b.v_2');
		$this->groupManager->method('get')->willReturnCallback(
			static function (string $gid) use ($taken): ?IGroup {
				if (in_array($gid, ['acme_b.v', 'acme_b.v_1'], true) === true) {
					return $taken;
				}

				return null;
			}
		);
		$this->groupManager->expects($this->once())->method('createGroup')->with('acme_b.v_2')->willReturn($group);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->settings->method('getSchemaIdForObjectType')->with('organization')->willReturn(6);
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['beoordeling' => 'Actief', 'name' => ' Acme  B.V ', 'group' => 'acme_b.v_2'], [], 5, 6, 'org-uuid')
			->willReturn($this->entity('org-uuid'));
		$organisation = $this->organisation(['beoordeling' => 'Actief', 'name' => ' Acme  B.V ']);

		self::assertTrue($this->handler()->processOrganization($organisation));
		self::assertSame('acme_b.v_2', $organisation->data['group']);
		self::assertContains('Successfully processed organization group', $this->messages('info'));
	}

	/**
	 * Without a configured register or schema the organisation is saved where it came from.
	 *
	 * @return void
	 */
	public function testWithoutConfigurationTheGroupIsSavedInTheOrganisationsOwnPlace(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('organization');
		$this->groupManager->method('createGroup')->willReturn($group);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(null);
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['beoordeling' => 'actief', 'group' => 'organization'], [], 11, 22, 'org-uuid')
			->willReturn($this->entity('org-uuid'));
		$data = ['beoordeling' => 'actief'];

		self::assertSame('organization', $this->handler()->ensureOrganizationGroup($this->organisation($data), $data));
		self::assertSame('organization', $data['group']);
		self::assertContains('Missing register or schema ID for organization, using fallback save method', $this->messages('warning'));
	}

	/**
	 * A group that cannot be created leaves the organisation without one.
	 *
	 * @return void
	 */
	public function testAFailedGroupCreationReturnsNoGroup(): void {
		$this->groupManager->method('createGroup')->willThrowException(new RuntimeException('backend read-only'));
		$this->objectService->expects($this->never())->method('saveObject');
		$data = ['beoordeling' => 'actief', 'name' => 'Acme'];

		self::assertNull($this->handler()->ensureOrganizationGroup($this->organisation($data), $data));
		self::assertSame(['Failed to create group: backend read-only'], $this->messages('error'));
	}

	/**
	 * An existing group is returned without creating it.
	 *
	 * @return void
	 */
	public function testCreateGroupIfNotExistsReturnsTheExistingGroup(): void {
		$group = $this->createMock(IGroup::class);
		$this->groupManager->method('get')->with('acme')->willReturn($group);
		$this->groupManager->expects($this->never())->method('createGroup');

		self::assertSame($group, $this->handler()->createGroupIfNotExists('acme'));
	}

	/**
	 * Without OpenRegister the failure is logged and rethrown.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterProcessingFails(): void {
		$this->openRegister = false;
		$group              = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('acme');
		$this->groupManager->method('createGroup')->willReturn($group);

		try {
			$this->handler()->processOrganization($this->organisation(['beoordeling' => 'actief', 'name' => 'Acme']));
			self::fail('Expected the missing OpenRegister to be reported');
		} catch (RuntimeException $e) {
			self::assertSame('OpenRegister service is not available.', $e->getMessage());
		}

		self::assertSame(['Failed to process organization object: OpenRegister service is not available.'], $this->messages('error'));
	}

	/**
	 * An organisation without contact persons yields none.
	 *
	 * @return void
	 */
	public function testNoContactPersonsYieldNothing(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		self::assertSame([], $this->handler()->processContactpersonen($this->organisation(['contactpersonen' => 'none'])));
		self::assertSame([], $this->handler()->processContactpersonen($this->organisation([])));
	}

	/**
	 * Contact persons become contactgegevens: the first gets every role, later ones a role mapped from their function.
	 *
	 * @return void
	 */
	public function testContactPersonsAreCreatedWithMappedRoles(): void {
		$this->settings->method('getSchemaIdForObjectType')->with('contactgegevens')->willReturn(9);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->objectService->method('findAll')->willReturn([]);
		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use (&$saved): ObjectEntityInterface {
				$saved[] = [$object, $register, $schema, $uuid];
				return $this->entity('cg-'.count($saved));
			}
		);
		$organisation = $this->organisation([
			'id' => 'org-1',
			'contactpersonen' => [
				['voornaam' => 'Ann', 'achternaam' => 'Bos', 'email' => 'ann@x', 'role' => 'viewer'],
				['voornaam' => 'Bob', 'e-mailadres' => 'bob@x', 'role' => ' Procurement Lead '],
				['voornaam' => 'Cas', 'email' => 'cas@x', 'role' => 'VNG contact'],
				['voornaam' => 'Dee', 'email' => 'dee@x'],
			],
		]);

		$result = $this->handler()->processContactpersonen($organisation);

		self::assertCount(4, $result);
		self::assertSame(
			['Functioneel-beheerder', 'Aanbod-beheerder', 'Gebruik-beheerder', 'Gebruik-raadpleger', 'VNG-raadpleger', 'maintainer'],
			$saved[0][0]['roles']
		);
		self::assertSame(['Gebruik-beheerder'], $saved[1][0]['roles']);
		self::assertSame('bob@x', $saved[1][0]['email']);
		self::assertSame(['VNG-raadpleger'], $saved[2][0]['roles']);
		self::assertSame(['Gebruik-raadpleger'], $saved[3][0]['roles']);
		self::assertSame(
			[
				'title' => $saved[0][0]['title'],
				'voornaam' => 'Ann',
				'tussenvoegsel' => '',
				'achternaam' => 'Bos',
				'telefoon' => '',
				'email' => 'ann@x',
				'role' => 'viewer',
				'organisation' => 'org-1',
				'roles' => $saved[0][0]['roles'],
				'username' => '',
			],
			$saved[0][0]
		);
		self::assertSame([5, 9, null], [$saved[0][1], $saved[0][2], $saved[0][3]]);
	}

	/**
	 * A contact person already on file is updated in place and keeps its username.
	 *
	 * @return void
	 */
	public function testAnExistingContactIsUpdatedKeepingItsUsername(): void {
		$this->settings->method('getSchemaIdForObjectType')->willReturn(9);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['email' => 'ann@x', 'organisation' => 'org-1'], '_register' => 5, '_schema' => 9])
			->willReturn([$this->found('cg-old', ['username' => 'ann.bos'])]);
		$this->objectService->expects($this->once())->method('saveObject')
			->with($this->callback(static fn (array $o): bool => $o['username'] === 'ann.bos'), [], 5, 9, 'cg-old')
			->willReturn($this->entity('cg-old'));

		$result = $this->handler()->processContactpersonen($this->organisation(['id' => 'org-1', 'contactpersonen' => [['email' => 'ann@x']]]));

		self::assertCount(1, $result);
		self::assertContains('Updated existing contactgegevens from contactpersoon', $this->messages('info'));
	}

	/**
	 * A failing lookup treats the contact as new; a missing register fails each contact without stopping the run.
	 *
	 * @return void
	 */
	public function testLookupAndConfigurationFailuresArePerContact(): void {
		$this->settings->method('getSchemaIdForObjectType')->willReturn(9);
		$this->settings->method('getVoorzieningenRegisterId')->willReturnOnConsecutiveCalls(5, null);
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('index offline'));
		$this->objectService->expects($this->once())->method('saveObject')
			->with($this->anything(), [], 5, 9)
			->willReturn($this->entity('cg-new'));

		$organisation = $this->organisation(['id' => 'org-1', 'contactpersonen' => [['email' => 'a@x'], ['email' => 'b@x']]]);
		$result       = $this->handler()->processContactpersonen($organisation);

		self::assertCount(1, $result);
		self::assertSame(
			['Failed to find existing contactgegevens: index offline', 'Failed to process contactPerson: Voorzieningen register ID not configured'],
			$this->messages('error')
		);
	}

	/**
	 * Without OpenRegister no contacts are processed and the failure is logged.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNoContactsAreProcessed(): void {
		$this->openRegister = false;

		self::assertSame([], $this->handler()->processContactpersonen($this->organisation(['contactpersonen' => [['email' => 'a@x']]])));
		self::assertSame(['Failed to process contactpersonen: OpenRegister service is not available.'], $this->messages('error'));
	}

	/**
	 * A new active organisation gets its group and its contacts; an inactive one only the check.
	 *
	 * @return void
	 */
	public function testHandleNewOrganisationProcessesContactsOnlyWhenActive(): void {
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->settings->method('getSchemaIdForObjectType')->willReturn(9);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($this->entity('cg-1'));

		$this->handler()->handleNewOrganization($this->organisation(['beoordeling' => 'concept', 'contactpersonen' => [['email' => 'a@x']]]));
		$this->handler()->handleNewOrganization(
			$this->organisation(['id' => 'org-1', 'beoordeling' => 'actief', 'group' => 'g', 'contactpersonen' => [['email' => 'a@x']]])
		);

		self::assertContains('Processed organization and contactgegevens', $this->messages('info'));
	}

	/**
	 * A failure while handling a new organisation is logged, not thrown.
	 *
	 * @return void
	 */
	public function testHandleNewOrganisationSwallowsFailures(): void {
		$this->openRegister = false;
		$group              = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('acme');
		$this->groupManager->method('createGroup')->willReturn($group);

		$this->handler()->handleNewOrganization($this->organisation(['beoordeling' => 'actief', 'name' => 'Acme']));

		self::assertContains('Failed to handle new organization: OpenRegister service is not available.', $this->messages('error'));
	}
}
