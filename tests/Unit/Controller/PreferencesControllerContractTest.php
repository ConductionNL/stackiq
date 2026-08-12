<?php

/**
 * Wire-contract tests for PreferencesController's two registered public
 * endpoints (`GET`/`POST /api/preferences/{key}`).
 *
 * The contract that matters here is the key namespacing: the endpoint is
 * `@NoAdminRequired`, so any authenticated user can name any key. The
 * controller sanitises the key and prefixes it with `pref_` precisely so a
 * caller cannot reach arbitrary IConfig user values (e.g. another app's stored
 * credentials). These tests assert that the sanitisation actually happens on
 * the value that reaches IConfig — not merely that a 200 comes back.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/dashboard-views-api/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\PreferencesController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for preferences#getPreference and preferences#setPreference.
 */
class PreferencesControllerContractTest extends TestCase {

	/**
	 * The mocked config.
	 *
	 * @var IConfig|MockObject
	 */
	private IConfig|MockObject $config;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * Build the controller under test with fresh mocks.
	 *
	 * @return PreferencesController The controller under test.
	 */
	private function makeController(): PreferencesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$this->config = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);

		return new PreferencesController(
			$request,
			$this->config,
			$this->userSession
		);

	}//end makeController()

	/**
	 * Authenticate the session.
	 *
	 * @return void
	 */
	private function withUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end withUser()

	/**
	 * GET /api/preferences/{key} rejects an anonymous caller with 401 and
	 * never touches IConfig.
	 *
	 * @return void
	 */
	public function testGetPreferenceRejectsAnonymousWith401(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);
		$this->config->expects($this->never())->method('getUserValue');

		$response = $controller->getPreference('tour-seen');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not logged in'], $response->getData());

	}//end testGetPreferenceRejectsAnonymousWith401()

	/**
	 * A key that sanitises to nothing is a 400, and IConfig is not consulted.
	 *
	 * @return void
	 */
	public function testGetPreferenceRejectsAKeyThatSanitisesToNothing(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->config->expects($this->never())->method('getUserValue');

		$response = $controller->getPreference('///');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Invalid key'], $response->getData());

	}//end testGetPreferenceRejectsAKeyThatSanitisesToNothing()

	/**
	 * The key that reaches IConfig is lower-cased, stripped to
	 * `[a-z0-9-]` and prefixed with `pref_`, so a caller cannot escape the
	 * preference namespace via path traversal or an app-name prefix.
	 *
	 * @return void
	 */
	public function testGetPreferenceNamespacesAndSanitisesTheKeyItReads(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->config->expects($this->once())
			->method('getUserValue')
			->with('alice', 'softwarecatalog', 'pref_appspassword', '')
			->willReturn('');

		$response = $controller->getPreference('../apps/Password');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['value' => null], $response->getData());

	}//end testGetPreferenceNamespacesAndSanitisesTheKeyItReads()

	/**
	 * A stored value is returned under the `value` key; an unset preference
	 * reads back as an explicit null rather than an empty string.
	 *
	 * @return void
	 */
	public function testGetPreferenceReturnsTheStoredValueOrNull(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->config->method('getUserValue')->willReturn('yes');

		$this->assertSame(['value' => 'yes'], $controller->getPreference('tour-seen')->getData());

	}//end testGetPreferenceReturnsTheStoredValueOrNull()

	/**
	 * POST /api/preferences/{key} rejects an anonymous caller with 401 and
	 * never writes.
	 *
	 * @return void
	 */
	public function testSetPreferenceRejectsAnonymousWith401(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);
		$this->config->expects($this->never())->method('setUserValue');
		$this->config->expects($this->never())->method('deleteUserValue');

		$response = $controller->setPreference('tour-seen', 'yes');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not logged in'], $response->getData());

	}//end testSetPreferenceRejectsAnonymousWith401()

	/**
	 * An unsafe key is rejected with 400 before any write happens.
	 *
	 * @return void
	 */
	public function testSetPreferenceRejectsAnUnsafeKeyBeforeWriting(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->config->expects($this->never())->method('setUserValue');

		$response = $controller->setPreference('!!!', 'yes');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSetPreferenceRejectsAnUnsafeKeyBeforeWriting()

	/**
	 * A write lands on the sanitised, `pref_`-namespaced key and echoes the
	 * stored value back.
	 *
	 * @return void
	 */
	public function testSetPreferenceWritesToTheNamespacedKeyAndEchoesTheValue(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', 'softwarecatalog', 'pref_tour-seen', 'yes');

		$response = $controller->setPreference('Tour-Seen', 'yes');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['value' => 'yes'], $response->getData());

	}//end testSetPreferenceWritesToTheNamespacedKeyAndEchoesTheValue()

	/**
	 * An empty value CLEARS the preference (delete, not a stored empty
	 * string), and the response reports the cleared state as null.
	 *
	 * @return void
	 */
	public function testSetPreferenceWithAnEmptyValueDeletesTheStoredPreference(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->config->expects($this->once())
			->method('deleteUserValue')
			->with('alice', 'softwarecatalog', 'pref_tour-seen');
		$this->config->expects($this->never())->method('setUserValue');

		$response = $controller->setPreference('tour-seen', '');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['value' => null], $response->getData());

	}//end testSetPreferenceWithAnEmptyValueDeletesTheStoredPreference()
}//end class
