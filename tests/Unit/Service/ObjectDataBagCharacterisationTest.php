<?php

/**
 * Characterisation test for the object-to-data-bag normalisation of the
 * federation, moderation and review aggregate services.
 *
 * Pins, for every service that normalises an ObjectService result item, the
 * bag it returns for an array, an entity with and without an id in its data,
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

use OCA\Stackiq\Service\Federation\FederationService;
use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\ReviewAggregateService;
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
	 * Every normalising service turns each input into the same bag.
	 *
	 * @param mixed $input    The result item.
	 * @param array $expected The bag.
	 *
	 * @return void
	 *
	 * @dataProvider inputs
	 */
	public function testEveryServiceBuildsTheSameBag(mixed $input, array $expected): void {
		foreach ([FederationService::class, ModerationService::class, ReviewAggregateService::class] as $class) {
			$this->assertSame(expected: $expected, actual: $this->bag(class: $class, input: $input), message: $class);
		}

	}//end testEveryServiceBuildsTheSameBag()

	/**
	 * The bag one service builds.
	 *
	 * @param class-string $class The service.
	 * @param mixed        $input The result item.
	 *
	 * @return array The bag, or `['throws' => class]` when building it throws.
	 */
	private function bag(string $class, mixed $input): array {
		$service = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		try {
			return (new \ReflectionMethod(objectOrMethod: $class, method: 'toDataBag'))->invoke($service, $input);
		} catch (\Throwable $e) {
			return ['throws' => get_class($e)];
		}

	}//end bag()

}//end class
