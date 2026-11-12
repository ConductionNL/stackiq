<?php

/**
 * Golden test of the four notice emails of SymfonyEmailService.
 *
 * Pins, for the organisation registration and activation emails and the user
 * creation and user organisation emails, the result, every log line and the
 * message sent, when delivery is ready, not configured, disabled for the
 * message type, and when the transport fails.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
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

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Records every log call as [level, message, context].
 */
class EmailNoticeLogRecorder extends AbstractLogger {
	/**
	 * Recorded log calls.
	 *
	 * @var array<int, array{0:string,1:string,2:array}>
	 */
	public array $records = [];

	/**
	 * Record one log call; stack traces are replaced by a marker.
	 *
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array              $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context=[]): void {
		if (isset($context['trace']) === true) {
			$context['trace'] = '<trace>';
		}

		$this->records[] = [(string)$level, (string)$message, $context];
	}
}

/**
 * Records the messages sent; optionally fails every send.
 */
class EmailNoticeTransport implements TransportInterface {
	/**
	 * The messages sent, summarised.
	 *
	 * @var array<int, array<int, string>>
	 */
	public array $sent = [];

	/**
	 * Constructor.
	 *
	 * @param bool $fail Whether every send throws.
	 */
	public function __construct(private readonly bool $fail=false) {
	}

	/**
	 * Record or fail one send.
	 *
	 * @param RawMessage    $message  The message.
	 * @param Envelope|null $envelope The envelope.
	 *
	 * @return SentMessage|null
	 */
	public function send(RawMessage $message, ?Envelope $envelope=null): ?SentMessage {
		if ($this->fail === true) {
			throw new \RuntimeException('transport down');
		}

		$this->sent[] = [
			$message->getTo()[0]->getAddress(),
			$message->getTo()[0]->getName(),
			$message->getSubject(),
			$message->getFrom()[0]->toString(),
			(string)$message->getHtmlBody(),
		];
		return null;
	}

	/**
	 * Transport name.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return 'recording://';
	}
}

/**
 * Golden test of the notice emails.
 */
class SymfonyEmailNoticeGoldenTest extends TestCase {
	/**
	 * Run one send on a fresh service and record what it did.
	 *
	 * @param callable $send     Sends the email on the service.
	 * @param array    $settings The email settings.
	 * @param bool     $fail     Whether the transport fails.
	 *
	 * @return array
	 */
	private function record(callable $send, array $settings, bool $fail): array {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getEmailSettings')->willReturn($settings);
		$log = new EmailNoticeLogRecorder();
		$service = new SymfonyEmailService($this->createMock(IAppConfig::class), $log, $settingsService);
		$transport = new EmailNoticeTransport($fail);
		$ref = new \ReflectionProperty(SymfonyEmailService::class, 'mailer');
		$ref->setAccessible(true);
		$ref->setValue($service, new Mailer($transport));

		try {
			$result = $send($service);
		} catch (\Throwable $e) {
			$result = get_class($e) . ': ' . $e->getMessage();
		}

		return ['result' => $result, 'log' => $log->records, 'sent' => $transport->sent];
	}//end record()

	/**
	 * The four sends on varied organisations and users, in every delivery state, match the golden.
	 *
	 * @return void
	 */
	public function testNoticeEmailsMatchTheGolden(): void {
		$ready = [
			'enabled' => true,
			'transportType' => 'null',
			'senderEmail' => 'sender@example.org',
			'senderName' => 'Sender',
			'organizationRegistrationEnabled' => true,
			'organizationActivationEnabled' => true,
			'userCreationEnabled' => true,
			'userOrganisationEnabled' => true,
			'templates' => [],
		];
		$states = [
			'ready' => [$ready, false],
			'not configured' => [['enabled' => false] + $ready, false],
			'type disabled' => [
				[
					'organizationRegistrationEnabled' => false,
					'organizationActivationEnabled' => false,
					'userCreationEnabled' => false,
					'userOrganisationEnabled' => false,
				] + $ready,
				false,
			],
			'transport fails' => [$ready, true],
			'custom templates' => [
				[
					'templates' => array_fill_keys(
						['organization_registration', 'organization_activation', 'user_creation', 'user_organisation'],
						'{{ organization.name }}|{{ user.name }}|{{ user.email }}|{{ user.password }}'
					),
				] + $ready,
				false,
			],
		];

		$organisations = [
			'full' => ['name' => 'Gemeente Test', 'e-mailadres' => 'org@example.org', 'type' => 'Gemeente', 'website' => 'https://example.org', 'beoordeling' => 'Goed'],
			'via contact' => ['name' => 'Via Contact', 'contactpersonen' => ['x', ['e-mailadres' => ''], ['e-mailadres' => 'contact@example.org']]],
			'bad email' => ['name' => 'Bad', 'e-mailadres' => 'not-an-email'],
			'no name' => ['e-mailadres' => 'anon@example.org'],
		];
		$users = [
			'full' => [['email' => 'user@example.org', 'name' => 'Jan Jansen', 'role' => 'admin'], ['name' => 'Gemeente Test']],
			'name parts' => [['email' => 'parts@example.org', 'voornaam' => 'Piet', 'achternaam' => 'Pietersen'], []],
			'no name' => [['email' => 'noname@example.org'], []],
			'no email' => [['name' => 'Geen Mail'], ['name' => 'Org']],
		];

		$sends = [];
		foreach ($organisations as $name => $organisation) {
			$sends['registration, ' . $name] = fn (SymfonyEmailService $s) => $s->sendOrganizationRegistrationEmail($organisation);
			$sends['activation, ' . $name] = fn (SymfonyEmailService $s) => $s->sendOrganizationActivationEmail($organisation);
		}

		foreach ($users as $name => [$user, $organisation]) {
			$sends['user creation, ' . $name] = fn (SymfonyEmailService $s) => $s->sendUserCreationEmail($user, $organisation);
			$sends['user organisation, ' . $name] = fn (SymfonyEmailService $s) => $s->sendUserUpdateEmail($user, $organisation);
		}

		$sends['user creation, no organisation'] = fn (SymfonyEmailService $s) => $s->sendUserCreationEmail(['email' => 'solo@example.org', 'name' => 'Solo']);
		$sends['user organisation, no organisation'] = fn (SymfonyEmailService $s) => $s->sendUserUpdateEmail(['email' => 'solo@example.org', 'name' => 'Solo']);

		$out = [];
		foreach ($sends as $sendName => $send) {
			foreach ($states as $stateName => [$settings, $fail]) {
				$out[$sendName . ' | ' . $stateName] = $this->record($send, $settings, $fail);
			}
		}

		$path = dirname(__DIR__, 2) . '/fixtures/email-notices/notices.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testNoticeEmailsMatchTheGolden()
}//end class
