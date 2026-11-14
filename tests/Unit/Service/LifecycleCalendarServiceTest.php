<?php

/**
 * LifecycleCalendarServiceTest
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\LifecycleCalendarService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One linked event per tracked lifecycle date: create, move, delete, keep, fail soft.
 *
 * @spec openspec/changes/adopt-integration-leaves/tasks.md#4.1
 */
#[CoversClass(LifecycleCalendarService::class)]
final class LifecycleCalendarServiceTest extends TestCase {

	/**
	 * An in-memory stand-in for OpenRegister's CalendarEventService.
	 *
	 * @var object
	 */
	private object $calendar;

	/**
	 * Build the calendar stand-in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calendar = new class {
			/** @var array<string, array<string, mixed>> Events by uri. */
			public array $events = [];

			/** @var list<string> Every write, in order. */
			public array $writes = [];

			/** @var bool Whether every call throws. */
			public bool $down = false;

			/** @var int */
			private int $next = 1;

			/**
			 * @param string $uuid The object.
			 * @return list<array<string, mixed>>
			 */
			public function getEventsForObject(string $uuid): array {
				if ($this->down === true) {
					throw new RuntimeException('no calendar');
				}

				return array_values(array_filter($this->events, fn (array $e): bool => $e['objectUuid'] === $uuid));
			}

			/**
			 * @param int    $register The register.
			 * @param int    $schema   The schema.
			 * @param string $uuid     The object.
			 * @param string $title    The object title.
			 * @param array  $data     The event data.
			 * @return array<string, mixed>
			 */
			public function createEvent(int $register, int $schema, string $uuid, string $title, array $data): array {
				$uri = 'E'.($this->next++).'.ics';
				$this->events[$uri] = [
					'id'          => $uri,
					'calendarId'  => '7',
					'objectUuid'  => $uuid,
					'summary'     => $data['summary'],
					'dtstart'     => str_replace('Z', '+00:00', $data['dtstart']),
					'dtend'       => $data['dtend'],
					'description' => $data['description'],
					'link'        => [$register, $schema, $title],
				];
				$this->writes[] = 'create '.$uri;
				return $this->events[$uri];
			}

