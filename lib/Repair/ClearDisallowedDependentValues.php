<?php

/**
 * Clears stored values that a dependent value table refuses, before the table is imported.
 *
 * `lib/Settings/register.d/dependent-field-options.json` declares, per
 * property, which of its values are allowed for each value of another
 * property (`x-openregister-dependent-values`). OpenRegister refuses a save
 * with a pair outside the table. A row written before the table existed can
 * hold such a pair, and its first edit afterwards would be refused for a field
 * the user did not touch. This step reads the same tables, clears every stored
 * value its table refuses, and says how many it cleared. It runs before
 * InitializeSettings imports the register, and records a pass in which nothing
 * failed so later upgrades skip it.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-002-existing-rows-that-break-a-table-are-cleaned-before-it-applies
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Repair step: clear values outside their dependent value table.
 *
 * @spec openspec/changes/landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-002-existing-rows-that-break-a-table-are-cleaned-before-it-applies
 */
class ClearDisallowedDependentValues implements IRepairStep {

	/**
	 * App config key set after a pass in which nothing failed.
	 *
	 * @var string
	 */
	public const DONE_CONFIG_KEY = 'dependent_values_cleared';

	/**
	 * Objects read per page.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 200;

	/**
	 * The register fragment that holds the tables.
	 *
	 * @var string
	 */
	public const TABLES_FILE = __DIR__ . '/../Settings/register.d/dependent-field-options.json';

	/**
	 * The annotation that carries a table.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-dependent-values';

	/**
	 * Constructor.
	 *
	 * @param IAppManager                   $appManager      Tells whether OpenRegister is installed.
	 * @param RegisterConfigReaderInterface $settingsService Resolves the register and schema of an object type.
	 * @param IAppConfig                    $appConfig       Records a completed pass.
	 * @param string                        $tablesFile      The fragment holding the tables.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly RegisterConfigReaderInterface $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly string $tablesFile = self::TABLES_FILE,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-002-existing-rows-that-break-a-table-are-cleaned-before-it-applies
	 */
	public function getName(): string {
		return 'Clear stored values that a dependent value table does not allow';
	}//end getName()

	/**
	 * Clear every stored value its table refuses.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/landscape-dependent-field-options/tasks.md#task-2-clear-disallowed-values-in-existing-rows
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(AppIdentity::APP_ID, self::DONE_CONFIG_KEY, false) === true) {
			return;
		}

		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->info('OpenRegister not installed, so there are no stored values to check.');
			return;
		}

		$objects = $this->settingsService->getObjectService();
		if ($objects === null) {
			$output->info('OpenRegister is not reachable, so no stored values were checked.');
			return;
		}

		$total = ['cleared' => 0, 'failed' => 0];
		foreach (self::tables(fragment: $this->readFragment()) as $table) {
			$result = $this->clearFor(objects: $objects, table: $table);
			$total['cleared'] += $result['cleared'];
			$total['failed']  += $result['failed'];
			if ($result['cleared'] > 0) {
				$output->info(
					$result['cleared'] . ' ' . $table['type'] . ' value(s) of ' . $table['field']
					. ' cleared: not allowed for their ' . $table['controlledBy'] . '.'
				);
			}
		}

		$output->info($total['cleared'] . ' stored value(s) cleared that a dependent value table does not allow.');
		if ($total['failed'] > 0) {
			$output->warning($total['failed'] . ' object(s) or searches failed; the next upgrade tries again.');
			return;
		}

		$this->appConfig->setValueBool(AppIdentity::APP_ID, self::DONE_CONFIG_KEY, true);
	}//end run()

	/**
	 * Every table in a register fragment.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return list<array{type: string, field: string, controlledBy: string, allowed: array<string, list<mixed>>}>
	 *
	 * @spec openspec/changes/landscape-dependent-field-options/tasks.md#task-2-clear-disallowed-values-in-existing-rows
	 */
	public static function tables(array $fragment): array {
		$tables = [];
		foreach ((array) ($fragment['components']['schemas'] ?? []) as $type => $schema) {
			foreach ((array) ($schema['properties'] ?? []) as $field => $property) {
				$table = null;
				if (is_array($property) === true) {
					$table = ($property[self::ANNOTATION] ?? null);
				}

				if (is_array($table) === false || is_string($table['controlledBy'] ?? null) === false || is_array($table['allowed'] ?? null) === false) {
					continue;
				}

				$tables[] = [
					'type'         => (string) $type,
					'field'        => (string) $field,
					'controlledBy' => $table['controlledBy'],
					'allowed'      => $table['allowed'],
				];
			}
		}

		return $tables;
	}//end tables()

