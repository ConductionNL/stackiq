<?php

/**
 * Stackiq connection report service.
 *
 * Tells integriq's connection registry what only stackiq can see about its
 * three outside connections: email, catalog federation and the end-of-life
 * feed. Integriq owns the rows the Integrations page lists and works out each
 * status itself (hydra change connection-registry, design D4). Stackiq asks
 * for a fresh resolve after a save, and reports what a save, a pull or a sync
 * run met.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
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

namespace OCA\Stackiq\Service;

use OCA\Stackiq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection refresh requests and reports to integriq.
 *
 * A save refreshes before it reports. Under hydra#674 a refresh retires the
 * observations older than itself, so a report sent before the refresh would
 * be retired by it. A pull or a sync run reports without a refresh: it
 * changes no settings.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
 */
class ConnectionReportService {

	/**
	 * Integriq's report event (ADR-041). Named by string so stackiq stays
	 * installable without integriq: the class is only there when integriq is.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * Integriq's refresh event. Same reason for the string as above.
	 *
	 * @var string
	 */
	public const REFRESH_EVENT = 'OCA\Integriq\Event\ConnectionRefreshRequestedEvent';

	/**
	 * The email connection key in lib/Settings/connections.json.
	 *
	 * @var string
	 */
	public const KEY_EMAIL = 'email';

	/**
	 * The catalog federation connection key in lib/Settings/connections.json.
	 *
	 * @var string
	 */
	public const KEY_FEDERATION = 'federation';

	/**
	 * The end-of-life feed connection key in lib/Settings/connections.json.
	 *
	 * @var string
	 */
	public const KEY_EOL = 'eol-feed';

	/**
	 * The longest failure reason a message carries.
	 *
	 * @var int
	 */
	public const REASON_LIMIT = 160;

