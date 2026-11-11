<?php

/**
 * Golden tests for ViewService::getView() and the views list read.
 *
 * Pins the responses of getView() (not found, plain, enriched with module
 * expansion, failure), and the list read behind getAllViews(): the cache hit,
 * the query, the serialisation of entities, the cache write, the log calls
 * and the refusals without OpenRegister or configuration, so splitting them
 * keeps every outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
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
use ReflectionClass;

/**
 * Golden tests for reading one view and the views list.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ViewServiceReadGoldenTest extends TestCase {

	/**
	 * Every logger, cache and search call, in order (traces left out).
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * The AMEF config.
	 *
	 * @var array
	 */
	private array $amefConfig = ['register' => 'amef-legacy', 'view_schema' => 'view', 'module_schema' => 'module', 'register_id' => 'amef'];

	/**
	 * Whether OpenRegister is installed.
	 *
	 * @var boolean
	 */
	private bool $installed = true;

	/**
	 * What the cache holds for any key.
	 *
	 * @var mixed
	 */
	private mixed $cached = null;

	/**
	 * What find() returns, or a Throwable to throw.
	 *
	 * @var mixed
	 */
	private mixed $found = null;

	/**
	 * Rows per schema.
	 *
	 * @var array<string, array>
	 */
	private array $rows = [];

	/**
	 * Build the service.
	 *
	 * @return ViewService
	 */
	private function service(): ViewService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (): bool => $this->installed);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getAmefConfig')->willReturnCallback(fn (): array => $this->amefConfig);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$organisation = new Organisation();
		$organisation->setUuid('org-own');
		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getActiveOrganisation')->willReturn($organisation);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$this->calls[] = ['search', $query];
				return $this->rows[$query['@self']['schema']] ?? [];
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (string $id) {
				$this->calls[] = ['find', $id];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === ObjectServiceInterface::class ? $objectService : $organisationService
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['trace']);
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key): mixed {
				$this->calls[] = ['cache.get', $key];
				return $this->cached;
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->calls[] = ['cache.set', $key, $value, $ttl];
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		return new ViewService(
			config: $this->createMock(IAppConfig::class),
			appManager: $appManager,
			container: $container,
			logger: $logger,
			settingsService: $settingsService,
			userSession: $userSession,
			cacheFactory: $cacheFactory
		);
	}//end service()

	/**
	 * An entity whose serialisation is this data.
	 *
	 * @param array $data The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Read the views list through the private reader.
	 *
	 * @return mixed The list, or the Throwable it raised.
	 */
	private function readList(): mixed {
		$target = (new ReflectionClass(objectOrClass: ViewService::class))->getMethod(name: 'getViewsFromRegister');
		$target->setAccessible(accessible: true);
		try {
			return $target->invoke($this->service());
		} catch (\Throwable $e) {
			return get_class($e) . ': ' . $e->getMessage();
		}
	}//end readList()

	/**
	 * The list read: query, serialisation, cache write and log; then the cache hit.
	 *
	 * @return void
	 */
	public function testTheListIsReadSerialisedAndCached(): void {
		$this->rows['view'] = [$this->entity(data: ['id' => 'v1']), ['id' => 'v2'], 'raw'];
		$key = 'views_list_' . hash('sha256', "alice\norg-own");

		$this->assertSame([['id' => 'v1'], ['id' => 'v2'], 'raw'], $this->readList());
		$this->assertSame(
			[
				['cache.get', $key],
				['search', ['@self' => ['register' => 'amef', 'schema' => 'view'], '_limit' => 500]],
				['cache.set', $key, [['id' => 'v1'], ['id' => 'v2'], 'raw'], 1800],
				['debug', 'Retrieved and cached views from register', ['register_id' => 'amef', 'view_schema_id' => 'view', 'views_count' => 3]],
			],
			$this->calls
		);

		$this->calls = [];
		$this->cached = [['id' => 'cached']];
		$this->assertSame([['id' => 'cached']], $this->readList());
		$this->assertSame(
			[
				['cache.get', $key],
				['debug', 'Returning cached views', ['views_count' => 1]],
			],
			$this->calls
		);
	}//end testTheListIsReadSerialisedAndCached()

	/**
	 * The list read refuses without OpenRegister or configuration, and falls back to the legacy register key.
	 *
	 * @return void
	 */
	public function testTheListReadRefusesWithoutOpenRegisterOrConfiguration(): void {
		$this->installed = false;
		$this->assertSame('RuntimeException: OpenRegister ObjectService not available', $this->readList());

		$this->installed = true;
		$this->amefConfig = ['register' => 'amef'];
		$this->assertSame('RuntimeException: AMEF configuration not found for views', $this->readList());
		$this->amefConfig = ['view_schema' => 'view'];
		$this->assertSame('RuntimeException: AMEF configuration not found for views', $this->readList());

		$this->calls = [];
		$this->amefConfig = ['register' => 'amef-legacy', 'view_schema' => 'view'];
		$this->readList();
		$this->assertSame(['search', ['@self' => ['register' => 'amef-legacy', 'schema' => 'view'], '_limit' => 500]], $this->calls[1]);
	}//end testTheListReadRefusesWithoutOpenRegisterOrConfiguration()

	/**
	 * getView(): not found, plain, enriched with module expansion, and failure.
	 *
	 * @return void
	 */
	public function testGetViewResponses(): void {
		$this->assertSame(
			['success' => false, 'error' => "View with ID 'v1' not found", 'view' => null],
			$this->service()->getView('v1')
		);

		$this->found = $this->entity(data: ['id' => 'v1', 'xml' => ['documentation' => 'doc']]);
		$this->assertSame(
			['success' => true, 'view' => ['id' => 'v1', 'xml' => ['documentation' => 'doc'], 'documentation' => 'doc'], 'enrichments_applied' => []],
			$this->service()->getView('v1')
		);

		$this->rows['module'] = [['id' => 'mod-1', 'name' => 'Module', 'elementRef' => 'mod-1', 'referentiecomponenten' => ['ref-a']]];
		$this->found = $this->entity(data: ['id' => 'v1', 'viewNodes' => [['viewNodeId' => 'n1', 'modelNodeId' => 'ref-a']]]);
		$enriched = $this->service()->getView('v1', ['include_modules' => true]);
		$this->assertSame(['modules'], $enriched['enrichments_applied']);
		$this->assertCount(2, $enriched['view']['viewNodes']);
		$this->assertSame([], $enriched['view']['viewNodes'][0]['modules']);
		$this->assertSame('mod-1', $enriched['view']['viewNodes'][1]['modelNodeId']);

		$plain = $this->service()->getView('v1', ['include_products' => false]);
		$this->assertCount(1, $plain['view']['viewNodes']);
		$this->assertSame([], $plain['enrichments_applied']);

		$this->calls = [];
		$this->amefConfig = [];
		$this->assertSame(
			['success' => false, 'error' => 'AMEF configuration not found for views', 'view' => null],
			$this->service()->getView('v2', ['include_modules' => true])
		);
		$this->assertSame(
			[
				['info', 'Getting specific view', ['view_id' => 'v2', 'options' => ['include_modules' => true]]],
				['error', 'Failed to get view', ['view_id' => 'v2', 'error' => 'AMEF configuration not found for views']],
			],
			$this->calls
		);
	}//end testGetViewResponses()

}//end class
