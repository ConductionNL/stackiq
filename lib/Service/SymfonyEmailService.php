<?php

/**
 * Symfony Email Service for Stackiq.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Service\Settings\EmailSettings;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Symfony Mailer-based email service for sending notification emails
 *
 * This service handles sending various types of notification emails
 * using Symfony Mailer with configurable transports including SMTP,
 * SendGrid, Mailgun, and other providers.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 * @version  GIT: <git_id>
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 */
class SymfonyEmailService {
	/**
	 * Email template for organization welcome emails
	 *
	 * @var string Organization welcome email template
	 */
	private const ORGANIZATION_WELCOME_TEMPLATE = '
        <html>
        <head>
            <title>Welkom bij de Software Catalogus</title>
            <meta charset="UTF-8">
        </head>
        <body>
            <h1>Welkom {{ organization.name }}!</h1>
            <p>Beste {{ organization.name }},</p>
            <p>Hartelijk welkom bij de Software Catalogus! Uw organisatie is succesvol geregistreerd.</p>
            <p>Met de Software Catalogus kunt u:</p>
            <ul>
                <li>Uw software overzichtelijk beheren</li>
                <li>Software delen met andere organisaties</li>
                <li>Ontdekken welke software andere organisaties gebruiken</li>
                <li>Bijdragen aan de open data gemeenschap</li>
            </ul>
            <p>U kunt nu inloggen op het platform en uw software catalogus beheren.</p>
            <p>Heeft u vragen? Neem dan contact met ons op via info@conduction.nl</p>
            <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
        </body>
        </html>
    ';

	/**
	 * Email template for organization activation emails
	 *
	 * @var string Organization activation email template
	 */
	private const ORGANIZATION_ACTIVATION_TEMPLATE = '
        <html>
        <head>
            <title>Organisatie Geactiveerd - Software Catalogus</title>
            <meta charset="UTF-8">
        </head>
        <body>
            <h1>{{ organization.name }} is nu Actief!</h1>
            <p>Beste {{ organization.name }},</p>
            <p>Goed nieuws! Uw organisatie is nu geactiveerd in de Software Catalogus.</p>
            <p>Dit betekent dat u nu:</p>
            <ul>
                <li>Volledig gebruik kunt maken van alle functies</li>
                <li>Software kunt publiceren en delen</li>
                <li>Toegang heeft tot de community features</li>
                <li>Deel kunt nemen aan collaboraties</li>
            </ul>
            <p>Log in op het platform om te beginnen met het beheren van uw software catalogus.</p>
            <p>Heeft u vragen? Neem dan contact met ons op via info@conduction.nl</p>
            <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
        </body>
        </html>
    ';

	/**
	 * Email template for user (gebruiker) welcome emails
	 *
	 * @var string User welcome email template
	 */
	private const GEBRUIKER_WELCOME_TEMPLATE = '
        <html>
        <head>
            <title>Welkom bij de Software Catalogus</title>
            <meta charset="UTF-8">
        </head>
        <body>
            <h1>Welkom {{ user.name }}!</h1>
            <p>Beste {{ user.name }},</p>
            <p>Hartelijk welkom bij de Software Catalogus! Uw gebruikersaccount is
            succesvol aangemaakt voor {{ organization.name }}.</p>
            <p>U kunt nu:</p>
            <ul>
                <li>Inloggen op het platform</li>
                <li>Software beheren voor uw organisatie</li>
                <li>Deelnemen aan de open data gemeenschap</li>
                <li>Samenwerken met andere organisaties</li>
            </ul>
            <p><strong>Login gegevens:</strong></p>
            <ul>
                <li>E-mailadres: {{ user.email }}</li>
                <li>Wachtwoord: U ontvangt een apart e-mailadres met instructies voor het instellen van uw wachtwoord</li>
            </ul>
            <p>Heeft u vragen? Neem dan contact met ons op via info@conduction.nl</p>
            <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
        </body>
        </html>
    ';

	/**
	 * Email template for contact welcome emails (backward compatibility)
	 *
	 * @var string Contact welcome email template
	 */
	private const CONTACT_ADDED_TEMPLATE = '
        <html>
        <head>
            <title>Toegevoegd aan organisatie</title>
            <meta charset="UTF-8">
        </head>
        <body>
            <h1>Welkom {{ user.name }}!</h1>
            <p>Beste {{ user.name }},</p>
            <p>Uw gebruikersnaam is succesvol toegevoegd aan {{ organization.name }}.</p>
            <p>U kunt nu:</p>
            <ul>
                <li>Inloggen op het platform</li>
                <li>Software beheren voor uw organisatie</li>
                <li>Deelnemen aan de open data gemeenschap</li>
                <li>Samenwerken met andere organisaties</li>
            </ul>
            <p><strong>Login gegevens:</strong></p>
            <p>We hebben uw bestaande account gekoppeld aan een nieuwe organisatie.
            Uw inloggegevens zijn hetzelfde, maar u kunt nu uw organisatie wisselen tussen uw organisaties.</p>
            <p>Heeft u vragen? Neem dan contact met ons op via info@conduction.nl</p>
            <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
        </body>
        </html>
    ';

