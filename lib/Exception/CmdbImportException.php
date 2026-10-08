<?php

/**
 * CmdbImportException.
 *
 * Carries one of the machine error codes of the CMDB import contract
 * (openspec/changes/cmdb-export-import/contract.md) together with the HTTP
 * status the controller answers with, and optional details such as the sheet
 * and column of a missing required column. The message is English and meant
 * for the log; the controller translates the code into a user-facing message.
 *
 * Messages and details never carry person data from the uploaded export.
 *
 * @category  Exception
 * @package   OCA\Stackiq\Exception
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Exception;

use RuntimeException;
use Throwable;

/**
 * A CMDB import failure with a contract error code and an HTTP status.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 */
class CmdbImportException extends RuntimeException {
	public const NOT_XLSX = 'NOT_XLSX';
	public const NO_SOURCE_SHEET = 'NO_SOURCE_SHEET';
	public const MISSING_COLUMN = 'MISSING_COLUMN';
	public const TOO_MANY_ROWS = 'TOO_MANY_ROWS';
	public const MUNICIPALITY_REQUIRED = 'MUNICIPALITY_REQUIRED';
	public const MUNICIPALITY_INVALID = 'MUNICIPALITY_INVALID';
	public const MAPPING_UNAVAILABLE = 'MAPPING_UNAVAILABLE';
	public const READER_UNAVAILABLE = 'READER_UNAVAILABLE';
	public const NOT_CONFIGURED = 'NOT_CONFIGURED';
	public const WORKBOOK_TOO_LARGE = 'WORKBOOK_TOO_LARGE';
	public const SCHEMA_OUTDATED = 'SCHEMA_OUTDATED';
	public const IMPORT_IN_PROGRESS = 'IMPORT_IN_PROGRESS';
	public const MUNICIPALITY_AMBIGUOUS = 'MUNICIPALITY_AMBIGUOUS';

	/**
	 * HTTP status per error code.
	 *
	 * @var array<string, int>
	 */
	private const STATUS = [
		self::NOT_XLSX => 400,
		self::NO_SOURCE_SHEET => 422,
		self::MISSING_COLUMN => 422,
		self::TOO_MANY_ROWS => 422,
		self::MUNICIPALITY_REQUIRED => 422,
		self::MUNICIPALITY_INVALID => 422,
		self::MAPPING_UNAVAILABLE => 503,
		self::READER_UNAVAILABLE => 503,
		self::NOT_CONFIGURED => 503,
		self::WORKBOOK_TOO_LARGE => 413,
		self::SCHEMA_OUTDATED => 503,
		self::IMPORT_IN_PROGRESS => 409,
		self::MUNICIPALITY_AMBIGUOUS => 422,
	];

	/**
	 * Constructor.
	 *
	 * @param string $errorCode One of the class constants.
	 * @param string $message English log message, no person data.
	 * @param array<string, mixed> $details Contract details, e.g. sheet and column.
	 * @param Throwable|null $previous The cause, if any.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function __construct(
		private readonly string $errorCode,
		string $message,
		private readonly array $details = [],
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 0, previous: $previous);
	}//end __construct()

	/**
	 * The contract error code, e.g. `MISSING_COLUMN`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()

	/**
	 * The HTTP status the controller answers with.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function getHttpStatus(): int {
		return (self::STATUS[$this->errorCode] ?? 500);
	}//end getHttpStatus()

	/**
	 * The contract details of the error.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function getDetails(): array {
		return $this->details;
	}//end getDetails()
}//end class
