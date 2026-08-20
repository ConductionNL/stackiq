<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\SettingsController;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the canonical AppHost write verb on `/api/settings`.
 *
 * `PUT /api/settings` → `settings#update` is the canonical write in
 * OpenRegister's AppHost dialect; `POST /api/settings` → `settings#create`
 * is the legacy alias. SoftwareCatalog ships its own SettingsController, so
 * AppHost's generic controller is never aliased in and the leaf owes both
 * methods itself. Before this change `PUT /api/settings` answered 405.
 *
 * @spec openspec/specs/method-decomposition/spec.md#requirement-settingscontroller-settings-crud-endpoints-req-decomp-013
 */
class SettingsControllerCanonicalWriteTest extends TestCase {
	/**
	 * The canonical `/api/settings` methods, asserted item by item.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function canonicalMethodProvider(): array {
		return [
			'index (GET)' => ['index'],
			'create (POST)' => ['create'],
			'update (PUT)' => ['update'],
		];
	}

	/**
	 * Each canonical method must exist and be publicly dispatchable.
	 *
	 * Asserted on the ITEM, not on the container: "the class exists" would
	 * pass with `update()` missing entirely.
	 *
	 * @dataProvider canonicalMethodProvider
	 */
	public function testCanonicalMethodExistsAndIsDispatchable(string $method): void {
		$reflection = new ReflectionClass(SettingsController::class);

		$this->assertTrue(
			$reflection->hasMethod($method),
			sprintf('SettingsController::%s() is missing — the route would 500 on dispatch.', $method)
		);

		$ref = $reflection->getMethod($method);

		$this->assertTrue(
			$ref->isPublic(),
			sprintf('SettingsController::%s() must be public to be dispatchable.', $method)
		);
		$this->assertFalse(
			$ref->isStatic(),
			sprintf('SettingsController::%s() must not be static.', $method)
		);
		$this->assertSame(
			0,
			$ref->getNumberOfRequiredParameters(),
			sprintf('SettingsController::%s() must take no required parameters.', $method)
		);
	}

	/**
	 * Positive control: the scan above must actually have inspected methods.
	 *
	 * Without this, a provider that silently returned [] would make the whole
	 * class report green while checking nothing.
	 */
	public function testPositiveControlScanInspectedMethods(): void {
		$reflection = new ReflectionClass(SettingsController::class);

		$inspected = 0;
		foreach (self::canonicalMethodProvider() as $case) {
			if ($reflection->hasMethod($case[0]) === true) {
				$inspected++;
			}
		}

		$this->assertGreaterThan(0, $inspected, 'Positive control: the canonical-method scan matched nothing.');
		$this->assertSame(3, $inspected, 'Positive control: expected all 3 canonical methods to be inspected.');
	}

