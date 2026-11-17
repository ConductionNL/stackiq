<?php

/**
 * Unit tests for PeerCatalogClient.
 *
 * The HTTP client is the boundary: Nextcloud's IClientService is mocked and
 * answers with canned pages. The tests read what the client asks the peer
 * (the listing URL, `_aggregate=false`, the page, the size, the timeout and
 * the local-address switch) and what it answers for every failure.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Federation\FederationConfig;
use OCA\Stackiq\Service\Federation\PeerCatalogClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Paging, the cap, the catalogue name and every way a fetch fails.
 *
 * @covers \OCA\Stackiq\Service\Federation\PeerCatalogClient
 * @uses   \OCA\Stackiq\Service\Federation\FederationConfig
 */
class PeerCatalogClientTest extends TestCase {

	private const PEER = 'https://catalogus.drechtsteden.nl';

	private const LISTING = self::PEER . PeerCatalogClient::LISTING_PATH;

	private const CATALOGI = self::PEER . PeerCatalogClient::CATALOGI_PATH;

	/**
	 * App config values by key.
	 *
	 * @var array<string,int>
	 */
	private array $store = [];

	/**
	 * Every GET the client made: [url, options].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $requests = [];

	/**
	 * The answers by URL: a list of responses (or Throwables) handed out in order.
	 *
	 * @var array<string,array<int,mixed>>
	 */
	private array $answers = [];

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Reset the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store    = ['federation_peer_timeout' => 7];
		$this->requests = [];
		$this->answers  = [];
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The client under test, its HTTP boundary answering from $this->answers.
	 *
	 * @return PeerCatalogClient
	 */
	private function client(): PeerCatalogClient {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => ($this->store[$key] ?? $default)
		);

		$http = $this->createMock(originalClassName: IClient::class);
		$http->method('get')->willReturnCallback(
			function (string $url, array $options): IResponse {
				$this->requests[] = [$url, $options];
				$next = array_shift($this->answers[$url]);
				if ($next instanceof \Throwable) {
					throw $next;
				}

				return $next;
			}
		);
		$clients = $this->createMock(originalClassName: IClientService::class);
		$clients->method('newClient')->willReturn($http);

