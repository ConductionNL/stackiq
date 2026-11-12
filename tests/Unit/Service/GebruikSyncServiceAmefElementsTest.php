<?php

/**
 * Characterisation tests for GebruikSyncService::processAmefElements.
 *
 * Pins the returned statistics, the log trail and the save call for every
 * branch: no reference components, no valid ids, missing AMEF configuration,
 * elements without slugs, elements with slugs, and a failing save.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\GebruikSyncService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

/**
 * Test class pinning the AMEF element linking of a gebruik object.
 */
class GebruikSyncServiceAmefElementsTest extends TestCase {
	/**
	 * Logger that records every call.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * @var SettingsService|MockObject
	 */
	private SettingsService|MockObject $settingsService;

	/**
	 * @var ObjectServiceInterface|MockObject
	 */
	private ObjectServiceInterface|MockObject $objectService;

	/**
	 * The service under test.
	 *
	 * @var GebruikSyncService
	 */
	private GebruikSyncService $service;

	/**
	 * Set up the service with a recording logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = new class extends AbstractLogger {
			/**
			 * @var array<int, array{0: mixed, 1: string, 2: array}>
			 */
			public array $records = [];

			/**
			 * @param mixed $level   The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string)$message, $context];
			}
		};
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->service = new GebruikSyncService(
			$this->logger,
			$this->settingsService,
			objectService: $this->objectService,
		);
	}//end setUp()

	/**
	 * Build a gebruik object mock.
	 *
	 * @param array $data The object data.
	 *
	 * @return ObjectEntity|MockObject The mock.
	 */
	private function gebruik(array $data): ObjectEntity|MockObject {
		$gebruik = $this->createMock(ObjectEntity::class);
		$gebruik->method('getObject')->willReturn($data);
		$gebruik->method('getUuid')->willReturn('g-1');
		return $gebruik;
	}//end gebruik()

	/**
	 * Build an AMEF element mock.
	 *
	 * @param array $data The element data.
	 *
	 * @return ObjectEntity|MockObject The mock.
	 */
	private function element(array $data): ObjectEntity|MockObject {
		$element = $this->createMock(ObjectEntity::class);
		$element->method('getObject')->willReturn($data);
		return $element;
	}//end element()

	/**
	 * Run the private method.
	 *
	 * @param ObjectEntity $gebruik The gebruik object.
	 *
	 * @return array The statistics.
	 */
	private function process(ObjectEntity $gebruik): array {
		$reflection = new ReflectionMethod(GebruikSyncService::class, 'processAmefElements');
		$reflection->setAccessible(true);
		return $reflection->invoke($this->service, $gebruik);
	}//end process()

	/**
	 * The levels and messages logged, in order.
	 *
	 * @return array<int, string> "level message" lines.
	 */
	private function trail(): array {
		return array_map(
			static fn (array $record): string => $record[0].' '.$record[1],
			$this->logger->records
		);
	}//end trail()

	/**
	 * No reference components: info and nothing else.
	 *
	 * @return void
	 */
	public function testNoReferenceComponents(): void {
		$this->settingsService->expects($this->never())->method('getVoorzieningenConfig');

		$stats = $this->process($this->gebruik(['usedForReferenceComponents' => []]));

		$this->assertSame(['amefElementsProcessed' => 0, 'errors' => []], $stats);
		$this->assertSame(
			[['info', 'No referentiecomponenten found for gebruik object', ['gebruikId' => 'g-1']]],
			$this->logger->records
		);
	}//end testNoReferenceComponents()

	/**
	 * Components without an id: debug then warning.
	 *
	 * @return void
	 */
	public function testNoValidIds(): void {
		$this->settingsService->expects($this->never())->method('getVoorzieningenConfig');

		$stats = $this->process($this->gebruik(['usedForReferenceComponents' => [['name' => 'x'], ['id' => null]]]));

		$this->assertSame(['amefElementsProcessed' => 0, 'errors' => []], $stats);
		$this->assertSame(
			[
				['debug', 'Processing referentiecomponenten', ['app' => 'stackiq', 'gebruikId' => 'g-1', 'referentieComponentenCount' => 2]],
				['warning', 'No valid IDs found in referentiecomponenten', ['gebruikId' => 'g-1']],
			],
			$this->logger->records
		);
	}//end testNoValidIds()

