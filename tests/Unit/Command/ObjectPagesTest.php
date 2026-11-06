<?php

/**
 * Tests for the paged object reader of the occ commands.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Command
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Command;

use OCA\Stackiq\Command\ObjectPages;
use PHPUnit\Framework\TestCase;

/**
 * Every page is read, every row is resolved to its payload, with the right scoping.
 *
 * @covers \OCA\Stackiq\Command\ObjectPages
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ObjectPagesTest extends TestCase {

	/**
	 * Rows past one page are read, and entity rows are resolved to their payload.
	 *
	 * @return void
	 */
	public function testEveryPageIsReadAndResolved(): void {
		$rows = [];
		for ($i = 0; $i < 401; $i++) {
			$rows[] = ['id' => 'o'.$i];
		}

		$rows[399] = new class() implements \JsonSerializable {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'entity'];
			}
		};
		$rows[400] = 'not an object';

		$service = $this->service(rows: $rows);
		$read    = (new ObjectPages($service))->all('reg', 'schema');

		self::assertCount(401, $read);
		self::assertSame(['id' => 'o0'], $read[0]);
		self::assertSame(['id' => 'o250'], $read[250]);
		self::assertSame(['id' => 'entity'], $read[399]);
		self::assertSame([], $read[400]);
		self::assertSame([[0, true, true], [200, true, true], [400, true, true]], $service->calls);
	}//end testEveryPageIsReadAndResolved()

	/**
	 * A system read turns RBAC and organisation scoping off.
	 *
	 * @return void
	 */
	public function testASystemReadIsUnscoped(): void {
		$service = $this->service(rows: [['id' => 'a']]);
		(new ObjectPages($service))->allAsSystem('reg', 'schema');

		self::assertSame([[0, false, false]], $service->calls);
	}//end testASystemReadIsUnscoped()

	/**
	 * A page that is not a list ends the read.
	 *
	 * @return void
	 */
	public function testANonListPageEndsTheRead(): void {
		$service = $this->service(rows: null);

		self::assertSame([], (new ObjectPages($service))->all('reg', 'schema'));
	}//end testANonListPageEndsTheRead()

	/**
	 * A fake ObjectService serving the rows in pages and recording each read.
	 *
	 * @param array<int, mixed>|null $rows The rows, or null to answer with a non-list.
	 *
	 * @return object The fake.
	 */
	private function service(?array $rows): object {
		return new class($rows) {
			/**
			 * @var array<int, array{0: int, 1: bool, 2: bool}>
			 */
			public array $calls = [];

			/**
			 * @param array<int, mixed>|null $rows The rows.
			 */
			public function __construct(private ?array $rows) {
			}

			/**
			 * @return self Fluent.
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @return self Fluent.
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, int> $config The query.
			 *
			 * @return mixed The page.
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->calls[] = [$config['offset'], $_rbac, $_multitenancy];
				if ($this->rows === null) {
					return 'not a list';
				}

				return array_slice($this->rows, $config['offset'], $config['limit']);
			}
		};
	}//end service()
}//end class