	/**
	 * Default sender email address
	 *
	 * @var string Default sender email
	 */
	private const DEFAULT_SENDER = 'noreply@softwarecatalogus.nl';

	/**
	 * Default sender name
	 *
	 * @var string Default sender name
	 */
	private const DEFAULT_SENDER_NAME = 'Software Catalogus';

	/**
	 * The organisation notices: the log prefix, the settings key that enables each,
	 * the words its log lines use, the default review state, the subject and the template.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const ORGANIZATION_NOTICES = [
		'registration' => [
			'readyPrefix' => 'OrganizationRegistrationEmail',
			'messagePrefix' => 'OrganizationRegistrationEmail',
			'settingsKey' => 'organizationRegistrationEnabled',
			'sendingKind' => 'registration',
			'sentKind' => 'registration',
			'beoordeling' => 'Geregistreerd',
			'subject' => 'Welkom bij de Software Catalogus - Organisatie Geregistreerd',
			'template' => 'organization_registration',
		],
		'activation' => [
			'readyPrefix' => 'OrganizationActivationEmail',
			'messagePrefix' => 'OrganizationActivationEmail',
			'settingsKey' => 'organizationActivationEnabled',
			'sendingKind' => 'activation',
			'sentKind' => 'activation',
			'beoordeling' => 'Active',
			'subject' => 'Software Catalogus - Organisatie Geactiveerd',
			'template' => 'organization_activation',
		],
	];

	/**
	 * The user notices: the readiness log prefix and the prefix of the other log lines,
	 * the settings key that enables each, the words its log lines use, the subject and the template.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const USER_NOTICES = [
		'creation' => [
			'readyPrefix' => 'UserCreationEmail',
			'messagePrefix' => 'UserCreationEmail',
			'settingsKey' => 'userCreationEnabled',
			'sendingKind' => 'user creation',
			'sentKind' => 'user creation',
			'subject' => 'Welkom bij de Software Catalogus - Account Aangemaakt',
			'template' => 'user_creation',
		],
		'organisation' => [
			'readyPrefix' => 'UserOrganisationEmail',
			'messagePrefix' => 'UserCreationEmail',
			'settingsKey' => 'userOrganisationEnabled',
			'sendingKind' => 'user creation',
			'sentKind' => 'user organisation',
			'subject' => 'Welkom bij de Software Catalogus - Account toegevoegd aan organisatie',
			'template' => 'user_organisation',
		],
	];

	/**
	 * Available Symfony Mailer transport types
	 *
	 * @var array<string, string> Transport type labels
	 */
	private const TRANSPORT_TYPES = [
		'smtp' => 'SMTP Server',
		'sendmail' => 'Sendmail',
		'native' => 'Native PHP Mail',
		'null' => 'Null (No Emails)',
		'sendgrid' => 'SendGrid',
		'mailgun' => 'Mailgun',
		'postmark' => 'Postmark',
		'ses' => 'Amazon SES',
		'mailjet' => 'Mailjet',
	];

	/**
	 * The settings each transport type needs to be non-empty; a type with an
	 * empty list needs no credentials, and an unknown type has no valid ones.
	 *
	 * @var array<string, string[]> Required setting keys per transport type
	 */
	private const TRANSPORT_CREDENTIAL_KEYS = [
		'mailjet' => ['mailjetApiKey', 'mailjetSecretKey'],
		'sendgrid' => ['sendgridApiKey'],
		'mailgun' => ['mailgunApiKey', 'mailgunDomain'],
		'postmark' => ['postmarkApiKey'],
		'ses' => ['sesAccessKey', 'sesSecretKey'],
		'smtp' => ['smtpHost'],
		'sendmail' => [],
		'native' => [],
		'null' => [],
	];

	/**
	 * The stored e-mail settings
	 *
	 * @var EmailSettings E-mail settings instance
	 */
	private readonly EmailSettings $emailSettings;

	/**
	 * Cached Symfony Mailer instance
	 *
	 * @var Mailer|null Cached mailer instance
	 */
	private ?Mailer $mailer = null;