	/**
	 * `update()` must carry the SAME auth posture as `create()`.
	 *
	 * Net privilege change must be zero: `create()` declares `@NoCSRFRequired`
	 * and deliberately NOT `@NoAdminRequired`, so the middleware demands an
	 * administrator. Copying `@NoAdminRequired` from a sibling READ method
	 * here would silently open instance-wide config to any logged-in user.
	 */
	public function testUpdateHasIdenticalAuthPostureToCreate(): void {
		$createDoc = (new ReflectionMethod(SettingsController::class, 'create'))->getDocComment();
		$updateDoc = (new ReflectionMethod(SettingsController::class, 'update'))->getDocComment();

		$this->assertIsString($createDoc, 'create() must have a docblock.');
		$this->assertIsString($updateDoc, 'update() must have a docblock.');

		foreach (['@NoCSRFRequired', '@PublicPage', '@NoAdminRequired'] as $tag) {
			$this->assertSame(
				str_contains($createDoc, $tag),
				str_contains($updateDoc, $tag),
				sprintf('Auth posture drift: %s differs between create() and update().', $tag)
			);
		}

		// Pin the absolute posture too, so a future relaxation of BOTH
		// methods cannot slip through the parity check above.
		$this->assertStringNotContainsString(
			'@NoAdminRequired',
			$updateDoc,
			'update() writes instance-wide config and must stay admin-only.'
		);
		$this->assertStringNotContainsString(
			'@PublicPage',
			$updateDoc,
			'update() must never be a public page.'
		);

		// Attribute form must not sneak the posture in either.
		$updateAttrs = array_map(
			static fn ($a) => $a->getName(),
			(new ReflectionMethod(SettingsController::class, 'update'))->getAttributes()
		);
		$this->assertNotContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $updateAttrs);
		$this->assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $updateAttrs);
	}

	/**
	 * Build a controller with only the collaborators the write path touches.
	 *
	 * @param array<string, mixed> $params The request params to serve.
	 */
	private function makeController(array $params, SettingsService $settingsService): SettingsController {
		$reflection = new ReflectionClass(SettingsController::class);
		/** @var SettingsController $controller */
		$controller = $reflection->newInstanceWithoutConstructor();

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		foreach (
			[
				'request' => $request,
				'settingsService' => $settingsService,
				'logger' => $this->createMock(LoggerInterface::class),
			] as $name => $value
		) {
			$prop = $reflection->getProperty($name);
			$prop->setAccessible(true);
			$prop->setValue($controller, $value);
		}

		return $controller;
	}

	/**
	 * `update()` persists exactly the three sections `index()` reads back.
	 */
	public function testUpdateWritesConfigurationUserGroupsAndEmailSettings(): void {
		$params = [
			'configuration' => ['catalog' => 'main'],
			'userGroups' => ['generic' => ['users']],
			'emailSettings' => ['smtpHost' => 'mail.example.org'],
		];

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->expects($this->once())
			->method('updateSettings')
			->willReturn(['configuration' => ['catalog' => 'main']]);
		$settingsService->expects($this->once())
			->method('validateGroups')
			->with(['users'])
			->willReturn(['valid' => ['users'], 'invalid' => []]);
		$settingsService->expects($this->once())
			->method('setGenericUserGroups')
			->with(['users']);
		$settingsService->expects($this->once())
			->method('updateEmailSettings')
			->with(['smtpHost' => 'mail.example.org'])
			->willReturn(['smtpHost' => 'mail.example.org']);

		$response = $this->makeController($params, $settingsService)->update();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('Settings updated successfully', $data['message']);
		$this->assertSame(['catalog' => 'main'], $data['data']['configuration']['configuration']);
		$this->assertSame(['users'], $data['data']['userGroups']['generic']);
		$this->assertSame(['smtpHost' => 'mail.example.org'], $data['data']['emailSettings']);
	}

	/**
	 * `update()` must NOT absorb the other configuration surfaces.
	 *
	 * A body carrying only keys that belong to the dedicated endpoints
	 * (general/sync/cronjob/eol-sync config) must persist nothing.
	 */
	public function testUpdateIsNotACatchAllForOtherSettingsSurfaces(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->expects($this->never())->method('updateSettings');
		$settingsService->expects($this->never())->method('setGenericUserGroups');
		$settingsService->expects($this->never())->method('updateEmailSettings');

		$params = [
			'catalogLocation' => '/somewhere',
			'syncTimeWindow' => '30',
			'cronjobs' => ['enabled' => true],
			'eolSync' => ['enabled' => true],
		];

		$response = $this->makeController($params, $settingsService)->update();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $response->getData()['data']);
	}

	/**
	 * Invalid group names still short-circuit to 400 through `update()`.
	 */
	public function testUpdateReturns400OnInvalidGroupNames(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('validateGroups')
			->willReturn(['valid' => [], 'invalid' => ['no-such-group']]);
		$settingsService->expects($this->never())->method('setGenericUserGroups');

		$response = $this->makeController(
			['userGroups' => ['generic' => ['no-such-group']]],
			$settingsService
		)->update();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Invalid generic group names provided', $response->getData()['error']);
	}

	/**
	 * `create()` is a pure delegation to `update()` — identical payload.
	 */
	public function testCreateDelegatesToUpdateAndReturnsTheSamePayload(): void {
		$params = ['configuration' => ['catalog' => 'main']];

		$makeService = function (): SettingsService {
			$svc = $this->createMock(SettingsService::class);
			$svc->method('updateSettings')->willReturn(['configuration' => ['catalog' => 'main']]);
			return $svc;
		};

		$viaUpdate = $this->makeController($params, $makeService())->update();
		$viaCreate = $this->makeController($params, $makeService())->create();

		$this->assertSame($viaUpdate->getStatus(), $viaCreate->getStatus());
		$this->assertSame($viaUpdate->getData(), $viaCreate->getData());
	}

	/**
	 * The delegation is structural, not copy-paste: `create()`'s body is a
	 * single `return $this->update();`.
	 */
	public function testCreateBodyIsASingleDelegationCall(): void {
		$ref = new ReflectionMethod(SettingsController::class, 'create');
		$lines = file($ref->getFileName());
		$body = implode(
			'',
			array_slice($lines, ($ref->getStartLine() - 1), ($ref->getEndLine() - $ref->getStartLine() + 1))
		);

		$this->assertStringContainsString('return $this->update();', $body);
		$this->assertStringNotContainsString(
			'updateConfigSettings',
			$body,
			'create() must delegate, not duplicate the write logic.'
		);
	}

	/**
	 * `update()` maps a service failure to the pre-existing 500 shape.
	 */
	public function testUpdateMapsExceptionsTo500(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('updateSettings')->willThrowException(new \RuntimeException('boom'));

		$response = $this->makeController(['configuration' => ['a' => 'b']], $settingsService)->update();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('boom', $response->getData()['error']);
	}
}