			/**
			 * @param string $calendarId The calendar.
			 * @param string $uri        The event.
			 * @return void
			 */
			public function deleteEvent(string $calendarId, string $uri): void {
				unset($this->events[$uri]);
				$this->writes[] = 'delete '.$calendarId.'/'.$uri;
			}
		};
	}//end setUp()

	/**
	 * The service over the stand-in.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return LifecycleCalendarService
	 */
	private function service(?LoggerInterface $logger = null): LifecycleCalendarService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === 'OCA\\OpenRegister\\Service\\CalendarEventService' ? $this->calendar : throw new RuntimeException('unexpected '.$id)
		);

		return new LifecycleCalendarService(container: $container, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end service()

	/**
	 * A contract object.
	 *
	 * @param string|null $endDate The end date.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(?string $endDate): array {
		return ['uuid' => 'c-1', 'register' => '3', 'schema' => '11', 'data' => ['contractNumber' => 'K-2026-04', 'endDate' => $endDate]];
	}//end contract()

	/**
	 * Setting an end date creates one all-day linked event with the English title.
	 *
	 * @return void
	 */
	public function testAnEndDateCreatesOneLinkedEvent(): void {
		$this->service()->syncContract(object: $this->contract('2027-03-31'));

		$this->assertCount(1, $this->calendar->events);
		$event = array_values($this->calendar->events)[0];
		$this->assertSame('Contract ends: K-2026-04', $event['summary']);
		$this->assertSame('2027-03-31T00:00:00+00:00', $event['dtstart']);
		$this->assertSame('2027-04-01T00:00:00Z', $event['dtend']);
		$this->assertSame('stackiq-lifecycle:catalogContract.endDate', $event['description']);
		$this->assertSame([3, 11, 'Contract ends: K-2026-04'], $event['link']);
	}//end testAnEndDateCreatesOneLinkedEvent()

	/**
	 * Saving twice with the same date writes nothing the second time.
	 *
	 * @return void
	 */
	public function testASecondSaveWithTheSameDateIsIdempotent(): void {
		$service = $this->service();
		$service->syncContract(object: $this->contract('2027-03-31'));
		$service->syncContract(object: $this->contract('2027-03-31T00:00:00+00:00'));

		$this->assertSame(['create E1.ics'], $this->calendar->writes);
	}//end testASecondSaveWithTheSameDateIsIdempotent()

	/**
	 * A changed date moves the event: the old one goes, one new one comes.
	 *
	 * @return void
	 */
	public function testAChangedDateMovesTheEvent(): void {
		$service = $this->service();
		$service->syncContract(object: $this->contract('2027-03-31'));
		$service->syncContract(object: $this->contract('2027-06-30'));

		$this->assertSame(['create E1.ics', 'delete 7/E1.ics', 'create E2.ics'], $this->calendar->writes);
		$this->assertCount(1, $this->calendar->events);
		$this->assertStringStartsWith('2027-06-30', $this->calendar->events['E2.ics']['dtstart']);
	}//end testAChangedDateMovesTheEvent()

	/**
	 * Clearing the date deletes the event, and other events of the object stay.
	 *
	 * @return void
	 */
	public function testAClearedDateDeletesTheEventAndLeavesOthers(): void {
		$service = $this->service();
		$this->calendar->events['M.ics'] = ['id' => 'M.ics', 'calendarId' => '7', 'objectUuid' => 'c-1', 'summary' => 'Renewal meeting', 'dtstart' => '2027-01-10T09:00:00+00:00', 'description' => 'agenda'];
		$service->syncContract(object: $this->contract('2027-03-31'));
		$service->syncContract(object: $this->contract(null));

		$this->assertSame(['M.ics'], array_keys($this->calendar->events));
	}//end testAClearedDateDeletesTheEventAndLeavesOthers()

	/**
	 * An EOL re-stamp of dateEndSupport moves the module version's single event.
	 *
	 * @return void
	 */
	public function testAnEolRestampMovesTheModuleVersionEvent(): void {
		$service = $this->service();
		$version = ['uuid' => 'v-9', 'register' => '3', 'schema' => '12', 'data' => ['module' => ['name' => 'Zaaksysteem'], 'version' => '4.2', 'dateEndSupport' => '2026-12-31']];
		$service->syncModuleVersion(object: $version);
		$version['data']['dateEndSupport'] = '2027-06-30';
		$service->syncModuleVersion(object: $version);

		$this->assertCount(1, $this->calendar->events);
		$event = array_values($this->calendar->events)[0];
		$this->assertSame('End of support: Zaaksysteem 4.2', $event['summary']);
		$this->assertStringStartsWith('2027-06-30', $event['dtstart']);
		$this->assertSame('stackiq-lifecycle:moduleVersion.dateEndSupport', $event['description']);
	}//end testAnEolRestampMovesTheModuleVersionEvent()

	/**
	 * A module given by reference only titles the event by its version.
	 *
	 * @return void
	 */
	public function testAModuleReferenceTitlesByVersionOnly(): void {
		$this->service()->syncModuleVersion(object: ['uuid' => 'v-1', 'register' => '3', 'schema' => '12', 'data' => ['module' => 'm-uuid', 'version' => '1.0', 'dateEndSupport' => '2026-12-31']]);

		$this->assertSame('End of support: 1.0', array_values($this->calendar->events)[0]['summary']);
	}//end testAModuleReferenceTitlesByVersionOnly()

	/**
	 * An unavailable calendar is a warning, never an exception.
	 *
	 * @return void
	 */
	public function testAnUnavailableCalendarFailsSoft(): void {
		$this->calendar->down = true;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'LifecycleCalendarService: could not sync the calendar event of catalogContract.endDate',
			['object' => 'c-1', 'error' => 'no calendar']
		);

		$this->service(logger: $logger)->syncContract(object: $this->contract('2027-03-31'));
		$this->assertSame([], $this->calendar->writes);
	}//end testAnUnavailableCalendarFailsSoft()

	/**
	 * Without a uuid nothing is looked up; an unreadable date writes no event.
	 *
	 * @return void
	 */
	public function testNoUuidOrAnUnreadableDateWritesNothing(): void {
		$service = $this->service();
		$service->syncContract(object: ['data' => ['endDate' => '2027-03-31']]);
		$service->syncContract(object: $this->contract('not a date'));

		$this->assertSame([], $this->calendar->writes);
	}//end testNoUuidOrAnUnreadableDateWritesNothing()
}//end class
