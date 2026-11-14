<?php

/**
 * Unit tests for ConnectionSuggestionController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connections-derived-dependencies/tasks.md#task-3-endpoints-with-a-per-object-guard
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

require_once __DIR__ . '/../Service/ConnectionSuggestionStore.php';

use OCA\Stackiq\Controller\ConnectionSuggestionController;
use OCA\Stackiq\Service\ConnectionSuggestionService;
use OCA\Stackiq\Service\OpenRegisterLookup;
use OCA\Stackiq\Tests\Unit\Service\ConnectionSuggestionStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The per-usage guard on the three routes.
 */
#[CoversClass(ConnectionSuggestionController::class)]
#[UsesClass(ConnectionSuggestionService::class)]
#[UsesClass(OpenRegisterLookup::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\OCA\Stackiq\Service\RelationRefs::class)]
class ConnectionSuggestionControllerTest extends TestCase {
	/**
	 * The store.
	 *
	 * @var ConnectionSuggestionStore
	 */
	private ConnectionSuggestionStore $store;

	/**
	 * A usage of municipality A with one suggestion.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new ConnectionSuggestionStore();
		$this->store->put('u-x', 10, ['consumer' => 'org-a', 'participants' => ['org-c'], 'module' => 'mod-x', 'koppelingen' => []]);
		$this->store->put('u-y', 10, ['consumer' => 'org-a', 'module' => 'mod-y']);
		$this->store->put('c-xy', 20, ['name' => 'X to Y', 'moduleA' => 'mod-x', 'moduleB' => 'mod-y']);
	}

	/**
	 * Another organisation gets 403 on every route, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnotherOrganisationIsRefusedAndNothingChanges(): void {
		$controller = $this->controller(organisation: 'org-b', ids: ['c-xy']);

		foreach (['index', 'accept', 'dismiss'] as $method) {
			$response = $controller->$method('u-x');
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $method);
		}

		$this->assertSame([], $this->store->saves);
		$this->assertSame([], $this->store->objects['u-x']['data']['koppelingen']);
	}

	/**
	 * Without an active organisation the caller is refused too.
	 *
	 * @return void
	 */
	public function testNoActiveOrganisationIsRefused(): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(organisation: null, ids: ['c-xy'])->accept('u-x')->getStatus());
		$this->assertSame([], $this->store->saves);
	}

	/**
	 * The owner lists and accepts; the connection is then on the usage.
	 *
	 * @return void
	 */
	public function testTheOwnerAccepts(): void {
		$controller = $this->controller(organisation: 'org-a', ids: ['c-xy']);

		$this->assertSame(['c-xy'], array_column($controller->index('u-x')->getData()['results'], 'id'));
		$response = $controller->accept('u-x');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['accepted' => ['c-xy']], $response->getData());
		$this->assertSame(['c-xy'], $this->store->objects['u-x']['data']['koppelingen']);
	}

	/**
	 * A participant dismisses; ids that are not a list are ignored.
	 *
	 * @return void
	 */
	public function testAParticipantDismisses(): void {
		$this->assertSame(['dismissed' => ['c-xy']], $this->controller(organisation: 'org-c', ids: ['c-xy', 7, ''])->dismiss('u-x')->getData());
		$this->assertSame(['dismissed' => []], $this->controller(organisation: 'org-c', ids: 'c-xy')->dismiss('u-x')->getData());
	}

	/**
	 * An unknown usage is 404.
	 *
	 * @return void
	 */
	public function testAnUnknownUsageIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(organisation: 'org-a', ids: [])->index('nope')->getStatus());
	}

	/**
	 * The controller with this active organisation and these request ids.
	 *
	 * @param string|null $organisation The active organisation, or null.
	 * @param mixed       $ids          The `ids` request parameter.
	 *
	 * @return ConnectionSuggestionController
	 */
	private function controller(?string $organisation, mixed $ids): ConnectionSuggestionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $key === 'ids' ? $ids : $default);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$organisations = new class($organisation) {
			public function __construct(private ?string $uuid) {
			}

			public function getActiveOrganisation(): ?object {
				if ($this->uuid === null) {
					return null;
				}

				return new class($this->uuid) {
					public function __construct(private string $uuid) {
					}

					public function getUuid(): string {
						return $this->uuid;
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisations);

		return new ConnectionSuggestionController(
			'stackiq',
			$request,
			new ConnectionSuggestionService($this->store->config(test: $this)),
			new OpenRegisterLookup($this->createMock(IAppManager::class), $container, $session, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class)
		);
	}
}
