<?php

/**
 * Edge cases of CmdbImportProfile: where the pack validator comes from, and
 * a concat mapping whose separator is malformed.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';

use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The validator lookup and the concat separator fallback.
 *
 * @covers \OCA\Stackiq\Service\Cmdb\CmdbImportProfile
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportPackRules
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportPackStore
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportPackStoreProbe
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbPackLoadRun
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbPackOrigin
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbRegisterSchemaFields
 * @uses   \OCA\Stackiq\Service\Cmdb\OpenRegisterInstance
 */
final class CmdbImportProfileEdgeCasesTest extends TestCase {

	/**
	 * Directories made by a test, removed afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $directories = [];

	/**
	 * Remove the profile directories.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->directories as $directory) {
			CmdbTestSupport::removeDirectory(directory: $directory);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A copy of the shipped profile directory with one pack file changed.
	 *
	 * @param string   $file   The pack file name.
	 * @param callable $change Takes and returns the decoded pack.
	 *
	 * @return string The directory.
	 */
	private function directoryWithPack(string $file, callable $change): string {
		CmdbTestSupport::loadMigrationPack();
		$directory = CmdbTestSupport::profileDirectory(overrides: []);
		$this->directories[] = $directory;
		$path = $directory . '/' . $file;
		$pack = json_decode((string)file_get_contents($path), true);
		file_put_contents($path, json_encode($change($pack)));

		return $directory;
	}//end directoryWithPack()

	/**
	 * A container answering the translation factory, and the validator the way a callback says.
	 *
	 * @param callable $has Answers has() for the validator class.
	 * @param callable $get Answers get() for the validator class.
	 *
	 * @return ContainerInterface
	 */
	private function container(callable $has, callable $get): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $id): bool => $id === IFactory::class ? true : $has($id)
		);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === IFactory::class ? CmdbTestSupport::translationFactory(test: $this) : $get($id)
		);

		return $container;
	}//end container()

	/**
	 * The validator the container holds is the one used.
	 *
	 * @return void
	 */
	public function testTheContainerValidatorIsUsed(): void {
		CmdbTestSupport::loadMigrationPack();
		$asked = [];
		$validator = CmdbTestSupport::newPackClass(class: 'PackDefinitionValidator');
		$container = $this->container(
			has: static fn (string $id): bool => $id === CmdbImportProfile::VALIDATOR_CLASS,
			get: static function (string $id) use (&$asked, $validator): object {
				$asked[] = $id;
				return $validator;
			}
		);

		$directory = CmdbTestSupport::profileDirectory(overrides: []);
		$this->directories[] = $directory;

		$profile = new CmdbImportProfile(container: $container, directory: $directory);
		$profile->load();

		self::assertSame([CmdbImportProfile::VALIDATOR_CLASS], array_values(array_unique($asked)));
		self::assertSame('trim', $profile->pack(target: 'module')['fieldMappings'][0]['transform']['type']);
	}//end testTheContainerValidatorIsUsed()

	/**
	 * A container that fails on the validator lookup falls back to building the validator from its class.
	 *
	 * @return void
	 */
	public function testAFailingContainerFallsBackToTheBuiltValidator(): void {
		CmdbTestSupport::loadMigrationPack();
		$container = $this->container(
			has: static function (string $id): bool {
				throw new RuntimeException('container broken for ' . $id);
			},
			get: static function (string $id): object {
				throw new RuntimeException('never asked: ' . $id);
			}
		);
		$directory = CmdbTestSupport::profileDirectory(overrides: []);
		$this->directories[] = $directory;

		$profile = new CmdbImportProfile(container: $container, directory: $directory);
		$profile->load();

		self::assertSame('trim', $profile->pack(target: 'module')['fieldMappings'][0]['transform']['type']);
	}//end testAFailingContainerFallsBackToTheBuiltValidator()

	/**
	 * A container whose validator has no validate method is passed over for the built one.
	 *
	 * @return void
	 */
	public function testAContainerValidatorWithoutValidateIsPassedOver(): void {
		CmdbTestSupport::loadMigrationPack();
		$asked = 0;
		$container = $this->container(
			has: static fn (string $id): bool => $id === CmdbImportProfile::VALIDATOR_CLASS,
			get: static function (string $id) use (&$asked): object {
				$asked++;
				return new \stdClass();
			}
		);
		$directory = CmdbTestSupport::profileDirectory(overrides: []);
		$this->directories[] = $directory;

		$profile = new CmdbImportProfile(container: $container, directory: $directory);
		$profile->load();

		self::assertGreaterThan(0, $asked);
		self::assertSame('trim', $profile->pack(target: 'module')['fieldMappings'][0]['transform']['type']);
	}//end testAContainerValidatorWithoutValidateIsPassedOver()

	/**
	 * A concat mapping whose separator is not a non-empty string splits on a space.
	 *
	 * @return void
	 */
	public function testAMalformedConcatSeparatorFallsBackToASpace(): void {
		$profiles = [];
		foreach ([7, '', null] as $separator) {
			$directory = $this->directoryWithPack(
				file: 'topdesk-usage.json',
				change: static function (array $pack) use ($separator): array {
					foreach ($pack['fieldMappings'] as $index => $mapping) {
						if (($mapping['transform']['type'] ?? null) === 'concat') {
							$pack['fieldMappings'][$index]['transform']['separator'] = $separator;
						}
					}

					return $pack;
				}
			);
			$profile = new CmdbImportProfile(container: CmdbTestSupport::emptyContainer(test: $this), directory: $directory);
			$profile->load();
			$profiles[] = $profile->concatSeparator(target: 'usage', field: 'interneAnnotation');
		}

		self::assertSame([' ', ' ', ' '], $profiles);
	}//end testAMalformedConcatSeparatorFallsBackToASpace()
}//end class
