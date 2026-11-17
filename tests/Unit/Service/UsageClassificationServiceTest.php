<?php

/**
 * UsageClassificationServiceTest
 *
 * The GEMMA suggestion and the anonymous classification summary
 * (security-baseline-classification REQ-BCL-003, REQ-BCL-004).
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\UsageClassificationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for UsageClassificationService.
 */
class UsageClassificationServiceTest extends TestCase {

	/**
	 * Objects by schema id and object id, as find() answers them.
	 *
	 * @var array<int, array<string, array>>
	 */
	private array $objects = [];

	/**
	 * The usages searchObjects answers.
	 *
	 * @var list<array>
	 */
	private array $usages = [];

	/**
	 * The search queries and their rbac/multitenancy flags.
	 *
	 * @var list<array>
	 */
	private array $searches = [];

	/**
	 * The service over recording doubles; schema ids usage 1, module 2, element 3, organization 4.
	 *
	 * @param bool $withOpenRegister Whether OpenRegister is available.
	 *
	 * @return UsageClassificationService
	 */
	private function service(bool $withOpenRegister = true): UsageClassificationService {
		$schemas = ['usage' => 1, 'module' => 2, 'element' => 3, 'organization' => 4];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->never())->method('saveObject');
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions $options = null): ?ObjectEntityInterface {
				$schema = $options?->schema;
				if (isset($this->objects[$schema][$id]) === false) {
					throw new \Exception('Object not found');
				}

				return self::entity(data: $this->objects[$schema][$id], uuid: (string) $id);
			}
		);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = [$query, $_rbac, $_multitenancy];
				return $this->usages;
			}
		);
		$config = $this->createMock(RegisterConfigReaderInterface::class);
		$config->method('getObjectService')->willReturn($withOpenRegister === true ? $objectService : null);
		$config->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $type): ?int => $schemas[$type] ?? null);
		$config->method('getRegisterIdForObjectType')->willReturn(7);

		return new UsageClassificationService(config: $config);
	}//end service()

	/**
	 * An object entity holding the data.
	 *
	 * @param array  $data The object data.
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function entity(array $data, string $uuid): ObjectEntityInterface {
		return new class($data, $uuid) implements ObjectEntityInterface {
			/**
			 * Build it.
			 *
			 * @param array  $data The data.
			 * @param string $uuid The uuid.
			 */
			public function __construct(private array $data, private string $uuid) {
			}

			/** @return string|null */
			public function getUuid(): ?string {
				return $this->uuid;
			}

			/** @return array */
			public function getObject(): array {
				return $this->data;
			}

			/** @return string|null */
			public function getRegister(): ?string {
				return '7';
			}

			/** @return string|null */
			public function getSchema(): ?string {
				return null;
			}

			/** @return string|null */
			public function getOrganisation(): ?string {
				return null;
			}

			/** @return string|null */
			public function getOwner(): ?string {
				return null;
			}

			/** @return array */
			public function jsonSerialize(): array {
				return ['should not be read' => true];
			}
		};
	}//end entity()

	/**
	 * A classified usage of module m-1 by an organisation.
	 *
	 * @param string      $organisation The consumer.
	 * @param string      $a            Availability level.
	 * @param string      $i            Integrity level.
	 * @param string      $c            Confidentiality level.
	 * @param string|null $overall      The derived level, when stored.
	 *
	 * @return array
	 */
	private static function usage(string $organisation, string $a, string $i, string $c, ?string $overall = null): array {
		return ['module' => 'm-1', 'consumer' => $organisation, 'availabilityLevel' => $a, 'integrityLevel' => $i, 'confidentialityLevel' => $c, 'bbnLevel' => $overall];
	}//end usage()

	/**
	 * The suggestion takes the highest GEMMA score per aspect of the usage's components and counts the scored ones.
	 *
	 * @return void
	 */
	public function testTheSuggestionTakesTheHighestScorePerAspect(): void {
		$this->objects[1]['u-1'] = ['module' => 'm-1', 'usedForReferenceComponents' => ['rc-1', ['id' => 'rc-2'], 'rc-3', 'rc-1']];
		$this->objects[3]['rc-1'] = ['availability' => '1', 'integrity' => 2, 'confidentiality' => '2'];
		$this->objects[3]['rc-2'] = ['availability' => 1, 'integrity' => '1', 'confidentiality' => 3];
		$this->objects[3]['rc-3'] = ['name' => 'no scores', 'availability' => 'hoog'];

		$this->assertSame(
			['levels' => ['availability' => 'BBN1', 'integrity' => 'BBN2', 'confidentiality' => 'BBN3'], 'scored' => 2, 'components' => 3],
			$this->service()->suggestion(usageId: 'u-1')
		);
	}//end testTheSuggestionTakesTheHighestScorePerAspect()

	/**
	 * Without components of its own, the usage gets its application's; without any score, no levels; unknown is null.
	 *
	 * @return void
	 */
	public function testTheSuggestionFallsBackToTheApplication(): void {
		$this->objects[1]['u-2'] = ['module' => 'm-1', 'usedForReferenceComponents' => []];
		$this->objects[2]['m-1'] = ['referenceComponents' => ['rc-9']];
		$this->objects[3]['rc-9'] = ['availability' => '1', 'integrity' => '2', 'confidentiality' => '2'];
		$this->objects[1]['u-3'] = ['module' => 'missing'];

		$this->assertSame(['availability' => 'BBN1', 'integrity' => 'BBN2', 'confidentiality' => 'BBN2'], $this->service()->suggestion(usageId: 'u-2')['levels']);
		$this->assertSame(
			['levels' => ['availability' => null, 'integrity' => null, 'confidentiality' => null], 'scored' => 0, 'components' => 0],
			$this->service()->suggestion(usageId: 'u-3')
		);
		$this->assertNull($this->service()->suggestion(usageId: 'unknown'));
	}//end testTheSuggestionFallsBackToTheApplication()

	/**
	 * Three organisations give counts per level per aspect and overall, each organisation once, and no organisation id.
	 *
	 * @return void
	 */
	public function testThreeOrganisationsGiveCountsWithoutNames(): void {
		$this->usages = [
			self::usage('org-1', 'BBN1', 'BBN2', 'BBN2', 'BBN2'),
			self::usage('org-2', 'BBN2', 'BBN2', 'BBN3', 'BBN3'),
			self::usage('org-3', 'BBN1', 'BBN2', 'BBN2'),
			// A second usage of org-1 counts once, at its highest level.
			self::usage('org-1', 'BBN1', 'BBN1', 'BBN1', 'BBN1'),
			['module' => 'm-1', 'consumer' => 'org-4'],
			['module' => 'm-2', 'consumer' => 'org-5', 'availabilityLevel' => 'BBN3'],
		];

		$summary = $this->service()->summary(moduleId: 'm-1');

		$this->assertSame(3, $summary['organisations']);
		$this->assertSame(
			[
				'availability'    => ['BBN1' => 2, 'BBN2' => 1, 'BBN3' => 0],
				'integrity'       => ['BBN1' => 0, 'BBN2' => 3, 'BBN3' => 0],
				'confidentiality' => ['BBN1' => 0, 'BBN2' => 2, 'BBN3' => 1],
				'overall'         => ['BBN1' => 0, 'BBN2' => 2, 'BBN3' => 1],
			],
			$summary['levels']
		);
		$this->assertStringNotContainsString('org-', (string) json_encode($summary));
		[$query, $rbac, $multitenancy] = $this->searches[0];
		$this->assertSame('m-1', $query['module']);
		$this->assertSame(['register' => 7, 'schema' => 1], $query['@self']);
		$this->assertFalse($rbac);
		$this->assertFalse($multitenancy);
	}//end testThreeOrganisationsGiveCountsWithoutNames()

	/**
	 * Two organisations give only the count, and the threshold is three.
	 *
	 * @return void
	 */
	public function testTwoOrganisationsGiveOnlyTheCount(): void {
		$this->usages = [self::usage('org-1', 'BBN1', 'BBN2', 'BBN2'), self::usage('org-2', 'BBN3', 'BBN2', 'BBN3')];

		$this->assertSame(['organisations' => 2, 'levels' => null], $this->service()->summary(moduleId: 'm-1'));
		$this->assertSame(3, UsageClassificationService::MIN_ORGANISATIONS);
	}//end testTwoOrganisationsGiveOnlyTheCount()

	/**
	 * The organisation type is read from the organisation; OpenRegister missing is an error, not an empty answer.
	 *
	 * @return void
	 */
	public function testOrganisationTypeAndMissingOpenRegister(): void {
		$this->objects[4]['org-1'] = ['type' => 'Municipality'];
		$this->objects[4]['org-2'] = ['name' => 'no type'];

		$this->assertSame('Municipality', $this->service()->organisationTypeOf(organisationId: 'org-1'));
		$this->assertNull($this->service()->organisationTypeOf(organisationId: 'org-2'));
		$this->assertNull($this->service()->organisationTypeOf(organisationId: 'org-x'));

		$this->expectException(RuntimeException::class);
		$this->service(withOpenRegister: false)->summary(moduleId: 'm-1');
	}//end testOrganisationTypeAndMissingOpenRegister()
}//end class
