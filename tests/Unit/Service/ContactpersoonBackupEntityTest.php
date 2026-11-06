<?php

/**
 * The backup organisation entity a new contact person's organisation gets.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A contact person of an active organisation without an entity triggers the backup creation.
 *
 * @covers \OCA\Stackiq\Service\ContactpersoonService
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class ContactpersoonBackupEntityTest extends TestCase {

	/**
	 * The organisation object the backup entity is created from.
	 *
	 * @return void
	 */
	public function testTheBackupEntityIsCreatedFromTheOrganisationObject(): void {
		$organisation = $this->stored(id: 'org-1', data: ['status' => 'Actief']);
		$calls        = [];

		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->method('ensureOrganisationEntityPublic')->willReturnCallback(
			function (object $organisationObject, array &$stats, bool $sendEmails = true) use (&$calls) {
				$calls[] = [$organisationObject, $sendEmails];
				return null;
			}
		);

		$this->service(organisation: $organisation, sync: $sync)->processContactpersoon(
			$this->stored(id: 'contact-backup-1', data: ['email' => 'new@gemeente.nl', 'organisation' => 'org-1'])
		);

		self::assertCount(1, $calls);
		self::assertSame($organisation, $calls[0][0]);
		self::assertTrue($calls[0][1], 'the backup creation keeps the default: registration mails are sent');
	}//end testTheBackupEntityIsCreatedFromTheOrganisationObject()

	/**
	 * An inactive organisation gets no backup entity.
	 *
	 * @return void
	 */
	public function testAnInactiveOrganisationGetsNoBackupEntity(): void {
		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->expects(self::never())->method('ensureOrganisationEntityPublic');

		$this->service(organisation: $this->stored(id: 'org-1', data: ['status' => 'inactief']), sync: $sync)->processContactpersoon(
			$this->stored(id: 'contact-backup-2', data: ['email' => 'new@gemeente.nl', 'organisation' => 'org-1'])
		);
	}//end testAnInactiveOrganisationGetsNoBackupEntity()

	/**
	 * The service over a container whose organisation has no OpenRegister entity yet.
	 *
	 * @param object                  $organisation The stored organisation object.
	 * @param OrganizationSyncService $sync         The sync service double.
	 *
	 * @return ContactpersoonService
	 */
	private function service(object $organisation, OrganizationSyncService $sync): ContactpersoonService {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willThrowException(new DoesNotExistException('missing'));

		$objects = new class($organisation) {
			/**
			 * @param object $organisation The stored organisation.
			 */
			public function __construct(private object $organisation) {
			}

			/**
			 * @param mixed ...$args The lookup.
			 *
			 * @return object
			 */
			public function find(...$args): object {
				return $this->organisation;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn([]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => [
				'OCP\IUserManager'                          => $users,
				'OCA\OpenRegister\Db\OrganisationMapper'    => $mapper,
				'OCA\OpenRegister\Service\ObjectService'    => $objects,
				'OCA\Stackiq\Service\SettingsService'       => $settings,
				'OCA\Stackiq\Service\OrganizationSyncService' => $sync,
			][$id] ?? null
		);

		return new ContactpersoonService(
			contactPersonHandler: $this->createMock(ContactPersonHandler::class),
			groupHandler: $this->createMock(GroupHandler::class),
			hierarchyHandler: $this->createMock(HierarchyHandler::class),
			logger: new NullLogger(),
			container: $container,
			appManager: $this->createMock(IAppManager::class),
			config: $this->createMock(IAppConfig::class),
			settingsService: $settings
		);
	}//end service()

	/**
	 * A stored object double.
	 *
	 * @param string $id   The id.
	 * @param array  $data The data.
	 *
	 * @return object
	 */
	private function stored(string $id, array $data): object {
		return new class($id, $data) {
			/**
			 * @param string $id   The id.
			 * @param array  $data The data.
			 */
			public function __construct(private string $id, private array $data) {
			}

			/**
			 * @return string
			 */
			public function getId(): string {
				return $this->id;
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}
		};
	}//end stored()
}//end class
