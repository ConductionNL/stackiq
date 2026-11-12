<?php

/**
 * One notice email: what is sent to whom, and the log lines around it.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use Psr\Log\LoggerInterface;

/**
 * A notice email being sent: its recipient, the data its template is rendered
 * with and what the log lines name, filled in step by step, and the log lines
 * written around it.
 *
 * Built by SymfonyEmailService for each notice.
 *
 * @spec openspec/specs/email-delivery/spec.md
 */
final class NoticeEmail {
	/**
	 * The organisation name a user notice names; unused for an organisation notice.
	 *
	 * @var string
	 */
	public string $organizationName = '';

	/**
	 * The recipient address, as the organisation or user carries it.
	 *
	 * @var mixed
	 */
	public mixed $recipientEmail = '';

	/**
	 * The recipient name, as the organisation or user carries it.
	 *
	 * @var mixed
	 */
	public mixed $recipientName = '';

	/**
	 * The context of the success and failure log lines.
	 *
	 * @var array<string, mixed>
	 */
	public array $logContext = [];

	/**
	 * The context of the sending log line, before the transport type.
	 *
	 * @var array<string, mixed>
	 */
	public array $sendingContext = [];

	/**
	 * The data the template is rendered with.
	 *
	 * @var array<string, mixed>
	 */
	public array $templateData = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $notice The notice: its log prefixes, settings key, subject, template and the words its log lines use.
	 * @param array<string, mixed> $data The organisation or the user the notice is about.
	 */
	public function __construct(
		public readonly array $notice,
		public readonly array $data,
	) {
	}//end __construct()

	/**
	 * Take the recipient address of an organisation; warn and refuse when it has no valid one.
	 *
	 * @param LoggerInterface $logger The logger of the email service.
	 * @param string|null $recipientEmail The valid address of the organisation, or null.
	 *
	 * @return bool Whether the organisation has a valid address.
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	public function addressOrganization(LoggerInterface $logger, ?string $recipientEmail): bool {
		if ($recipientEmail === null) {
			$logger->warning(
				$this->notice['messagePrefix'] . ': Cannot send without valid email address',
				['organization' => $this->data, 'organizationName' => $this->recipientName]
			);
			return false;
		}

		$this->recipientEmail = $recipientEmail;
		$this->logContext = ['organizationName' => $this->recipientName, 'recipientEmail' => $recipientEmail];
		$this->sendingContext = $this->logContext;
		return true;
	}//end addressOrganization()

	/**
	 * The template data of an organisation notice.
	 *
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	public function organizationTemplateData(): array {
		return [
			'organization' => [
				'name' => $this->recipientName,
				'beoordeling' => ($this->data['beoordeling'] ?? $this->notice['beoordeling']),
				'type' => ($this->data['type'] ?? 'Organisatie'),
				'website' => ($this->data['website'] ?? ''),
			],
		];
	}//end organizationTemplateData()

	/**
	 * Take the address and name of a user; warn and refuse when the user has no address.
	 *
	 *
	 * @param LoggerInterface $logger The logger of the email service.
	 *
	 * @return bool Whether the user has an address.
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	public function addressUser(LoggerInterface $logger): bool {
		$userEmail = $this->data['email'] ?? '';
		$userName = $this->userName(user: $this->data);
		if (empty($userEmail) === true) {
			$logger->warning(
				$this->notice['messagePrefix'] . ': Cannot send without email address',
				['user' => $this->data, 'userName' => $userName]
			);
			return false;
		}

		$this->recipientEmail = $userEmail;
		$this->logContext = ['userName' => $userName, 'userEmail' => $userEmail];
		$this->sendingContext = array_merge($this->logContext, ['organizationName' => $this->organizationName]);
		return true;
	}//end addressUser()

	/**
	 * The recipient name and template data of a user notice: the user's name, or 'Gebruiker' without one.
	 *
	 *
	 * @return void
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	public function describeUserNotice(): void {
		$displayName = 'Gebruiker';
		if (empty($this->logContext['userName']) === false) {
			$displayName = $this->logContext['userName'];
		}

		$this->recipientName = $displayName;
		$this->templateData = [
			'user' => [
				'name' => $displayName,
				'email' => $this->recipientEmail,
				'role' => ($this->data['role'] ?? ''),
			],
			'organization' => ['name' => $this->organizationName],
		];
	}//end describeUserNotice()

	/**
	 * Log that a notice is being sent, with the transport type.
	 *
	 * @param LoggerInterface $logger The logger of the email service.
	 * @param array $configStatus The configuration status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	public function logSending(LoggerInterface $logger, array $configStatus): void {
		$logger->info(
			$this->notice['messagePrefix'] . ': Sending ' . $this->notice['sendingKind'] . ' email',
			array_merge($this->sendingContext, ['transportType' => $configStatus['transportType']])
		);
	}//end logSending()

	/**
	 * Log that the templated email of a notice could not be sent.
	 *
	 * @param LoggerInterface $logger The logger of the email service.
	 * @param \Exception $error The failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function logTemplatedFailure(LoggerInterface $logger, \Exception $error): void {
		$logger->error(
			'Failed to send templated email',
			[
				'recipient' => $this->recipientEmail,
				'template' => $this->notice['template'],
				'error' => $error->getMessage(),
				'trace' => $error->getTraceAsString(),
			]
		);
	}//end logTemplatedFailure()

	/**
	 * The name of a user: its name, or its first and last name.
	 *
	 * @param array $user The user data.
	 *
	 * @return string
	 */
	private function userName(array $user): string {
		$userName = $user['name'] ?? ($user['voornaam'] ?? '') . ' ' . ($user['achternaam'] ?? '');
		return trim($userName);
	}//end userName()
}//end class