	/**
	 * Missing AMEF configuration: an error in the stats and an error log.
	 *
	 * @return void
	 */
	public function testMissingConfiguration(): void {
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(['amef_register' => '7']);
		$this->objectService->expects($this->never())->method('searchObjects');

		$stats = $this->process($this->gebruik(['usedForReferenceComponents' => [['id' => 'r-1']]]));

		$this->assertSame(['amefElementsProcessed' => 0, 'errors' => ['AMEF register or element schema not configured']], $stats);
		$this->assertSame(
			['error', 'AMEF configuration missing', ['app' => 'stackiq', 'amefRegister' => '7', 'elementSchema' => '']],
			$this->logger->records[1]
		);
		$this->assertCount(2, $this->logger->records);
	}//end testMissingConfiguration()

	/**
	 * Elements without a slug: no save, an info log.
	 *
	 * @return void
	 */
	public function testElementsWithoutSlugs(): void {
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(['amef_register' => '7', 'element_schema' => '8']);
		$this->objectService->method('searchObjects')->willReturn([$this->element(['name' => 'no slug'])]);
		$this->objectService->expects($this->never())->method('saveObject');

		$stats = $this->process($this->gebruik(['usedForReferenceComponents' => [['id' => 'r-1']]]));

		$this->assertSame(['amefElementsProcessed' => 0, 'errors' => []], $stats);
		$this->assertSame(
			[
				'debug Processing referentiecomponenten',
				'info AMEF elements search completed',
				'info No AMEF elements with slugs found',
			],
			$this->trail()
		);
		$this->assertSame(['gebruikId' => 'g-1'], $this->logger->records[2][2]);
	}//end testElementsWithoutSlugs()

	/**
	 * Elements with slugs: counted, saved once with unique slugs, critical log.
	 *
	 * @return void
	 */
	public function testElementsWithSlugsAreSaved(): void {
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(
			['amef_register' => '7', 'element_schema' => '8', 'register' => '3', 'gebruik_schema' => '4']
		);
		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[$this->element(['slug' => 'a']), $this->element(['slug' => 'b'])],
			[$this->element(['slug' => 'a'])]
		);
		$saved = [];
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved) {
				$saved = $object;
				return $this->element($object);
			}
		);

		$data = ['usedForReferenceComponents' => [['id' => 'r-1'], ['id' => 'r-2']], 'name' => 'G'];
		$stats = $this->process($this->gebruik($data));

		$this->assertSame(['amefElementsProcessed' => 3, 'errors' => []], $stats);
		$this->assertSame($data + ['amefElements' => ['a', 'b']], $saved);
		$this->assertSame(
			[
				'debug Processing referentiecomponenten',
				'info AMEF elements search completed',
				'info Gebruik object updated successfully',
				'critical AMEF ELEMENTS UPDATED',
			],
			$this->trail()
		);
		$this->assertSame(
			['app' => 'stackiq', 'gebruikId' => 'g-1', 'amefSlugs' => ['a', 'b', 'a'], 'amefElementsCount' => 3],
			$this->logger->records[3][2]
		);
	}//end testElementsWithSlugsAreSaved()

	/**
	 * A failing save keeps the count and adds the error.
	 *
	 * @return void
	 */
	public function testFailingSaveIsReported(): void {
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(['amef_register' => '7', 'element_schema' => '8']);
		$this->objectService->method('searchObjects')->willReturn([$this->element(['slug' => 'a'])]);

		$stats = $this->process($this->gebruik(['usedForReferenceComponents' => [['id' => 'r-1']]]));

		$this->assertSame(
			['amefElementsProcessed' => 1, 'errors' => ['AMEF processing error: Register or gebruik schema not configured']],
			$stats
		);
		$this->assertSame(
			['error', 'AMEF PROCESSING ERROR', ['app' => 'stackiq', 'gebruikId' => 'g-1', 'exception' => 'Register or gebruik schema not configured']],
			$this->logger->records[3]
		);
		$this->assertSame('error Failed to update gebruik object', $this->trail()[2]);
	}//end testFailingSaveIsReported()
}//end class
