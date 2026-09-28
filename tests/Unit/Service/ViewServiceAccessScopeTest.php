<?php

/**
 * Unit tests for the access scope of ViewService's register reads.
 *
 * A single view read by uuid must go through OpenRegister's RBAC and
 * multitenancy checks, the same checks the views list reads with, and the
 * list cache must never hand one caller's scoped list to another caller.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/architecture-views-editor/tasks.md#task-8-keep-drawn-objects-out-of-the-shared-list-the-single-view-read-and-the-full-export
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\ViewService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Access scope of ViewService::getView() and ViewService::getAllViews().
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ViewServiceAccessScopeTest extends TestCase {

	/**
	 * The signed-in user id, or null for no user.
	 *
	 * @var string|null
	 */
	private ?string $currentUserId = null;

	/**
	 * The uuid of the signed-in user's active organisation.
	 *
	 * @var string|null
	 */
	private ?string $activeOrganisation = null;

	/**
	 * In-memory store behind the ICache double.
	 *
	 * @var array<string, mixed>
	 */
	private array $cacheStore = [];

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ObjectServiceInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The ViewService under test.
	 *
	 * @var ViewService
	 */
	private ViewService $viewService;

	/**
	 * Build the service with a signed-in user, an active organisation and a
	 * cache that really stores what it is given.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getAmefConfig')->willReturn(
			['register_id' => 'amef', 'view_schema' => 'view']
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->currentUserId === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->currentUserId);
				return $user;
			}
		);

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getActiveOrganisation')->willReturnCallback(
			function (): ?Organisation {
				if ($this->activeOrganisation === null) {
					return null;
				}

				$organisation = new Organisation();
				$organisation->setUuid($this->activeOrganisation);
				return $organisation;
			}
		);

		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($organisationService) {
				if ($id === ObjectServiceInterface::class) {
					return $this->objectService;
				}

				if ($id === OrganisationService::class) {
					return $organisationService;
				}

				throw new \RuntimeException('Unexpected container id ' . $id);
			}
		);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			fn (string $key) => $this->cacheStore[$key] ?? null
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->cacheStore[$key] = $value;
				return true;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$this->viewService = new ViewService(
			config: $this->createMock(IAppConfig::class),
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settingsService,
			userSession: $userSession,
			cacheFactory: $cacheFactory
		);
	}//end setUp()

	/**
	 * A view read by uuid goes through OpenRegister's RBAC and multitenancy
	 * checks, like the list does, so a uuid is not a way around them.
	 *
	 * @return void
	 */
	public function testSingleViewIsReadWithRbacAndMultitenancyOn(): void {
		$this->currentUserId = 'bob';
		$this->activeOrganisation = 'org-b';

		$seen = [];
		$this->objectService->expects($this->once())
			->method('find')
			->willReturnCallback(
				function (
					int|string $id,
					?array $_extend=[],
					bool $files=false,
					string|int|null $register=null,
					string|int|null $schema=null,
					bool $_rbac=true,
					bool $_multitenancy=true
				) use (&$seen) {
					$seen = [
						'id' => $id,
						'register' => $register,
						'schema' => $schema,
						'_rbac' => $_rbac,
						'_multitenancy' => $_multitenancy,
					];
					return null;
				}
			);

		$this->viewService->getView(viewId: 'view-of-org-a');

		$this->assertSame('view-of-org-a', $seen['id']);
		$this->assertSame('amef', $seen['register']);
		$this->assertSame('view', $seen['schema']);
		$this->assertTrue($seen['_rbac'], 'find() must apply register RBAC');
		$this->assertTrue($seen['_multitenancy'], 'find() must apply organisation scoping');
	}//end testSingleViewIsReadWithRbacAndMultitenancyOn()

	/**
	 * When OpenRegister withholds the view from the caller, the service
	 * answers "not found", which the controller turns into a 404.
	 *
	 * @return void
	 */
	public function testAViewTheCallerCannotReadIsNotFound(): void {
		$this->currentUserId = 'bob';
		$this->activeOrganisation = 'org-b';

		$this->objectService->method('find')->willReturn(null);

		$result = $this->viewService->getView(viewId: 'view-of-org-a');

		$this->assertFalse($result['success']);
		$this->assertNull($result['view']);
	}//end testAViewTheCallerCannotReadIsNotFound()

	/**
	 * The scoped list one user gets is not served from the cache to another
	 * user.
	 *
	 * @return void
	 */
	public function testTheCachedListOfOneUserIsNotServedToAnother(): void {
		$this->objectService->method('searchObjects')->willReturnCallback(
			fn (): array => [['id' => 'view-of-' . $this->currentUserId]]
		);

		$this->currentUserId = 'alice';
		$this->activeOrganisation = 'org-a';
		$alice = $this->viewService->getAllViews();

		$this->currentUserId = 'bob';
		$this->activeOrganisation = 'org-b';
		$bob = $this->viewService->getAllViews();

		$this->assertSame(['view-of-alice'], array_column($alice['views'], 'id'));
		$this->assertSame(['view-of-bob'], array_column($bob['views'], 'id'));
	}//end testTheCachedListOfOneUserIsNotServedToAnother()

	/**
	 * A user who switches the active organisation gets that organisation's
	 * list, not the list cached for the organisation they left.
	 *
	 * @return void
	 */
	public function testTheCachedListFollowsTheActiveOrganisation(): void {
		$this->objectService->method('searchObjects')->willReturnCallback(
			fn (): array => [['id' => 'view-in-' . $this->activeOrganisation]]
		);

		$this->currentUserId = 'alice';
		$this->activeOrganisation = 'org-a';
		$first = $this->viewService->getAllViews();

		$this->activeOrganisation = 'org-b';
		$second = $this->viewService->getAllViews();

		$this->assertSame(['view-in-org-a'], array_column($first['views'], 'id'));
		$this->assertSame(['view-in-org-b'], array_column($second['views'], 'id'));
	}//end testTheCachedListFollowsTheActiveOrganisation()

	/**
	 * The same user in the same organisation still gets the cached list,
	 * so the cache keeps doing its job.
	 *
	 * @return void
	 */
	public function testTheSameCallerIsServedFromTheCache(): void {
		$this->objectService->expects($this->once())
			->method('searchObjects')
			->willReturn([['id' => 'view-1']]);

		$this->currentUserId = 'alice';
		$this->activeOrganisation = 'org-a';

		$first = $this->viewService->getAllViews();
		$second = $this->viewService->getAllViews();

		$this->assertSame(['view-1'], array_column($first['views'], 'id'));
		$this->assertSame(['view-1'], array_column($second['views'], 'id'));
	}//end testTheSameCallerIsServedFromTheCache()

}//end class