	/**
	 * Constructor for SymfonyEmailService
	 *
	 * @param IAppConfig $config The Nextcloud configuration service
	 * @param LoggerInterface $logger The logger instance
	 * @param EmailSettings $emailSettings The stored e-mail settings
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
		EmailSettings $emailSettings,
	) {
		$this->emailSettings = $emailSettings;
	}//end __construct()

	/**
	 * Gets or creates a Symfony Mailer instance with the configured transport.
	 *
	 * @return Mailer The configured Symfony Mailer instance.
	 *
	 * @throws \Exception If transport configuration is invalid.
	 */
	private function getMailer(): Mailer {
		if ($this->mailer === null) {
			$transport = $this->createTransport();
			$this->mailer = new Mailer($transport);
		}

		return $this->mailer;
	}//end getMailer()

	/**
	 * Creates a Symfony Mailer transport based on configuration.
	 *
	 * @return TransportInterface The configured transport.
	 *
	 * @throws \Exception If transport configuration is invalid.
	 */
	private function createTransport(): TransportInterface {
		$factory = new MailTransportFactory(logger: $this->logger);
		return $factory->create(emailSettings: $this->emailSettings->getEmailSettings());
	}//end createTransport()

	/**
	 * Sends an organization registration email.
	 *
	 * @param array $organization The organization data.
	 *
	 * @return bool True if email was sent successfully, false otherwise.
	 *
	 * @throws \Exception If email sending fails.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function sendOrganizationRegistrationEmail(array $organization): bool {
		return $this->sendOrganizationNotice(
			notice: new NoticeEmail(notice: self::ORGANIZATION_NOTICES['registration'], data: $organization)
		);
	}//end sendOrganizationRegistrationEmail()

	/**
	 * Sends an organization activation email.
	 *
	 * @param array $organization The organization data.
	 *
	 * @return bool True if email was sent successfully, false otherwise.
	 *
	 * @throws \Exception If email sending fails.
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function sendOrganizationActivationEmail(array $organization): bool {
		return $this->sendOrganizationNotice(
			notice: new NoticeEmail(notice: self::ORGANIZATION_NOTICES['activation'], data: $organization)
		);
	}//end sendOrganizationActivationEmail()

	/**
	 * Sends a user creation email.
	 *
	 * @param array $user The user data.
	 * @param array $organization The organization data (optional).
	 *
	 * @return bool True if email was sent successfully, false otherwise.
	 *
	 * @throws \Exception If email sending fails.
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function sendUserCreationEmail(array $user, array $organization = []): bool {
		$notice = new NoticeEmail(notice: self::USER_NOTICES['creation'], data: $user);
		$notice->organizationName = ($organization['name'] ?? 'Software Catalogus');

		return $this->sendUserNotice(notice: $notice);
	}//end sendUserCreationEmail()

	/**
	 * Sends a user update/organisation email.
	 *
	 * @param array $user The user data.
	 * @param array $organization The organization data (optional).
	 *
	 * @return bool True if email was sent successfully, false otherwise.
	 *
	 * @throws \Exception If email sending fails.
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function sendUserUpdateEmail(array $user, array $organization = []): bool {
		$notice = new NoticeEmail(notice: self::USER_NOTICES['organisation'], data: $user);
		$notice->organizationName = ($organization['name'] ?? 'Software Catalogus');

		return $this->sendUserNotice(notice: $notice);
	}//end sendUserUpdateEmail()

	/**
	 * Send an organisation notice: check delivery is ready, address the organisation, log and deliver.
	 *
	 * @param NoticeEmail $notice The notice and the organization data.
	 *
	 * @return bool True if the email was sent, false otherwise.
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	private function sendOrganizationNotice(NoticeEmail $notice): bool {
		$configStatus = $this->getReadyConfigStatus(notice: $notice, extraLogContext: ['organizationName' => $notice->data['name'] ?? 'Unknown']);
		if ($configStatus === null) {
			return false;
		}

		$notice->recipientName = ($notice->data['name'] ?? 'Onbekende Organisatie');
		if ($notice->addressOrganization(logger: $this->logger, recipientEmail: $this->getRecipientEmail(data: $notice->data)) === false) {
			return false;
		}

		$notice->logSending(logger: $this->logger, configStatus: $configStatus);
		$notice->templateData = $notice->organizationTemplateData();

		return $this->deliverNotice(notice: $notice);
	}//end sendOrganizationNotice()

	/**
	 * Send a user notice: check delivery is ready, address the user, log and deliver.
	 *
	 * @param NoticeEmail $notice The notice, the user data and the organisation name.
	 *
	 * @return bool True if the email was sent, false otherwise.
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	private function sendUserNotice(NoticeEmail $notice): bool {
		$configStatus = $this->getReadyConfigStatus(notice: $notice, extraLogContext: ['userEmail' => $notice->data['email'] ?? 'Unknown']);
		if ($configStatus === null) {
			return false;
		}

		if ($notice->addressUser(logger: $this->logger) === false) {
			return false;
		}

		$notice->logSending(logger: $this->logger, configStatus: $configStatus);
		$notice->describeUserNotice();

		return $this->deliverNotice(notice: $notice);
	}//end sendUserNotice()

	/**
	 * The configuration status when the email system is configured and this notice type is enabled; logs why not.
	 *
	 * @param NoticeEmail $notice The notice.
	 * @param array $extraLogContext The context the readiness log lines add.
	 *
	 * @return array|null The configuration status, or null when the notice is not sent.
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	private function getReadyConfigStatus(NoticeEmail $notice, array $extraLogContext): ?array {
		return $this->ensureEmailDeliveryReady(
			logPrefix: $notice->notice['readyPrefix'],
			settingsKey: $notice->notice['settingsKey'],
			extraLogContext: $extraLogContext
		);
	}//end getReadyConfigStatus()

	/**
	 * Send the templated email of a notice and log the outcome; a failure is logged and answered with false.
	 *
	 * @param NoticeEmail $notice The addressed and described notice.
	 *
	 * @return bool True if the email was sent, false otherwise.
	 *
	 * @spec openspec/specs/email-delivery/spec.md
	 */
	private function deliverNotice(NoticeEmail $notice): bool {
		try {
			$success = $this->sendTemplatedEmail(notice: $notice);
			if ($success === true) {
				$this->logger->info($notice->notice['messagePrefix'] . ': Successfully sent ' . $notice->notice['sentKind'] . ' email', $notice->logContext);
			}

			return $success;
		} catch (\Exception $e) {
			$this->logger->error(
				$notice->notice['messagePrefix'] . ': Failed to send ' . $notice->notice['sentKind'] . ' email',
				array_merge($notice->logContext, ['error' => $e->getMessage()])
			);
			return false;
		}//end try
	}//end deliverNotice()

