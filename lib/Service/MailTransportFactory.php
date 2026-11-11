<?php

/**
 * Mail transport factory for Stackiq
 *
 * Builds the Symfony Mailer transport for the configured transport type.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/email-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds a Symfony Mailer transport from the email settings: SMTP, the fixed
 * DSN transports (sendmail, native, null) and the API transports; an unknown
 * type falls back to SMTP.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)             — Transport::fromDsn is Symfony Mailer's static factory pattern
 *
 * @spec openspec/specs/email-delivery/spec.md
 */
final class MailTransportFactory {
	/**
	 * Transports built from a fixed DSN, without settings.
	 *
	 * @var array<string, string> DSN per transport type
	 */
	private const FIXED_DSN_TRANSPORTS = [
		'sendmail' => 'sendmail://default',
		'native' => 'native://default',
		'null' => 'null://null',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger instance.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Creates the transport for the configured transport type.
	 *
	 * @param array $emailSettings The email settings.
	 *
	 * @return TransportInterface The configured transport.
	 *
	 * @throws \Exception If transport configuration is invalid.
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	public function create(array $emailSettings): TransportInterface {
		$transportType = $emailSettings['transportType'] ?? 'smtp';

		try {
			return $this->transportFor(transportType: $transportType, settings: $emailSettings);
		} catch (Exception $e) {
			$this->logTransportFailure(transportType: $transportType, error: $e);
			throw $e;
		}
	}//end create()

	/**
	 * Logs that the transport could not be created.
	 *
	 * @param mixed      $transportType The configured transport type.
	 * @param Exception $error         The failure.
	 *
	 * @return void
	 */
	private function logTransportFailure(mixed $transportType, Exception $error): void {
		$this->logger->error(
			'Failed to create email transport',
			[
				'transportType' => $transportType,
				'error' => $error->getMessage(),
			]
		);
	}//end logTransportFailure()

	/**
	 * Builds the transport for a transport type; an unknown type falls back to SMTP.
	 *
	 * @param string $transportType The configured transport type.
	 * @param array  $settings      Email settings.
	 *
	 * @return TransportInterface The transport.
	 */
	private function transportFor(string $transportType, array $settings): TransportInterface {
		if (isset(self::FIXED_DSN_TRANSPORTS[$transportType]) === true) {
			return Transport::fromDsn(self::FIXED_DSN_TRANSPORTS[$transportType]);
		}

		return match ($transportType) {
			'smtp' => $this->createSmtpTransport(settings: $settings),
			'sendgrid' => $this->createSendGridTransport(settings: $settings),
			'mailgun' => $this->createMailgunTransport(settings: $settings),
			'postmark' => $this->createPostmarkTransport(settings: $settings),
			'ses' => $this->createSesTransport(settings: $settings),
			'mailjet' => $this->createMailjetTransport(settings: $settings),
			default => $this->createFallbackSmtpTransport(transportType: $transportType, settings: $settings),
		};
	}//end transportFor()

	/**
	 * Logs an unknown transport type and builds the SMTP transport instead.
	 *
	 * @param string $transportType The unknown transport type.
	 * @param array  $settings      Email settings.
	 *
	 * @return TransportInterface SMTP transport.
	 */
	private function createFallbackSmtpTransport(string $transportType, array $settings): TransportInterface {
		$this->logger->warning(
			'Unknown transport type, falling back to SMTP',
			[
				'transportType' => $transportType,
			]
		);
		return $this->createSmtpTransport(settings: $settings);
	}//end createFallbackSmtpTransport()

	/**
	 * Creates an SMTP transport.
	 *
	 * @param array $settings Email settings.
	 *
	 * @return TransportInterface SMTP transport.
	 */
	private function createSmtpTransport(array $settings): TransportInterface {
		$dsn = $this->smtpDsn(settings: $settings);

		try {
			return Transport::fromDsn($dsn);
		} catch (Exception $e) {
			// Strip credentials from exception message before logging.
			$safeMessage = $this->redactDsnCredentials(message: $e->getMessage());
			throw new RuntimeException('Failed to create SMTP transport: ' . $safeMessage, 0, $e);
		}
	}//end createSmtpTransport()

	/**
	 * Builds the SMTP DSN from the settings; SSL encryption uses the smtps scheme.
	 *
	 * @param array $settings Email settings.
	 *
	 * @return string The DSN, with the credentials URL-encoded.
	 */
	private function smtpDsn(array $settings): string {
		$dsn = sprintf(
			'smtp://%s:%s@%s:%d',
			rawurlencode($settings['smtpUsername'] ?? ''),
			rawurlencode($settings['smtpPassword'] ?? ''),
			$settings['smtpHost'] ?? 'localhost',
			$settings['smtpPort'] ?? 587
		);

		if (($settings['smtpEncryption'] ?? 'tls') === 'ssl') {
			$dsn = str_replace('smtp://', 'smtps://', $dsn);
		}

		return $dsn;
	}//end smtpDsn()

	/**
	 * Replaces the credentials of an SMTP DSN in a message by asterisks.
	 *
	 * @param string $message The message.
	 *
	 * @return string The message without credentials.
	 */
	private function redactDsnCredentials(string $message): string {
		return preg_replace(
			'~(smtp[s]?://)[^:]+:[^@]+@~i',
			'$1***:***@',
			$message
		) ?? $message;
	}//end redactDsnCredentials()

	/**
	 * Creates a SendGrid transport.
	 *
	 * @param array $settings Email settings.
	 *
	 * @return TransportInterface SendGrid transport.
	 */
	private function createSendGridTransport(array $settings): TransportInterface {
		$apiKey = ($settings['sendgridApiKey'] ?? '');
		if (empty($apiKey) === true) {
			throw new InvalidArgumentException('SendGrid API key is required');
		}

		return Transport::fromDsn('sendgrid+api://' . rawurlencode($apiKey) . '@default');
	}//end createSendGridTransport()

	/**
	 * Creates a Mailgun transport.
	 *
	 * @param array $settings Email settings.
	 *
	 * @return TransportInterface Mailgun transport.
	 */
	private function createMailgunTransport(array $settings): TransportInterface {
		$apiKey = ($settings['mailgunApiKey'] ?? '');
		$domain = ($settings['mailgunDomain'] ?? '');

		if (empty($apiKey) === true || empty($domain) === true) {
			throw new InvalidArgumentException('Mailgun API key and domain are required');
		}

		return Transport::fromDsn(
			sprintf(
				'mailgun+api://%s:%s@default',
				rawurlencode($apiKey),
				rawurlencode($domain)
			)
		);
	}//end createMailgunTransport()

	/**
	 * Creates a Postmark transport.
	 *
	 * @param array $settings Email settings.
	 *
	 * @return TransportInterface Postmark transport.
	 */
	private function createPostmarkTransport(array $settings): TransportInterface {
		$apiKey = ($settings['postmarkApiKey'] ?? '');
		if (empty($apiKey) === true) {
			throw new InvalidArgumentException('Postmark API key is required');
		}

		return Transport::fromDsn('postmark+api://' . rawurlencode($apiKey) . '@default');
	}//end createPostmarkTransport()

	/**
	 * Creates an Amazon SES transport.
	 *
	 * @param array $settings Email settings.
	 *
	 * @return TransportInterface SES transport.
	 */
	private function createSesTransport(array $settings): TransportInterface {
		$accessKey = ($settings['sesAccessKey'] ?? '');
		$secretKey = ($settings['sesSecretKey'] ?? '');
		$region = ($settings['sesRegion'] ?? 'us-east-1');

		if (empty($accessKey) === true || empty($secretKey) === true) {
			throw new InvalidArgumentException('Amazon SES access key and secret key are required');
		}

		return Transport::fromDsn(
			sprintf(
				'ses+api://%s:%s@default?region=%s',
				rawurlencode($accessKey),
				rawurlencode($secretKey),
				rawurlencode($region)
			)
		);
	}//end createSesTransport()

	/**
	 * Creates a Mailjet transport.
	 *
	 * @param array $settings Email settings.
	 *
	 * @return TransportInterface Mailjet transport.
	 */
	private function createMailjetTransport(array $settings): TransportInterface {
		$apiKey = ($settings['mailjetApiKey'] ?? '');
		$secretKey = ($settings['mailjetSecretKey'] ?? '');

		if (empty($apiKey) === true || empty($secretKey) === true) {
			throw new InvalidArgumentException('Mailjet API key and secret key are required');
		}

		return Transport::fromDsn(
			sprintf(
				'mailjet+api://%s:%s@default',
				rawurlencode($apiKey),
				rawurlencode($secretKey)
			)
		);
	}//end createMailjetTransport()
}//end class
