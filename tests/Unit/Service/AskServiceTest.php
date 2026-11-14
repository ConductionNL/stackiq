<?php

/**
 * AskService Test
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Exception\AskUnavailableException;
use OCA\Stackiq\Service\AskService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Search as the caller over the seven schemas, at most 20 entries; converse
 * with those entries; the sources are only the cited ones; nothing matched
 * means hermiq is not called; no hermiq means unavailable.
 *
 * @covers \OCA\Stackiq\Service\AskService
 * @uses   \OCA\Stackiq\Exception\AskUnavailableException
 *
 * @spec openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 */
class AskServiceTest extends TestCase {

	/**
	 * Searches the object service received, in order.
	 *
	 * @var list<array{query: array, rbac: bool, multitenancy: bool}>
	 */
	private array $searches = [];

	/**
	 * Turns the assistant received.
	 *
	 * @var list<array{0: string, 1: ?string, 2: string, 3: array}>
	 */
	public array $turns = [];

	/**
	 * Rows per schema id.
	 *
	 * @var array<int, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The service, with hermiq present or absent.
	 *
	 * @param bool   $hermiq Whether hermiq answers.
	 * @param string $reply  The assistant's reply.
	 *
	 * @return AskService
	 */
	private function service(bool $hermiq = true, string $reply = ''): AskService {
		// Schema ids 1..7 in the order the service searches; register 50.
		$ids = ['module' => 1, 'catalogService' => 2, 'organization' => 3, 'usage' => 4, 'connection' => 5, 'compliancy' => 6, 'catalogContract' => 7];
		$locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturn(50);
		$locator->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $objectType): ?int => $ids[$objectType] ?? null);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$rows = ($this->rows[$query['@self']['schema']] ?? []);
				return ['results' => array_slice($rows, 0, (int)$query['_limit'])];
			}
		);
		$registers = $this->createMock(RegisterConfigReaderInterface::class);
		$registers->method('getObjectService')->willReturn($objects);

		$test = $this;
		$assistant = new class ($test, $reply) {
			public function __construct(private AskServiceTest $test, private string $reply) {
			}

			public function converse(string $userId, ?string $sessionId, string $message, array $context): array {
				$this->test->turns[] = [$userId, $sessionId, $message, $context];
				return ['sessionId' => ($sessionId ?? 'session-1'), 'reply' => $this->reply, 'usage' => []];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($hermiq, $assistant): object {
				if ($hermiq === false || $id !== AskService::HERMIQ_ASSISTANT) {
					throw new class ('no hermiq') extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $assistant;
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.nl' . $path);

		return new AskService(
			locator: $locator,
			registers: $registers,
			container: $container,
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * The answer holds the reply, only the cited entries as sources, and everything searched.
	 *
	 * @return void
	 */
	public function testTheAnswerListsTheEntriesItCited(): void {
		$this->rows = [
			1 => [['id' => 'm-1', 'name' => 'Zaaksysteem', 'status' => 'actief', 'description' => 'Zaakgericht werken', 'secret' => 'not sent']],
			3 => [['id' => 'o-1', 'name' => 'Leverancier BV', 'type' => 'leverancier']],
			7 => [['id' => 'c-1', 'contractNumber' => 'C-2026-7', 'endDate' => '2026-12-31']],
		];

		$answer = $this->service(reply: 'Zaaksysteem [E1] valt onder contract C-2026-7 [E3].')->ask(question: 'Welk contract heeft het zaaksysteem?', sessionId: null, userId: 'alice');

		$this->assertSame('session-1', $answer['sessionId']);
		$this->assertSame('Zaaksysteem [E1] valt onder contract C-2026-7 [E3].', $answer['reply']);
		$this->assertSame(['E1', 'E3'], array_column($answer['sources'], 'ref'));
		$this->assertSame(['E1', 'E2', 'E3'], array_column($answer['searched'], 'ref'));
		$this->assertSame(
			[
				'ref' => 'E1',
				'schema' => 'module',
				'id' => 'm-1',
				'title' => 'Zaaksysteem',
				'summary' => 'name: Zaaksysteem; status: actief; description: Zaakgericht werken',
				'url' => 'https://cloud.example.nl/index.php/apps/stackiq/modules/m-1',
			],
			$answer['searched'][0]
		);
		$this->assertSame('https://cloud.example.nl/index.php/apps/stackiq/contracten/c-1', $answer['searched'][2]['url']);

		// One turn, as the caller, with the entries as context and the citation instruction.
		$this->assertCount(1, $this->turns);
		[$userId, $sessionId, $message, $context] = $this->turns[0];
		$this->assertSame('alice', $userId);
		$this->assertNull($sessionId);
		$this->assertStringStartsWith('Welk contract heeft het zaaksysteem?', $message);
		$this->assertStringContainsString('[E1]', $message);
		$this->assertSame('stackiq', $context['app']);
		$this->assertSame($answer['searched'], $context['contextData']);
		$this->assertStringNotContainsString('not sent', json_encode($context));
	}//end testTheAnswerListsTheEntriesItCited()

	/**
	 * The search runs as the caller over the seven schemas, with the question as full text.
	 *
	 * @return void
	 */
	public function testTheSearchRunsAsTheCallerOverTheSevenSchemas(): void {
		$this->service()->ask(question: 'zaak', sessionId: 'session-9', userId: 'alice');

		$this->assertSame([1, 2, 3, 4, 5, 6, 7], array_map(static fn (array $s): int => $s['query']['@self']['schema'], $this->searches));
		foreach ($this->searches as $search) {
			$this->assertTrue($search['rbac']);
			$this->assertTrue($search['multitenancy']);
			$this->assertSame('zaak', $search['query']['_search']);
			$this->assertSame(50, $search['query']['@self']['register']);
		}
	}//end testTheSearchRunsAsTheCallerOverTheSevenSchemas()

	/**
	 * At most 20 entries are sent, and the search stops once it has them.
	 *
	 * @return void
	 */
	public function testAtMostTwentyEntriesAreSent(): void {
		$this->rows = [
			1 => array_map(static fn (int $i): array => ['id' => 'm-' . $i, 'name' => 'Module ' . $i], range(1, 15)),
			2 => array_map(static fn (int $i): array => ['id' => 's-' . $i, 'name' => 'Dienst ' . $i], range(1, 15)),
			3 => [['id' => 'o-1', 'name' => 'Never reached']],
		];

		$answer = $this->service(reply: 'E20')->ask(question: 'alles', sessionId: null, userId: 'alice');

		$this->assertCount(20, $answer['searched']);
		$this->assertSame('E20', $answer['searched'][19]['ref']);
		$this->assertSame('Dienst 5', $answer['searched'][19]['title']);
		$this->assertSame([20, 5], array_map(static fn (array $s): int => $s['query']['_limit'], $this->searches));
		$this->assertSame(['E20'], array_column($answer['sources'], 'ref'));
	}//end testAtMostTwentyEntriesAreSent()

	/**
	 * When nothing matches, the assistant is not called.
	 *
	 * @return void
	 */
	public function testAnEmptySearchDoesNotCallTheAssistant(): void {
		$answer = $this->service(reply: 'should not be asked')->ask(question: 'nothing', sessionId: 'session-2', userId: 'alice');

		$this->assertSame([], $this->turns);
		$this->assertSame(['sessionId' => 'session-2', 'reply' => '', 'sources' => [], 'searched' => []], $answer);
	}//end testAnEmptySearchDoesNotCallTheAssistant()

	/**
	 * Without hermiq the question is refused before anything is searched.
	 *
	 * @return void
	 */
	public function testWithoutHermiqItIsUnavailable(): void {
		$this->rows = [1 => [['id' => 'm-1', 'name' => 'Zaaksysteem']]];

		try {
			$this->service(hermiq: false)->ask(question: 'zaak', sessionId: null, userId: 'alice');
			$this->fail('A question was answered without hermiq.');
		} catch (AskUnavailableException $e) {
			$this->assertSame([], $this->searches);
		}
	}//end testWithoutHermiqItIsUnavailable()
}//end class
