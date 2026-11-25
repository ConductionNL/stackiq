<?php

/**
 * Unit tests for ArchitectureViewDraftService.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Exception\DraftRefusedException;
use OCA\Stackiq\Service\ArchitectureViewDraftService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The draft service validates, reuses, marks and stops on a refusal.
 */
#[CoversClass(ArchitectureViewDraftService::class)]
#[UsesClass(DraftRefusedException::class)]
final class ArchitectureViewDraftServiceTest extends TestCase {

	private const EXISTING = '11111111-1111-4111-8111-111111111111';

	/** @var list<array{schema: string, object: array<string, mixed>}> Every save, in order. */
	private array $saves = [];

	/** @var array<string, list<array<string, mixed>>> Search results per schema. */
	private array $rows = [];

	/** @var list<array{schema: string, filters: array<string, mixed>}> Every search, in order. */
	private array $searches = [];

	/** @var string|null A schema whose save is refused. */
	private ?string $refuse = null;

	/** @var array<string, string> The AMEF mapping: the stackiq register carries the AMEF schemas, as on a current install. */
	private array $amefConfig = ['register' => '26', 'element_schema' => '51', 'relation_schema' => '55', 'view_schema' => '52'];

	/** @var string The register every read and write must name. */
	private string $register = '26';

	/**
	 * A malformed draft is refused before any save.
	 *
	 * @param array<int, array<string, mixed>> $elements  The elements.
	 * @param array<int, array<string, mixed>> $relations The relations.
	 * @param string                           $message   A part of the refusal.
	 *
	 * @return void
	 */
	#[DataProvider('malformedDrafts')]
	public function testAMalformedDraftIsRefusedBeforeAnySave(array $elements, array $relations, string $message): void {
		try {
			$this->service()->draftView(name: 'Concept', description: '', elements: $elements, relations: $relations);
			$this->fail(message: 'The draft was not refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString(needle: $message, haystack: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: $this->saves);
	}//end testAMalformedDraftIsRefusedBeforeAnySave()

	/**
	 * The malformed drafts.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: string}>
	 */
	public static function malformedDrafts(): array {
		$one = [['key' => 'a', 'type' => 'ApplicationComponent', 'name' => 'Zaaksysteem']];
		$many = [];
		for ($i = 0; $i <= ArchitectureViewDraftService::MAX_ELEMENTS; $i++) {
			$many[] = ['key' => 'k' . $i, 'type' => 'Node', 'name' => 'Server ' . $i];
		}

		$tooManyRelations = array_fill(0, ArchitectureViewDraftService::MAX_RELATIONS + 1, ['source' => 'a', 'target' => 'a', 'type' => 'Flow']);

		return [
			'unknown element type' => [[['key' => 'a', 'type' => 'Spaceship', 'name' => 'X']], [], "Element 'a' needs an existing uuid"],
			'unknown relation type' => [$one, [['source' => 'a', 'target' => 'a', 'type' => 'Teleports']], 'Relation 0 needs an ArchiMate relation type'],
			'dangling key' => [$one, [['source' => 'a', 'target' => 'b', 'type' => 'Flow']], 'names a target that is not one of the element keys'],
			'duplicate key' => [[$one[0], $one[0]], [], 'Element 1 needs a key of its own'],
			'element without a name' => [[['key' => 'a', 'type' => 'Node', 'name' => ' ']], [], "Element 'a' needs a name"],
			'no elements' => [[], [], 'holds 1 to 60 elements'],
			'elements over the cap' => [$many, [], 'holds 1 to 60 elements'],
			'relations over the cap' => [$one, $tooManyRelations, 'at most 120 relations'],
		];
	}//end malformedDrafts()

	/**
	 * A draft without a name is refused.
	 *
	 * @return void
	 */
	public function testADraftWithoutANameIsRefused(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->service()->draftView(name: '  ', description: '', elements: [['key' => 'a', 'type' => 'Node', 'name' => 'S']], relations: []);
	}//end testADraftWithoutANameIsRefused()

	/**
	 * A new element, its relation and the view are each saved with the assistant mark, the view with who and when.
	 *
	 * @return void
	 */
	public function testEveryCreatedObjectCarriesTheMarkInItsOwnSave(): void {
		$result = $this->service(uid: 'anna')->draftView(
			name: 'Zaakgericht werken, concept',
			description: 'How the case system hands documents to document management.',
			elements: [
				['key' => 'a', 'type' => 'ApplicationComponent', 'name' => 'Zaaksysteem'],
				['key' => 'b', 'type' => 'ApplicationComponent', 'name' => 'Documentbeheer'],
			],
			relations: [['source' => 'a', 'target' => 'b', 'type' => 'Flow']]
		);

		$this->assertSame(expected: ['element', 'element', 'relation', 'view'], actual: array_column(array: $this->saves, column_key: 'schema'));
		foreach ($this->saves as $save) {
			$this->assertSame(expected: 'assistant', actual: $save['object']['origin'], message: $save['schema']);
		}

		[$zaak, $dms, $relation, $view] = array_column($this->saves, 'object');
		$ends = array_intersect_key($relation, ['type' => 1, 'source' => 1, 'target' => 1]);
		$this->assertSame(expected: ['type' => 'Flow', 'source' => $zaak['identifier'], 'target' => $dms['identifier']], actual: $ends);
		$this->assertSame(expected: 'draft', actual: $view['status']);
		$this->assertSame(expected: 'anna', actual: $view['draftedFor']);
		$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', string: $view['draftedAt']);
		$this->assertSame(expected: 'How the case system hands documents to document management.', actual: $view['documentation']);
		$this->assertMatchesRegularExpression(pattern: '/^id-[0-9a-f-]{36}$/', string: $view['identifier']);

		$this->assertSame(expected: [$zaak['identifier'], $dms['identifier']], actual: array_column(array: $view['nodes'], column_key: 'elementRef'));
		foreach ($view['nodes'] as $node) {
			$this->assertArrayNotHasKey(key: 'x', array: $node, message: 'a draft is laid out on first open');
			$this->assertArrayNotHasKey(key: 'y', array: $node);
		}

		$connection = $view['connections'][0];
		$this->assertSame(expected: $relation['identifier'], actual: $connection['modelRelationshipId']);
		$nodeIds = [$view['nodes'][0]['viewNodeId'], $view['nodes'][1]['viewNodeId']];
		$this->assertSame(expected: $nodeIds, actual: [$connection['sourceId'], $connection['targetId']]);
		$this->assertSame(expected: ['viewNodes' => $view['nodes'], 'viewRelationships' => $view['connections']], actual: $view['xml']);

		$this->assertSame(expected: 'saved-view', actual: $result['uuid']);
		$this->assertSame(expected: '/apps/stackiq/views/saved-view', actual: $result['url']);
		$this->assertSame(expected: ['elements' => 2, 'relations' => 1], actual: $result['created']);
	}//end testEveryCreatedObjectCarriesTheMarkInItsOwnSave()

	/**
	 * A named element, a new one matching an existing type and exact name, and an existing relation are reused.
	 *
	 * @return void
	 */
	public function testExistingElementsAndRelationsAreReused(): void {
		$this->rows['element'] = [
			['identifier' => 'id-dms', 'type' => 'ApplicationComponent', 'name' => 'Documentbeheer', 'uuid' => 'u-dms'],
			['identifier' => 'id-dms-lower', 'type' => 'ApplicationComponent', 'name' => 'documentbeheer', 'uuid' => 'u-lower'],
		];
		$this->rows['relation'] = [['identifier' => 'id-rel', 'type' => 'Flow', 'source' => 'id-zaak', 'target' => 'id-dms']];

		$result = $this->service()->draftView(
			name: 'Concept',
			description: '',
			elements: [
				['key' => 'a', 'uuid' => self::EXISTING],
				['key' => 'b', 'type' => 'ApplicationComponent', 'name' => 'Documentbeheer'],
			],
			relations: [['source' => 'a', 'target' => 'b', 'type' => 'Flow']]
		);

		$this->assertSame(expected: ['view'], actual: array_column(array: $this->saves, column_key: 'schema'));
		$view = $this->saves[0]['object'];
		$this->assertSame(expected: ['id-zaak', 'id-dms'], actual: array_column(array: $view['nodes'], column_key: 'elementRef'));
		$this->assertSame(expected: ['Zaaksysteem', 'Documentbeheer'], actual: array_column(array: $view['nodes'], column_key: 'name'));
		$this->assertSame(expected: 'id-rel', actual: $view['connections'][0]['modelRelationshipId']);
		$this->assertSame(expected: ['elements' => 0, 'relations' => 0], actual: $result['created']);
		$elementSearch = ['schema' => 'element', 'filters' => ['type' => 'ApplicationComponent', 'name' => 'Documentbeheer', '_limit' => 5]];
		$this->assertContains(needle: $elementSearch, haystack: $this->searches);
		$relationSearch = ['schema' => 'relation', 'filters' => ['type' => 'Flow', 'source' => 'id-zaak', 'target' => 'id-dms', '_limit' => 5]];
		$this->assertContains(needle: $relationSearch, haystack: $this->searches);
	}//end testExistingElementsAndRelationsAreReused()

	/**
	 * A relation of another type between the same elements is not reused.
	 *
	 * @return void
	 */
	public function testARelationOfAnotherTypeIsNotReused(): void {
		$this->rows['relation'] = [['identifier' => 'id-rel', 'type' => 'Serving', 'source' => 'id-zaak', 'target' => 'id-zaak']];

		$this->service()->draftView(
			name: 'Concept',
			description: '',
			elements: [['key' => 'a', 'uuid' => self::EXISTING]],
			relations: [['source' => 'a', 'target' => 'a', 'type' => 'Flow']]
		);

		$this->assertSame(expected: ['relation', 'view'], actual: array_column(array: $this->saves, column_key: 'schema'));
	}//end testARelationOfAnotherTypeIsNotReused()

	/**
	 * A named element the caller cannot read is a validation error, and nothing is saved.
	 *
	 * @return void
	 */
	public function testAnUnreadableNamedElementIsRefused(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: "Element 'a' names an element that does not exist or that you cannot read.");

		try {
			$this->service()->draftView(
				name: 'Concept',
				description: '',
				elements: [['key' => 'a', 'uuid' => '22222222-2222-4222-8222-222222222222']],
				relations: []
			);
		} finally {
			$this->assertSame(expected: [], actual: $this->saves);
		}
	}//end testAnUnreadableNamedElementIsRefused()

	/**
	 * A refused save is forbidden and stops the draft: no later save runs.
	 *
	 * @return void
	 */
	public function testARefusedSaveIsForbiddenAndStopsTheDraft(): void {
		$this->refuse = 'element';

		try {
			$this->service()->draftView(
				name: 'Concept',
				description: '',
				elements: [['key' => 'a', 'type' => 'Node', 'name' => 'S1'], ['key' => 'b', 'type' => 'Node', 'name' => 'S2']],
				relations: [['source' => 'a', 'target' => 'b', 'type' => 'Flow']]
			);
			$this->fail(message: 'The refusal was not passed on.');
		} catch (DraftRefusedException $e) {
			$this->assertStringContainsString(needle: 'You may not create a element here', haystack: $e->getMessage());
		}

		$this->assertSame(expected: ['element'], actual: array_column(array: $this->saves, column_key: 'schema'));
	}//end testARefusedSaveIsForbiddenAndStopsTheDraft()

	/**
	 * A refused view save is forbidden.
	 *
	 * @return void
	 */
	public function testARefusedViewSaveIsForbidden(): void {
		$this->refuse = 'view';
		$this->expectException(exception: DraftRefusedException::class);

		$this->service()->draftView(name: 'Concept', description: '', elements: [['key' => 'a', 'uuid' => self::EXISTING]], relations: []);
	}//end testARefusedViewSaveIsForbidden()

	/**
	 * Without an AMEF mapping the draft falls back to the vng-gemma register by slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-002-stackiq-shall-offer-a-create-tool-that-writes-a-draft-view-from-elements-and-relations
	 */
	public function testWithoutAnAmefMappingTheDraftUsesTheVngGemmaSlug(): void {
		$this->amefConfig = [];
		$this->register = ArchitectureViewDraftService::REGISTER;

		$this->service()->draftView(name: 'Concept', description: '', elements: [['key' => 'a', 'type' => 'Node', 'name' => 'Server']], relations: []);

		self::assertSame(expected: ['element', 'view'], actual: array_column($this->saves, 'schema'));
	}//end testWithoutAnAmefMappingTheDraftUsesTheVngGemmaSlug()

	/**
	 * A save that fails for another reason is not reported as forbidden.
	 *
	 * @return void
	 */
	public function testAnotherSaveFailureIsNotForbidden(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjectsPaginated')->willReturn(['results' => []]);
		$objects->method('saveObject')->willThrowException(new RuntimeException('database gone'));

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'The element could not be saved: database gone');

		$this->service(objects: $objects)->draftView(
			name: 'Concept',
			description: '',
			elements: [['key' => 'a', 'type' => 'Node', 'name' => 'S']],
			relations: []
		);
	}//end testAnotherSaveFailureIsNotForbidden()

