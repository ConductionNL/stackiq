<?php

/**
 * Characterisation test for the register/schema target resolution of the
 * intake, review, review aggregate, moderation and federation services.
 *
 * Pins, for each service, the object type it asks the locator for and the
 * target it returns: both ids cast to int, or null when either is missing
 * (and, for federation, null without a locator).
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
use OCA\Stackiq\Service\IntakeService;
use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\ReviewAggregateService;
use OCA\Stackiq\Service\ReviewService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the target resolution.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
class ObjectTypeTargetCharacterisationTest extends TestCase {

	/**
	 * Every resolver: service, method and its argument (null for none).
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: string|null}>
	 */
	public static function resolvers(): array {
		return [
			'intake'           => [IntakeService::class, 'resolveTarget', null],
			'review'           => [ReviewService::class, 'resolveTarget', null],
			'review aggregate' => [ReviewAggregateService::class, 'resolveTarget', null],
			'moderation'       => [ModerationService::class, 'resolveTarget', 'software-review'],
			'federation'       => [FederationService::class, 'resolveMirrorTarget', null],
		];

	}//end resolvers()

	/**
	 * Both ids give the target, cast to int, for the service's object type.
	 *
	 * @param class-string $class    The service.
	 * @param string       $method   The resolver.
	 * @param string|null  $argument The object type argument.
	 *
	 * @return void
	 *
	 * @dataProvider resolvers
	 */
	public function testBothIdsGiveTheTarget(string $class, string $method, ?string $argument): void {
		[$target, $asked] = $this->resolve(class: $class, method: $method, argument: $argument, ids: ['register' => 4, 'schema' => 12]);
		$this->assertSame(expected: ['register' => 4, 'schema' => 12], actual: $target);
		$this->assertSame(expected: $this->expectedAsks(class: $class, argument: $argument), actual: $asked);

	}//end testBothIdsGiveTheTarget()

	/**
	 * A missing register or schema gives null.
	 *
	 * @param class-string $class    The service.
	 * @param string       $method   The resolver.
	 * @param string|null  $argument The object type argument.
	 *
	 * @return void
	 *
	 * @dataProvider resolvers
	 */
	public function testAMissingIdGivesNull(string $class, string $method, ?string $argument): void {
		$this->assertNull(actual: $this->resolve(class: $class, method: $method, argument: $argument, ids: ['register' => null, 'schema' => 12])[0]);
		$this->assertNull(actual: $this->resolve(class: $class, method: $method, argument: $argument, ids: ['register' => 4, 'schema' => null])[0]);

	}//end testAMissingIdGivesNull()

	/**
	 * Federation without a locator has no target.
	 *
	 * @return void
	 */
	public function testFederationWithoutALocatorHasNoTarget(): void {
		$service = (new \ReflectionClass(objectOrClass: FederationService::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(class: FederationService::class, property: 'settingsService'))->setValue($service, null);
		$this->assertNull(actual: (new \ReflectionMethod(objectOrMethod: FederationService::class, method: 'resolveMirrorTarget'))->invoke($service));

	}//end testFederationWithoutALocatorHasNoTarget()

	/**
	 * The locator calls a service makes: register first, then schema, for its object type.
	 *
	 * @param class-string $class    The service.
	 * @param string|null  $argument The object type argument.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function expectedAsks(string $class, ?string $argument): array {
		$type = $argument ?? [
			IntakeService::class => 'organization',
			ReviewService::class => 'software-review',
			ReviewAggregateService::class => 'software-review',
			FederationService::class => 'organization',
		][$class];
		return [['register', $type], ['schema', $type]];

	}//end expectedAsks()

	/**
	 * Run one resolver against a recording locator.
	 *
	 * @param class-string                   $class    The service.
	 * @param string                         $method   The resolver.
	 * @param string|null                    $argument The object type argument.
	 * @param array{register: ?int, schema: ?int} $ids The ids the locator answers.
	 *
	 * @return array{0: array|null, 1: array}
	 */
	private function resolve(string $class, string $method, ?string $argument, array $ids): array {
		$asked   = new \ArrayObject();
		$locator = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturnCallback(
			static function (string $type) use ($asked, $ids): ?int {
				$asked[] = ['register', $type];
				return $ids['register'];
			}
		);
		$locator->method('getSchemaIdForObjectType')->willReturnCallback(
			static function (string $type) use ($asked, $ids): ?int {
				$asked[] = ['schema', $type];
				return $ids['schema'];
			}
		);

		$service = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(class: $class, property: 'settingsService'))->setValue($service, $locator);
		$arguments = array_filter([$argument], static fn ($value) => $value !== null);
		$target    = (new \ReflectionMethod(objectOrMethod: $class, method: $method))->invoke($service, ...$arguments);
		return [$target, $asked->getArrayCopy()];

	}//end resolve()

}//end class
