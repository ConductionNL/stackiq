<?php

/**
 * Characterisation test for the object-to-data-bag normalisation the
 * federation, moderation and review aggregate services share.
 *
 * Pins, for ObjectDataBag and ModerationService's delegate, the bag returned for an array, an entity with and without an id in its data,
 * an entity whose data is not an array, an object without data and a scalar.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\ObjectDataBag;
use PHPUnit\Framework\TestCase;

/**
 * An entity double: data plus an optional uuid.
 */
class ObjectDataBagEntity {
	/**
	 * Build the double.
	 *
	 * @param mixed $data The object data.
	 * @param string $uuid The uuid.
	 */
	public function __construct(private mixed $data, private string $uuid) {
	}

	/**
	 * The object data.
	 *
	 * @return mixed
	 */
	public function getObject(): mixed {
		return $this->data;
	}

	/**
	 * The uuid.
	 *
	 * @return string
	 */
	public function getUuid(): string {
		return $this->uuid;
	}
}

/**
 * An object with data but no uuid.
 */
class ObjectDataBagWithoutUuid {
	/**
	 * The object data.
	 *
	 * @return array
	 */
	public function getObject(): array {
		return ['name' => 'no uuid'];
	}
}

/**
 * Characterisation of the data bag.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
class ObjectDataBagCharacterisationTest extends TestCase {

	/**
	 * Each input and the bag it becomes.
	 *
	 * @return array<string, array{0: mixed, 1: array}>
	 */
	public static function inputs(): array {
		return [
			'array'                    => [['id' => 'a', 'name' => 'A'], ['id' => 'a', 'name' => 'A']],
			'entity without id'        => [new ObjectDataBagEntity(['name' => 'B'], 'uuid-b'), ['name' => 'B', 'id' => 'uuid-b']],
			'entity with empty id'     => [new ObjectDataBagEntity(['id' => '', 'name' => 'C'], 'uuid-c'), ['id' => 'uuid-c', 'name' => 'C']],
			'entity with id'           => [new ObjectDataBagEntity(['id' => 'own', 'name' => 'D'], 'uuid-d'), ['id' => 'own', 'name' => 'D']],
			'entity with no data'      => [new ObjectDataBagEntity(null, 'uuid-e'), ['id' => 'uuid-e']],
			'entity with scalar data'  => [new ObjectDataBagEntity('text', 'uuid-f'), ['throws' => \TypeError::class]],
			'object without uuid'      => [new ObjectDataBagWithoutUuid(), ['name' => 'no uuid']],
			'object without data'      => [new \stdClass(), []],
			'scalar'                   => ['uuid-g', []],
			'null'                     => [null, []],
		];

	}//end inputs()

	/**
	 * The shared helper and the moderation delegate turn each input into the same bag.
	 *
	 * @param mixed $input    The result item.
	 * @param array $expected The bag.
	 *
	 * @return void
	 *
	 * @dataProvider inputs
	 */
	public function testEveryServiceBuildsTheSameBag(mixed $input, array $expected): void {
		$this->assertSame(expected: $expected, actual: $this->bag(build: static fn () => ObjectDataBag::of(object: $input)));
		$moderation = (new \ReflectionClass(objectOrClass: ModerationService::class))->newInstanceWithoutConstructor();
		$delegate   = new \ReflectionMethod(objectOrMethod: ModerationService::class, method: 'toDataBag');
		$this->assertSame(expected: $expected, actual: $this->bag(build: static fn () => $delegate->invoke($moderation, $input)));

	}//end testEveryServiceBuildsTheSameBag()

	/**
	 * The bag one builder returns.
	 *
	 * @param \Closure $build Builds the bag.
	 *
	 * @return array The bag, or `['throws' => class]` when building it throws.
	 */
	private function bag(\Closure $build): array {
		try {
			return $build();
		} catch (\Throwable $e) {
			return ['throws' => get_class($e)];
		}

	}//end bag()

}//end class