	/**
	 * The search returns the short projection only, filtered to the asked types, capped at fifty rows.
	 *
	 * @return void
	 */
	public function testTheSearchReturnsAShortProjection(): void {
		$this->rows['element'] = [
			[
				'identifier'    => 'id-z',
				'type'          => 'ApplicationComponent',
				'name'          => 'Zaaksysteem',
				'gemmaType'     => 'Referentiecomponent',
				'documentation' => 'long text',
				'uuid'          => 'u-z',
			],
			['identifier' => 'id-n', 'type' => 'Node', 'name' => 'Zaakserver', 'uuid' => 'u-n'],
		];

		$rows = $this->service()->searchElements(query: 'zaak', types: ['ApplicationComponent', 'BusinessProcess'], limit: 500);

		$expected = [
			['uuid' => 'u-z', 'identifier' => 'id-z', 'name' => 'Zaaksysteem', 'type' => 'ApplicationComponent', 'gemmaType' => 'Referentiecomponent'],
		];
		$this->assertSame(expected: $expected, actual: $rows);
		$this->assertSame(expected: ['schema' => 'element', 'filters' => ['_search' => 'zaak', '_limit' => 50]], actual: $this->searches[0]);

		$this->service()->searchElements(query: 'zaak', types: ['Node'], limit: 3);
		$this->assertSame(expected: ['_search' => 'zaak', '_limit' => 3, 'type' => 'Node'], actual: $this->searches[1]['filters']);
	}//end testTheSearchReturnsAShortProjection()

