<?php

/**
 * Restores the approved-only public read rule on the software-review schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The register declares that anonymous readers see a software review only once
 * it is approved (register.d/catalog-ratings.json). An install that already had
 * the schema keeps the authorization stored on its row: re-import does not
 * rewrite the authorization of an existing schema, so an upgraded install can
 * still carry the older unconditional public read, and pending and rejected
 * reviews stay readable to everyone. This step rewrites the stored read rule:
 * every public entry without the approved condition is removed and the
 * approved-only public entry is added. Every other entry is kept as it is.
 *
 * Idempotent: a row that already carries only the approved-only public entry
 * is left alone. It never throws: it runs under `<install>` too, where an
 * escaping exception aborts the install.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\Kernel\AppIdentity;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites the software-review schema's public read to approved reviews only.
 *
 * @spec exclude No canonical spec covers repairing the stored authorization of an
 *  existing schema row; the rule itself is declared in register.d/catalog-ratings.json.
 */
class RestoreReviewReadRule implements IRepairStep {

	/**
	 * The schema slug whose read rule is restored.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'software-review';

	/**
	 * The only public read entry the schema may carry.
	 *
	 * @var array{group: string, match: array{status: string}, crossOrganisation: bool}
	 */
	public const APPROVED_ONLY = ['group' => 'public', 'match' => ['status' => 'approved'], 'crossOrganisation' => true];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec exclude repair step plumbing.
	 */
	public function getName(): string {
		return 'Show anonymous readers approved software reviews only';
	}//end getName()

	/**
	 * Rewrite the read rule of every software-review schema row of this app.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude repair step plumbing.
	 */
	public function run(IOutput $output): void {
		try {
			$rows = $this->db->executeQuery(
				'SELECT id, `authorization` FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application = ?',
				[self::SCHEMA_SLUG, AppIdentity::APP_ID]
			)->fetchAll();
		} catch (Throwable $e) {
			$this->logger->warning(
				'RestoreReviewReadRule: could not read the software-review schema; nothing done.',
				['exception' => $e->getMessage()]
			);
			$output->info('RestoreReviewReadRule: could not read the software-review schema; nothing done.');
			return;
		}

		$fixed = 0;
		foreach ($rows as $row) {
			if ($this->restore(row: $row) === true) {
				$fixed++;
			}
		}

		$output->info(sprintf('RestoreReviewReadRule: %d software-review schema(s) now show anonymous readers approved reviews only.', $fixed));
	}//end run()

	/**
	 * The read entries with every ungated public entry replaced by the approved-only one.
	 *
	 * @param array<int, mixed> $read The stored read entries.
	 *
	 * @return array<int, mixed> The restored read entries.
	 *
	 * @spec exclude repair step plumbing.
	 */
	public static function restoredRead(array $read): array {
		$kept = [];
		foreach ($read as $entry) {
			if (self::isPublic(entry: $entry) === false) {
				$kept[] = $entry;
			}
		}

		return array_merge([self::APPROVED_ONLY], $kept);
	}//end restoredRead()

	/**
	 * Restore one schema row.
	 *
	 * @param array<string, mixed> $row The id and stored authorization.
	 *
	 * @return bool True when the row was rewritten.
	 */
	private function restore(array $row): bool {
		$authorization = json_decode((string)($row['authorization'] ?? ''), true);
		if (is_array($authorization) === false) {
			$authorization = [];
		}

		$read = $authorization['read'] ?? [];
		if (is_array($read) === false) {
			$read = [];
		}

		$restored = self::restoredRead(read: array_values($read));
		if ($restored === array_values($read)) {
			return false;
		}

		$authorization['read'] = $restored;
		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET `authorization` = ? WHERE id = ?',
				[json_encode($authorization, JSON_UNESCAPED_SLASHES), $row['id']]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'RestoreReviewReadRule: could not rewrite the software-review read rule.',
				['id' => $row['id'] ?? null, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end restore()

	/**
	 * Whether a read entry is a public one (bare `public` or a public group entry).
	 *
	 * @param mixed $entry A read entry.
	 *
	 * @return bool
	 */
	private static function isPublic(mixed $entry): bool {
		if ($entry === 'public') {
			return true;
		}

		return is_array($entry) === true && ($entry['group'] ?? null) === 'public';
	}//end isPublic()
}//end class
