<?php

/**
 * Golden test for the stored e-mail settings: what is read back, what an
 * update writes, which secrets are redacted and the template reads/writes.
 *
 * The mask rules are the access part: a set secret leaves the process only
 * as the mask, and a save that echoes the mask back never overwrites the
 * stored secret. Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden cases for the e-mail settings store.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class EmailSettingsGoldenTest extends TestCase {

	/**
	 * Every key the settings read, with a distinct stored value.
	 *
	 * @var array<int, string>
	 */
	private const KEYS = [
		'email_enabled', 'sender_email', 'sender_name', 'test_receiver_override',
		'email_org_registration_enabled', 'email_org_activation_enabled', 'email_user_creation_enabled',
		'email_user_organisation_enabled', 'email_transport_type', 'email_smtp_host', 'email_smtp_port',
		'email_smtp_encryption', 'email_smtp_username', 'email_smtp_password', 'email_sendgrid_api_key',
		'email_mailgun_api_key', 'email_mailgun_domain', 'email_postmark_api_key', 'email_ses_access_key',
		'email_ses_secret_key', 'email_ses_region', 'email_mailjet_api_key', 'email_mailjet_secret_key',
		'email_template_organization_registration', 'email_template_user_creation',
	];

	/**
	 * The in-memory app config store.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Every write, in order, as [app, key, value].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $writes = [];

	/**
	 * Whether a write throws.
	 *
	 * @var boolean
	 */
	private bool $failWrites = false;

	/**
	 * Every log call, in order, as [level, message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logs = [];

	/**
	 * Defaults with nothing stored, and every key read back when stored.
	 *
	 * @return void
	 */
	public function testReadBack(): void {
		$out = ['defaults' => $this->service()->getEmailSettings()];

		foreach (self::KEYS as $key) {
			$this->store[$key] = 'v:' . $key;
		}

		$this->store['email_enabled'] = 'true';
		$this->store['email_mailjet_api_key'] = 'abc';
		$out['stored'] = $this->service()->getEmailSettings();
		$out['logs'] = $this->logs;

		$this->check(key: 'read back', value: $out);
	}//end testReadBack()

	/**
	 * An update writes the allowed keys only, booleans as strings, and skips a
	 * secret that comes back as the mask while a non-secret mask value is stored.
	 *
	 * @return void
	 */
	public function testUpdate(): void {
		$this->store['email_smtp_password'] = 'real-password';
		$result = $this->service()->updateEmailSettings(
			[
				'enabled' => true,
				'userCreationEnabled' => false,
				'smtpPort' => 587,
				'smtpPassword' => SettingsService::SECRET_MASK,
				'sendgridApiKey' => SettingsService::SECRET_MASK,
				'mailjetSecretKey' => 'new-secret',
				'senderName' => SettingsService::SECRET_MASK,
				'unknown' => 'ignored',
			]
		);

		$this->assertSame('real-password', $this->store['email_smtp_password']);
		$this->assertArrayNotHasKey('email_sendgrid_api_key', $this->store);
		$this->check(key: 'update', value: ['result' => $result, 'writes' => $this->writes, 'logs' => $this->logs]);
	}//end testUpdate()

	/**
	 * A set secret is masked, an empty one stays empty, a missing one stays
	 * missing, and nothing else is touched.
	 *
	 * @return void
	 */
	public function testRedaction(): void {
		$service = $this->service();
		$in = [
			'smtpPassword' => 'p',
			'sendgridApiKey' => '',
			'mailgunApiKey' => null,
			'postmarkApiKey' => 'k',
			'sesSecretKey' => '0',
			'smtpUsername' => 'user',
			'mailjetApiKey' => 'public-part',
		];

		$out = $service->redactEmailSecrets($in);

		$this->assertSame(SettingsService::SECRET_MASK, $out['smtpPassword']);
		$this->assertSame('user', $out['smtpUsername']);
		$this->assertArrayNotHasKey('mailjetSecretKey', $out);
		$this->check(
			key: 'redaction',
			value: [
				'out' => $out,
				'mask' => SettingsService::SECRET_MASK,
				'fields' => SettingsService::SECRET_EMAIL_FIELDS,
				'allRedacted' => $service->redactEmailSecrets($service->getEmailSettings()),
			]
		);
	}//end testRedaction()

	/**
	 * Template reads fall back to the shipped default, writes log and report
	 * success, and a failing write reports false.
	 *
	 * @return void
	 */
	public function testTemplates(): void {
		$this->store['email_template_user_creation'] = '<p>stored</p>';
		$service = $this->service();

		$out = [
			'stored' => $service->getEmailTemplate('user_creation'),
			'default' => $service->getEmailTemplate('organization_activation'),
			'all' => $service->getAllEmailTemplates(),
			'updated' => $service->updateEmailTemplate('welcome', '<p>hi</p>'),
		];

		$this->failWrites = true;
		$out['failed'] = $service->updateEmailTemplate('welcome', '<p>again</p>');
		$out['writes'] = $this->writes;
		$out['logs'] = $this->logs;

		$this->check(key: 'templates', value: $out);
	}//end testTemplates()

	/**
	 * Build the service over the in-memory store and the recording logger.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame('stackiq', $app);
				return $this->store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($this->failWrites === true) {
					throw new \RuntimeException('config down');
				}

				$this->writes[] = [$app, $key, $value];
				$this->store[$key] = $value;
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new SettingsService(
			config: $config,
			request: $this->createMock(IRequest::class),
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			logger: $logger,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);
	}//end service()

	/**
	 * Compare a case with the golden file, or record it.
	 *
	 * @param string $key   The case.
	 * @param array  $value What the case observed.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$file  = __DIR__ . '/golden/emailSettings.json';
		$saved = [];
		if (file_exists($file) === true) {
			$saved = json_decode((string) file_get_contents($file), true);
		}

		if (getenv('RECORD_GOLDEN') !== false) {
			$saved[$key] = $value;
			file_put_contents($file, json_encode($saved, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($key, $saved, 'No golden for ' . $key);
		$this->assertSame($saved[$key], json_decode(json_encode($value), true));
	}//end check()
}//end class