	/**
	 * A search for an unknown type is refused.
	 *
	 * @return void
	 */
	public function testASearchForAnUnknownTypeIsRefused(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->service()->searchElements(query: 'x', types: ['Spaceship']);
	}//end testASearchForAnUnknownTypeIsRefused()

	/**
	 * Without OpenRegister nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheServiceIsUnavailable(): void {
		$registers = $this->createMock(originalClassName: RegisterConfigReaderInterface::class);
		$registers->method('getObjectService')->willReturn(null);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'OpenRegister is not available.');

		$service = new ArchitectureViewDraftService(registers: $registers, userSession: $this->createMock(originalClassName: IUserSession::class));
		$service->searchElements(query: 'x');
	}//end testWithoutOpenRegisterTheServiceIsUnavailable()

	/**
	 * The service over a fake OpenRegister that records saves and searches.
	 *
	 * @param string                      $uid     The signed-in user.
	 * @param ObjectServiceInterface|null $objects An object service to use instead of the fake.
	 *
	 * @return ArchitectureViewDraftService
	 */
	private function service(string $uid = 'admin', ?ObjectServiceInterface $objects = null): ArchitectureViewDraftService {
		$objects ??= $this->fakeObjects();
		$registers = $this->createMock(originalClassName: RegisterConfigReaderInterface::class);
		$registers->method('getObjectService')->willReturn($objects);
		$registers->method('getAmefConfig')->willReturn($this->amefConfig);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ArchitectureViewDraftService(registers: $registers, userSession: $session);
	}//end service()

