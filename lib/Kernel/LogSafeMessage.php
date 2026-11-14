<?php

/**
 * A log-safe exception message
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel;

use Throwable;

/**
 * Strips cell values and e-mail addresses from an exception message.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 */
final class LogSafeMessage {

	/**
	 * An exception message that is safe for the log: no cell value and no e-mail address.
	 *
	 * Only the first line is kept, at most 300 characters. Every value of the
	 * row of three characters or more is replaced by "…", longest first, and
	 * every e-mail address by "<e-mail>". The owner step logs no message at
	 * all, so no contact data can reach the log. The import service and
	 * the controller log a failure the same way through it.
	 *
	 * @param string $step The step that failed.
	 * @param Throwable $e The exception.
	 * @param array<string, string> $values The normalised row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public static function of(string $step, Throwable $e, array $values): string {
		if ($step === 'owners') {
			return '';
		}

		$lines = preg_split('/\R/u', $e->getMessage());
		$message = mb_substr(trim((string)($lines[0] ?? '')), 0, 300);
		$message = (string)preg_replace('/[^\s@<>"\'(),;:=]+@[^\s@<>"\'(),;:]+/u', '<e-mail>', $message);
		$secrets = array_filter(array_unique(array_map('strval', $values)), static fn (string $value): bool => mb_strlen($value) >= 3);
		usort($secrets, static fn (string $left, string $right): int => (mb_strlen($right) <=> mb_strlen($left)));
		foreach ($secrets as $secret) {
			$message = str_ireplace($secret, '…', $message);
		}

		return $message;
	}//end of()
}//end class