		return new PeerCatalogClient(clients: $clients, config: new FederationConfig(appConfig: $appConfig), logger: $this->logger);
	}//end client()

	/**
	 * A response with a status and a body.
	 *
	 * @param int   $status The HTTP status.
	 * @param mixed $body   The body; arrays are JSON-encoded.
	 *
	 * @return IResponse
	 */
	private function response(int $status, mixed $body): IResponse {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(is_array($body) === true ? json_encode($body) : $body);
		return $response;
	}//end response()

	/**
	 * The query of the n-th request.
	 *
	 * @param int $index The request.
	 *
	 * @return array<string,mixed>
	 */
	private function queryOf(int $index): array {
		return $this->requests[$index][1]['query'];
	}//end queryOf()

	/**
	 * Every page is followed until the last, only the peer's own entries are
	 * asked for, and the catalogue name comes from the peer's catalogue.
	 *
	 * @return void
	 */
	public function testTheListingIsReadPageByPage(): void {
		$this->answers[self::LISTING]  = [
			$this->response(status: 200, body: ['results' => [['id' => 'a'], ['id' => 'b']], 'page' => 1, 'pages' => 2]),
			$this->response(status: 200, body: ['results' => [['id' => 'c'], 'not an entry'], 'page' => 2, 'pages' => 2]),
		];
		$this->answers[self::CATALOGI] = [$this->response(status: 200, body: ['results' => [['title' => ' Softwarecatalogus Drechtsteden ']]])];

		$result = $this->client()->fetch(peerUrl: self::PEER . '/');

		$this->assertSame(
			expected: ['ok' => true, 'reason' => 'ok', 'organisation' => 'Softwarecatalogus Drechtsteden', 'entries' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]],
			actual: $result
		);
		$this->assertSame(expected: [self::LISTING, self::LISTING, self::CATALOGI], actual: array_column($this->requests, 0));
		$this->assertSame(expected: ['_aggregate' => 'false', '_page' => 1, '_limit' => 100], actual: $this->queryOf(index: 0));
		$this->assertSame(expected: ['_aggregate' => 'false', '_page' => 2, '_limit' => 100], actual: $this->queryOf(index: 1));
		$options = $this->requests[0][1];
		$this->assertSame(expected: 7, actual: $options['timeout']);
		$this->assertFalse(condition: $options['http_errors']);
		$this->assertSame(expected: ['allow_local_address' => false], actual: $options['nextcloud']);
		$this->assertSame(expected: 'application/json', actual: $options['headers']['Accept']);
	}//end testTheListingIsReadPageByPage()

	/**
	 * The cap ends the read, even when the peer has more pages.
	 *
	 * @return void
	 */
	public function testTheCapEndsTheRead(): void {
		$this->store['federation_max_entries'] = 3;
		$this->answers[self::LISTING]  = [
			$this->response(status: 200, body: ['results' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c'], ['id' => 'd']], 'pages' => 9]),
		];
		$this->answers[self::CATALOGI] = [$this->response(status: 200, body: ['results' => [['name' => 'Peer']]])];

		$result = $this->client()->fetch(peerUrl: self::PEER);

		$this->assertSame(expected: [['id' => 'a'], ['id' => 'b'], ['id' => 'c']], actual: $result['entries']);
		$this->assertSame(expected: 'Peer', actual: $result['organisation']);
		$this->assertSame(expected: 3, actual: $this->queryOf(index: 0)['_limit']);
		$this->assertCount(expectedCount: 2, haystack: $this->requests);
	}//end testTheCapEndsTheRead()

	/**
	 * An empty page ends the read; a listing without a page count is one page.
	 *
	 * @return void
	 */
	public function testAnEmptyOrUncountedPageEndsTheRead(): void {
		$this->answers[self::LISTING]  = [$this->response(status: 200, body: ['results' => [['id' => 'a']]])];
		$this->answers[self::CATALOGI] = [$this->response(status: 200, body: ['results' => []])];

		$result = $this->client()->fetch(peerUrl: self::PEER);

		$this->assertSame(expected: [['id' => 'a']], actual: $result['entries']);
		$this->assertNull(actual: $result['organisation']);
		$this->assertCount(expectedCount: 2, haystack: $this->requests);

		$this->requests                = [];
		$this->answers[self::LISTING]  = [$this->response(status: 200, body: ['results' => [], 'pages' => 4])];
		$this->answers[self::CATALOGI] = [$this->response(status: 500, body: [])];
		$this->assertSame(expected: [], actual: $this->client()->fetch(peerUrl: self::PEER)['entries']);
		$this->assertCount(expectedCount: 2, haystack: $this->requests);
	}//end testAnEmptyOrUncountedPageEndsTheRead()

	/**
	 * A peer that does not answer is not reachable, and nothing is returned.
	 *
	 * @return void
	 */
	public function testAPeerThatDoesNotAnswerIsNotReachable(): void {
		$this->answers[self::LISTING] = [new RuntimeException('cURL error 28: timed out')];
		$this->logger->expects($this->once())->method('warning')->with(
			'[Federation] peer did not answer',
			['url' => self::LISTING, 'error' => 'cURL error 28: timed out']
		);

		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'not reachable', 'organisation' => null, 'entries' => []],
			actual: $this->client()->fetch(peerUrl: self::PEER)
		);
	}//end testAPeerThatDoesNotAnswerIsNotReachable()

	/**
	 * A host the HTTP client refuses as local reads as blocked.
	 *
	 * @return void
	 */
	public function testALocalAddressIsBlocked(): void {
		$this->answers[self::LISTING] = [new LocalServerException('Host violates local access rules')];

		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'blocked', 'organisation' => null, 'entries' => []],
			actual: $this->client()->fetch(peerUrl: self::PEER)
		);
	}//end testALocalAddressIsBlocked()

	/**
	 * An allowlisted peer may resolve to a local address.
	 *
	 * @return void
	 */
	public function testAnAllowlistedPeerMayBeLocal(): void {
		$this->answers[self::LISTING]  = [$this->response(status: 200, body: ['results' => []])];
		$this->answers[self::CATALOGI] = [$this->response(status: 200, body: ['results' => []])];

		$this->client()->fetch(peerUrl: self::PEER, allowLocal: true);

		$this->assertSame(expected: ['allow_local_address' => true], actual: $this->requests[0][1]['nextcloud']);
		$this->assertSame(expected: ['allow_local_address' => true], actual: $this->requests[1][1]['nextcloud']);
	}//end testAnAllowlistedPeerMayBeLocal()

	/**
	 * A failed later page fails the whole fetch: no half listing is merged.
	 *
	 * @return void
	 */
	public function testANonSuccessAnswerFailsTheFetch(): void {
		$this->answers[self::LISTING] = [
			$this->response(status: 200, body: ['results' => [['id' => 'a']], 'pages' => 2]),
			$this->response(status: 503, body: 'busy'),
		];

		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'HTTP 503', 'organisation' => null, 'entries' => []],
			actual: $this->client()->fetch(peerUrl: self::PEER)
		);
	}//end testANonSuccessAnswerFailsTheFetch()

	/**
	 * An answer that is no JSON, or a JSON body without a results list, is invalid.
	 *
	 * @return void
	 */
	public function testAnAnswerThatIsNoListingIsInvalid(): void {
		$this->answers[self::LISTING] = [$this->response(status: 200, body: '<html>maintenance</html>')];
		$this->assertSame(expected: 'invalid answer', actual: $this->client()->fetch(peerUrl: self::PEER)['reason']);

		$this->answers[self::LISTING] = [$this->response(status: 200, body: ['results' => 'none'])];
		$result = $this->client()->fetch(peerUrl: self::PEER);
		$this->assertFalse(condition: $result['ok']);
		$this->assertSame(expected: 'invalid answer', actual: $result['reason']);
	}//end testAnAnswerThatIsNoListingIsInvalid()
}//end class
