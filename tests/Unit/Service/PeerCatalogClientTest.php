<?php

/**
 * Unit tests for PeerCatalogClient.
 *
 * The pull reads the peer's own public listing over Nextcloud's HTTP client:
 * page by page up to the cap, with the configured timeout, and fails whole on
 * a transport error, a status outside 2xx or a body that is not a listing.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
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
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fetching a peer's published listing.
 *
 * @covers \OCA\Stackiq\Service\Federation\PeerCatalogClient
 * @uses   \OCA\Stackiq\Service\Federation\FederationConfig
 */
class PeerCatalogClientTest extends TestCase {

	private const PEER = 'https://catalogus.drechtsteden.nl';

	/**
	 * App config values by key.
	 *
	 * @var array<string,mixed>
	 */
	private array $store = [];

	/**
	 * The GET requests made: [url, options].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $requests = [];

	/**
	 * A client whose GETs answer the given responses in order (a Throwable is thrown).
	 *
	 * @param array<int,IResponse|\Throwable> $answers The answers.
	 *
	 * @return PeerCatalogClient
	 */
	private function client(array $answers): PeerCatalogClient {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->store[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->store[$key] ?? $default)
		);

		$http = $this->createMock(originalClassName: IClient::class);
		$http->method('get')->willReturnCallback(
			function (string $uri, array $options) use (&$answers): IResponse {
				$this->requests[] = [$uri, $options];
				$answer = array_shift($answers);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$service = $this->createMock(originalClassName: IClientService::class);
		$service->method('newClient')->willReturn($http);

		return new PeerCatalogClient(
			clientService: $service,
			config: new FederationConfig(appConfig: $appConfig),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end client()

	/**
	 * A response with a status and a body.
	 *
	 * @param int   $status The HTTP status.
	 * @param mixed $body   The body (an array is JSON-encoded).
	 *
	 * @return IResponse
	 */
	private function response(int $status, mixed $body): IResponse {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$text = $body;
		if (is_array($body) === true) {
			$text = json_encode($body);
		}

		$response->method('getBody')->willReturn($text);
		return $response;
	}//end response()

	/**
	 * Entries with ids from $from up to $to.
	 *
	 * @param int $from The first id.
	 * @param int $to   The last id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function entries(int $from, int $to): array {
		return array_map(static fn (int $id): array => ['id' => 'p-'.$id, 'name' => 'Entry '.$id], range($from, $to));
	}//end entries()

	/**
	 * The listing URL is the peer's address plus the public listing path, once.
	 *
	 * @return void
	 */
	public function testTheListingUrlAddsThePathOnce(): void {
		$client = $this->client(answers: []);

		$listing = self::PEER.PeerCatalogClient::LISTING_PATH;

		$this->assertSame(expected: $listing, actual: $client->listingUrl(peerUrl: self::PEER));
		$this->assertSame(expected: $listing, actual: $client->listingUrl(peerUrl: ' '.self::PEER.'/ '));
		$this->assertSame(expected: $listing, actual: $client->listingUrl(peerUrl: $listing));
	}//end testTheListingUrlAddsThePathOnce()

	/**
	 * A reachable peer is read page by page with the configured timeout, and
	 * its catalogue name comes from the listing.
	 *
	 * @return void
	 */
	public function testAReachablePeerIsReadPageByPage(): void {
		$this->store['federation_peer_timeout'] = 7;
		$client = $this->client(
			answers: [
				$this->response(
					status: 200,
					body: ['organisation' => 'Softwarecatalogus Drechtsteden', 'pages' => 2, 'results' => $this->entries(from: 1, to: 100)]
				),
				$this->response(status: 200, body: ['pages' => 2, 'results' => $this->entries(from: 101, to: 104)]),
			]
		);

		$result = $client->fetch(peerUrl: self::PEER);

		$this->assertTrue(condition: $result['ok']);
		$this->assertSame(expected: 'Softwarecatalogus Drechtsteden', actual: $result['organisation']);
		$this->assertCount(expectedCount: 104, haystack: $result['entries']);
		$this->assertSame(expected: 'p-104', actual: $result['entries'][103]['id']);
		$this->assertCount(expectedCount: 2, haystack: $this->requests);
		$this->assertSame(expected: self::PEER.PeerCatalogClient::LISTING_PATH, actual: $this->requests[0][0]);
		$this->assertSame(expected: ['_page' => 1, '_limit' => 100], actual: $this->requests[0][1]['query']);
		$this->assertSame(expected: ['_page' => 2, '_limit' => 100], actual: $this->requests[1][1]['query']);
		$this->assertSame(expected: 7, actual: $this->requests[0][1]['timeout']);
		$this->assertFalse(condition: $this->requests[0][1]['http_errors']);
		$this->assertFalse(condition: $this->requests[0][1]['nextcloud']['allow_local_address']);
	}//end testAReachablePeerIsReadPageByPage()

	/**
	 * The cap stops the pull: no page past it is asked for, and no more
	 * entries than the cap are returned.
	 *
	 * @return void
	 */
	public function testTheCapStopsThePull(): void {
		$this->store['federation_max_entries'] = 150;
		$client = $this->client(
			answers: [
				$this->response(status: 200, body: ['pages' => 5, 'results' => $this->entries(from: 1, to: 100)]),
				$this->response(status: 200, body: ['pages' => 5, 'results' => $this->entries(from: 101, to: 150)]),
			]
		);

		$result = $client->fetch(peerUrl: self::PEER);

		$this->assertTrue(condition: $result['ok']);
		$this->assertCount(expectedCount: 150, haystack: $result['entries']);
		$this->assertCount(expectedCount: 2, haystack: $this->requests);
		$this->assertSame(expected: ['_page' => 2, '_limit' => 50], actual: $this->requests[1][1]['query']);
	}//end testTheCapStopsThePull()

	/**
	 * A bare list is a single page; an empty page ends the pull.
	 *
	 * @return void
	 */
	public function testABareListIsOnePage(): void {
		$client = $this->client(answers: [$this->response(status: 200, body: $this->entries(from: 1, to: 2))]);

		$result = $client->fetch(peerUrl: self::PEER);

		$this->assertSame(expected: ['ok' => true, 'reason' => 'ok', 'organisation' => null, 'entries' => $this->entries(from: 1, to: 2)], actual: $result);
		$this->assertCount(expectedCount: 1, haystack: $this->requests);
	}//end testABareListIsOnePage()

	/**
	 * A peer that does not answer, answers an error status or answers no
	 * listing fails the whole fetch, with nothing half read.
	 *
	 * @return void
	 */
	public function testAFailingPeerFailsTheWholeFetch(): void {
		$cases = [
			'not reachable' => [new RuntimeException('cURL error 28: timed out')],
			'peer answered HTTP 503' => [$this->response(status: 503, body: 'down')],
			'peer answered no JSON listing' => [$this->response(status: 200, body: '<html>')],
		];
		foreach ($cases as $reason => $answers) {
			$this->assertSame(
				expected: ['ok' => false, 'reason' => $reason, 'organisation' => null, 'entries' => []],
				actual: $this->client(answers: $answers)->fetch(peerUrl: self::PEER)
			);
		}

		$halfway = $this->client(
			answers: [
				$this->response(status: 200, body: ['pages' => 2, 'results' => $this->entries(from: 1, to: 100)]),
				$this->response(status: 500, body: ''),
			]
		)->fetch(peerUrl: self::PEER);
		$this->assertSame(expected: ['ok' => false, 'reason' => 'peer answered HTTP 500', 'organisation' => null, 'entries' => []], actual: $halfway);

		$notAList = $this->client(answers: [$this->response(status: 200, body: ['results' => ['id' => 'p-1']])])->fetch(peerUrl: self::PEER);
		$this->assertSame(expected: 'peer answered no JSON listing', actual: $notAList['reason']);
	}//end testAFailingPeerFailsTheWholeFetch()

	/**
	 * Only an allowlisted host may be reached at a private address.
	 *
	 * @return void
	 */
	public function testOnlyAnAllowlistedHostMayBeLocal(): void {
		$this->store['local_federation_hosts'] = 'peer.local, 10.0.0.5';
		$client = $this->client(
			answers: [
				$this->response(status: 200, body: []),
				$this->response(status: 200, body: []),
			]
		);

		$client->fetch(peerUrl: 'http://10.0.0.5:8080');
		$client->fetch(peerUrl: self::PEER);

		$this->assertTrue(condition: $this->requests[0][1]['nextcloud']['allow_local_address']);
		$this->assertFalse(condition: $this->requests[1][1]['nextcloud']['allow_local_address']);
	}//end testOnlyAnAllowlistedHostMayBeLocal()

	/**
	 * The catalogue name may come from the listing's catalog title.
	 *
	 * @return void
	 */
	public function testTheNameMayComeFromTheCatalogTitle(): void {
		$client = $this->client(answers: [$this->response(status: 200, body: ['catalog' => ['title' => ' WSHD '], 'results' => []])]);

		$this->assertSame(expected: 'WSHD', actual: $client->fetch(peerUrl: self::PEER)['organisation']);
	}//end testTheNameMayComeFromTheCatalogTitle()
}//end class
