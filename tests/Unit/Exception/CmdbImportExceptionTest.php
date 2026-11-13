<?php

/**
 * Unit tests for CmdbImportException: every error code maps to its HTTP
 * status, an unknown code to 500, and the message, details and previous
 * exception are kept.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Exception
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Exception;

use OCA\Stackiq\Exception\CmdbImportException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for CmdbImportException.
 *
 * @covers \OCA\Stackiq\Exception\CmdbImportException
 */
final class CmdbImportExceptionTest extends TestCase {

	/**
	 * Every code maps to the status the import endpoint answers with.
	 *
	 * @return void
	 */
	public function testEveryCodeMapsToItsStatus(): void {
		$expected = [
			CmdbImportException::NOT_XLSX               => 400,
			CmdbImportException::NO_SOURCE_SHEET        => 422,
			CmdbImportException::MISSING_COLUMN         => 422,
			CmdbImportException::TOO_MANY_ROWS          => 422,
			CmdbImportException::MUNICIPALITY_REQUIRED  => 422,
			CmdbImportException::MUNICIPALITY_INVALID   => 422,
			CmdbImportException::MUNICIPALITY_AMBIGUOUS => 422,
			CmdbImportException::MAPPING_UNAVAILABLE    => 503,
			CmdbImportException::READER_UNAVAILABLE     => 503,
			CmdbImportException::NOT_CONFIGURED         => 503,
			CmdbImportException::SCHEMA_OUTDATED        => 503,
			CmdbImportException::ARCHIVE_UNAVAILABLE    => 503,
			CmdbImportException::WORKBOOK_TOO_LARGE     => 413,
			CmdbImportException::IMPORT_IN_PROGRESS     => 409,
		];

		foreach ($expected as $code => $status) {
			$exception = new CmdbImportException(errorCode: $code, message: 'x');
			self::assertSame($status, $exception->getHttpStatus(), $code);
			self::assertSame($code, $exception->getErrorCode());
		}
	}//end testEveryCodeMapsToItsStatus()

	/**
	 * An unknown code is a 500, never a client error.
	 *
	 * @return void
	 */
	public function testAnUnknownCodeIsA500(): void {
		self::assertSame(500, (new CmdbImportException(errorCode: 'SOMETHING_ELSE', message: 'x'))->getHttpStatus());
		self::assertSame(500, (new CmdbImportException(errorCode: 'not_xlsx', message: 'x'))->getHttpStatus());
	}//end testAnUnknownCodeIsA500()

	/**
	 * The message, details and previous exception are kept, and the numeric code is 0.
	 *
	 * @return void
	 */
	public function testTheMessageDetailsAndCauseAreKept(): void {
		$cause     = new RuntimeException('zip broken');
		$exception = new CmdbImportException(
			errorCode: CmdbImportException::MISSING_COLUMN,
			message: 'Column APPID is missing',
			details: ['column' => 'APPID', 'sheet' => 'Applicaties'],
			previous: $cause
		);

		self::assertSame('Column APPID is missing', $exception->getMessage());
		self::assertSame(['column' => 'APPID', 'sheet' => 'Applicaties'], $exception->getDetails());
		self::assertSame($cause, $exception->getPrevious());
		self::assertSame(0, $exception->getCode());
		self::assertSame([], (new CmdbImportException(errorCode: 'X', message: 'y'))->getDetails());
	}//end testTheMessageDetailsAndCauseAreKept()
}//end class
