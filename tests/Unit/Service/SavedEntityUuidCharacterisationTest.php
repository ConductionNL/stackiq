<?php

/**
 * Characterisation test for entityUuid of IntakeService and ReviewService.
 *
 * Pins, for both services, the uuid read from a saveObject result: an
 * entity with a concrete or magic getUuid(), an empty uuid, a getUuid()
 * that throws (logged as a warning naming the service), an array with an
 * id or a uuid, a non-string id, and anything else.
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

use OCA\Stackiq\Service\IntakeService;
use OCA\Stackiq\Service\ReviewService;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * An entity whose uuid is read through Entity::__call().
 */
class SavedEntityUuidMagicEntity extends Entity {
	/**
	 * The uuid.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;
}

/**
 * An object whose getUuid() throws.
 */
class SavedEntityUuidBrokenEntity {
	/**
	 * Always fails.
	 *
	 * @return string
	 */
	public function getUuid(): string {
		throw new \LogicException('no uuid yet');
	}
}

/**
 * An object with a concrete getUuid().
 */
class SavedEntityUuidConcreteEntity {
	/**
	 * Build the double.
	 *
	 * @param mixed $uuid The uuid.
	 */
	public function __construct(private mixed $uuid) {
	}

	/**
	 * The uuid.
	 *
	 * @return mixed
	 */
	public function getUuid(): mixed {
		return $this->uuid;
	}
}

/**
 * Characterisation of the saved-entity uuid.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
class SavedEntityUuidCharacterisationTest extends TestCase {

	/**
	 * Every case for both services: service, entity, expected uuid, expected log lines.
	 *
	 * @return array<string, array{0: class-string, 1: mixed, 2: string|null, 3: array}>
	 */
	public static function cases(): array {
		$magic = new SavedEntityUuidMagicEntity();
		$magic->setUuid('magic-1');
		$cases = [];
		foreach ([IntakeService::class => 'IntakeService', ReviewService::class => 'ReviewService'] as $class => $name) {
			$warning = [['warning', $name . ': could not read uuid from saved entity', ['exception' => 'no uuid yet']]];
			$cases[$name . ' magic accessor']    = [$class, $magic, 'magic-1', []];
			$cases[$name . ' concrete accessor'] = [$class, new SavedEntityUuidConcreteEntity('concrete-1'), 'concrete-1', []];
			$cases[$name . ' empty uuid']        = [$class, new SavedEntityUuidConcreteEntity(''), null, []];
			$cases[$name . ' non-string uuid']   = [$class, new SavedEntityUuidConcreteEntity(7), null, []];
			$cases[$name . ' throwing accessor'] = [$class, new SavedEntityUuidBrokenEntity(), null, $warning];
			$cases[$name . ' array id']          = [$class, ['id' => 'id-1', 'uuid' => 'uuid-1'], 'id-1', []];
			$cases[$name . ' array uuid']        = [$class, ['uuid' => 'uuid-1'], 'uuid-1', []];
			$cases[$name . ' array numeric id']  = [$class, ['id' => 5], null, []];
			$cases[$name . ' plain object']      = [$class, new \stdClass(), null, []];
			$cases[$name . ' scalar']            = [$class, 'uuid-x', null, []];
		}

		return $cases;

	}//end cases()

	/**
	 * The uuid and the log lines are as pinned.
	 *
	 * @param class-string $class    The service.
	 * @param mixed        $entity   The saveObject result.
	 * @param string|null  $expected The uuid.
	 * @param array        $logged   The log lines.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testTheUuidIsReadAsPinned(string $class, mixed $entity, ?string $expected, array $logged): void {
		$records = new \ArrayObject();
		$logger  = new class($records) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param \ArrayObject $records The records.
			 */
			public function __construct(private \ArrayObject $records) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};

		$service = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(class: $class, property: 'logger'))->setValue($service, $logger);
		$uuid = (new \ReflectionMethod(objectOrMethod: $class, method: 'entityUuid'))->invoke($service, $entity);

		$this->assertSame(expected: $expected, actual: $uuid);
		$this->assertSame(expected: $logged, actual: $records->getArrayCopy());

	}//end testTheUuidIsReadAsPinned()

}//end class