	/**
	 * A fake object service: searches answer from $rows, find answers the one existing element, saves are recorded.
	 *
	 * @return ObjectServiceInterface
	 */
	private function fakeObjects(): ObjectServiceInterface {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query): array {
				$this->assertSame(expected: $this->register, actual: $query['@self']['register']);
				$schemaSlug = $this->schemaSlugOf(schema: $query['@self']['schema']);
				$filters = $query;
				unset($filters['@self']);
				$this->searches[] = ['schema' => $schemaSlug, 'filters' => $filters];
				return ['results' => ($this->rows[$schemaSlug] ?? [])];
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				if ($id !== self::EXISTING) {
					return null;
				}

				return $this->entity(data: ['identifier' => 'id-zaak', 'type' => 'ApplicationComponent', 'name' => 'Zaaksysteem'], uuid: self::EXISTING);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntityInterface {
				$schema = $this->schemaSlugOf(schema: (string)$options->target->schema);
				$this->assertSame(expected: $this->register, actual: $options->target->register);
				$this->saves[] = ['schema' => $schema, 'object' => $object];
				if ($this->refuse === $schema) {
					throw new RuntimeException("User 'Anna' does not have permission to 'create' objects in schema '{$schema}'");
				}

				return $this->entity(data: $object, uuid: 'saved-' . $schema);
			}
		);

		return $objects;
	}//end fakeObjects()

	/**
	 * The schema slug the fake files a target schema under: an id from the AMEF mapping, or the slug itself.
	 *
	 * @param string $schema The target schema.
	 *
	 * @return string
	 */
	private function schemaSlugOf(string $schema): string {
		$bySchemaId = ['51' => 'element', '55' => 'relation', '52' => 'view'];
		return ($bySchemaId[$schema] ?? $schema);
	}//end schemaSlugOf()

	/**
	 * An object entity double.
	 *
	 * @param array<string, mixed> $data The object data.
	 * @param string               $uuid The uuid.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data, string $uuid): ObjectEntityInterface {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}//end entity()
}//end class
