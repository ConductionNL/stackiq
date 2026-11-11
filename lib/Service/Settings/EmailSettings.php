<?php

/**
 * Email Settings for Stackiq
 *
 * Moved out of the general settings service: the stored e-mail settings (sender,
 * notification switches, transport and provider credentials), the
 * redaction of their secrets for HTTP responses, and the stored e-mail
 * templates.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the e-mail settings and templates.
 *
 * Not final, like the other settings handlers in this directory, so the
 * services that depend on it can be tested against a mock.
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class EmailSettings {

	/**
	 * The email settings that carry a secret value.
	 *
	 * Single source of truth for three call sites that must agree, and previously
	 * did not: the two GET responses redact these, and updateEmailSettings() skips
	 * a value that comes back as the mask so a save cannot overwrite the real
	 * secret with the placeholder. Adding a provider means adding its secret here
	 * — not to a fourth private list.
	 *
	 * @var array<int, string>
	 */
	public const SECRET_EMAIL_FIELDS = [
		'smtpPassword',
		'sendgridApiKey',
		'mailgunApiKey',
		'postmarkApiKey',
		'sesSecretKey',
		'mailjetSecretKey',
	];

	/**
	 * The placeholder a redacted secret is rendered as.
	 *
	 * @var string
	 */
	public const SECRET_MASK = '••••••••';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $config The app config holding the e-mail settings.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Redact every secret in an email-settings array for an HTTP response.
	 *
	 * `getEmailSettings()` itself must keep returning the REAL values — SymfonyEmailService
	 * reads it to actually send mail — so redaction belongs at the response boundary, not
	 * in the getter. A set secret becomes the mask; an unset one stays empty, so the UI can
	 * still tell "configured" from "not configured" without ever receiving the value.
	 *
	 * @param array<string, mixed> $settings Raw settings from getEmailSettings().
	 *
	 * @return array<string, mixed> The settings, safe to return over HTTP.
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function redactEmailSecrets(array $settings): array {
		foreach (self::SECRET_EMAIL_FIELDS as $field) {
			if (array_key_exists($field, $settings) === false) {
				continue;
			}

			$masked = '';
			if (empty($settings[$field]) === false) {
				$masked = self::SECRET_MASK;
			}

			$settings[$field] = $masked;
		}

		return $settings;
	}//end redactEmailSecrets()

	/**
	 * Gets email configuration settings
	 *
	 * WARNING: returns REAL secret values — it is what SymfonyEmailService uses to send
	 * mail. Never hand the result straight to an HTTP response; run it through
	 * redactEmailSecrets() first.
	 *
	 * @return array Email settings configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailSettings(): array {
		$this->logger->debug('Stackiq: Loading email settings from configuration');

		$app = AppIdentity::APP_ID;
		$settings = [
			'enabled' => $this->config->getValueString(
				$app,
				'email_enabled',
				'false'
			) === 'true',
			'senderEmail' => $this->config->getValueString(
				$app,
				'sender_email',
				'noreply@softwarecatalogus.nl'
			),
			'senderName' => $this->config->getValueString(
				$app,
				'sender_name',
				'Software Catalogus'
			),
			'testReceiverOverride' => $this->config->getValueString(
				$app,
				'test_receiver_override',
				''
			),
			'organizationRegistrationEnabled' => $this->config->getValueString(
				$app,
				'email_org_registration_enabled',
				'true'
			) === 'true',
			'organizationActivationEnabled' => $this->config->getValueString(
				$app,
				'email_org_activation_enabled',
				'true'
			) === 'true',
			'userCreationEnabled' => $this->config->getValueString(
				$app,
				'email_user_creation_enabled',
				'true'
			) === 'true',
			'userOrganisationEnabled' => $this->config->getValueString(
				$app,
				'email_user_organisation_enabled',
				'true'
			) === 'true',

			// Symfony Mailer transport configuration.
			'transportType' => $this->config->getValueString(
				$app,
				'email_transport_type',
				'smtp'
			),

			// SMTP configuration.
			'smtpHost' => $this->config->getValueString(
				$app,
				'email_smtp_host',
				'localhost'
			),
			'smtpPort' => (int)$this->config->getValueString(
				$app,
				'email_smtp_port',
				'587'
			),
			'smtpEncryption' => $this->config->getValueString(
				$app,
				'email_smtp_encryption',
				'tls'
			),
			'smtpUsername' => $this->config->getValueString(
				$app,
				'email_smtp_username',
				''
			),
			'smtpPassword' => $this->config->getValueString(
				$app,
				'email_smtp_password',
				''
			),

			// SendGrid configuration.
			'sendgridApiKey' => $this->config->getValueString(
				$app,
				'email_sendgrid_api_key',
				''
			),

			// Mailgun configuration.
			'mailgunApiKey' => $this->config->getValueString(
				$app,
				'email_mailgun_api_key',
				''
			),
			'mailgunDomain' => $this->config->getValueString(
				$app,
				'email_mailgun_domain',
				''
			),

			// Postmark configuration.
			'postmarkApiKey' => $this->config->getValueString(
				$app,
				'email_postmark_api_key',
				''
			),

			// Amazon SES configuration.
			'sesAccessKey' => $this->config->getValueString(
				$app,
				'email_ses_access_key',
				''
			),
			'sesSecretKey' => $this->config->getValueString(
				$app,
				'email_ses_secret_key',
				''
			),
			'sesRegion' => $this->config->getValueString(
				$app,
				'email_ses_region',
				'us-east-1'
			),

			// Mailjet configuration.
			'mailjetApiKey' => $this->config->getValueString(
				$app,
				'email_mailjet_api_key',
				''
			),
			'mailjetSecretKey' => $this->config->getValueString(
				$app,
				'email_mailjet_secret_key',
				''
			),

			// Templates.
			'templates' => [
				'organization_registration' => $this->getEmailTemplate(templateName: 'organization_registration'),
				'organization_activation' => $this->getEmailTemplate(templateName: 'organization_activation'),
				'user_creation' => $this->getEmailTemplate(templateName: 'user_creation'),
			],
		];

		$this->logger->info(
			'Stackiq: Email settings loaded from configuration',
			[
				'enabled' => $settings['enabled'],
				'transport_type' => $settings['transportType'],
				'sender_email' => $settings['senderEmail'],
				'has_mailjet_api_key' => empty($settings['mailjetApiKey']) === false,
				'mailjet_api_key_length' => strlen($settings['mailjetApiKey']),
				'has_mailjet_secret_key' => empty($settings['mailjetSecretKey']) === false,
				'mailjet_secret_key_length' => strlen($settings['mailjetSecretKey']),
				'test_receiver_override' => $settings['testReceiverOverride'],
			]
		);

		return $settings;
	}//end getEmailSettings()

	/**
	 * Updates email configuration settings
	 *
	 * @param array $emailSettings Email settings to update
	 *
	 * @return array Updated email settings
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateEmailSettings(array $emailSettings): array {
		$allowedSettings = [
			'enabled' => 'email_enabled',
			'senderEmail' => 'sender_email',
			'senderName' => 'sender_name',
			'testReceiverOverride' => 'test_receiver_override',
			'organizationRegistrationEnabled' => 'email_org_registration_enabled',
			'organizationActivationEnabled' => 'email_org_activation_enabled',
			'userCreationEnabled' => 'email_user_creation_enabled',
			'userOrganisationEnabled' => 'email_user_organisation_enabled',

			// Symfony Mailer transport configuration.
			'transportType' => 'email_transport_type',

			// SMTP configuration.
			'smtpHost' => 'email_smtp_host',
			'smtpPort' => 'email_smtp_port',
			'smtpEncryption' => 'email_smtp_encryption',
			'smtpUsername' => 'email_smtp_username',
			'smtpPassword' => 'email_smtp_password',

			// SendGrid configuration.
			'sendgridApiKey' => 'email_sendgrid_api_key',

			// Mailgun configuration.
			'mailgunApiKey' => 'email_mailgun_api_key',
			'mailgunDomain' => 'email_mailgun_domain',

			// Postmark configuration.
			'postmarkApiKey' => 'email_postmark_api_key',

			// Amazon SES configuration.
			'sesAccessKey' => 'email_ses_access_key',
			'sesSecretKey' => 'email_ses_secret_key',
			'sesRegion' => 'email_ses_region',

			// Mailjet configuration.
			'mailjetApiKey' => 'email_mailjet_api_key',
			'mailjetSecretKey' => 'email_mailjet_secret_key',
		];
		$updatedSettings = [];

		foreach ($allowedSettings as $settingKey => $configKey) {
			if (array_key_exists($settingKey, $emailSettings) === true) {
				$value = $emailSettings[$settingKey];

				// Skip the masked placeholder — the client is echoing back the redacted value;
				// preserve the real stored secret. Keyed off the same constant the GET responses
				// redact with, so a new provider cannot be masked on read but clobbered on write.
				if (in_array($settingKey, self::SECRET_EMAIL_FIELDS, true) === true && $value === self::SECRET_MASK) {
					continue;
				}

				// Convert boolean values to strings.
				if (is_bool($value) === true) {
					if ($value === true) {
						$value = 'true';
					} else {
						$value = 'false';
					}
				}

				$this->config->setValueString(AppIdentity::APP_ID, $configKey, (string)$value);
				$updatedSettings[$settingKey] = $this->config->getValueString(AppIdentity::APP_ID, $configKey);
			}//end if
		}//end foreach

		$this->logger->info(
			'Email settings updated successfully',
			[
				'updatedKeys' => array_keys($updatedSettings),
			]
		);

		return $updatedSettings;
	}//end updateEmailSettings()

	/**
	 * Gets email template content for a specific template
	 *
	 * @param string $templateName The template name (organization_registration, organization_activation, user_creation)
	 *
	 * @return string The template content
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailTemplate(string $templateName): string {
		$configKey = "email_template_{$templateName}";
		$defaultTemplate = $this->getDefaultEmailTemplate(templateName: $templateName);

		return $this->config->getValueString(AppIdentity::APP_ID, $configKey, $defaultTemplate);
	}//end getEmailTemplate()

	/**
	 * Updates email template content
	 *
	 * @param string $templateName The template name
	 * @param string $templateContent The template content
	 *
	 * @return bool True if update was successful
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateEmailTemplate(string $templateName, string $templateContent): bool {
		try {
			$configKey = "email_template_{$templateName}";
			$this->config->setValueString(AppIdentity::APP_ID, $configKey, $templateContent);

			$this->logger->info(
				'Email template updated successfully',
				[
					'templateName' => $templateName,
				]
			);

			return true;
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update email template: ' . $e->getMessage(),
				[
					'templateName' => $templateName,
				]
			);
			return false;
		}//end try
	}//end updateEmailTemplate()

	/**
	 * Gets default email template content
	 *
	 * @param string $templateName The template name
	 *
	 * @return string Default template content
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getDefaultEmailTemplate(string $templateName): string {
		return (new EmailTemplateDefaults())->template(templateName: $templateName);
	}//end getDefaultEmailTemplate()

	/**
	 * Gets available email template variables for a specific template
	 *
	 * @param string $templateName The template name
	 *
	 * @return array Available template variables
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailTemplateVariables(string $templateName): array {
		return (new EmailTemplateDefaults())->variables(templateName: $templateName);
	}//end getEmailTemplateVariables()

	/**
	 * Get all email templates with error handling
	 * This handles template iteration and individual failures
	 *
	 * @return array All email templates
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAllEmailTemplates(): array {
		$templateTypes = [
			'organization_registration',
			'organization_activation',
			'user_creation',
			'user_organisation',
		];
		$templates = [];

		foreach ($templateTypes as $templateName) {
			try {
				$templates[$templateName] = $this->getEmailTemplate(templateName: $templateName);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to get template {$templateName}", ['error' => $e->getMessage()]);
				$templates[$templateName] = null;
			}
		}

		return $templates;
	}//end getAllEmailTemplates()
}//end class
