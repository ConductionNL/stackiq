<?php

/**
 * ConnectionReportService unit tests.
 *
 * The service tells integriq's connection registry what stackiq can see about
 * email, catalog federation and the end-of-life feed. Every test guards one way
 * it could quietly stop telling the truth: a report sent before the refresh
 * that retires it, a pull with a failing peer read as configured, a peer URL
 * with its path or credentials on a row, a listener's failure turned into a
 * failed save, or an event sent when integriq is not installed.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Stackiq\Service\ConnectionReportService;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ConnectionReportService.
 *
 * @covers \OCA\Stackiq\Service\ConnectionReportService
 */
class ConnectionReportServiceTest extends TestCase {

	/**
	 * The transport labels SymfonyEmailService answers.
	 *
	 * @var array<string, string>
	 */
	private const TRANSPORTS = [
		'smtp' => 'SMTP Server',
		'null' => 'Null (No Emails)',
		'sendgrid' => 'SendGrid',
	];

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Mocked email service.
	 *
	 * @var SymfonyEmailService&MockObject
	 */
	private SymfonyEmailService&MockObject $emailService;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every event handed to the dispatcher, in order.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sent = [];

		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);

		$this->emailService = $this->createMock(originalClassName: SymfonyEmailService::class);
		$this->emailService->method('getAvailableTransports')->willReturn(self::TRANSPORTS);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The service as production builds it.
	 *
	 * @return ConnectionReportService
	 */
	private function service(): ConnectionReportService {
		return new ConnectionReportService(
			eventDispatcher: $this->dispatcher,
			emailService: $this->emailService,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * The service as it behaves on an instance without integriq.
	 *
	 * Only the class lookup is replaced. The stubs make both event classes
	 * resolvable in this process, so absence is simulated at the one seam
	 * that asks.
	 *
	 * @return ConnectionReportService
	 */
	private function serviceWithoutIntegriq(): ConnectionReportService {
		return new class($this->dispatcher, $this->emailService, $this->logger) extends ConnectionReportService {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};
	}//end serviceWithoutIntegriq()

	/**
	 * The event classes and keys of everything sent, as "class:key:status".
	 *
	 * @return array<int, string>
	 */
	private function sentSummary(): array {
		return array_map(
			static function (Event $event): string {
				if ($event instanceof ConnectionStatusReportedEvent) {
					return 'report:' . $event->key . ':' . $event->status;
				}

				if ($event instanceof ConnectionRefreshRequestedEvent) {
					return 'refresh:' . $event->key;
				}

				return get_class($event);
			},
			$this->sent
		);
	}//end sentSummary()

	/**
	 * An email save refreshes first and reports second, as stackiq, for the email key.
	 *
	 * Under hydra#674 a refresh retires every older observation, so the other
	 * order would have integriq throw the report away.
	 *
	 * @return void
	 */
	public function testAnEmailSaveRefreshesBeforeItReports(): void {
		$this->emailService->method('isEmailSystemConfigured')->willReturn(
			['configured' => false, 'reason' => 'Email notifications are disabled', 'hasCredentials' => false, 'hasTemplates' => false]
		);

		$this->assertTrue(condition: $this->service()->emailSettingsSaved());

		$this->assertSame(expected: ['refresh:email', 'report:email:unconfigured'], actual: $this->sentSummary());
		$this->assertSame(expected: 'stackiq', actual: $this->sent[0]->app);
		$this->assertSame(expected: 'stackiq', actual: $this->sent[1]->app);
		$this->assertSame(expected: 'Email is switched off, so stackiq sends no mail.', actual: $this->sent[1]->message);
	}//end testAnEmailSaveRefreshesBeforeItReports()

	/**
	 * Each email configuration state maps to the status the design names.
	 *
	 * @return void
	 */
	public function testEachEmailStateMapsToTheDesignedStatus(): void {
		$service = $this->service();

		$this->assertSame(
			expected: ['unconfigured', 'Email is on, and the SendGrid transport misses a setting it needs.'],
			actual: $service->describeEmail(
				configStatus: ['configured' => false, 'hasCredentials' => false, 'hasTemplates' => true, 'transportType' => 'sendgrid'],
				transportLabels: self::TRANSPORTS
			)
		);
		$this->assertSame(
			expected: ['unconfigured', 'Email is on, and a required mail template is empty.'],
			actual: $service->describeEmail(
				configStatus: ['configured' => false, 'hasCredentials' => true, 'hasTemplates' => false, 'transportType' => 'smtp'],
				transportLabels: self::TRANSPORTS
			)
		);
		$this->assertSame(
			expected: ['configured', 'Email is on and the SMTP Server transport settings are filled. No test mail was sent.'],
			actual: $service->describeEmail(
				configStatus: ['configured' => true, 'hasCredentials' => true, 'hasTemplates' => true, 'transportType' => 'smtp'],
				transportLabels: self::TRANSPORTS
			)
		);
	}//end testEachEmailStateMapsToTheDesignedStatus()

	/**
	 * An email service that throws still refreshes, reports nothing, and never escapes.
	 *
	 * @return void
	 */
	public function testAFailingEmailReadNeverEscapes(): void {
		$this->emailService->method('isEmailSystemConfigured')->willThrowException(new RuntimeException('config broken'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'could not read the email settings'), $this->arrayHasKey(key: 'exception'));

		$this->assertFalse(condition: $this->service()->emailSettingsSaved());
		$this->assertSame(expected: ['refresh:email'], actual: $this->sentSummary());
	}//end testAFailingEmailReadNeverEscapes()

	/**
	 * A peer change refreshes, and reports only a state that blocks federation.
	 *
	 * @return void
	 */
	public function testAPeerChangeReportsOnlyABlockingState(): void {
		$service = $this->service();

		$service->federationPeersChanged(status: ['available' => false, 'enabled' => true, 'peers' => [['url' => 'https://a.example']]]);
		$service->federationPeersChanged(status: ['available' => true, 'enabled' => false, 'peers' => [['url' => 'https://a.example']]]);
		$service->federationPeersChanged(status: ['available' => true, 'enabled' => true, 'peers' => []]);
		$this->assertFalse(
			condition: $service->federationPeersChanged(status: ['available' => true, 'enabled' => true, 'peers' => [['url' => 'https://a.example']]])
		);

		$this->assertSame(
			expected: [
				'refresh:federation',
				'report:federation:unavailable',
				'refresh:federation',
				'report:federation:unconfigured',
				'refresh:federation',
				'report:federation:unconfigured',
				'refresh:federation',
			],
			actual: $this->sentSummary()
		);
		$this->assertStringContainsString(needle: 'OpenCatalogi', haystack: $this->sent[1]->message);
		$this->assertStringContainsString(needle: 'federation_enabled', haystack: $this->sent[3]->message);
		$this->assertStringContainsString(needle: 'no peer catalog', haystack: $this->sent[5]->message);
	}//end testAPeerChangeReportsOnlyABlockingState()

	/**
	 * A pull where every peer answered reads configured, and a pull that was blocked says why.
	 *
	 * @return void
	 */
	public function testAPullMapsItsOutcome(): void {
		$service = $this->service();
		$ok      = ['ok' => true, 'reason' => 'pulled'];

		$this->assertSame(
			expected: ['configured', 'All 2 peer catalogs answered the last pull.'],
			actual: $service->describePull(pull: ['ok' => true, 'peers' => [['peer' => 'https://a.example'] + $ok, ['peer' => 'https://b.example'] + $ok]])
		);
		$this->assertSame(
			expected: ['configured', 'The peer catalog answered the last pull.'],
			actual: $service->describePull(pull: ['ok' => true, 'peers' => [['peer' => 'https://a.example'] + $ok]])
		);
		$this->assertSame(expected: 'unconfigured', actual: $service->describePull(pull: ['ok' => true, 'peers' => []])[0]);
		$this->assertSame(expected: 'unconfigured', actual: $service->describePull(pull: ['ok' => false, 'reason' => 'federation disabled'])[0]);
		$this->assertSame(expected: 'unavailable', actual: $service->describePull(pull: ['ok' => false, 'reason' => 'OpenCatalogi unavailable'])[0]);
		$this->assertSame(
			expected: ['error', 'The last federation pull failed: something else'],
			actual: $service->describePull(pull: ['ok' => false, 'reason' => 'something else'])
		);
	}//end testAPullMapsItsOutcome()

	/**
	 * A pull where some peers fail reads limited, and where all fail reads error.
	 *
	 * Neither may borrow configured: a failing peer is exactly what the row is for.
	 *
	 * @return void
	 */
	public function testAPullWithFailingPeersIsNeverConfigured(): void {
		$service = $this->service();
		$good    = ['peer' => 'https://good.example/catalog', 'ok' => true, 'reason' => 'pulled'];
		$bad     = ['peer' => 'https://bad.example/catalog', 'ok' => false, 'reason' => 'Connection refused'];

		$this->assertSame(
			expected: ['limited', '1 of 2 peer catalogs answered the last pull. bad.example did not: Connection refused'],
			actual: $service->describePull(pull: ['ok' => true, 'peers' => [$good, $bad]])
		);
		$this->assertSame(
			expected: ['error', 'No peer catalog answered the last pull. bad.example did not: Connection refused'],
			actual: $service->describePull(pull: ['ok' => true, 'peers' => [$bad]])
		);
	}//end testAPullWithFailingPeersIsNeverConfigured()

	/**
	 * A message names a peer by host, never by path, query or credentials, and cuts a long reason.
	 *
	 * Every admin reads the row, and a peer URL can carry a token.
	 *
	 * @return void
	 */
	public function testAPullMessageCarriesOnlyTheHostAndAShortReason(): void {
		$peer = [
			'peer' => 'https://user:s3cret@peer.gemeente.example/api/catalog?token=abc',
			'ok' => false,
			'reason' => str_repeat('x', 400),
		];

		$message = $this->service()->describePull(pull: ['ok' => true, 'peers' => [$peer]])[1];

		$this->assertStringContainsString(needle: 'peer.gemeente.example did not', haystack: $message);
		foreach (['s3cret', 'user', 'token', 'abc', '/api'] as $leak) {
			$this->assertStringNotContainsString(needle: $leak, haystack: $message);
		}

		$this->assertStringContainsString(needle: str_repeat('x', ConnectionReportService::REASON_LIMIT) . '...', haystack: $message);
		$this->assertStringNotContainsString(needle: str_repeat('x', ConnectionReportService::REASON_LIMIT + 1), haystack: $message);
	}//end testAPullMessageCarriesOnlyTheHostAndAShortReason()

	/**
	 * A pull reports without a refresh: it changes no settings.
	 *
	 * @return void
	 */
	public function testAPullReportsWithoutARefresh(): void {
		$this->assertTrue(condition: $this->service()->federationPulled(pull: ['ok' => false, 'reason' => 'federation disabled']));
		$this->assertSame(expected: ['report:federation:unconfigured'], actual: $this->sentSummary());
	}//end testAPullReportsWithoutARefresh()

	/**
	 * An EOL settings save refreshes, and reports only a switched-off sync.
	 *
	 * @return void
	 */
	public function testAnEolSaveReportsOnlyASwitchedOffSync(): void {
		$service = $this->service();

		$this->assertTrue(condition: $service->eolSyncConfigSaved(config: ['enabled' => false]));
		$this->assertFalse(condition: $service->eolSyncConfigSaved(config: ['enabled' => true]));

		$this->assertSame(expected: ['refresh:eol-feed', 'report:eol-feed:unconfigured', 'refresh:eol-feed'], actual: $this->sentSummary());
	}//end testAnEolSaveReportsOnlyASwitchedOffSync()

	/**
	 * Every reason EolSyncService records maps to a status, and an unknown one reads error.
	 *
	 * @return void
	 */
	public function testEachEolRunOutcomeMapsToTheDesignedStatus(): void {
		$service  = $this->service();
		$expected = [
			'disabled' => 'unconfigured',
			'openregister-not-installed' => 'unavailable',
			'object-service-unavailable' => 'error',
			'module-schema-not-configured' => 'unconfigured',
			'eol-register-or-schema-not-found' => 'unconfigured',
		];

		foreach ($expected as $reason => $status) {
			$this->assertSame(
				expected: $status,
				actual: $service->describeEolRun(runStatus: ['available' => false, 'reason' => $reason])[0],
				message: $reason
			);
		}

		$this->assertStringContainsString(
			needle: 'endoflife.date source in integriq',
			haystack: $service->describeEolRun(runStatus: ['available' => false, 'reason' => 'eol-register-or-schema-not-found'])[1]
		);
		$this->assertSame(
			expected: ['configured', 'The last sync stamped 4 module versions and skipped 2.'],
			actual: $service->describeEolRun(runStatus: ['available' => true, 'reason' => null, 'matched' => 4, 'skipped' => 2])
		);
		$this->assertSame(
			expected: ['error', 'The last end-of-life sync stopped: something-new'],
			actual: $service->describeEolRun(runStatus: ['available' => false, 'reason' => 'something-new'])
		);
	}//end testEachEolRunOutcomeMapsToTheDesignedStatus()

	/**
	 * The EOL reasons the report knows are exactly the ones EolSyncService records.
	 *
	 * A reason added to the service and not here would read as a bare error.
	 *
	 * @return void
	 */
	public function testTheEolReasonsAreTheOnesTheSyncRecords(): void {
		$source = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/EolSyncService.php');
		preg_match_all('/degrade\(reason: \'([a-z-]+)\'\)/', $source, $matches);

		$recorded = array_values(array_unique($matches[1]));
		sort($recorded);
		$known = array_keys(ConnectionReportService::EOL_REASONS);
		sort($known);

		$this->assertNotSame(expected: [], actual: $recorded);
		$this->assertSame(expected: $recorded, actual: $known);
	}//end testTheEolReasonsAreTheOnesTheSyncRecords()

	/**
	 * Without integriq nothing is sent and nothing is logged.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->never())->method('warning');
		$this->emailService->expects($this->never())->method('isEmailSystemConfigured');

		$service = $this->serviceWithoutIntegriq();

		$this->assertFalse(condition: $service->emailSettingsSaved());
		$this->assertFalse(condition: $service->federationPeersChanged(status: ['available' => false]));
		$this->assertFalse(condition: $service->federationPulled(pull: ['ok' => false, 'reason' => 'federation disabled']));
		$this->assertFalse(condition: $service->eolSyncConfigSaved(config: ['enabled' => false]));
		$this->assertFalse(condition: $service->eolSyncRan(runStatus: ['available' => false, 'reason' => 'disabled']));
	}//end testWithoutIntegriqNothingIsSentOrLogged()

	/**
	 * The class lookup answers null for a class nobody ships, and the class for a stub.
	 *
	 * This is the real guard, not the test double above.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method  = new ReflectionMethod(ConnectionReportService::class, 'resolveEventClass');
		$service = $this->service();

		$this->assertNull(actual: $method->invoke($service, 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			expected: '\\' . ConnectionReportService::STATUS_EVENT,
			actual: $method->invoke($service, ConnectionReportService::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * The event names are the ones integriq ships.
	 *
	 * A string class name is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stubs' real names.
	 *
	 * @return void
	 */
	public function testTheEventNamesAreTheContractNames(): void {
		$this->assertSame(expected: ConnectionStatusReportedEvent::class, actual: ConnectionReportService::STATUS_EVENT);
		$this->assertSame(expected: ConnectionRefreshRequestedEvent::class, actual: ConnectionReportService::REFRESH_EVENT);
	}//end testTheEventNamesAreTheContractNames()

	/**
	 * A listener that throws never escapes into the save, pull or run.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->exactly(count: 2))->method('warning')
			->with($this->stringContains(string: 'could not send'), $this->arrayHasKey(key: 'key'));

		$service = new ConnectionReportService(eventDispatcher: $dispatcher, emailService: $this->emailService, logger: $this->logger);

		$this->assertFalse(condition: $service->eolSyncRan(runStatus: ['available' => false, 'reason' => 'disabled']));
		$this->assertFalse(condition: $service->refresh(key: ConnectionReportService::KEY_FEDERATION));
	}//end testAThrowingListenerNeverEscapes()
}//end class
