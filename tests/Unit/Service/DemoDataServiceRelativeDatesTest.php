<?php

/**
 * The demo import resolves date placeholders, and the shipped demo set seeds
 * an Expiring contract with a responsible user (contracts-expiry-and-owner task 6).
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use OCA\Stackiq\Service\DemoDataService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What the importer receives is dated on the import day.
 *
 * @covers \OCA\Stackiq\Service\DemoDataService
 * @uses   \OCA\Stackiq\Service\RelativeDemoDates
 */
class DemoDataServiceRelativeDatesTest extends TestCase {

	/**
	 * The notice window the daily pass uses by default, in days.
	 *
	 * @var integer
	 */
	private const DEFAULT_WINDOW_DAYS = 90;

	/**
	 * Install a descriptor on a fixed day and return what the importer received.
	 *
	 * @param string $descriptor The descriptor JSON.
	 * @param string $now        The import moment.
	 *
	 * @return array<string, mixed> The data handed to importFromApp().
	 */
	private function importedOn(string $descriptor, string $now): array {
		$appPath = sys_get_temp_dir() . '/or-demo-dates-' . bin2hex(random_bytes(4));
		mkdir($appPath . '/lib/Settings', 0777, true);
		$file = $appPath . '/lib/Settings/stackiq_mock_register.json';
		file_put_contents($file, $descriptor);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($appPath);
		$appManager->method('getAppVersion')->willReturn('1.2.3');
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$importer = new class {
			public array $data = [];

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->data = $data;
				return ['registers' => [], 'schemas' => []];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($importer): object {
				if ($id === 'OCA\OpenRegister\Service\ConfigurationService') {
					return $importer;
				}

				throw new \RuntimeException('not available');
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->expects($this->once())->method('getDateTime')->willReturn(new DateTime($now));

		try {
			(new DemoDataService($appManager, $container, $this->createMock(LoggerInterface::class), $clock))->install();
		} finally {
			unlink($file);
			rmdir($appPath . '/lib/Settings');
			rmdir($appPath . '/lib');
			rmdir($appPath);
		}

		return $importer->data;
	}//end importedOn()

	/**
	 * A placeholder reaches the importer as the date it names on the import day.
	 *
	 * @return void
	 */
	public function testThePlaceholdersReachTheImporterAsDatesOfTheImportDay(): void {
		$data = $this->importedOn(
			descriptor: (string) json_encode(['components' => ['objects' => [['endDate' => '{{today+45d}}', 'startDate' => '{{today-2y}}', 'note' => 'n']]]]),
			now: '2027-01-31 08:00:00'
		);

		$this->assertSame(
			[['endDate' => '2027-03-17', 'startDate' => '2025-01-31', 'note' => 'n']],
			$data['components']['objects']
		);
	}//end testThePlaceholdersReachTheImporterAsDatesOfTheImportDay()

	/**
	 * Whenever the shipped demo set is imported, it holds an Expiring contract
	 * that names a responsible user and ends inside the default window, so the
	 * daily pass keeps it Expiring.
	 *
	 * @return void
	 */
	public function testTheShippedSetSeedsAnExpiringContractWithAResponsibleUserWhateverTheDay(): void {
		$descriptor = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/stackiq_mock_register.json');

		foreach (['2026-11-14', '2028-06-30'] as $day) {
			$objects = $this->importedOn(descriptor: $descriptor, now: $day . ' 12:00:00')['components']['objects'];
			$today = new DateTimeImmutable($day);
			$limit = $today->modify('+' . self::DEFAULT_WINDOW_DAYS . ' days');

			$expiring = array_values(
				array_filter(
					$objects,
					static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'catalogContract'
						&& ($o['status'] ?? null) === 'Expiring'
				)
			);

			$this->assertNotSame([], $expiring, $day);
			foreach ($expiring as $contract) {
				$this->assertIsString($contract['responsibleUser'] ?? null, $day);
				$this->assertNotSame('', $contract['responsibleUser'], $day);
				$end = new DateTimeImmutable($contract['endDate']);
				$this->assertGreaterThanOrEqual($today, $end, $day);
				$this->assertLessThanOrEqual($limit, $end, $day);
				$this->assertLessThan($today, new DateTimeImmutable($contract['startDate']), $day);
			}
		}
	}//end testTheShippedSetSeedsAnExpiringContractWithAResponsibleUserWhateverTheDay()
}//end class
