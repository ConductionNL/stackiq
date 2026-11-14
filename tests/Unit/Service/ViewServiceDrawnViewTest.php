<?php

/**
 * Drawn views stay with the organisation that drew them.
 *
 * The `view` schema is readable by everyone across organisations, so the views
 * list and the single view read keep only GEMMA content: views whose origin is
 * empty (imported before the field existed) or `imported`. A drawn view is left
 * out of the list and its cache, and its uuid answers as a missing view.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-006-drawn-views-shall-stay-inside-the-organisation-that-drew-them
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\ViewOrigin;
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
 * The views list and the single read keep only imported views.
 *
 * @covers \OCA\Stackiq\Service\ViewService
 * @covers \OCA\Stackiq\Service\ViewOrigin
 */
final class ViewServiceDrawnViewTest extends TestCase {

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
	 * A view read from the register.
	 *
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The list holds the imported and the legacy view, never the drawn one, and neither does its cache.
	 *
	 * @return void
	 */
	public function testTheListKeepsOnlyImportedViews(): void {
		$this->currentUserId = 'alice';
		$this->activeOrganisation = 'org-a';
		$this->objectService->method('searchObjects')->willReturn(
			[
				$this->entity(['id' => 'gemma', 'name' => 'GEMMA view', 'origin' => 'imported']),
				$this->entity(['id' => 'legacy', 'name' => 'Imported before origin existed']),
				$this->entity(['id' => 'drawn', 'name' => 'Our landscape', 'origin' => 'drawn']),
				$this->entity(['id' => 'future', 'name' => 'Some later origin', 'origin' => 'generated']),
			]
		);

		$result = $this->viewService->getAllViews();

		$this->assertTrue($result['success']);
		$this->assertSame(['gemma', 'legacy'], array_column($result['views'], 'id'));
		foreach ($this->cacheStore as $cached) {
			$this->assertSame(['gemma', 'legacy'], array_column($cached, 'id'));
		}
	}//end testTheListKeepsOnlyImportedViews()

	/**
	 * A drawn view's uuid answers exactly as a missing view does.
	 *
	 * @return void
	 */
	public function testADrawnViewReadsAsMissing(): void {
		$this->currentUserId = 'bob';
		$this->activeOrganisation = 'org-b';
		$this->objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntityInterface => $id === 'drawn-uuid' ? $this->entity(['id' => 'drawn-uuid', 'name' => 'Our landscape', 'origin' => 'drawn']) : null
		);

		$drawn = $this->viewService->getView(viewId: 'drawn-uuid');
		$missing = $this->viewService->getView(viewId: 'missing-uuid');

		$this->assertFalse($drawn['success']);
		$this->assertNull($drawn['view']);
		$this->assertSame(str_replace('missing-uuid', 'drawn-uuid', $missing['error']), $drawn['error']);
	}//end testADrawnViewReadsAsMissing()

	/**
	 * An imported view and one saved before `origin` existed are still served.
	 *
	 * @return void
	 */
	public function testImportedAndLegacyViewsAreServed(): void {
		$this->currentUserId = 'bob';
		$this->activeOrganisation = 'org-b';
		$this->objectService->method('find')->willReturnCallback(
			fn (int|string $id): ObjectEntityInterface => $this->entity(['id' => (string)$id, 'name' => 'GEMMA view'] + ($id === 'gemma' ? ['origin' => ViewOrigin::IMPORTED] : []))
		);

		foreach (['gemma', 'legacy'] as $id) {
			$result = $this->viewService->getView(viewId: $id);
			$this->assertTrue($result['success'], $id);
		}
	}//end testImportedAndLegacyViewsAreServed()
}//end class
