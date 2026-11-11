<?php

/**
 * Golden test for the account-manager guard of ContactpersonenController's
 * enableUser, disableUser and getBulkUserInfo.
 *
 * Pins, per endpoint and caller (anonymous, ordinary user, administrator,
 * gebruik-beheerder, aanbod-beheerder), the status, body, every group
 * manager and contactpersoon service call, and the log lines; plus a
 * failing enable and disable for an administrator.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\ContactpersonenController;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden account-manager guard.
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 */
class ContactpersonenAccountGuardGoldenTest extends TestCase {

	/**
	 * The callers: uid (null for anonymous), admin flag and the groups they are in.
	 */
	private const CALLERS = [
		'anonymous' => [null, false, []],
		'ordinary user' => ['bob', false, []],
		'administrator' => ['admin', true, []],
		'gebruik-beheerder' => ['gb', false, ['gebruik-beheerder']],
		'aanbod-beheerder' => ['ab', false, ['aanbod-beheerder']],
	];

	/**
	 * Every endpoint answers every caller as pinned.
	 *
	 * @return void
	 */
	public function testEveryEndpointAnswersEveryCallerAsPinned(): void {
		$out = [];
		foreach (['enableUser', 'disableUser', 'getBulkUserInfo'] as $endpoint) {
			foreach (self::CALLERS as $name => $caller) {
				$out[$endpoint . ' ' . $name] = $this->outcome(endpoint: $endpoint, caller: $caller, throws: false);
			}
		}

		foreach (['enableUser', 'disableUser'] as $endpoint) {
			$out[$endpoint . ' fails'] = $this->outcome(endpoint: $endpoint, caller: self::CALLERS['administrator'], throws: true);
		}

		$path = dirname(__DIR__, 2) . '/fixtures/contactpersonen-account-guard.json';
		if (getenv(name: 'RECORD_GOLDEN') !== false) {
			file_put_contents(filename: $path, data: json_encode(value: $out, flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(
			expected: json_decode(json: (string) file_get_contents(filename: $path), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR),
			actual: $out
		);

	}//end testEveryEndpointAnswersEveryCallerAsPinned()

	/**
	 * Call one endpoint as one caller.
	 *
	 * @param string $endpoint The endpoint.
	 * @param array  $caller   uid, admin flag, groups.
	 * @param bool   $throws   Whether the contactpersoon service throws.
	 *
	 * @return array{status: int, body: mixed, calls: array}
	 */
	private function outcome(string $endpoint, array $caller, bool $throws): array {
		[$uid, $isAdmin, $groups] = $caller;
		$calls   = new \ArrayObject();
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			static function (string $id) use ($calls, $isAdmin): bool {
				$calls[] = ['isAdmin', $id];
				return $isAdmin;
			}
		);
		$groupManager->method('isInGroup')->willReturnCallback(
			static function (string $id, string $group) use ($calls, $groups): bool {
				$calls[] = ['isInGroup', $id, $group];
				return in_array(needle: $group, haystack: $groups, strict: true);
			}
		);
		$contacts = $this->createMock(originalClassName: ContactpersoonService::class);
		foreach (['enableUserForContactpersoon', 'disableUserForContactpersoon'] as $method) {
			$contacts->method($method)->willReturnCallback(
				static function (string $id) use ($calls, $method, $throws): bool {
					$calls[] = [$method, $id];
					if ($throws === true) {
						throw new \RuntimeException('account store down');
					}

					return true;
				}
			);
		}

		$controller = new ContactpersonenController(
			'stackiq',
			$this->createMock(originalClassName: IRequest::class),
			$this->createMock(originalClassName: SettingsService::class),
			$this->createMock(originalClassName: ContactPersonHandler::class),
			$contacts,
			$this->createMock(originalClassName: IUserManager::class),
			$groupManager,
			$session,
			$this->createMock(originalClassName: ISecureRandom::class),
			$this->logger(calls: $calls),
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
			organisationService: $this->createMock(originalClassName: OrganisationService::class),
			organisationGuard: $this->createMock(originalClassName: ContactPersonOrganisationGuard::class),
		);
		if ($endpoint === 'getBulkUserInfo') {
			$response = $controller->getBulkUserInfo();
		} else {
			$response = $controller->$endpoint(contactPersonId: 'cp-1');
		}

		return ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $calls->getArrayCopy()];

	}//end outcome()

	/**
	 * A logger that records info, warning and error lines next to the other calls.
	 *
	 * @param \ArrayObject $calls The shared call record.
	 *
	 * @return AbstractLogger
	 */
	private function logger(\ArrayObject $calls): AbstractLogger {
		return new class($calls) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param \ArrayObject $calls The shared call record.
			 */
			public function __construct(private \ArrayObject $calls) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				if ((string) $level === 'debug') {
					return;
				}

				$this->calls[] = ['log.' . $level, (string) $message, $context];
			}
		};

	}//end logger()

}//end class