	/**
	 * What each EOL sync degrade reason means for the row, as status and message.
	 *
	 * The reasons are the ones EolSyncService::degrade() records.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const EOL_REASONS = [
		'disabled' => [
			'unconfigured',
			'End-of-life sync is switched off. Switch it on in the End-of-life feed sync section.',
		],
		'openregister-not-installed' => [
			'unavailable',
			'The end-of-life sync needs OpenRegister, and it is not installed.',
		],
		'object-service-unavailable' => [
			'error',
			'OpenRegister did not answer the last end-of-life sync.',
		],
		'module-schema-not-configured' => [
			'unconfigured',
			'Stackiq has no module or module version schema configured, so the sync has nothing to stamp.',
		],
		'eol-register-or-schema-not-found' => [
			'unconfigured',
			'The end-of-life register or schemas are missing. Install the endoflife.date source in integriq, '
			. 'or fix the names in the End-of-life feed sync section.',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher    $eventDispatcher Sends the integriq events (ADR-041).
	 * @param SymfonyEmailService $emailService    Tells whether the saved email settings are complete.
	 * @param LoggerInterface     $logger          Records what could not be sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly SymfonyEmailService $emailService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * After an email settings save: refresh, then report what the saved settings say.
	 *
	 * The `null` transport is left to integriq's rule 3, which outranks any
	 * report. Never throws, and does nothing without integriq.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function emailSettingsSaved(): bool {
		if ($this->refresh(key: self::KEY_EMAIL) === false) {
			return false;
		}

		try {
			[$status, $message] = $this->describeEmail(
				configStatus: $this->emailService->isEmailSystemConfigured(),
				transportLabels: $this->emailService->getAvailableTransports()
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Stackiq: could not read the email settings for a connection report',
				['key' => self::KEY_EMAIL, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return $this->report(key: self::KEY_EMAIL, status: $status, message: $message);
	}//end emailSettingsSaved()

	/**
	 * What the email configuration status says about the connection.
	 *
	 * @param array<string, mixed>  $configStatus    The result of SymfonyEmailService::isEmailSystemConfigured().
	 * @param array<string, string> $transportLabels Transport type to its label.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function describeEmail(array $configStatus, array $transportLabels): array {
		if (array_key_exists('transportType', $configStatus) === false) {
			// SymfonyEmailService answers without a transport only when email is off.
			return ['unconfigured', 'Email is switched off, so stackiq sends no mail.'];
		}

		$transport = (string) $configStatus['transportType'];
		$label     = ($transportLabels[$transport] ?? $transport);

		if (($configStatus['hasCredentials'] ?? false) !== true) {
			return ['unconfigured', 'Email is on, and the ' . $label . ' transport misses a setting it needs.'];
		}

		if (($configStatus['hasTemplates'] ?? false) !== true) {
			return ['unconfigured', 'Email is on, and a required mail template is empty.'];
		}

		return ['configured', 'Email is on and the ' . $label . ' transport settings are filled. No test mail was sent.'];
	}//end describeEmail()

	/**
	 * After a peer was added or removed: refresh, then report a state that blocks federation.
	 *
	 * A ready federation gets no report: only a pull can tell whether the peers
	 * answer, so the row reads the declared "Not checked yet" until then.
	 *
	 * @param array<string, mixed> $status The result of FederationService::getStatus().
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function federationPeersChanged(array $status): bool {
		if ($this->refresh(key: self::KEY_FEDERATION) === false) {
			return false;
		}

		$blocked = $this->federationBlocker(
			available: ($status['available'] ?? false) === true,
			enabled: ($status['enabled'] ?? false) === true,
			peerCount: count((array) ($status['peers'] ?? []))
		);
		if ($blocked === null) {
			return false;
		}

		return $this->report(key: self::KEY_FEDERATION, status: $blocked[0], message: $blocked[1]);
	}//end federationPeersChanged()

	/**
	 * After a federation pull: report what the peers answered.
	 *
	 * @param array<string, mixed> $pull The result of FederationService::pullAllPeers().
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function federationPulled(array $pull): bool {
		[$status, $message] = $this->describePull(pull: $pull);

		return $this->report(key: self::KEY_FEDERATION, status: $status, message: $message);
	}//end federationPulled()

	/**
	 * What a federation pull says about the connection.
	 *
	 * @param array<string, mixed> $pull The result of FederationService::pullAllPeers().
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function describePull(array $pull): array {
		$reason = (string) ($pull['reason'] ?? '');
		if (($pull['ok'] ?? false) !== true) {
			$blocked = $this->federationBlocker(
				available: $reason !== 'OpenCatalogi unavailable',
				enabled: $reason !== 'federation disabled',
				peerCount: 1
			);

			return ($blocked ?? ['error', 'The last federation pull failed: ' . $this->shorten(text: $reason)]);
		}

		$peers = array_values((array) ($pull['peers'] ?? []));
		if ($peers === []) {
			return ['unconfigured', 'Federation is on, and no peer catalog is added yet.'];
		}

		$failed = array_values(
			array_filter($peers, static fn (mixed $peer): bool => is_array($peer) === true && ($peer['ok'] ?? false) !== true)
		);
		if ($failed === [] && count($peers) === 1) {
			return ['configured', 'The peer catalog answered the last pull.'];
		}

		if ($failed === []) {
			return ['configured', 'All ' . count($peers) . ' peer catalogs answered the last pull.'];
		}

		$first = $this->peerHost(url: (string) ($failed[0]['peer'] ?? ''))
			. ' did not: ' . $this->shorten(text: (string) ($failed[0]['reason'] ?? ''));
		if (count($failed) === count($peers)) {
			return ['error', 'No peer catalog answered the last pull. ' . $first];
		}

		$answered = (count($peers) - count($failed));

		return ['limited', $answered . ' of ' . count($peers) . ' peer catalogs answered the last pull. ' . $first];
	}//end describePull()

	/**
	 * After an EOL sync settings save: refresh, then report a switched-off sync.
	 *
	 * @param array<string, mixed> $config The configuration as saved.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function eolSyncConfigSaved(array $config): bool {
		if ($this->refresh(key: self::KEY_EOL) === false) {
			return false;
		}

		if (($config['enabled'] ?? false) === true) {
			return false;
		}

		[$status, $message] = self::EOL_REASONS['disabled'];

		return $this->report(key: self::KEY_EOL, status: $status, message: $message);
	}//end eolSyncConfigSaved()

	/**
	 * After an EOL sync run: report the outcome the run recorded.
	 *
	 * @param array<string, mixed> $runStatus The status EolSyncService::run() recorded.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function eolSyncRan(array $runStatus): bool {
		[$status, $message] = $this->describeEolRun(runStatus: $runStatus);

		return $this->report(key: self::KEY_EOL, status: $status, message: $message);
	}//end eolSyncRan()

	/**
	 * What an EOL sync run says about the connection.
	 *
	 * @param array<string, mixed> $runStatus The status EolSyncService::run() recorded.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function describeEolRun(array $runStatus): array {
		if (($runStatus['available'] ?? false) === true) {
			return [
				'configured',
				'The last sync stamped ' . (int) ($runStatus['matched'] ?? 0) . ' module versions and skipped '
				. (int) ($runStatus['skipped'] ?? 0) . '.',
			];
		}

		$reason = (string) ($runStatus['reason'] ?? '');

		return (self::EOL_REASONS[$reason] ?? ['error', 'The last end-of-life sync stopped: ' . $this->shorten(text: $reason)]);
	}//end describeEolRun()

	/**
	 * Ask integriq to resolve one connection again.
	 *
	 * @param string $key The connection key.
	 *
	 * @return bool True when the event was dispatched.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function refresh(string $key): bool {
		$eventClass = $this->resolveEventClass(eventClass: self::REFRESH_EVENT);
		if ($eventClass === null) {
			return false;
		}

		return $this->send(
			key: $key,
			build: static fn (): object => new $eventClass(app: Application::APP_ID, key: $key)
		);
	}//end refresh()

	/**
	 * Report one status for one connection.
	 *
	 * @param string $key     The connection key.
	 * @param string $status  One of the six registry statuses.
	 * @param string $message What stackiq observed.
	 *
	 * @return bool True when the event was dispatched.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function report(string $key, string $status, string $message): bool {
		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		return $this->send(
			key: $key,
			build: static fn (): object => new $eventClass(app: Application::APP_ID, key: $key, status: $status, message: $message)
		);
	}//end report()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * The state that keeps federation from pulling at all, or null when none does.
	 *
	 * @param bool $available Whether OpenCatalogi is installed.
	 * @param bool $enabled   Whether federation_enabled is on.
	 * @param int  $peerCount How many peers are configured.
	 *
	 * @return array{0: string, 1: string}|null The status and the message, or null.
	 */
	private function federationBlocker(bool $available, bool $enabled, int $peerCount): ?array {
		if ($available === false) {
			return ['unavailable', 'Federation needs the OpenCatalogi app, and it is not installed.'];
		}

		if ($enabled === false) {
			return [
				'unconfigured',
				'Federation is switched off. Run occ config:app:set stackiq federation_enabled --value=true --type=boolean.',
			];
		}

		if ($peerCount === 0) {
			return ['unconfigured', 'Federation is on, and no peer catalog is added yet.'];
		}

		return null;
	}//end federationBlocker()

	/**
	 * The host of a peer URL, so a message never carries its path, query or credentials.
	 *
	 * @param string $url The peer URL.
	 *
	 * @return string The host, or "A peer" when the URL has none.
	 */
	private function peerHost(string $url): string {
		$host = parse_url($url, PHP_URL_HOST);
		if (is_string($host) === false || $host === '') {
			return 'A peer';
		}

		return $host;
	}//end peerHost()

	/**
	 * A reason cut to REASON_LIMIT characters.
	 *
	 * @param string $text The reason.
	 *
	 * @return string The reason, cut and trimmed.
	 */
	private function shorten(string $text): string {
		$text = trim($text);
		if (mb_strlen($text) <= self::REASON_LIMIT) {
			return $text;
		}

		return rtrim(mb_substr($text, 0, self::REASON_LIMIT)) . '...';
	}//end shorten()

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * @param string             $key   The connection the event is about, for the log.
	 * @param callable(): object $build Builds the event.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if (($event instanceof Event) === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Stackiq: could not send a connection event to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()
}//end class