	/**
	 * Whether an object holds a value its table refuses.
	 *
	 * Like OpenRegister: a controlling value the table does not list leaves the
	 * field free, and an empty field is always allowed.
	 *
	 * @param array<string, mixed>                                                               $data  The object's data.
	 * @param array{type: string, field: string, controlledBy: string, allowed: array<string, list<mixed>>} $table The table.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/landscape-dependent-field-options/tasks.md#task-2-clear-disallowed-values-in-existing-rows
	 */
	public static function refuses(array $data, array $table): bool {
		$value = ($data[$table['field']] ?? null);
		if ($value === null || $value === '' || $value === []) {
			return false;
		}

		$controlling = ($data[$table['controlledBy']] ?? null);
		if (is_string($controlling) === false || array_key_exists($controlling, $table['allowed']) === false) {
			return false;
		}

		$allowed = (array) $table['allowed'][$controlling];
		foreach ((array) $value as $one) {
			if (in_array($one, $allowed, true) === false) {
				return true;
			}
		}

		return false;
	}//end refuses()

	/**
	 * The fragment holding the tables.
	 *
	 * @return array<string, mixed> The fragment, or an empty array when it cannot be read.
	 */
	private function readFragment(): array {
		$raw = @file_get_contents($this->tablesFile);
		if ($raw === false) {
			return [];
		}

		$fragment = json_decode($raw, true);
		if (is_array($fragment) === false) {
			return [];
		}

		return $fragment;
	}//end readFragment()

	/**
	 * Clear one table's refused values, page by page.
	 *
	 * @param ObjectServiceInterface                                                               $objects The object service.
	 * @param array{type: string, field: string, controlledBy: string, allowed: array<string, list<mixed>>} $table   The table.
	 *
	 * @return array{cleared: int, failed: int}
	 */
	private function clearFor(ObjectServiceInterface $objects, array $table): array {
		$register = $this->settingsService->getRegisterIdForObjectType($table['type']);
		$schema   = $this->settingsService->getSchemaIdForObjectType($table['type']);
		if ($register === null || $schema === null) {
			return ['cleared' => 0, 'failed' => 0];
		}

		$total  = ['cleared' => 0, 'failed' => 0];
		$offset = 0;
		do {
			try {
				$page = (array) $objects->setRegister($register)->setSchema($schema)->findAll(
					config: ['limit' => self::PAGE_SIZE, 'offset' => $offset],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$total['failed']++;
				return $total;
			}

			foreach ($page as $object) {
				$result = $this->clearOne(objects: $objects, object: $object, table: $table, register: $register, schema: $schema);
				$total['cleared'] += $result['cleared'];
				$total['failed']  += $result['failed'];
			}

			$offset += self::PAGE_SIZE;
		} while (count($page) === self::PAGE_SIZE);

		return $total;
	}//end clearFor()

	/**
	 * Clear the field of one object when its table refuses it.
	 *
	 * @param ObjectServiceInterface                                                               $objects  The object service.
	 * @param mixed                                                                                $object   The object read.
	 * @param array{type: string, field: string, controlledBy: string, allowed: array<string, list<mixed>>} $table    The table.
	 * @param int                                                                                  $register The register id.
	 * @param int                                                                                  $schema   The schema id.
	 *
	 * @return array{cleared: int, failed: int}
	 */
	private function clearOne(ObjectServiceInterface $objects, mixed $object, array $table, int $register, int $schema): array {
		if (($object instanceof ObjectEntityInterface) === false) {
			return ['cleared' => 0, 'failed' => 0];
		}

		$data = $object->getObject();
		if (self::refuses(data: $data, table: $table) === false) {
			return ['cleared' => 0, 'failed' => 0];
		}

		$data[$table['field']] = null;
		try {
			// The whole object goes back: a save replaces it. Silent: a data fix, not an edit.
			$objects->saveObject(
				object: $data,
				register: $register,
				schema: $schema,
				uuid: $object->getUuid(),
				_rbac: false,
				_multitenancy: false,
				silent: true
			);
		} catch (Throwable $e) {
			return ['cleared' => 0, 'failed' => 1];
		}

		return ['cleared' => 1, 'failed' => 0];
	}//end clearOne()
}//end class
