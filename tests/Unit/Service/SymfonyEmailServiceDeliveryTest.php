<?php

/**
 * Characterisation tests for SymfonyEmailService delivery.
 *
 * Pins the transport selection, the credential check and the four send
 * methods (organisation registration and activation, user creation and
 * user organisation) with their log lines and the messages they send.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/email-delivery/spec.md
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
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Records every log call as [level, message, context].
 */
class EmailDeliveryLogSpy extends AbstractLogger {
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
 * Records sent messages; optionally fails every send.
 */
class EmailDeliveryRecordingTransport implements TransportInterface {
	/**
	 * Sent emails.
	 *
	 * @var Email[]
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

		$this->sent[] = $message;
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
 * Characterisation tests for SymfonyEmailService delivery.
 *
 * @spec openspec/specs/email-delivery/spec.md
 */
class SymfonyEmailServiceDeliveryTest extends TestCase {
	/**
	 * The log spy of the last built service.
	 *
	 * @var EmailDeliveryLogSpy
	 */
	private EmailDeliveryLogSpy $log;

	/**
	 * Build a service over fixed email settings.
	 *
	 * @param array $emailSettings The settings getEmailSettings() returns.
	 *
	 * @return SymfonyEmailService
	 */
	private function makeService(array $emailSettings): SymfonyEmailService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getEmailSettings')->willReturn($emailSettings);
		$this->log = new EmailDeliveryLogSpy();

		return new SymfonyEmailService(
			$this->createMock(IAppConfig::class),
			$this->log,
			$settings,
		);
	}

	/**
	 * Give the service a mailer over the given transport.
	 *
	 * @param SymfonyEmailService $service   The service.
	 * @param TransportInterface  $transport The transport.
	 *
	 * @return void
	 */
	private function useTransport(SymfonyEmailService $service, TransportInterface $transport): void {
		$ref = new \ReflectionProperty(SymfonyEmailService::class, 'mailer');
		$ref->setAccessible(true);
		$ref->setValue($service, new Mailer($transport));
	}

	/**
	 * Settings with email on, the null transport and every type enabled.
	 *
	 * @param array $overrides Settings to override.
	 *
	 * @return array
	 */
	private function readySettings(array $overrides=[]): array {
		return $overrides + [
			'enabled'                         => true,
			'transportType'                   => 'null',
			'senderEmail'                     => 'sender@example.org',
			'senderName'                      => 'Sender',
			'organizationRegistrationEnabled' => true,
			'organizationActivationEnabled'   => true,
			'userCreationEnabled'             => true,
			'userOrganisationEnabled'         => true,
			'templates'                       => [],
		];
	}