	/**
	 * Sends the templated email of a notice using the configured templates.
	 *
	 * @param NoticeEmail $notice The addressed and described notice: recipient, subject, template and its data.
	 *
	 * @return bool True if email was sent successfully, false otherwise.
	 *
	 * @throws \Exception If email sending fails.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function sendTemplatedEmail(NoticeEmail $notice): bool {
		try {
			return $this->sendRenderedNotice(notice: $notice);
		} catch (\Exception $e) {
			$notice->logTemplatedFailure(logger: $this->logger, error: $e);
			throw $e;
		}
	}//end sendTemplatedEmail()

	/**
	 * Render the template of a notice and send it.
	 *
	 * @param NoticeEmail $notice The addressed and described notice.
	 *
	 * @return bool True if email was sent successfully.
	 *
	 * @throws \Exception If email sending fails.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function sendRenderedNotice(NoticeEmail $notice): bool {
		$htmlBody = $this->renderTemplate(
			templateName: $notice->notice['template'],
			templateData: $notice->templateData
		);

		return $this->sendEmail(
			recipientEmail: $notice->recipientEmail,
			recipientName: $notice->recipientName,
			subject: $notice->notice['subject'],
			htmlBody: $htmlBody
		);
	}//end sendRenderedNotice()

	/**
	 * Render a named template against the provided data.
	 *
	 * Single-purpose helper extracted from `sendTemplatedEmail()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 7.5: combines
	 * template resolution (custom override from email settings, fallback to
	 * the built-in default) with variable substitution.
	 *
	 * @param string $templateName The template name.
	 * @param array $templateData The data to substitute into the template.
	 *
	 * @return string The rendered HTML body.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7-5
	 */
	private function renderTemplate(string $templateName, array $templateData): string {
		$emailSettings = $this->emailSettings->getEmailSettings();
		$templates = ($emailSettings['templates'] ?? []);
		$templateContent = ($templates[$templateName] ?? $this->getDefaultTemplate(templateName: $templateName));

		return $this->processTemplate(
			template: $templateContent,
			templateData: $templateData
		);
	}//end renderTemplate()

