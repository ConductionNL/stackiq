<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ContactpersoonService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for the W30 ContactpersoonService decomposition helper
 * (isContactpersoonEmailUsable).
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */
class ContactpersoonServiceDecompositionTest extends TestCase {
	private function makeService(LoggerInterface $logger): ContactpersoonService {
		$reflection = new ReflectionClass(ContactpersoonService::class);
		/** @var ContactpersoonService $svc */
		$svc = $reflection->newInstanceWithoutConstructor();
		$loggerProp = $reflection->getProperty('logger');
		$loggerProp->setAccessible(true);
		$loggerProp->setValue($svc, $logger);
		return $svc;
	}

	private function call(ContactpersoonService $svc, string $email, string $contactId): bool {
		$ref = new ReflectionMethod($svc, 'isContactPersonEmailUsable');
		$ref->setAccessible(true);
		return $ref->invokeArgs($svc, [$email, $contactId]);
	}

	public function testReturnsTrueForValidEmail(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$svc = $this->makeService($logger);
		$this->assertTrue($this->call($svc, 'alice@example.org', 'contact-1'));
	}

	public function testReturnsFalseAndWarnsForEmptyEmail(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('has no email')
		);
		$svc = $this->makeService($logger);
		$this->assertFalse($this->call($svc, '', 'contact-2'));
	}

	public function testReturnsFalseAndWarnsForMalformedEmail(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('invalid email')
		);
		$svc = $this->makeService($logger);
		$this->assertFalse($this->call($svc, 'not-an-email', 'contact-3'));
	}

	public function testReturnsFalseForEmailWithoutAt(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$svc = $this->makeService($logger);
		$this->assertFalse($this->call($svc, 'aliceexample.org', 'contact-4'));
	}
}
