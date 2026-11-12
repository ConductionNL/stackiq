<?php

/**
 * A contact person's organisation never takes over another organisation by name.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

if (class_exists('OCA\\OpenRegister\\Exception\\OrganisationSlugConflictException') === false) {
	require_once __DIR__ . '/../../../Stubs/OpenRegister/Exception/OrganisationSlugConflictException.php';
}

/**
 * The contact person's user joins no organisation it does not belong to.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 * @uses   \OCA\Stackiq\Service\OrganisationSlug
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */
class ContactPersonHandlerSlugConflictTest extends TestCase {

	/**
	 * The slugs findBySlug() was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $slugs = [];

	/**
	 * The organisations written.
	 *
	 * @var array<int, string>
	 */
	private array $writes = [];

	/**
	 * The log levels and messages.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $logs = [];

	/**
	 * The organisations created.
	 *
	 * @var array<int, string>
	 */
	private array $created = [];

	/**
	 * A same-named organisation is not joined, and no second one is created.
	 *
	 * @return void
	 */
	public function testASameNamedOrganisationIsNotJoined(): void {
		$name = 'Gemeente ' . str_repeat('a', 120);
		$this->handler(name: $name, slugTakenBy: 'o-other')->addUserToOrganizationEntity($this->contact(), 'new@gemeente.nl');

		self::assertSame([substr('gemeente-' . str_repeat('a', 120), 0, 100)], $this->slugs);
		self::assertSame([], $this->writes);
		self::assertSame([], $this->created);
	}//end testASameNamedOrganisationIsNotJoined()

	/**
	 * OpenRegister refusing the taken slug leaves the user out as well.
	 *
	 * @return void
	 */
	public function testARefusedSlugJoinsNothing(): void {
		$this->handler(name: 'Gemeente B', slugTakenBy: null, refuse: true)->addUserToOrganizationEntity($this->contact(), 'new@gemeente.nl');

		self::assertSame(['Gemeente B'], $this->created);
		self::assertSame([], $this->writes);
		self::assertContains('warning', array_column($this->logs, 0), 'a refused slug is a neutral warning, not an error');
		self::assertNotContains('error', array_column($this->logs, 0));
	}//end testARefusedSlugJoinsNothing()

	/**
	 * A free slug creates the organisation and adds the user to it.
	 *
	 * @return void
	 */
	public function testAFreeSlugCreatesTheOrganisationAndAddsTheUser(): void {
		$this->handler(name: 'Gemeente C', slugTakenBy: null)->addUserToOrganizationEntity($this->contact(), 'new@gemeente.nl');

		self::assertSame(['gemeente-c'], $this->slugs);
		self::assertSame(['Gemeente C'], $this->created);
		self::assertSame(['org-1'], $this->writes);
	}//end testAFreeSlugCreatesTheOrganisationAndAddsTheUser()

	/**
	 * A contact person of organisatie org-1.
	 *
	 * @return object
	 */
	private function contact(): object {
		return new class() {
			/**
			 * @return string
			 */
			public function getId(): string {
				return 'contact-1';
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return ['organisation' => 'org-1'];
			}
		};
	}//end contact()

	/**
	 * The handler over an organisatie org-1 that has no entity yet.
	 *
	 * @param string      $name        The organisatie's name.
	 * @param string|null $slugTakenBy The uuid of an organisation holding the slug, or null when free.
	 * @param bool        $refuse      Whether OpenRegister refuses the create as a taken slug.
	 *
	 * @return ContactPersonHandler
	 */
	private function handler(string $name, ?string $slugTakenBy, bool $refuse = false): ContactPersonHandler {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willThrowException(new DoesNotExistException('missing'));
		$mapper->method('findBySlug')->willReturnCallback(
			function (string $slug) use ($slugTakenBy) {
				$this->slugs[] = $slug;
				if ($slugTakenBy === null) {
					throw new DoesNotExistException('no slug');
				}

				return $this->organisation(uuid: $slugTakenBy);
			}
		);
		foreach (['update', 'save'] as $write) {
			$mapper->method($write)->willReturnCallback(
				function ($organisation) {
					$this->writes[] = $organisation->getUuid();
					return $organisation;
				}
			);
		}

		$organisatie = $this->createMock(ObjectEntity::class);
		$organisatie->method('getObject')->willReturn(['name' => $name]);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($organisatie);

		$organisations = new class($this, $refuse) {
			/**
			 * @param ContactPersonHandlerSlugConflictTest $test   The test.
			 * @param bool                                 $refuse Whether to refuse.
			 */
			public function __construct(private ContactPersonHandlerSlugConflictTest $test, private bool $refuse) {
			}

			/**
			 * @param mixed ...$args The organisation details, then whether the caller joins.
			 *
			 * @return Organisation
			 */
			public function createOrganisation(...$args): Organisation {
				return $this->test->create(name: ($args['details'] ?? $args[0])->name, refuse: $this->refuse);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => '5', 'organisatie_schema' => '1']);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => [
				'OCA\\OpenRegister\\Db\\OrganisationMapper'      => $mapper,
				'OCA\OpenRegister\Service\ObjectService'        => $objects,
				'OCA\Stackiq\Service\SettingsService'           => $settings,
				'OCA\\OpenRegister\\Service\\OrganisationService' => $organisations,
			][$id] ?? null
		);

		return new ContactPersonHandler(
			$this->createMock(IUserManager::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$container,
			$apps,
			$this->logger(),
			$this->createMock(SymfonyEmailService::class),
			$this->createMock(IConfig::class)
		);
	}//end handler()

	/**
	 * A logger that keeps each level and message.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class($this) extends AbstractLogger {
			/**
			 * @param ContactPersonHandlerSlugConflictTest $test The test.
			 */
			public function __construct(private ContactPersonHandlerSlugConflictTest $test) {
			}

			/**
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->logs[] = [(string) $level, (string) $message];
			}
		};
	}//end logger()

	/**
	 * Record a create and answer it, or refuse it as a taken slug.
	 *
	 * @param string $name   The organisation name.
	 * @param bool   $refuse Whether to refuse.
	 *
	 * @return Organisation The created organisation.
	 */
	public function create(string $name, bool $refuse): Organisation {
		$this->created[] = $name;
		if ($refuse === true) {
			throw new \OCA\OpenRegister\Exception\OrganisationSlugConflictException(slug: 'taken');
		}

		return $this->organisation(uuid: 'org-1');
	}//end create()

	/**
	 * An organisation entity double with users.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return Organisation
	 */
	private function organisation(string $uuid): Organisation {
		return new class($uuid) extends Organisation {
			/**
			 * @var array<int, string>
			 */
			private array $members = [];

			/**
			 * @param string $entityUuid The uuid.
			 */
			public function __construct(private string $entityUuid) {
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return $this->entityUuid;
			}

			/**
			 * @param string $name The method.
			 * @param array  $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args) {
				if ($name === 'getUsers') {
					return $this->members;
				}

				if ($name === 'setUsers') {
					$this->members = $args[0];
				}

				return null;
			}
		};
	}//end organisation()
}//end class
