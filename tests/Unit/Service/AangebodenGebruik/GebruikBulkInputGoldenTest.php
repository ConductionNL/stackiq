<?php

/**
 * Golden tests for the GebruikBulkHandler input check.
 *
 * Pins the refusal of an empty batch, of items that are not arrays and of
 * items without consumer or provider (one message per item, in order, with
 * nothing saved), so splitting the check keeps every outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\AangebodenGebruik
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\AangebodenGebruik;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\AangebodenGebruik\GebruikBulkHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden tests for GebruikBulkHandler::bulkCreate() input refusals.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\AangebodenGebruik
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class GebruikBulkInputGoldenTest extends TestCase {

	/**
	 * A handler whose object service must never save.
	 *
	 * @return GebruikBulkHandler
	 */
	private function handler(): GebruikBulkHandler {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->never())->method('saveObject');
		return new GebruikBulkHandler(objectService: $objectService, logger: new NullLogger());
	}//end handler()

	/**
	 * An empty batch is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyBatchIsRefused(): void {
		$this->assertSame(
			['success' => false, 'created' => 0, 'failed' => 0, 'errors' => ['Bulk input may not be empty.']],
			$this->handler()->bulkCreate([], 'register', 'schema')
		);
	}//end testAnEmptyBatchIsRefused()

	/**
	 * Every bad item gets its own message, in order, and nothing is saved.
	 *
	 * @return void
	 */
	public function testEveryBadItemIsReportedInOrder(): void {
		$items = [
			['consumer' => 'a', 'provider' => 'b'],
			'text',
			['consumer' => 'a'],
			['provider' => 'b'],
			['consumer' => null, 'provider' => 'b'],
			7 => null,
			'key' => ['consumer' => 'a', 'provider' => 'b'],
			'other' => [],
		];

		$this->assertSame(
			[
				'success' => false,
				'created' => 0,
				'failed' => 8,
				'errors' => [
					'Item at index 1 is not an array.',
					'Item at index 2 is missing required fields: afnemer, aanbieder.',
					'Item at index 3 is missing required fields: afnemer, aanbieder.',
					'Item at index 4 is missing required fields: afnemer, aanbieder.',
					'Item at index 7 is not an array.',
					'Item at index 0 is missing required fields: afnemer, aanbieder.',
				],
			],
			$this->handler()->bulkCreate($items, 'register', 'schema')
		);
	}//end testEveryBadItemIsReportedInOrder()

}//end class
