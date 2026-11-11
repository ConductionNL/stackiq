<?php

/**
 * Characterisation test for setUserActive and setUserInactive of
 * ContactPersonHandler.
 *
 * Pins, for both: an unknown user is refused (false, a warning and no
 * change), a known user is enabled or disabled (true, an info line), and a
 * failing user manager gives false and an error line.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Characterisation of enabling and disabling a contact person's account.
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */
class ContactPersonHandlerUserEnabledTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The two actions and the state word and enabled flag of each.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function actions(): array {
		return [
			'active'   => ['setUserActive', 'active', true],
			'inactive' => ['setUserInactive', 'inactive', false],
		];

	}//end actions()

	/**
	 * An unknown user is refused without a change.
	 *
	 * @param string $action The action.
	 * @param string $state  The state word in the log lines.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testAnUnknownUserIsRefused(string $action, string $state): void {
		$this->assertFalse(condition: $this->invoke(action: $action, plan: 'unknown'));
		$this->assertSame(
			expected: [['users.get', 'jan'], ['log.warning', 'User not found when trying to set ' . $state, ['username' => 'jan']]],
			actual: $this->trace
		);

	}//end testAnUnknownUserIsRefused()

	/**
	 * A known user is enabled or disabled and logged.
	 *
	 * @param string $action  The action.
	 * @param string $state   The state word in the log lines.
	 * @param bool   $enabled The enabled flag set.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testAKnownUserIsChanged(string $action, string $state, bool $enabled): void {
		$this->assertTrue(condition: $this->invoke(action: $action, plan: 'known'));
		$this->assertSame(
			expected: [['users.get', 'jan'], ['user.setEnabled', $enabled], ['log.info', 'Set user account to ' . $state, ['username' => 'jan']]],
			actual: $this->trace
		);

	}//end testAKnownUserIsChanged()

	/**
	 * A failing user manager gives false and an error line.
	 *
	 * @param string $action The action.
	 * @param string $state  The state word in the log lines.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testAFailureGivesFalse(string $action, string $state): void {
		$this->assertFalse(condition: $this->invoke(action: $action, plan: 'throws'));
		$this->assertSame(
			expected: [
				['users.get', 'jan'],
				['log.error', 'Failed to set user ' . $state . ': backend down', ['username' => 'jan', 'exception' => 'RuntimeException']],
			],
			actual: $this->trace
		);

	}//end testAFailureGivesFalse()

	/**
	 * Run one action with recording doubles.
	 *
	 * @param string $action The action.
	 * @param string $plan   unknown, known or throws.
	 *
	 * @return bool
	 */
	private function invoke(string $action, string $plan): bool {
		$this->trace = [];
		$user        = $this->createMock(originalClassName: IUser::class);
		$user->method('setEnabled')->willReturnCallback(
			function (bool $enabled): void {
				$this->trace[] = ['user.setEnabled', $enabled];
			}
		);
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) use ($plan, $user): ?IUser {
				$this->trace[] = ['users.get', $uid];
				if ($plan === 'throws') {
					throw new \RuntimeException('backend down');
				}

				return $plan === 'known' ? $user : null;
			}
		);

		$handler = (new \ReflectionClass(objectOrClass: ContactPersonHandler::class))->newInstanceWithoutConstructor();
		foreach (['_userManager' => $users, '_logger' => $this->logger()] as $prop => $value) {
			(new \ReflectionProperty(class: ContactPersonHandler::class, property: $prop))->setValue($handler, $value);
		}

		return $handler->$action(username: 'jan');

	}//end invoke()

	/**
	 * A logger that records every line, with exceptions as their class.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$trace = &$this->trace;
		return new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
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
				if (isset($context['exception']) === true && $context['exception'] instanceof \Throwable) {
					$context['exception'] = get_class($context['exception']);
				}

				$this->trace[] = ['log.' . $level, (string) $message, $context];
			}
		};

	}//end logger()

}//end class
