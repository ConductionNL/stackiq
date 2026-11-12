<?php

/**
 * Unit tests for FederationConfig.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Federation
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Configuration accessor; behaviour pinned here, no scenario owns the defaults.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Federation;

use OCA\Stackiq\Service\Federation\FederationConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Defaults, sanitising and the per-peer failure counter of the federation settings.
 *
 * @covers \OCA\Stackiq\Service\Federation\FederationConfig
 *
 * @spec exclude Configuration accessor; behaviour pinned here, no scenario owns the defaults.
 */
class FederationConfigTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Build the app config double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
	}//end setUp()

	/**
	 * Answer string reads from a map, falling back to the caller's default.
	 *
	 * @param array<string,string> $values Key to stored value.
	 *
	 * @return void
	 */
	private function strings(array $values): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $values[$key] ?? $default
		);
	}//end strings()

	/**
	 * The enabled flag is read from federation_enabled with false as default.
	 *
	 * @return void
	 */
	public function testEnabledFlagIsReadWithFalseAsDefault(): void {
		$this->appConfig->expects($this->once())->method('getValueBool')
			->with('stackiq', 'federation_enabled', false)->willReturn(true);

		$this->assertTrue(condition: (new FederationConfig(appConfig: $this->appConfig))->isEnabled());
	}//end testEnabledFlagIsReadWithFalseAsDefault()

	/**
	 * A blank directory URL falls back to the public directory; a set one is trimmed.
	 *
	 * @return void
	 */
	public function testDirectoryUrlFallsBackWhenBlank(): void {
		$this->strings(values: ['federation_directory_url' => '   ']);
		$config = new FederationConfig(appConfig: $this->appConfig);
		$this->assertSame(expected: FederationConfig::DEFAULT_DIRECTORY_URL, actual: $config->getDirectoryUrl());
	}//end testDirectoryUrlFallsBackWhenBlank()

	/**
	 * A configured directory URL is trimmed.
	 *
	 * @return void
	 */
	public function testConfiguredDirectoryUrlIsTrimmed(): void {
		$this->strings(values: ['federation_directory_url' => ' https://dir.example.nl ']);
		$this->assertSame(expected: 'https://dir.example.nl', actual: (new FederationConfig(appConfig: $this->appConfig))->getDirectoryUrl());
	}//end testConfiguredDirectoryUrlIsTrimmed()

	/**
	 * Peers are read as a JSON list, empty entries dropped and re-indexed; garbage reads as none.
	 *
	 * @return void
	 */
	public function testPeersAreReadAsACleanList(): void {
		$this->strings(values: ['federation_peers' => '["https://a.nl","",null,"https://b.nl"]']);
		$this->assertSame(expected: ['https://a.nl', 'https://b.nl'], actual: (new FederationConfig(appConfig: $this->appConfig))->getPeers());
	}//end testPeersAreReadAsACleanList()

	/**
	 * A stored value that is not a JSON list reads as no peers.
	 *
	 * @return void
	 */
	public function testUnreadablePeersReadAsNone(): void {
		$this->strings(values: ['federation_peers' => 'not json']);
		$this->assertSame(expected: [], actual: (new FederationConfig(appConfig: $this->appConfig))->getPeers());
	}//end testUnreadablePeersReadAsNone()

	/**
	 * Peers are stored deduplicated, without empty entries.
	 *
	 * @return void
	 */
	public function testPeersAreStoredDeduplicated(): void {
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('stackiq', 'federation_peers', '["https:\/\/a.nl","https:\/\/b.nl"]');

		(new FederationConfig(appConfig: $this->appConfig))->setPeers(['https://a.nl', '', 'https://b.nl', 'https://a.nl']);
	}//end testPeersAreStoredDeduplicated()

	/**
	 * The integer settings use their default for a zero or negative stored value.
	 *
	 * @return void
	 */
	public function testIntegerSettingsRejectNonPositiveValues(): void {
		$this->appConfig->method('getValueInt')->willReturn(0);
		$config = new FederationConfig(appConfig: $this->appConfig);

		$this->assertSame(expected: 3600, actual: $config->getSyncInterval());
		$this->assertSame(expected: 3, actual: $config->getStaleAfterFailures());
		$this->assertSame(expected: 15, actual: $config->getPeerTimeout());
	}//end testIntegerSettingsRejectNonPositiveValues()

	/**
	 * Positive integer settings are returned as stored.
	 *
	 * @return void
	 */
	public function testPositiveIntegerSettingsAreReturned(): void {
		$this->appConfig->method('getValueInt')->willReturnMap(
			[
				['stackiq', 'federation_sync_interval', 3600, false, 600],
				['stackiq', 'federation_stale_after_failures', 3, false, 5],
				['stackiq', 'federation_peer_timeout', 15, false, 30],
			]
		);
		$config = new FederationConfig(appConfig: $this->appConfig);

		$this->assertSame(expected: 600, actual: $config->getSyncInterval());
		$this->assertSame(expected: 5, actual: $config->getStaleAfterFailures());
		$this->assertSame(expected: 30, actual: $config->getPeerTimeout());
	}//end testPositiveIntegerSettingsAreReturned()

	/**
	 * The failure count of a peer is read from the JSON map, zero when absent or unreadable.
	 *
	 * @return void
	 */
	public function testPeerFailuresAreReadPerPeer(): void {
		$this->strings(values: ['federation_peer_failures' => '{"https://a.nl":2}']);
		$config = new FederationConfig(appConfig: $this->appConfig);

		$this->assertSame(expected: 2, actual: $config->getPeerFailures('https://a.nl'));
		$this->assertSame(expected: 0, actual: $config->getPeerFailures('https://b.nl'));
	}//end testPeerFailuresAreReadPerPeer()

	/**
	 * An unreadable failure map reads as zero.
	 *
	 * @return void
	 */
	public function testUnreadablePeerFailuresReadAsZero(): void {
		$this->strings(values: ['federation_peer_failures' => '"oops"']);
		$this->assertSame(expected: 0, actual: (new FederationConfig(appConfig: $this->appConfig))->getPeerFailures('https://a.nl'));
	}//end testUnreadablePeerFailuresReadAsZero()

	/**
	 * Recording a positive count keeps the other peers.
	 *
	 * @return void
	 */
	public function testRecordingAFailureKeepsOtherPeers(): void {
		$this->strings(values: ['federation_peer_failures' => '{"https://a.nl":2}']);
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('stackiq', 'federation_peer_failures', '{"https:\/\/a.nl":2,"https:\/\/b.nl":1}');

		(new FederationConfig(appConfig: $this->appConfig))->setPeerFailures('https://b.nl', 1);
	}//end testRecordingAFailureKeepsOtherPeers()

	/**
	 * A zero count removes the peer's entry; an unreadable map starts empty.
	 *
	 * @return void
	 */
	public function testAZeroCountClearsThePeer(): void {
		$this->strings(values: ['federation_peer_failures' => 'garbage']);
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('stackiq', 'federation_peer_failures', '[]');

		(new FederationConfig(appConfig: $this->appConfig))->setPeerFailures('https://a.nl', 0);
	}//end testAZeroCountClearsThePeer()

	/**
	 * Local hosts are a comma-separated list, trimmed, without empty entries.
	 *
	 * @return void
	 */
	public function testLocalHostsAreSplitAndTrimmed(): void {
		$this->strings(values: ['local_federation_hosts' => ' a.local , ,b.local ']);
		$this->assertSame(expected: ['a.local', 'b.local'], actual: (new FederationConfig(appConfig: $this->appConfig))->getLocalFederationHosts());
	}//end testLocalHostsAreSplitAndTrimmed()

	/**
	 * A blank host setting means no local hosts.
	 *
	 * @return void
	 */
	public function testBlankLocalHostsMeanNone(): void {
		$this->strings(values: ['local_federation_hosts' => '  ']);
		$this->assertSame(expected: [], actual: (new FederationConfig(appConfig: $this->appConfig))->getLocalFederationHosts());
	}//end testBlankLocalHostsMeanNone()
}//end class