	/**
	 * Resolve the configured sender for outbound mail.
	 *
	 * Single-purpose helper extracted from `sendEmail()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 7.5.
	 *
	 * @return array{0:string,1:string,2:string} Tuple of [senderEmail,
	 *                                           senderName, transportType]
	 *                                           with sane fallbacks when
	 *                                           unset in settings.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7-5
	 */
	private function resolveSender(): array {
		$emailSettings = $this->emailSettings->getEmailSettings();
		$senderEmail = ($emailSettings['senderEmail'] ?? self::DEFAULT_SENDER);
		$senderName = ($emailSettings['senderName'] ?? self::DEFAULT_SENDER_NAME);
		$transportType = ($emailSettings['transportType'] ?? 'smtp');

		return [$senderEmail, $senderName, $transportType];
	}//end resolveSender()

	/**
	 * Gets the default template for a given template name.
	 *
	 * @param string $templateName The template name.
	 *
	 * @return string The default template content.
	 */
	private function getDefaultTemplate(string $templateName): string {
		return match ($templateName) {
			'organization_registration' => self::ORGANIZATION_WELCOME_TEMPLATE,
			'organization_activation' => self::ORGANIZATION_ACTIVATION_TEMPLATE,
			'user_creation' => self::GEBRUIKER_WELCOME_TEMPLATE,
			'user_organisation' => self::CONTACT_ADDED_TEMPLATE,
			default => self::ORGANIZATION_WELCOME_TEMPLATE,
		};
	}//end getDefaultTemplate()

