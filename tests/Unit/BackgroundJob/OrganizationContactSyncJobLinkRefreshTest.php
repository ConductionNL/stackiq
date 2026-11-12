<?php

/**
 * The organisation contact sync job keeps contactsUid links fresh.
 *
 * Runs the real job over a real SettingsService (in-memory app config) and a
 * mocked OpenRegister object service and contacts bridge, and pins when a
 * record's contactsUid is rewritten, when it is left alone, and that one
 * failing record or a failing sync does not stop the pass.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Service\Contactpersoon\SystemContactWrites;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCA\Stackiq\Service\Settings\VoorzieningenConfigNormalizer;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the contactsUid link refresh of OrganizationContactSyncJob.
 */
#[CoversClass(OrganizationContactSyncJob::class)]
#[UsesClass(SettingsService::class)]
#[UsesClass(SystemContactWrites::class)]
#[UsesClass(VoorzieningenConfigNormalizer::class)]
class OrganizationContactSyncJobLinkRefreshTest extends TestCase {

	/**
	 * The object service double handed out by the container.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The container double the settings service resolves OpenRegister from.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Warnings and errors the job logged, as [level, message, context].
	 *
	 * @var array<int,array{0:string,1:string,2:array}>
	 */
	private array $logged = [];

	/**
	 * Set up the object service and container doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($this->objects);
	}//end setUp()

	/**
	 * Without Nextcloud Contacts the refresh stops before touching OpenRegister.
	 *
	 * @return void
	 */
	public function testContactsUnavailableSkipsTheRefresh(): void {
		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('isAvailable')->willReturn(false);
		$this->container->expects($this->never())->method('get');

		$this->runJob($contacts, $this->configured());
	}//end testContactsUnavailableSkipsTheRefresh()

	/**
	 * Without a configured voorzieningen register no record is read or written.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterSkipsTheRefresh(): void {
		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('isAvailable')->willReturn(true);
		$contacts->expects($this->never())->method('syncToContacts');
		$this->objects->expects($this->never())->method('findAll');
		$this->objects->expects($this->never())->method('saveObject');

		$this->runJob($contacts, []);
	}//end testAnUnconfiguredRegisterSkipsTheRefresh()

	/**
	 * A stale link is rewritten with the uid the contacts bridge resolves, into
	 * the configured register and schema, under the record's own id, as a
	 * system write without RBAC or multitenancy.
	 *
	 * @return void
	 */
	public function testAStaleLinkIsRewritten(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with([], false, false)
			->willReturn([['id' => 'org-1', 'name' => 'Acme', 'contactsUid' => 'gone']]);

		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('isAvailable')->willReturn(true);
		$contacts->expects($this->once())->method('findContactByUid')->with('gone')->willReturn(null);
		$contacts->expects($this->once())->method('syncToContacts')
			->with('organization', ['id' => 'org-1', 'name' => 'Acme', 'contactsUid' => 'gone'])
			->willReturn('fresh-uid');

		$this->objects->expects($this->once())->method('saveObject')->with(
			['id' => 'org-1', 'name' => 'Acme', 'contactsUid' => 'fresh-uid'],
			[],
			5,
			12,
			'org-1',
			false,
			false
		);

		$this->runJob($contacts, $this->configured());
		$this->assertSame([], $this->logged);
	}//end testAStaleLinkIsRewritten()

	/**
	 * A link that still resolves, or a resolution that yields nothing new,
	 * leaves the record unsaved.
	 *
	 * @return void
	 */
	public function testAFreshOrUnchangedLinkIsNotSaved(): void {
		$this->objects->method('findAll')->willReturn(
			[
				['id' => 'org-1', 'contactsUid' => 'alive'],
				['id' => 'org-2', 'contactsUid' => ''],
				['id' => 'org-3', 'contactsUid' => 'same'],
			]
		);

		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('isAvailable')->willReturn(true);
		$contacts->method('findContactByUid')->willReturnCallback(
			static fn (string $uid): ?array => ($uid === 'alive' ? ['uid' => 'alive'] : null)
		);
		$contacts->expects($this->exactly(2))->method('syncToContacts')->willReturnCallback(
			static fn (string $type, array $data): ?string => ($data['id'] === 'org-2' ? null : 'same')
		);
		$this->objects->expects($this->never())->method('saveObject');

		$this->runJob($contacts, $this->configured());
	}//end testAFreshOrUnchangedLinkIsNotSaved()

	/**
	 * One failing record is logged and the next record is still refreshed.
	 *
	 * @return void
	 */
	public function testOneFailingRecordDoesNotStopThePass(): void {
		$this->objects->method('findAll')->willReturn([['id' => 'bad'], ['id' => 'good']]);

		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('isAvailable')->willReturn(true);
		$contacts->method('syncToContacts')->willReturnCallback(
			static function (string $type, array $data): string {
				if ($data['id'] === 'bad') {
					throw new RuntimeException('contacts down');
				}

				return 'uid-good';
			}
		);
		$this->objects->expects($this->once())->method('saveObject')
			->with(['id' => 'good', 'contactsUid' => 'uid-good'], [], 5, 12, 'good', false, false);

		$this->runJob($contacts, $this->configured());

		$this->assertSame(
			[
				[
					'warning',
					'[OrganizationContactSyncJob] Could not refresh contactsUid link — record left intact',
					['objectType' => 'organization', 'error' => 'contacts down'],
				],
			],
			$this->logged
		);
	}//end testOneFailingRecordDoesNotStopThePass()

	/**
	 * A failing organisation sync is logged and the link refresh still runs.
	 *
	 * @return void
	 */
	public function testAFailingSyncStillRefreshesLinks(): void {
		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->method('performScheduledSync')->willThrowException(new RuntimeException('sync broke'));

		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->expects($this->once())->method('isAvailable')->willReturn(false);

		$this->runJob($contacts, $this->configured(), $sync);

		$this->assertCount(1, $this->logged);
		$this->assertSame('error', $this->logged[0][0]);
		$this->assertSame('[OrganizationContactSyncJob] Fatal error during sync — cron pass protected', $this->logged[0][1]);
		$this->assertSame('sync broke', $this->logged[0][2]['error']);
	}//end testAFailingSyncStillRefreshesLinks()

	/**
	 * App config naming register 5 and organisation schema 12.
	 *
	 * @return array<string,string>
	 */
	private function configured(): array {
		return [
			'voorzieningen_organisatie_register' => '5',
			'amef_config' => '{"organization_schema": 12}',
		];
	}//end configured()

	/**
	 * Run the job's protected run() once.
	 *
	 * @param StackiqContactSyncService    $contacts The contacts bridge double.
	 * @param array<string,string>         $store    The app config values.
	 * @param OrganizationSyncService|null $sync     The sync service double.
	 *
	 * @return void
	 */
	private function runJob(StackiqContactSyncService $contacts, array $store, ?OrganizationSyncService $sync=null): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		$job = new OrganizationContactSyncJob(
			$this->createMock(ITimeFactory::class),
			($sync ?? $this->createMock(OrganizationSyncService::class)),
			$contacts,
			$this->settings($store, $appManager),
			$appManager,
			$logger
		);

		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * A real SettingsService over a fixed in-memory app config.
	 *
	 * @param array<string,string> $store      The app config values.
	 * @param IAppManager          $appManager The app manager double.
	 *
	 * @return SettingsService
	 */
	private function settings(array $store, IAppManager $appManager): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($store[$key] ?? $default)
		);

		return new SettingsService(
			config: $config,
			request: $this->createMock(IRequest::class),
			container: $this->container,
			appManager: $appManager,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);
	}//end settings()
}//end class
