<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Service;

use OCA\Stackiq\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-111 rule 1 — the shipped demo dataset.
 *
 * Every failure path here exists because the caller reports the outcome to an
 * operator who just asked for demo data: "nothing happened" must never be
 * presentable as success, so each one must THROW rather than return empty.
 */
class DemoDataServiceTest extends TestCase {
	private string $appPath;
	private IAppManager $appManager;
	private ContainerInterface $container;

	protected function setUp(): void {
		$this->appPath = sys_get_temp_dir() . '/or-demo-' . uniqid();
		mkdir($this->appPath . '/lib/Settings', 0777, true);

		$this->appManager = $this->createMock(originalClassName: IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$this->appManager->method('getAppVersion')->willReturn('1.2.3');
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
	}

	protected function tearDown(): void {
		$file = $this->descriptor();
		if (is_file($file) === true) {
			unlink($file);
		}

		@rmdir($this->appPath . '/lib/Settings');
		@rmdir($this->appPath . '/lib');
		@rmdir($this->appPath);
	}

	private function descriptor(): string {
		return $this->appPath . '/lib/Settings/stackiq_mock_register.json';
	}

	private function service(): DemoDataService {
		return new DemoDataService(
			appManager: $this->appManager,
			container: $this->container,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}

	public function testIsAvailableIsFalseWithoutADescriptor(): void {
		$this->assertFalse(condition: $this->service()->isAvailable());
	}

	public function testIsAvailableIsTrueWithADescriptor(): void {
		file_put_contents($this->descriptor(), '{}');

		$this->assertTrue(condition: $this->service()->isAvailable());
	}

	public function testDecliningIsOfferedEvenWhenNoDatasetShips(): void {
		// 🔴 "NO THANKS" HAS TO BE SAYABLE. Every app in this fleet implemented a
		// `skip-demo-data` action that no manifest step could reach, so the step
		// stayed outstanding and CnAppRoot reopened the wizard over every page
		// unless the operator imported data they did not want.
		$choices = $this->service()->listChoices();

		$this->assertSame(expected: ['none'], actual: array_column($choices, 'id'));
		$this->assertNotSame(expected: '', actual: $choices[0]['description']);
		$this->assertNotSame(expected: '', actual: $choices[0]['icon']);
	}

	public function testTheShippedDatasetIsOfferedWithTheCountItActuallyCarries(): void {
		// The card promises a number, so the number has to come from the file
		// that will be imported rather than from a manifest that could disagree
		// with it.
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2], ['c' => 3]]]])
		);

		$choices = $this->service()->listChoices();

		$this->assertSame(expected: ['none', 'demo'], actual: array_column($choices, 'id'));
		$this->assertSame(expected: 3, actual: $choices[1]['objectCount']);
		$this->assertNotSame(expected: '', actual: $choices[1]['label']);
		$this->assertNotSame(expected: '', actual: $choices[1]['description']);
	}

	public function testAMalformedDescriptorOffersNothingRatherThanAnImportThatCannotRun(): void {
		file_put_contents($this->descriptor(), 'not json at all');

		$this->assertSame(expected: ['none'], actual: array_column($this->service()->listChoices(), 'id'));
	}

	public function testTheOfferedDescriptionCarriesNoNumber(): void {
		// 🔴 THE WIZARD TRANSLATES A CARD'S DESCRIPTION BY LITERAL LOOKUP. A
		// count interpolated into the sentence would make it untranslatable and
		// leave a Dutch operator reading English. The count travels separately,
		// as `objectCount`.
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1]]]])
		);

		$demo = $this->service()->listChoices()[1];

		$this->assertDoesNotMatchRegularExpression(pattern: '/\d/', string: $demo['description']);
	}

	public function testADescriptorWithNoObjectsBlockOffersTheSetWithNoCount(): void {
		// A descriptor can ship schemas and no objects. That is a real dataset
		// with nothing to count, not a broken file, so it stays on offer.
		file_put_contents($this->descriptor(), json_encode(['components' => ['schemas' => []]]));

		$choices = $this->service()->listChoices();

		$this->assertSame(expected: ['none', 'demo'], actual: array_column($choices, 'id'));
		$this->assertSame(expected: 0, actual: $choices[1]['objectCount']);
	}

	public function testInstallThrowsWhenNoDatasetShips(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/No demo dataset/');

		$this->service()->install();
	}

	public function testInstallThrowsOnInvalidJson(): void {
		file_put_contents($this->descriptor(), 'not json at all');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/not valid JSON/');

		$this->service()->install();
	}

	public function testInstallNamesTheMissingAppWhenOpenRegisterIsAbsent(): void {
		file_put_contents($this->descriptor(), '{"components":{"objects":[]}}');
		$this->appManager = $this->createMock(originalClassName: IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$this->appManager->method('getInstalledApps')->willReturn([]);

		// 🔴 The message must NAME the missing app. Asking the container for a
		// class from an app that is not installed otherwise surfaces as an error
		// about a class the operator never mentioned.
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/OpenRegister/');

		$this->service()->install();
	}

	public function testInstallCountsTheObjectsInTheFileNotTheImportersReply(): void {
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2], ['c' => 3]]]])
		);

		// 🔴 THE PARAMETER NAMES ARE THE CONTRACT. install() calls this with
		// named arguments, so a fake whose parameters are named differently
		// fails at the call site rather than validating anything.
		$importer = new class {
			public array $seen = [];

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->seen = ['appId' => $appId, 'version' => $version, 'force' => $force];

				// Deliberately reports FEWER than the file holds: an object whose
				// schema does not resolve is skipped, and the operator is told
				// what was ASKED FOR so the discrepancy stays visible.
				return ['registers' => [1], 'schemas' => [1, 1]];
			}
		};
		$this->container->method('get')->willReturn($importer);

		$result = $this->service()->install();

		$this->assertSame(expected: 3, actual: $result['objects']);
		$this->assertSame(expected: 1, actual: $result['registers']);
		$this->assertSame(expected: 2, actual: $result['schemas']);

		// Its own configuration namespace, so a demo import cannot mask — or be
		// masked by — a pending real configuration update.
		$this->assertSame(expected: 'stackiq.demo', actual: $importer->seen['appId']);
		$this->assertTrue(condition: $importer->seen['force']);
	}

	/**
	 * Relative dates are resolved against the import date.
	 *
	 * @return void
	 */
	public function testRelativeDatesAreResolvedAgainstTheImportDate(): void {
		// The demo review dates are written relative (`-P7D`): a fixed date
		// would leave "reviewed a week ago" a year old a year later.
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['accessReviewedAt' => '-P7D'], ['accessReviewedAt' => '2020-01-01T00:00:00+00:00']]]])
		);

		$importer = new class {
			/**
			 * Objects.
			 *
			 * @var array
			 */
			public array $objects = [];

			/**
			 * Import from app.
			 *
			 * @param string $appId   App Id.
			 * @param array  $data    Data.
			 * @param string $version Version.
			 * @param bool   $force   Force.
			 *
			 * @return array
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->objects = $data['components']['objects'];
				return [];
			}
		};
		$this->container->method('get')->willReturn($importer);

		$before = new \DateTimeImmutable('-7 days -1 minute');
		$this->service()->install();
		$after = new \DateTimeImmutable('-7 days +1 minute');

		$stamped = new \DateTimeImmutable($importer->objects[0]['accessReviewedAt']);
		$this->assertGreaterThanOrEqual(expected: $before->getTimestamp(), actual: $stamped->getTimestamp());
		$this->assertLessThanOrEqual(expected: $after->getTimestamp(), actual: $stamped->getTimestamp());
		$this->assertSame(expected: '2020-01-01T00:00:00+00:00', actual: $importer->objects[1]['accessReviewedAt']);
	}

	public function testEachObjectIsPointedAtTheSchemaOfItsOwnRegister(): void {
		file_put_contents(
			$this->descriptor(),
			json_encode([
				'components' => [
					'objects' => [
						['@self' => ['register' => 'stackiq', 'schema' => 'organization', 'slug' => 'org-1']],
						['@self' => ['register' => 'stackiq', 'schema' => 'notInTheRegister', 'slug' => 'x-1']],
						['@self' => ['register' => 'missing', 'schema' => 'organization', 'slug' => 'org-2']],
					],
				],
			])
		);

		$importer = new class {
			public array $data = [];

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->data = $data;
				return [];
			}
		};
		// 🔴 TWO SCHEMAS SHARE THE SLUG `organization` ON A REAL INSTANCE: OpenCatalogi's
		// (id 3) and stackiq's (id 9). Only the one the register lists is the target.
		$schema = static fn (int $id, string $slug): object => new class ($id, $slug) {
			public function __construct(private int $id, private string $slug) {
			}

			public function getId(): int {
				return $this->id;
			}

			public function getSlug(): string {
				return $this->slug;
			}
		};
		$registers = new class {
			public function find(string|int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				if ($id !== 'stackiq') {
					throw new \RuntimeException('not found');
				}

				return new class {
					public function getSchemas(): array {
						return [5, 9];
					}
				};
			}
		};
		$schemas = new class ($schema) {
			public function __construct(private \Closure $schema) {
			}

			public function findMultiple(array $ids, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->lastIds = $ids;
				return [($this->schema)(5, 'module'), ($this->schema)(9, 'organization')];
			}

			public array $lastIds = [];
		};
		$this->container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Db\RegisterMapper' => $registers,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
				default => $importer,
			}
		);

		$this->service()->install();

		$selves = array_column($importer->data['components']['objects'], '@self');
		$this->assertSame(expected: '9', actual: $selves[0]['schema'], message: 'resolved within the register it names');
		$this->assertSame(
			expected: 'notInTheRegister',
			actual: $selves[1]['schema'],
			message: 'a slug the register does not list is left to the importer'
		);
		$this->assertSame(expected: 'organization', actual: $selves[2]['schema'], message: 'a register that cannot be read is left to the importer');
		$this->assertSame(expected: [5, 9], actual: $schemas->lastIds);
	}
	public function testAnObjectWithoutARegisterOrSchemaIsLeftAndEachRegisterIsReadOnce(): void {
		file_put_contents(
			$this->descriptor(),
			json_encode([
				'components' => [
					'objects' => [
						['@self' => ['register' => 'stackiq', 'schema' => 'module', 'slug' => 'm-1']],
						['@self' => ['register' => 'stackiq', 'schema' => '', 'slug' => 'm-2']],
						['@self' => ['register' => '', 'schema' => 'module', 'slug' => 'm-3']],
						['@self' => ['register' => 'stackiq', 'schema' => 'module', 'slug' => 'm-4']],
						['naam' => 'no self'],
					],
				],
			])
		);

		$importer = new class {
			public array $data = [];

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->data = $data;
				return [];
			}
		};
		$registers = new class {
			public int $finds = 0;

			public function find(string|int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				$this->finds++;
				return new class {
					public function getSchemas(): array {
						return [5];
					}
				};
			}
		};
		$schemas = new class {
			public function findMultiple(array $ids, bool $_rbac = true, bool $_multitenancy = true): array {
				return [new class {
					public function getId(): int {
						return 5;
					}

					public function getSlug(): string {
						return 'module';
					}
				}];
			}
		};
		$this->container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Db\RegisterMapper' => $registers,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
				default => $importer,
			}
		);

		$this->service()->install();

		$objects = $importer->data['components']['objects'];
		$this->assertSame(
			expected: ['5', '', 'module', '5'],
			actual: array_map(static fn (array $object): string => $object['@self']['schema'], array_slice($objects, 0, 4))
		);
		$this->assertSame(expected: ['naam' => 'no self'], actual: $objects[4]);
		$this->assertSame(expected: 1, actual: $registers->finds, message: 'a register is read once');
	}
}