	/**
	 * Processes a template by replacing variables with actual data.
	 *
	 * @param string $template The template content.
	 * @param array $templateData The data to replace in the template.
	 *
	 * @return string The processed template.
	 */
	private function processTemplate(string $template, array $templateData): string {
		$processed = $template;

		// Replace organization variables.
		if (isset($templateData['organization']) === true) {
			$org = $templateData['organization'];
			$processed = str_replace(
				search: '{{ organization.name }}',
				replace: htmlspecialchars((string)($org['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				subject: $processed
			);
		}

		// Replace user variables — never include passwords in email output.
		if (isset($templateData['user']) === true) {
			$user = $templateData['user'];
			$processed = str_replace(
				search: '{{ user.name }}',
				replace: htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				subject: $processed
			);
			$processed = str_replace(
				search: '{{ user.email }}',
				replace: htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				subject: $processed
			);
			// Remove any {{ user.password }} placeholder that may appear in custom templates
			// to prevent accidental credential exposure.
			$processed = str_replace(search: '{{ user.password }}', replace: '', subject: $processed);
		}

		return $processed;
	}//end processTemplate()

	/**
	 * Gets recipient email from organization or user data.
	 *
	 * @param array $data The organization or user data.
	 *
	 * @return string|null The recipient email address or null if not found.
	 */
	private function getRecipientEmail(array $data): ?string {
		// Check for test receiver override.
		$testReceiver = $this->getTestReceiverOverride();
		if ($testReceiver !== null) {
			return $testReceiver;
		}

		// Try to get email from various fields; if no direct email, try to get from contactpersonen.
		$email = ($data['e-mailadres'] ?? null);
		if ($email === null) {
			$email = $this->contactPersonEmail(data: $data);
		}

		return $this->validRecipient(email: $email);
	}//end getRecipientEmail()

	/**
	 * The address when it is a valid email address, else null.
	 *
	 * @param mixed $email The candidate address.
	 *
	 * @return string|null
	 */
	private function validRecipient(mixed $email): ?string {
		if ($email !== null && $this->validateEmail(email: $email) === true) {
			return $email;
		}

		return null;
	}//end validRecipient()

	/**
	 * The first non-empty email address among the contact persons.
	 *
	 * @param array $data The organization or user data.
	 *
	 * @return mixed The address, or null when no contact person has one.
	 */
	private function contactPersonEmail(array $data): mixed {
		foreach ($this->contactPersons(data: $data) as $contact) {
			if (is_array($contact) === true && empty($contact['e-mailadres']) === false) {
				return $contact['e-mailadres'];
			}
		}

		return null;
	}//end contactPersonEmail()

	/**
	 * The contact persons of the data, or an empty list when it has none.
	 *
	 * @param array $data The organization or user data.
	 *
	 * @return array
	 */
	private function contactPersons(array $data): array {
		if (isset($data['contactpersonen']) === true && is_array($data['contactpersonen']) === true) {
			return $data['contactpersonen'];
		}

		return [];
	}//end contactPersons()

	/**
	 * Gets the test receiver override email address.
	 *
	 * @return string|null The test receiver override email address or null if not set.
	 */
	private function getTestReceiverOverride(): ?string {
		$emailSettings = $this->emailSettings->getEmailSettings();
		$override = ($emailSettings['testReceiverOverride'] ?? '');

		if (empty($override) === false && $this->validateEmail(email: $override) === true) {
			return $override;
		}

		return null;
	}//end getTestReceiverOverride()

	/**
	 * Sends an email using Symfony Mailer.
	 *
	 * @param string $recipientEmail The recipient email address.
	 * @param string $recipientName The recipient name.
	 * @param string $subject The email subject.
	 * @param string $htmlBody The HTML email body.
	 *
	 * @return bool True if email was sent successfully, false otherwise.
	 *
	 * @throws \Exception If email sending fails.
	 */
	private function sendEmail(
		string $recipientEmail,
		string $recipientName,
		string $subject,
		string $htmlBody,
	): bool {
		try {
			[$senderEmail, $senderName, $transportType] = $this->resolveSender();

			// Create Symfony Email.
			$email = (new Email())
				->from(new Address(address: $senderEmail, name: $senderName))
				->to(new Address(address: $recipientEmail, name: $recipientName))
				->subject($subject)
				->html($htmlBody)
				->text(strip_tags($htmlBody));
			// Fallback text version.
			// Send email using Symfony Mailer.
			$this->getMailer()->send($email);

			$this->logger->info(
				'Email sent successfully using Symfony Mailer',
				[
					'recipient' => $recipientEmail,
					'subject' => $subject,
					'sender' => $senderEmail,
					'transport' => $transportType,
				]
			);

			return true;
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to send email using Symfony Mailer',
				[
					'recipient' => $recipientEmail,
					'subject' => $subject,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			throw $e;
		}//end try
	}//end sendEmail()

	/**
	 * Validates email address format.
	 *
	 * @param string $email The email address to validate.
	 *
	 * @return bool True if email is valid, false otherwise.
	 */
	private function validateEmail(string $email): bool {
		return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
	}//end validateEmail()

	/**
	 * Gets the configured sender email address.
	 *
	 * @return string The sender email address.
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function getSenderEmail(): string {
		$emailSettings = $this->emailSettings->getEmailSettings();
		return $emailSettings['senderEmail'] ?? self::DEFAULT_SENDER;
	}//end getSenderEmail()

	/**
	 * Gets the configured sender name.
	 *
	 * @return string The sender name.
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function getSenderName(): string {
		$emailSettings = $this->emailSettings->getEmailSettings();
		return $emailSettings['senderName'] ?? self::DEFAULT_SENDER_NAME;
	}//end getSenderName()

	/**
	 * Gets all email settings including transport configuration.
	 *
	 * @return array The email settings.
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function getEmailSettings(): array {
		$settings = $this->emailSettings->getEmailSettings();

		// Add transport information.
		$settings['availableTransports'] = self::TRANSPORT_TYPES;
		$settings['transportType'] = ($settings['transportType'] ?? 'smtp');

		return $settings;
	}//end getEmailSettings()

	/**
	 * Gets available transport types.
	 *
	 * @return array<string, string> Available transport types.
	 */
	public function getAvailableTransports(): array {
		return self::TRANSPORT_TYPES;
	}//end getAvailableTransports()

	/**
	 * Sets the sender email address in app configuration.
	 *
	 * @param string $email The sender email address.
	 *
	 * @return void
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function setSenderEmail(string $email): void {
		if ($this->validateEmail(email: $email) === false) {
			throw new \InvalidArgumentException('Invalid email address: ' . $email);
		}

		$this->emailSettings->updateEmailSettings(['senderEmail' => $email]);
		$this->mailer = null;
		// Reset mailer to pick up new settings.
	}//end setSenderEmail()

	/**
	 * Sets the sender name in app configuration.
	 *
	 * @param string $name The sender name.
	 *
	 * @return void
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function setSenderName(string $name): void {
		$this->emailSettings->updateEmailSettings(['senderName' => $name]);
		$this->mailer = null;
		// Reset mailer to pick up new settings.
	}//end setSenderName()

	/**
	 * Sets the transport type and related configuration.
	 *
	 * @param string $transportType The transport type.
	 * @param array $transportConfig Transport-specific configuration.
	 *
	 * @return void
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function setTransportConfiguration(string $transportType, array $transportConfig = []): void {
		if (isset(self::TRANSPORT_TYPES[$transportType]) === false) {
			throw new \InvalidArgumentException('Invalid transport type: ' . $transportType);
		}

		$settings = ['transportType' => $transportType] + $transportConfig;
		$this->emailSettings->updateEmailSettings($settings);
		$this->mailer = null;
		// Reset mailer to pick up new settings.
	}//end setTransportConfiguration()

	/**
	 * Tests if the email system is working by sending a test email.
	 *
	 * @param string $testEmail The email address to send test email to.
	 *
	 * @return bool True if test email was sent successfully.
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function sendTestEmail(string $testEmail): bool {
		if ($this->validateEmail(email: $testEmail) === false) {
			$this->logger->error('Invalid test email address', ['email' => $testEmail]);
			return false;
		}

		$subject = 'Software Catalogus - Test Email (Symfony Mailer)';
		$emailSettings = $this->getEmailSettings();
		$transportType = ($emailSettings['transportType'] ?? 'smtp');

		$htmlBody = '
            <html>
            <head>
                <title>Test Email</title>
                <meta charset="UTF-8">
            </head>
            <body>
                <h1>Test Email - Symfony Mailer</h1>
                <p>Dit is een test email van de Software Catalogus.</p>
                <p>Als u deze email ontvangt, werkt het email systeem correct.</p>
                <p><strong>Transport Type:</strong> ' . htmlspecialchars($transportType) . '
                    (' . htmlspecialchars(self::TRANSPORT_TYPES[$transportType] ?? 'Unknown') . ')</p>
                <p><strong>Datum:</strong> ' . date('Y-m-d H:i:s') . '</p>
                <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            </body>
            </html>
        ';

		try {
			return $this->sendEmail(
				recipientEmail: $testEmail,
				recipientName: 'Test Recipient',
				subject: $subject,
				htmlBody: $htmlBody
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Test email failed',
				[
					'testEmail' => $testEmail,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}
	}//end sendTestEmail()

	/**
	 * Sets whether email notifications are enabled.
	 *
	 * @param bool $enabled True to enable email notifications, false to disable.
	 *
	 * @return void
	 */
	public function setEnabled(bool $enabled): void {
		$this->emailSettings->updateEmailSettings(['enabled' => $enabled]);
	}//end setEnabled()

	/**
	 * Sets the test receiver override email address.
	 *
	 * @param string $email The test receiver override email address.
	 *
	 * @return void
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function setTestReceiverOverride(string $email): void {
		if (empty($email) === false && $this->validateEmail(email: $email) === false) {
			throw new \InvalidArgumentException('Invalid test receiver override email address: ' . $email);
		}

		$this->emailSettings->updateEmailSettings(['testReceiverOverride' => $email]);
	}//end setTestReceiverOverride()

	/**
	 * Sets whether organization registration emails are enabled.
	 *
	 * @param bool $enabled True to enable organization registration emails, false to disable.
	 *
	 * @return void
	 */
	public function setOrganizationRegistrationEnabled(bool $enabled): void {
		$this->emailSettings->updateEmailSettings(['organizationRegistrationEnabled' => $enabled]);
	}//end setOrganizationRegistrationEnabled()

	/**
	 * Sets whether organization activation emails are enabled.
	 *
	 * @param bool $enabled True to enable organization activation emails, false to disable.
	 *
	 * @return void
	 */
	public function setOrganizationActivationEnabled(bool $enabled): void {
		$this->emailSettings->updateEmailSettings(['organizationActivationEnabled' => $enabled]);
	}//end setOrganizationActivationEnabled()

	/**
	 * Sets whether user creation emails are enabled.
	 *
	 * @param bool $enabled True to enable user creation emails, false to disable.
	 *
	 * @return void
	 */
	public function setUserCreationEnabled(bool $enabled): void {
		$this->emailSettings->updateEmailSettings(['userCreationEnabled' => $enabled]);
	}//end setUserCreationEnabled()

	/**
	 * Checks if the email system is fully configured with credentials and templates.
	 *
	 * @return array Configuration status with details.
	 * @spec   openspec/specs/email-delivery/spec.md
	 */
	public function isEmailSystemConfigured(): array {
		$emailSettings = $this->emailSettings->getEmailSettings();

		// Check if emails are enabled.
		if (($emailSettings['enabled'] ?? false) === false) {
			return [
				'configured' => false,
				'reason' => 'Email notifications are disabled',
				'hasCredentials' => false,
				'hasTemplates' => false,
			];
		}

		// Check transport credentials.
		$hasCredentials = $this->hasValidTransportCredentials(emailSettings: $emailSettings);

		// Check templates.
		$hasTemplates = $this->hasValidTemplates(emailSettings: $emailSettings);

		$configured = ($hasCredentials === true && $hasTemplates === true);

		$reason = $this->getConfigurationIssues(
			hasCredentials: $hasCredentials,
			hasTemplates: $hasTemplates
		);
		if ($configured === true) {
			$reason = 'Email system fully configured';
		}

		return [
			'configured' => $configured,
			'reason' => $reason,
			'hasCredentials' => $hasCredentials,
			'hasTemplates' => $hasTemplates,
			'transportType' => ($emailSettings['transportType'] ?? 'smtp'),
		];
	}//end isEmailSystemConfigured()

	/**
	 * Checks if the current transport has valid credentials.
	 *
	 * @param array $emailSettings Email settings.
	 *
	 * @return bool True if credentials are valid for the current transport.
	 */
	private function hasValidTransportCredentials(array $emailSettings): bool {
		$transportType = ($emailSettings['transportType'] ?? 'smtp');
		$requiredKeys  = (self::TRANSPORT_CREDENTIAL_KEYS[$transportType] ?? null);
		if ($requiredKeys === null) {
			return false;
		}

		// Sendmail, native and null don't require credentials.
		foreach ($requiredKeys as $key) {
			if (empty($emailSettings[$key]) === true) {
				return false;
			}
		}

		return true;
	}//end hasValidTransportCredentials()

	/**
	 * Checks if required templates are configured.
	 *
	 * @param array $emailSettings Email settings.
	 *
	 * @return bool True if templates are configured.
	 */
	private function hasValidTemplates(array $emailSettings): bool {
		$templates = ($emailSettings['templates'] ?? []);
		$requiredTemplates = ['organization_registration', 'organization_activation', 'user_creation'];

		foreach ($requiredTemplates as $templateName) {
			$template = ($templates[$templateName] ?? '');
			// Template is valid if it's not empty or if we have a default template.
			$defaultTpl = $this->getDefaultTemplate(templateName: $templateName);
			if (empty($template) === true && empty($defaultTpl) === true) {
				return false;
			}
		}

		return true;
	}//end hasValidTemplates()

	/**
	 * Gets configuration issues description.
	 *
	 * @param bool $hasCredentials Whether credentials are configured.
	 * @param bool $hasTemplates Whether templates are configured.
	 *
	 * @return string Description of configuration issues.
	 */
	private function getConfigurationIssues(bool $hasCredentials, bool $hasTemplates): string {
		$issues = [];

		if ($hasCredentials === false) {
			$issues[] = 'missing transport credentials';
		}

		if ($hasTemplates === false) {
			$issues[] = 'missing email templates';
		}

		return 'Configuration incomplete: ' . implode(separator: ', ', array: $issues);
	}//end getConfigurationIssues()

	/**
	 * Combined precheck that the email delivery pipeline is ready for a given
	 * message type.
	 *
	 * Replaces the two-stage check ("isEmailSystemConfigured" + per-type
	 * `$emailSettings[$key] === false`) that every `send*Email()` opens with.
	 * Returns the configStatus array on success (caller still needs it for the
	 * transportType log entry), or null when delivery should be skipped — in
	 * which case the appropriate `info` log entry has already been emitted.
	 *
	 * Extracted from {@see sendOrganizationRegistrationEmail()},
	 * {@see sendOrganizationActivationEmail()},
	 * {@see sendUserCreationEmail()}, and {@see sendUserUpdateEmail()} as part
	 * of task 7.5.
	 *
	 * @param string $logPrefix Log-message prefix
	 *                          (e.g. "OrganizationRegistrationEmail").
	 * @param string $settingsKey The per-type "enabled" key on
	 *                            the EmailSettings array.
	 * @param array<string, mixed> $extraLogContext Extra fields to attach to the
	 *                                              log payloads.
	 *
	 * @return array<string, mixed>|null The configStatus array, or null on skip.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	private function ensureEmailDeliveryReady(
		string $logPrefix,
		string $settingsKey,
		array $extraLogContext,
	): ?array {
		$configStatus = $this->isEmailSystemConfigured();
		if ($configStatus['configured'] === false) {
			$this->logger->info(
				$logPrefix . ': Email system not configured, skipping',
				array_merge(
					[
						'reason' => $configStatus['reason'],
						'hasCredentials' => $configStatus['hasCredentials'],
						'hasTemplates' => $configStatus['hasTemplates'],
					],
					$extraLogContext
				)
			);
			return null;
		}

		$emailSettings = $this->emailSettings->getEmailSettings();
		if (($emailSettings[$settingsKey] ?? null) === false) {
			$this->logger->info(
				$logPrefix . ': Delivery disabled for this message type',
				$extraLogContext
			);
			return null;
		}

		return $configStatus;
	}//end ensureEmailDeliveryReady()
}//end class
