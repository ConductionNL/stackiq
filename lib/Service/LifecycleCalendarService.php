<?php

/**
 * LifecycleCalendarService
 *
 * Keeps one linked calendar event per lifecycle date: a contract's end date
 * and a module version's end of support.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/adopt-integration-leaves/tasks.md#2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Upserts the calendar event of a tracked lifecycle date through OpenRegister's
 * calendar link path (adopt-integration-leaves §3).
 *
 * One tracked field is one event, found again by a marker in its description:
 * a new date creates it, a changed date moves it (delete and create, since
 * OpenRegister offers no update), a cleared date deletes it, and an unchanged
 * date leaves it alone. Every calendar failure is logged as a warning and
 * never reaches the object save.
 *
 * The object is passed as `['uuid' => string, 'register' => int|string,
 * 'schema' => int|string, 'data' => array]`.
 */
class LifecycleCalendarService {

	/**
	 * OpenRegister's calendar event service, by name: stackiq references no
	 * OpenRegister class outside its contracts.
	 *
	 * @var string
	 */
	public const CALENDAR_SERVICE = 'OCA\\OpenRegister\\Service\\CalendarEventService';

	/**
	 * The marker prefix that ties an event to its tracked field.
	 *
	 * @var string
	 */
	public const MARKER_PREFIX = 'stackiq-lifecycle:';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's calendar event service.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Keep the "Contract ends" event of a contract in step with its end date.
	 *
	 * @param array<string, mixed> $object The contract (uuid, register, schema, data).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-integration-leaves/tasks.md#2.1
	 */
	public function syncContract(array $object): void {
		$data   = $this->dataOf(object: $object);
		$number = trim((string) ($data['contractNumber'] ?? ''));
		$this->sync(
			object: $object,
			field: 'catalogContract.endDate',
			date: ($data['endDate'] ?? null),
			summary: trim('Contract ends: '.$number)
		);
	}//end syncContract()

	/**
	 * Keep the "End of support" event of a module version in step with its
	 * end-of-support date, including a date the EOL sync stamps again.
	 *
	 * @param array<string, mixed> $object The module version (uuid, register, schema, data).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-integration-leaves/tasks.md#2.1
	 */
	public function syncModuleVersion(array $object): void {
		$data    = $this->dataOf(object: $object);
		$module  = ($data['module'] ?? null);
		$name    = '';
		if (is_array($module) === true) {
			$name = trim((string) ($module['name'] ?? ''));
		}

		$version = trim((string) ($data['version'] ?? ''));
		$this->sync(
			object: $object,
			field: 'moduleVersion.dateEndSupport',
			date: ($data['dateEndSupport'] ?? null),
			summary: trim('End of support: '.trim($name.' '.$version))
		);
	}//end syncModuleVersion()

	/**
	 * Create, move, delete or keep the one event of a tracked field.
	 *
	 * @param array<string, mixed> $object  The object.
	 * @param string               $field   The tracked field, e.g. `catalogContract.endDate`.
	 * @param mixed                $date    The field's value.
	 * @param string               $summary The event title.
	 *
	 * @return void
	 */
	private function sync(array $object, string $field, mixed $date, string $summary): void {
		$uuid = (string) ($object['uuid'] ?? '');
		if ($uuid === '') {
			return;
		}

		try {
			$calendar = $this->container->get(self::CALENDAR_SERVICE);
			$marker   = self::MARKER_PREFIX.$field;
			$existing = $this->eventsWithMarker(events: $calendar->getEventsForObject($uuid), marker: $marker);
			$day      = $this->dayOf(value: $date);

			if ($day !== null && count($existing) === 1 && $this->isSameEvent(event: $existing[0], day: $day, summary: $summary) === true) {
				return;
			}

			foreach ($existing as $event) {
				$calendar->deleteEvent((string) $event['calendarId'], (string) $event['id']);
			}

			if ($day === null) {
				return;
			}

			$calendar->createEvent(
				(int) ($object['register'] ?? 0),
				(int) ($object['schema'] ?? 0),
				$uuid,
				$summary,
				[
					'summary'     => $summary,
					'dtstart'     => $day.'T00:00:00Z',
					'dtend'       => $this->nextDay(day: $day).'T00:00:00Z',
					'description' => $marker,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'LifecycleCalendarService: could not sync the calendar event of '.$field,
				['object' => $uuid, 'error' => $e->getMessage()]
			);
		}//end try
	}//end sync()

	/**
	 * The events whose description carries the marker of the tracked field.
	 *
	 * @param mixed  $events The events linked to the object.
	 * @param string $marker The marker.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function eventsWithMarker(mixed $events, string $marker): array {
		$found = [];
		if (is_array($events) === false) {
			return $found;
		}

		foreach ($events as $event) {
			if (is_array($event) === true && str_contains((string) ($event['description'] ?? ''), $marker) === true) {
				$found[] = $event;
			}
		}

		return $found;
	}//end eventsWithMarker()

	/**
	 * Whether the stored event already shows this day and title.
	 *
	 * @param array<string, mixed> $event   The stored event.
	 * @param string               $day     The tracked day, `Y-m-d`.
	 * @param string               $summary The wanted title.
	 *
	 * @return bool
	 */
	private function isSameEvent(array $event, string $day, string $summary): bool {
		return substr((string) ($event['dtstart'] ?? ''), 0, 10) === $day && (string) ($event['summary'] ?? '') === $summary;
	}//end isSameEvent()

	/**
	 * The day a date value names, as `Y-m-d`, or null when it is empty or unreadable.
	 *
	 * @param mixed $value The field's value.
	 *
	 * @return string|null
	 */
	private function dayOf(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable(trim($value), new DateTimeZone('UTC')))->format('Y-m-d');
		} catch (Throwable) {
			return null;
		}
	}//end dayOf()

	/**
	 * The day after a day, as `Y-m-d`.
	 *
	 * @param string $day The day.
	 *
	 * @return string
	 */
	private function nextDay(string $day): string {
		return (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
	}//end nextDay()

	/**
	 * The object's own data.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private function dataOf(array $object): array {
		$data = ($object['data'] ?? []);
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end dataOf()
}//end class
