<?php

/**
 * Characterisation tests for ContactpersoonServiceExample.
 *
 * Pins the processed contact-person rows, the log calls and the API call
 * example structure before the methods are split into named steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Examples
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Examples;

use OCA\Stackiq\Examples\ContactpersoonServiceExample;
use OCA\Stackiq\Service\ContactpersoonService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ContactpersoonServiceExample.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Examples
 */
class ContactpersoonServiceExampleTest extends TestCase {

	/**
	 * Build a contact-person stub exposing getId/getUuid/getObject.
	 *
	 * @param int $id Contact person id
	 * @param array $data Object data
	 *
	 * @return object
	 */
	private function contactPerson(int $id, array $data): object {
		return new class($id, $data) {
			/**
			 * @param int $id Id
			 * @param array $data Data
			 */
			public function __construct(private int $id, private array $data) {
			}

			/**
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return 'uuid-' . $this->id;
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}
		};

	}//end contactPerson()

	/**
	 * Processed rows, defaults and both info log calls are pinned.
	 *
	 * @return void
	 */
	public function testProcessesContactPersonsAndLogs(): void {
		$service = $this->createMock(ContactpersoonService::class);
		$service->expects($this->once())
			->method('getContactPersonsWithUserDetailsForOrganization')
			->with('org-1')
			->willReturn(
				[
					$this->contactPerson(1, ['name' => 'Ann', 'email' => 'a@x', 'username' => 'ann', 'userDetails' => ['uid' => 'ann']]),
					$this->contactPerson(2, ['userDetails' => null]),
				]
			);
		$logger = $this->createMock(LoggerInterface::class);
		$calls  = [];
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$calls): void {
				$calls[] = [$message, $context];
			}
		);
		$logger->expects($this->never())->method('error');

		$result = (new ContactpersoonServiceExample($service, $logger))->getContactPersonsWithUserDetailsExample('org-1');

		$this->assertSame(
			[
				[
					'contactPersonId' => 1,
					'contactPersonUuid' => 'uuid-1',
					'name' => 'Ann',
					'email' => 'a@x',
					'username' => 'ann',
					'hasUserDetails' => true,
					'userDetails' => ['uid' => 'ann'],
				],
				[
					'contactPersonId' => 2,
					'contactPersonUuid' => 'uuid-2',
					'name' => 'Unknown',
					'email' => null,
					'username' => null,
					'hasUserDetails' => false,
					'userDetails' => null,
				],
			],
			$result
		);
		$this->assertSame(
			[
				['ContactpersoonServiceExample: Getting contact persons with user details', ['organizationUuid' => 'org-1']],
				['ContactpersoonServiceExample: Retrieved contact persons', ['organizationUuid' => 'org-1', 'contactPersonCount' => 2]],
			],
			$calls
		);

	}//end testProcessesContactPersonsAndLogs()

	/**
	 * A failing service call is logged and rethrown.
	 *
	 * @return void
	 */
	public function testLogsAndRethrowsOnFailure(): void {
		$service   = $this->createMock(ContactpersoonService::class);
		$exception = new \RuntimeException('boom');
		$service->method('getContactPersonsWithUserDetailsForOrganization')->willThrowException($exception);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'ContactpersoonServiceExample: Failed to get contact persons with user details',
			['organizationUuid' => 'org-9', 'error' => 'boom']
		);

		try {
			(new ContactpersoonServiceExample($service, $logger))->getContactPersonsWithUserDetailsExample('org-9');
			$this->fail('Expected the exception to be rethrown');
		} catch (\RuntimeException $caught) {
			$this->assertSame($exception, $caught);
		}

	}//end testLogsAndRethrowsOnFailure()

	/**
	 * The API call example structure is pinned in full.
	 *
	 * @return void
	 */
	public function testApiCallExampleStructure(): void {
		$example = new ContactpersoonServiceExample(
			$this->createMock(ContactpersoonService::class),
			$this->createMock(LoggerInterface::class)
		);

		$result = $example->getApiCallExample('org-7');

		$this->assertSame('/api/contactpersonen/organisation/org-7/with-user-details', $result['url']);
		$this->assertSame('org-7', $result['response']['organizationUuid']);
		$this->assertSame('8f3193da525ec668845fd10343877a2cf3f51574', sha1(json_encode($result)));

	}//end testApiCallExampleStructure()
}//end class
