<?php

/**
 * Unit tests for ControllerRegistrar.
 *
 * Asserts that the hand-written ContactpersonenController factory resolves
 * every constructor dependency from the container under the id it is bound
 * to, builds the controller, and that the controller interface is aliased to
 * it.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\ContactpersonenController;
use OCA\Stackiq\Controller\ControllerRegistrar;
use OCA\Stackiq\Kernel\Contracts\ContactPersonHandlerInterface;
use OCA\Stackiq\Kernel\Contracts\ContactPersonOrganisationGuardInterface;
use OCA\Stackiq\Kernel\Contracts\ContactpersonenControllerInterface;
use OCA\Stackiq\Kernel\Contracts\ContactpersoonServiceInterface;
use OCA\Stackiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(ControllerRegistrar::class)]
#[UsesClass(ContactpersonenController::class)]
class ControllerRegistrarTest extends TestCase {

	/**
	 * Services registered on the context, by name.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * Aliases registered on the context, alias => target.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = [];

	/**
	 * Run register() against a recording context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->factories[$name] = $factory;
			}
		);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				$this->aliases[$alias] = $target;
			}
		);

		(new ControllerRegistrar())->register(context: $context);
	}//end setUp()

	/**
	 * Only the contact persons controller is registered, and its interface aliases it.
	 *
	 * @return void
	 */
	public function testRegistersTheContactpersonenFactoryAndItsAlias(): void {
		self::assertSame([ContactpersonenController::class], array_keys($this->factories));
		self::assertSame(
			[ContactpersonenControllerInterface::class => ContactpersonenController::class],
			$this->aliases
		);
	}//end testRegistersTheContactpersonenFactoryAndItsAlias()

	/**
	 * The factory reads each dependency once, by its bound id, and builds the controller.
	 *
	 * @return void
	 */
	public function testTheFactoryBuildsTheControllerFromTheContainer(): void {
		$services = [
			'OCP\IRequest'                                 => $this->createMock(IRequest::class),
			SettingsServiceInterface::class                => $this->createMock(SettingsServiceInterface::class),
			ContactPersonHandlerInterface::class           => $this->createMock(ContactPersonHandlerInterface::class),
			ContactpersoonServiceInterface::class          => $this->createMock(ContactpersoonServiceInterface::class),
			'OCP\IUserManager'                             => $this->createMock(IUserManager::class),
			'OCP\IGroupManager'                            => $this->createMock(IGroupManager::class),
			'OCP\IUserSession'                             => $this->createMock(IUserSession::class),
			'OCP\Security\ISecureRandom'                   => $this->createMock(ISecureRandom::class),
			'Psr\Log\LoggerInterface'                      => $this->createMock(LoggerInterface::class),
			ObjectServiceInterface::class                  => $this->createMock(ObjectServiceInterface::class),
			OrganisationService::class                     => $this->createMock(OrganisationService::class),
			ContactPersonOrganisationGuardInterface::class => $this->createMock(ContactPersonOrganisationGuardInterface::class),
		];

		$requested = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services, &$requested): object {
				$requested[] = $id;
				return $services[$id];
			}
		);

		$controller = ($this->factories[ContactpersonenController::class])($container);

		self::assertInstanceOf(ContactpersonenController::class, $controller);
		self::assertSame(array_keys($services), $requested);
	}//end testTheFactoryBuildsTheControllerFromTheContainer()
}//end class
