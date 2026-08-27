<?php

/**
 * Unit tests for ContractStatusService::shouldExpire().
 *
 * The pure transition decision is the security/correctness core of the
 * scheduled status maintenance: it must expire ONLY past-end-date `Actief`
 * contracts and never touch `In onderhandeling`, missing/blank/unparseable
 * end dates, future end dates, or any other status — and never reverse.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/contract-administration/specs/contract-administration/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ContractStatusService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for the contract status-transition decision.
 */
class ContractStatusServiceTest extends TestCase {
	/**
	 * @var ContractStatusService
	 */
	private ContractStatusService $service;

	/**
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface|MockObject $container;

	/**
	 * @var SettingsService|MockObject
	 */
	private SettingsService|MockObject $settingsService;

	/**
	 * Set up the service with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new ContractStatusService($this->container, $this->settingsService, $logger);
	}//end setUp()

	/**
	 * Logical "now" fixed for deterministic tests.
	 *
	 * @return \DateTimeImmutable A fixed date.
	 */
	private function now(): \DateTimeImmutable {
		return new \DateTimeImmutable('2026-06-14T12:00:00+00:00');
	}//end now()

	/**
	 * A past-end-date active contract must expire.
	 *
	 * @return void
	 */
	public function testActiveWithPastEndDateExpires(): void {
		$this->assertTrue(
			$this->service->shouldExpire(['status' => 'Active', 'endDate' => '2026-06-01'], $this->now())
		);
	}//end testActiveWithPastEndDateExpires()

	/**
	 * A future-end-date active contract is untouched.
	 *
	 * @return void
	 */
	public function testActiveWithFutureEndDateDoesNotExpire(): void {
		$this->assertFalse(
			$this->service->shouldExpire(['status' => 'Active', 'endDate' => '2027-01-01'], $this->now())
		);
	}//end testActiveWithFutureEndDateDoesNotExpire()

	/**
	 * An active contract without an end date is untouched.
	 *
	 * @return void
	 */
	public function testActiveWithoutEndDateDoesNotExpire(): void {
		$this->assertFalse($this->service->shouldExpire(['status' => 'Active'], $this->now()));
		$this->assertFalse($this->service->shouldExpire(['status' => 'Active', 'endDate' => ''], $this->now()));
		$this->assertFalse($this->service->shouldExpire(['status' => 'Active', 'endDate' => '   '], $this->now()));
	}//end testActiveWithoutEndDateDoesNotExpire()

	/**
	 * An active contract with an unparseable end date fails closed (no transition).
	 *
	 * @return void
	 */
	public function testUnparseableEndDateDoesNotExpire(): void {
		$this->assertFalse(
			$this->service->shouldExpire(['status' => 'Active', 'endDate' => 'not-a-date'], $this->now())
		);
	}//end testUnparseableEndDateDoesNotExpire()

	/**
	 * `In onderhandeling` is never touched, even with a past end date.
	 *
	 * @return void
	 */
	public function testNegotiationStatusNeverExpires(): void {
		$this->assertFalse(
			$this->service->shouldExpire(['status' => 'In negotiation', 'endDate' => '2020-01-01'], $this->now())
		);
	}//end testNegotiationStatusNeverExpires()

	/**
	 * An already-expired contract is not re-processed (no reverse / re-trigger).
	 *
	 * @return void
	 */
	public function testAlreadyExpiredIsNotReprocessed(): void {
		$this->assertFalse(
			$this->service->shouldExpire(['status' => 'Expired', 'endDate' => '2020-01-01'], $this->now())
		);
	}//end testAlreadyExpiredIsNotReprocessed()

	/**
	 * expirePastContracts degrades to 0 (no error) when OpenRegister is absent.
	 *
	 * @return void
	 */
	public function testExpirePastContractsDegradesWithoutOpenRegister(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));
		$this->assertSame(0, $this->service->expirePastContracts($this->now()));
	}//end testExpirePastContractsDegradesWithoutOpenRegister()
}//end class