	/**
	 * Call a private method.
	 *
	 * @param object $service The service.
	 * @param string $method  The method.
	 * @param array  $args    Positional arguments.
	 *
	 * @return mixed
	 */
	private function callPrivate(object $service, string $method, array $args=[]): mixed {
		$ref = new \ReflectionMethod($service, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs($service, $args);
	}

	/**
	 * Summarise sent emails as [to, to name, subject, from, html].
	 *
	 * @param EmailDeliveryRecordingTransport $transport The transport.
	 *
	 * @return array
	 */
	private function sentSummary(EmailDeliveryRecordingTransport $transport): array {
		$out = [];
		foreach ($transport->sent as $email) {
			$out[] = [
				$email->getTo()[0]->getAddress(),
				$email->getTo()[0]->getName(),
				$email->getSubject(),
				$email->getFrom()[0]->toString(),
				md5((string)$email->getHtmlBody()),
				md5((string)$email->getTextBody()),
			];
		}

		return $out;
	}

	/**
	 * Credential check per transport type, through isEmailSystemConfigured().
	 *
	 * @return void
	 */
	public function testCredentialCheckPerTransport(): void {
		$cases = [
			[['transportType' => 'mailjet', 'mailjetApiKey' => 'k', 'mailjetSecretKey' => 's'], true],
			[['transportType' => 'mailjet', 'mailjetApiKey' => 'k'], false],
			[['transportType' => 'mailjet', 'mailjetSecretKey' => 's'], false],
			[['transportType' => 'sendgrid', 'sendgridApiKey' => 'k'], true],
			[['transportType' => 'sendgrid', 'sendgridApiKey' => ''], false],
			[['transportType' => 'mailgun', 'mailgunApiKey' => 'k', 'mailgunDomain' => 'd'], true],
			[['transportType' => 'mailgun', 'mailgunApiKey' => 'k', 'mailgunDomain' => '0'], false],
			[['transportType' => 'mailgun', 'mailgunDomain' => 'd'], false],
			[['transportType' => 'postmark', 'postmarkApiKey' => 'k'], true],
			[['transportType' => 'postmark'], false],
			[['transportType' => 'ses', 'sesAccessKey' => 'a', 'sesSecretKey' => 's'], true],
			[['transportType' => 'ses', 'sesAccessKey' => 'a'], false],
			[['transportType' => 'ses', 'sesSecretKey' => 's'], false],
			[['transportType' => 'smtp', 'smtpHost' => 'h'], true],
			[['transportType' => 'smtp'], false],
			[['smtpHost' => 'h'], true],
			[[], false],
			[['transportType' => 'sendmail'], true],
			[['transportType' => 'native'], true],
			[['transportType' => 'null'], true],
			[['transportType' => 'carrier-pigeon', 'smtpHost' => 'h'], false],
			[['transportType' => 'SMTP', 'smtpHost' => 'h'], false],
		];
		foreach ($cases as $i => [$settings, $expected]) {
			$service = $this->makeService(['enabled' => true] + $settings);
			$status = $service->isEmailSystemConfigured();
			$this->assertSame($expected, $status['hasCredentials'], 'case ' . $i);
			$this->assertSame($expected, $status['configured'], 'case ' . $i);
		}
	}

	/**
	 * Transport selection per type, with the fallback and failure logs.
	 *
	 * @return void
	 */
	public function testTransportSelection(): void {
		$cases = [
			'smtp'     => [['smtpHost' => 'mail.example.org', 'smtpPort' => 25, 'smtpUsername' => 'u', 'smtpPassword' => 'p']],
			'sendmail' => [[]],
			'native'   => [[]],
			'null'     => [[]],
			'sendgrid' => [['sendgridApiKey' => 'key']],
			'mailgun'  => [['mailgunApiKey' => 'key', 'mailgunDomain' => 'example.org']],
			'postmark' => [['postmarkApiKey' => 'key']],
			'ses'      => [['sesAccessKey' => 'a', 'sesSecretKey' => 's', 'sesRegion' => 'eu-west-1']],
			'mailjet'  => [['mailjetApiKey' => 'k', 'mailjetSecretKey' => 's']],
			'unknown'  => [['smtpHost' => 'mail.example.org']],
		];
		$results = [];
		foreach ($cases as $type => [$settings]) {
			$service = $this->makeService(['transportType' => $type] + $settings);
			try {
				$transport      = $this->callPrivate($service, 'createTransport');
				$results[$type] = [get_class($transport), (string)$transport, $this->log->records];
			} catch (\Throwable $e) {
				$results[$type] = [get_class($e), $e->getMessage(), $this->log->records];
			}
		}

		$service = $this->makeService([]);
		$results['default'] = [get_class($this->callPrivate($service, 'createTransport')), $this->log->records];

		foreach (['sendgrid', 'mailgun', 'postmark', 'ses', 'mailjet'] as $type) {
			$service = $this->makeService(['transportType' => $type]);
			try {
				$this->callPrivate($service, 'createTransport');
				$results['missing-' . $type] = 'no exception';
			} catch (\Throwable $e) {
				$results['missing-' . $type] = [get_class($e), $e->getMessage(), $this->log->records];
			}
		}

		$this->assertGolden('transports', $results);
	}

	/**
	 * The four send methods across their skip, failure and success paths.
	 *
	 * @return void
	 */
	public function testSendMethods(): void {
		$organisation = ['name' => 'Gemeente Test', 'e-mailadres' => 'org@example.org', 'type' => 'Gemeente', 'website' => 'https://example.org'];
		$viaContact   = ['name' => 'Via Contact', 'contactpersonen' => ['x', ['e-mailadres' => ''], ['e-mailadres' => 'contact@example.org'], ['e-mailadres' => 'second@example.org']]];
		$badEmail     = ['name' => 'Bad', 'e-mailadres' => 'not-an-email'];
		$noName       = ['e-mailadres' => 'anon@example.org'];
		$user         = ['email' => 'user@example.org', 'name' => 'Jan Jansen', 'role' => 'admin'];
		$userParts    = ['email' => 'parts@example.org', 'voornaam' => 'Piet', 'achternaam' => 'Pietersen'];
		$userNoName   = ['email' => 'noname@example.org'];
		$userNoEmail  = ['name' => 'Geen Mail'];
		$orgForUser   = ['name' => 'Gemeente Test'];

		$calls = [
			'reg'      => fn (SymfonyEmailService $s) => $s->sendOrganizationRegistrationEmail($organisation),
			'reg-cp'   => fn (SymfonyEmailService $s) => $s->sendOrganizationRegistrationEmail($viaContact),
			'reg-bad'  => fn (SymfonyEmailService $s) => $s->sendOrganizationRegistrationEmail($badEmail),
			'reg-anon' => fn (SymfonyEmailService $s) => $s->sendOrganizationRegistrationEmail($noName),
			'act'      => fn (SymfonyEmailService $s) => $s->sendOrganizationActivationEmail($organisation),
			'act-bad'  => fn (SymfonyEmailService $s) => $s->sendOrganizationActivationEmail($badEmail),
			'act-anon' => fn (SymfonyEmailService $s) => $s->sendOrganizationActivationEmail($noName),
			'new'      => fn (SymfonyEmailService $s) => $s->sendUserCreationEmail($user, $orgForUser),
			'new-p'    => fn (SymfonyEmailService $s) => $s->sendUserCreationEmail($userParts),
			'new-n'    => fn (SymfonyEmailService $s) => $s->sendUserCreationEmail($userNoName, $orgForUser),
			'new-x'    => fn (SymfonyEmailService $s) => $s->sendUserCreationEmail($userNoEmail),
			'upd'      => fn (SymfonyEmailService $s) => $s->sendUserUpdateEmail($user, $orgForUser),
			'upd-p'    => fn (SymfonyEmailService $s) => $s->sendUserUpdateEmail($userParts),
			'upd-n'    => fn (SymfonyEmailService $s) => $s->sendUserUpdateEmail($userNoName),
			'upd-x'    => fn (SymfonyEmailService $s) => $s->sendUserUpdateEmail($userNoEmail, $orgForUser),
		];
		$settingsVariants = [
			'ready'    => $this->readySettings(),
			'override' => $this->readySettings(['testReceiverOverride' => 'tester@example.org']),
			'badover'  => $this->readySettings(['testReceiverOverride' => 'nope']),
			'custom'   => $this->readySettings(['templates' => ['organization_registration' => '<p>{{ organization.name }}</p>', 'user_creation' => '<p>{{ user.name }} {{ user.email }} {{ user.password }}</p>']]),
			'off'      => $this->readySettings(['enabled' => false]),
			'typesoff' => $this->readySettings(['organizationRegistrationEnabled' => false, 'organizationActivationEnabled' => false, 'userCreationEnabled' => false, 'userOrganisationEnabled' => false]),
			'nocreds'  => $this->readySettings(['transportType' => 'smtp']),
		];

		$results = [];
		foreach ($settingsVariants as $variant => $settings) {
			foreach ($calls as $name => $call) {
				foreach ([false, true] as $fail) {
					$service   = $this->makeService($settings);
					$transport = new EmailDeliveryRecordingTransport(fail: $fail);
					$this->useTransport($service, $transport);
					$key = $variant . '/' . $name . '/' . ($fail === true ? 'fail' : 'ok');
					$results[$key] = [$call($service), $this->log->records, $this->sentSummary($transport)];
				}
			}
		}

		$this->assertGolden('send-methods', $results);
	}

	/**
	 * Compare results with a golden recorded from the code before the split.
	 *
	 * @param string $name    The fixture name.
	 * @param array  $results The results.
	 *
	 * @return void
	 */
	private function assertGolden(string $name, array $results): void {
		$path   = dirname(__DIR__, 2) . '/fixtures/email-delivery/' . $name . '.json';
		$actual = json_decode((string)json_encode($results, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $actual);
	}
}
