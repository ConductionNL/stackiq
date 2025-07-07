<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use Psr\Log\LoggerInterface;
use OCP\IConfig;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Symfony Mailer-based email service for sending notification emails
 * 
 * This service handles sending various types of notification emails
 * using Symfony Mailer with configurable transports including SMTP,
 * SendGrid, Mailgun, and other providers.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */
class SymfonyEmailService
{
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
            <h1>Welkom {ORGANIZATION_NAME}!</h1>
            <p>Beste {ORGANIZATION_NAME},</p>
            <p>Hartelijk welkom bij de Software Catalogus! Uw organisatie is succesvol geregistreerd.</p>
            <p>Met de Software Catalogus kunt u:</p>
            <ul>
                <li>Uw software overzichtelijk beheren</li>
                <li>Software delen met andere organisaties</li>
                <li>Ontdekken welke software andere organisaties gebruiken</li>
            </ul>
            <p>U kunt nu inloggen op het platform en uw software catalogus beheren.</p>
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
            <h1>Welkom {USER_NAME}!</h1>
            <p>Beste {USER_NAME},</p>
            <p>Hartelijk welkom bij de Software Catalogus! Uw gebruikersaccount is succesvol aangemaakt.</p>
            <p>U kunt nu:</p>
            <ul>
                <li>Inloggen op het platform</li>
                <li>Software beheren voor uw organisatie</li>
                <li>Deelnemen aan de open data gemeenschap</li>
            </ul>
            <p>Login gegevens:</p>
            <ul>
                <li>E-mailadres: {USER_EMAIL}</li>
                <li>Wachtwoord: U ontvangt een apart e-mailadres met instructies voor het instellen van uw wachtwoord</li>
            </ul>
            <p>Heeft u vragen? Neem dan contact met ons op via info@conduction.nl</p>
            <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
        </body>
        </html>
    ';

    /**
     * Email template for contact welcome emails
     *
     * @var string Contact welcome email template
     */
    private const CONTACT_WELCOME_TEMPLATE = '
        <html>
        <head>
            <title>Welkom bij de Software Catalogus</title>
            <meta charset="UTF-8">
        </head>
        <body>
            <h1>Welkom {CONTACT_NAME}!</h1>
            <p>Beste {CONTACT_NAME},</p>
            <p>U bent toegevoegd als contactpersoon in de Software Catalogus.</p>
            <p>Er is automatisch een gebruikersaccount voor u aangemaakt waarmee u kunt inloggen op het platform.</p>
            <p>U kunt nu:</p>
            <ul>
                <li>Inloggen op het platform</li>
                <li>Software informatie beheren</li>
                <li>Samenwerken met andere organisaties</li>
            </ul>
            <p>Login gegevens:</p>
            <ul>
                <li>E-mailadres: {CONTACT_EMAIL}</li>
                <li>Wachtwoord: U ontvangt een apart e-mailadres met instructies voor het instellen van uw wachtwoord</li>
            </ul>
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
     * The settings service for accessing email configuration
     *
     * @var SettingsService Settings service instance
     */
    private readonly SettingsService $settingsService;

    /**
     * Cached Symfony Mailer instance
     *
     * @var Mailer|null Cached mailer instance
     */
    private ?Mailer $mailer = null;

    /**
     * Constructor for SymfonyEmailService
     *
     * @param IConfig         $config          The Nextcloud configuration service
     * @param LoggerInterface $logger          The logger instance
     * @param SettingsService $settingsService The settings service for email configuration
     */
    public function __construct(
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
        SettingsService $settingsService,
    ) {
        $this->settingsService = $settingsService;
    }

    /**
     * Gets or creates a Symfony Mailer instance with the configured transport
     *
     * @return Mailer The configured Symfony Mailer instance
     * @throws \Exception If transport configuration is invalid
     */
    private function getMailer(): Mailer
    {
        if ($this->mailer === null) {
            $transport = $this->createTransport();
            $this->mailer = new Mailer($transport);
        }

        return $this->mailer;
    }

    /**
     * Creates a Symfony Mailer transport based on configuration
     *
     * @return TransportInterface The configured transport
     * @throws \Exception If transport configuration is invalid
     */
    private function createTransport(): TransportInterface
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        $transportType = $emailSettings['transportType'] ?? 'smtp';
        
        try {
            switch ($transportType) {
                case 'smtp':
                    return $this->createSmtpTransport($emailSettings);
                case 'sendmail':
                    return Transport::fromDsn('sendmail://default');
                case 'native':
                    return Transport::fromDsn('native://default');
                case 'null':
                    return Transport::fromDsn('null://null');
                case 'sendgrid':
                    return $this->createSendGridTransport($emailSettings);
                case 'mailgun':
                    return $this->createMailgunTransport($emailSettings);
                case 'postmark':
                    return $this->createPostmarkTransport($emailSettings);
                case 'ses':
                    return $this->createSesTransport($emailSettings);
                case 'mailjet':
                    return $this->createMailjetTransport($emailSettings);
                default:
                    $this->logger->warning('Unknown transport type, falling back to SMTP', [
                        'transportType' => $transportType
                    ]);
                    return $this->createSmtpTransport($emailSettings);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to create email transport', [
                'transportType' => $transportType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Creates an SMTP transport
     *
     * @param array $settings Email settings
     * @return TransportInterface SMTP transport
     */
    private function createSmtpTransport(array $settings): TransportInterface
    {
        $host = $settings['smtpHost'] ?? 'localhost';
        $port = $settings['smtpPort'] ?? 587;
        $encryption = $settings['smtpEncryption'] ?? 'tls';
        $username = $settings['smtpUsername'] ?? '';
        $password = $settings['smtpPassword'] ?? '';

        $dsn = sprintf(
            'smtp://%s:%s@%s:%d',
            urlencode($username),
            urlencode($password),
            $host,
            $port
        );

        if ($encryption === 'ssl') {
            $dsn = str_replace('smtp://', 'smtps://', $dsn);
        }

        return Transport::fromDsn($dsn);
    }

    /**
     * Creates a SendGrid transport
     *
     * @param array $settings Email settings
     * @return TransportInterface SendGrid transport
     */
    private function createSendGridTransport(array $settings): TransportInterface
    {
        $apiKey = $settings['sendgridApiKey'] ?? '';
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('SendGrid API key is required');
        }

        return Transport::fromDsn('sendgrid+api://' . urlencode($apiKey) . '@default');
    }

    /**
     * Creates a Mailgun transport
     *
     * @param array $settings Email settings
     * @return TransportInterface Mailgun transport
     */
    private function createMailgunTransport(array $settings): TransportInterface
    {
        $apiKey = $settings['mailgunApiKey'] ?? '';
        $domain = $settings['mailgunDomain'] ?? '';
        
        if (empty($apiKey) || empty($domain)) {
            throw new \InvalidArgumentException('Mailgun API key and domain are required');
        }

        return Transport::fromDsn(sprintf(
            'mailgun+api://%s:%s@default',
            urlencode($apiKey),
            urlencode($domain)
        ));
    }

    /**
     * Creates a Postmark transport
     *
     * @param array $settings Email settings
     * @return TransportInterface Postmark transport
     */
    private function createPostmarkTransport(array $settings): TransportInterface
    {
        $apiKey = $settings['postmarkApiKey'] ?? '';
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('Postmark API key is required');
        }

        return Transport::fromDsn('postmark+api://' . urlencode($apiKey) . '@default');
    }

    /**
     * Creates an Amazon SES transport
     *
     * @param array $settings Email settings
     * @return TransportInterface SES transport
     */
    private function createSesTransport(array $settings): TransportInterface
    {
        $accessKey = $settings['sesAccessKey'] ?? '';
        $secretKey = $settings['sesSecretKey'] ?? '';
        $region = $settings['sesRegion'] ?? 'us-east-1';
        
        if (empty($accessKey) || empty($secretKey)) {
            throw new \InvalidArgumentException('Amazon SES access key and secret key are required');
        }

        return Transport::fromDsn(sprintf(
            'ses+api://%s:%s@default?region=%s',
            urlencode($accessKey),
            urlencode($secretKey),
            urlencode($region)
        ));
    }

    /**
     * Creates a Mailjet transport
     *
     * @param array $settings Email settings
     * @return TransportInterface Mailjet transport
     */
    private function createMailjetTransport(array $settings): TransportInterface
    {
        $apiKey = $settings['mailjetApiKey'] ?? '';
        $secretKey = $settings['mailjetSecretKey'] ?? '';
        
        if (empty($apiKey) || empty($secretKey)) {
            throw new \InvalidArgumentException('Mailjet API key and secret key are required');
        }

        return Transport::fromDsn(sprintf(
            'mailjet+api://%s:%s@default',
            urlencode($apiKey),
            urlencode($secretKey)
        ));
    }

    /**
     * Sends an organization registration email
     *
     * @param array $organization The organization data
     * @return bool True if email was sent successfully, false otherwise
     * @throws \Exception If email sending fails
     */
    public function sendOrganizationRegistrationEmail(array $organization): bool
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        
        // Check if email is enabled and organization registration emails are enabled
        if (!$emailSettings['enabled'] || !$emailSettings['organizationRegistrationEnabled']) {
            $this->logger->info('Organization registration email disabled', [
                'emailEnabled' => $emailSettings['enabled'],
                'orgRegistrationEnabled' => $emailSettings['organizationRegistrationEnabled']
            ]);
            return false;
        }

        $organizationName = $organization['naam'] ?? $organization['name'] ?? 'Onbekende Organisatie';
        
        // Determine recipient email
        $recipientEmail = $this->getRecipientEmail($organization);
        if (!$recipientEmail) {
            $this->logger->warning('Cannot send organization registration email without valid email address', [
                'organization' => $organization
            ]);
            return false;
        }

        // Prepare template data
        $templateData = [
            'organization' => [
                'name' => $organizationName,
                'beoordeling' => $organization['beoordeling'] ?? 'Geregistreerd',
                'type' => $organization['type'] ?? 'Organisatie',
                'website' => $organization['website'] ?? '',
            ]
        ];

        return $this->sendTemplatedEmail(
            $recipientEmail,
            $organizationName,
            'Welkom bij de Software Catalogus - Organisatie Geregistreerd',
            'organization_registration',
            $templateData
        );
    }

    /**
     * Sends an organization activation email
     *
     * @param array $organization The organization data
     * @return bool True if email was sent successfully, false otherwise
     * @throws \Exception If email sending fails
     */
    public function sendOrganizationActivationEmail(array $organization): bool
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        
        // Check if email is enabled and organization activation emails are enabled
        if (!$emailSettings['enabled'] || !$emailSettings['organizationActivationEnabled']) {
            $this->logger->info('Organization activation email disabled', [
                'emailEnabled' => $emailSettings['enabled'],
                'orgActivationEnabled' => $emailSettings['organizationActivationEnabled']
            ]);
            return false;
        }

        $organizationName = $organization['naam'] ?? $organization['name'] ?? 'Onbekende Organisatie';
        
        // Determine recipient email
        $recipientEmail = $this->getRecipientEmail($organization);
        if (!$recipientEmail) {
            $this->logger->warning('Cannot send organization activation email without valid email address', [
                'organization' => $organization
            ]);
            return false;
        }

        // Prepare template data
        $templateData = [
            'organization' => [
                'name' => $organizationName,
                'beoordeling' => $organization['beoordeling'] ?? 'Actief',
                'type' => $organization['type'] ?? 'Organisatie',
                'website' => $organization['website'] ?? '',
            ]
        ];

        return $this->sendTemplatedEmail(
            $recipientEmail,
            $organizationName,
            'Software Catalogus - Organisatie Geactiveerd',
            'organization_activation',
            $templateData
        );
    }

    /**
     * Sends a user creation email
     *
     * @param array $user         The user data
     * @param array $organization The organization data (optional)
     * @return bool True if email was sent successfully, false otherwise
     * @throws \Exception If email sending fails
     */
    public function sendUserCreationEmail(array $user, array $organization = []): bool
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        
        // Check if email is enabled and user creation emails are enabled
        if (!$emailSettings['enabled'] || !$emailSettings['userCreationEnabled']) {
            $this->logger->info('User creation email disabled', [
                'emailEnabled' => $emailSettings['enabled'],
                'userCreationEnabled' => $emailSettings['userCreationEnabled']
            ]);
            return false;
        }

        $userEmail = $user['email'] ?? '';
        $userName = $user['naam'] ?? $user['name'] ?? ($user['voornaam'] ?? '') . ' ' . ($user['achternaam'] ?? '');
        $userName = trim($userName);
        
        if (empty($userEmail)) {
            $this->logger->warning('Cannot send user creation email without email address', [
                'user' => $user
            ]);
            return false;
        }

        // Prepare template data
        $templateData = [
            'user' => [
                'name' => $userName ?: 'Gebruiker',
                'email' => $userEmail,
                'functie' => $user['functie'] ?? '',
            ],
            'organization' => [
                'name' => $organization['naam'] ?? $organization['name'] ?? 'Software Catalogus',
            ]
        ];

        return $this->sendTemplatedEmail(
            $userEmail,
            $userName ?: 'Gebruiker',
            'Welkom bij de Software Catalogus - Account Aangemaakt',
            'user_creation',
            $templateData
        );
    }

    /**
     * Sends a user password email
     *
     * @param array  $user         The user data
     * @param string $password     The generated password
     * @param array  $organization The organization data (optional)
     * @return bool True if email was sent successfully, false otherwise
     * @throws \Exception If email sending fails
     */
    public function sendUserPasswordEmail(array $user, string $password, array $organization = []): bool
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        
        // Check if email is enabled and user password emails are enabled
        if (!$emailSettings['enabled'] || !$emailSettings['userPasswordEnabled']) {
            $this->logger->info('User password email disabled', [
                'emailEnabled' => $emailSettings['enabled'],
                'userPasswordEnabled' => $emailSettings['userPasswordEnabled']
            ]);
            return false;
        }

        $userEmail = $user['email'] ?? '';
        $userName = $user['naam'] ?? $user['name'] ?? ($user['voornaam'] ?? '') . ' ' . ($user['achternaam'] ?? '');
        $userName = trim($userName);
        
        if (empty($userEmail)) {
            $this->logger->warning('Cannot send user password email without email address', [
                'user' => $user
            ]);
            return false;
        }

        // Prepare template data
        $templateData = [
            'user' => [
                'name' => $userName ?: 'Gebruiker',
                'email' => $userEmail,
                'password' => $password,
                'functie' => $user['functie'] ?? '',
            ],
            'organization' => [
                'name' => $organization['naam'] ?? $organization['name'] ?? 'Software Catalogus',
            ]
        ];

        return $this->sendTemplatedEmail(
            $userEmail,
            $userName ?: 'Gebruiker',
            'Software Catalogus - Inloggegevens',
            'user_password',
            $templateData
        );
    }

    /**
     * Sends a templated email using the configured templates
     *
     * @param string $recipientEmail The recipient email address
     * @param string $recipientName  The recipient name
     * @param string $subject        The email subject
     * @param string $templateName   The template name
     * @param array  $templateData   The template data
     * @return bool True if email was sent successfully, false otherwise
     * @throws \Exception If email sending fails
     */
    private function sendTemplatedEmail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $templateName,
        array $templateData
    ): bool {
        try {
            // Get template content from settings service
            $emailSettings = $this->settingsService->getEmailSettings();
            $templates = $emailSettings['templates'] ?? [];
            $templateContent = $templates[$templateName] ?? $this->getDefaultTemplate($templateName);

            // Replace template variables
            $htmlBody = $this->processTemplate($templateContent, $templateData);

            return $this->sendEmail(
                $recipientEmail,
                $recipientName,
                $subject,
                $htmlBody
            );

        } catch (\Exception $e) {
            $this->logger->error('Failed to send templated email', [
                'recipient' => $recipientEmail,
                'template' => $templateName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Gets the default template for a given template name
     *
     * @param string $templateName The template name
     * @return string The default template content
     */
    private function getDefaultTemplate(string $templateName): string
    {
        return match($templateName) {
            'organization_registration' => self::ORGANIZATION_WELCOME_TEMPLATE,
            'organization_activation' => self::ORGANIZATION_WELCOME_TEMPLATE,
            'user_creation' => self::GEBRUIKER_WELCOME_TEMPLATE,
            'user_password' => self::CONTACT_WELCOME_TEMPLATE,
            default => self::ORGANIZATION_WELCOME_TEMPLATE,
        };
    }

    /**
     * Processes a template by replacing variables with actual data
     *
     * @param string $template     The template content
     * @param array  $templateData The data to replace in the template
     * @return string The processed template
     */
    private function processTemplate(string $template, array $templateData): string
    {
        $processed = $template;
        
        // Replace organization variables
        if (isset($templateData['organization'])) {
            $org = $templateData['organization'];
            $processed = str_replace('{ORGANIZATION_NAME}', $org['name'] ?? '', $processed);
        }
        
        // Replace user variables
        if (isset($templateData['user'])) {
            $user = $templateData['user'];
            $processed = str_replace('{USER_NAME}', $user['name'] ?? '', $processed);
            $processed = str_replace('{USER_EMAIL}', $user['email'] ?? '', $processed);
            $processed = str_replace('{USER_PASSWORD}', $user['password'] ?? '', $processed);
        }
        
        // Replace contact variables (backward compatibility)
        if (isset($templateData['user'])) {
            $user = $templateData['user'];
            $processed = str_replace('{CONTACT_NAME}', $user['name'] ?? '', $processed);
            $processed = str_replace('{CONTACT_EMAIL}', $user['email'] ?? '', $processed);
        }

        return $processed;
    }

    /**
     * Gets recipient email from organization or user data
     *
     * @param array $data The organization or user data
     * @return string|null The recipient email address or null if not found
     */
    private function getRecipientEmail(array $data): ?string
    {
        // Check for test receiver override
        $testReceiver = $this->getTestReceiverOverride();
        if ($testReceiver) {
            return $testReceiver;
        }

        // Try to get email from various fields
        $email = $data['email'] ?? null;
        
        // If no direct email, try to get from contactpersonen
        if (!$email && isset($data['contactpersonen']) && is_array($data['contactpersonen'])) {
            foreach ($data['contactpersonen'] as $contact) {
                if (is_array($contact) && !empty($contact['email'])) {
                    $email = $contact['email'];
                    break;
                }
            }
        }

        return $email && $this->validateEmail($email) ? $email : null;
    }

    /**
     * Gets the test receiver override email address
     *
     * @return string|null The test receiver override email address or null if not set
     */
    private function getTestReceiverOverride(): ?string
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        $override = $emailSettings['testReceiverOverride'] ?? '';
        
        return !empty($override) && $this->validateEmail($override) ? $override : null;
    }

    /**
     * Sends an email using Symfony Mailer
     *
     * @param string $recipientEmail The recipient email address
     * @param string $recipientName  The recipient name
     * @param string $subject        The email subject
     * @param string $htmlBody       The HTML email body
     * @return bool True if email was sent successfully, false otherwise
     * @throws \Exception If email sending fails
     */
    private function sendEmail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $htmlBody
    ): bool {
        try {
            // Get sender configuration from settings service
            $emailSettings = $this->settingsService->getEmailSettings();
            $senderEmail = $emailSettings['senderEmail'] ?? self::DEFAULT_SENDER;
            $senderName = $emailSettings['senderName'] ?? self::DEFAULT_SENDER_NAME;

            // Create Symfony Email
            $email = (new Email())
                ->from(new Address($senderEmail, $senderName))
                ->to(new Address($recipientEmail, $recipientName))
                ->subject($subject)
                ->html($htmlBody)
                ->text(strip_tags($htmlBody)); // Fallback text version

            // Send email using Symfony Mailer
            $this->getMailer()->send($email);

            $this->logger->info('Email sent successfully using Symfony Mailer', [
                'recipient' => $recipientEmail,
                'subject' => $subject,
                'sender' => $senderEmail,
                'transport' => $emailSettings['transportType'] ?? 'smtp'
            ]);
            
            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to send email using Symfony Mailer', [
                'recipient' => $recipientEmail,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Validates email address format
     *
     * @param string $email The email address to validate
     * @return bool True if email is valid, false otherwise
     */
    private function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Gets the configured sender email address
     *
     * @return string The sender email address
     */
    public function getSenderEmail(): string
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        return $emailSettings['senderEmail'] ?? self::DEFAULT_SENDER;
    }

    /**
     * Gets the configured sender name
     *
     * @return string The sender name
     */
    public function getSenderName(): string
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        return $emailSettings['senderName'] ?? self::DEFAULT_SENDER_NAME;
    }

    /**
     * Gets all email settings including transport configuration
     *
     * @return array The email settings
     */
    public function getEmailSettings(): array
    {
        $settings = $this->settingsService->getEmailSettings();
        
        // Add transport information
        $settings['availableTransports'] = self::TRANSPORT_TYPES;
        $settings['transportType'] = $settings['transportType'] ?? 'smtp';
        
        return $settings;
    }

    /**
     * Gets available transport types
     *
     * @return array<string, string> Available transport types
     */
    public function getAvailableTransports(): array
    {
        return self::TRANSPORT_TYPES;
    }

    /**
     * Sets the sender email address in app configuration
     *
     * @param string $email The sender email address
     * @return void
     */
    public function setSenderEmail(string $email): void
    {
        if (!$this->validateEmail($email)) {
            throw new \InvalidArgumentException('Invalid email address: ' . $email);
        }
        $this->settingsService->updateEmailSettings(['senderEmail' => $email]);
        $this->mailer = null; // Reset mailer to pick up new settings
    }

    /**
     * Sets the sender name in app configuration
     *
     * @param string $name The sender name
     * @return void
     */
    public function setSenderName(string $name): void
    {
        $this->settingsService->updateEmailSettings(['senderName' => $name]);
        $this->mailer = null; // Reset mailer to pick up new settings
    }

    /**
     * Sets the transport type and related configuration
     *
     * @param string $transportType The transport type
     * @param array  $transportConfig Transport-specific configuration
     * @return void
     */
    public function setTransportConfiguration(string $transportType, array $transportConfig = []): void
    {
        if (!isset(self::TRANSPORT_TYPES[$transportType])) {
            throw new \InvalidArgumentException('Invalid transport type: ' . $transportType);
        }

        $settings = ['transportType' => $transportType] + $transportConfig;
        $this->settingsService->updateEmailSettings($settings);
        $this->mailer = null; // Reset mailer to pick up new settings
    }

    /**
     * Tests if the email system is working by sending a test email
     *
     * @param string $testEmail The email address to send test email to
     * @return bool True if test email was sent successfully
     */
    public function sendTestEmail(string $testEmail): bool
    {
        if (!$this->validateEmail($testEmail)) {
            $this->logger->error('Invalid test email address', ['email' => $testEmail]);
            return false;
        }

        $subject = 'Software Catalogus - Test Email (Symfony Mailer)';
        $emailSettings = $this->getEmailSettings();
        $transportType = $emailSettings['transportType'] ?? 'smtp';
        
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
                <p><strong>Transport Type:</strong> ' . htmlspecialchars($transportType) . ' (' . htmlspecialchars(self::TRANSPORT_TYPES[$transportType] ?? 'Unknown') . ')</p>
                <p><strong>Datum:</strong> ' . date('Y-m-d H:i:s') . '</p>
                <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            </body>
            </html>
        ';

        try {
            return $this->sendEmail(
                $testEmail,
                'Test Recipient',
                $subject,
                $htmlBody
            );
        } catch (\Exception $e) {
            $this->logger->error('Test email failed', [
                'testEmail' => $testEmail,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Sets whether email notifications are enabled
     *
     * @param bool $enabled True to enable email notifications, false to disable
     * @return void
     */
    public function setEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['enabled' => $enabled]);
    }

    /**
     * Sets the test receiver override email address
     *
     * @param string $email The test receiver override email address
     * @return void
     */
    public function setTestReceiverOverride(string $email): void
    {
        if (!empty($email) && !$this->validateEmail($email)) {
            throw new \InvalidArgumentException('Invalid test receiver override email address: ' . $email);
        }
        $this->settingsService->updateEmailSettings(['testReceiverOverride' => $email]);
    }

    /**
     * Sets whether organization registration emails are enabled
     *
     * @param bool $enabled True to enable organization registration emails, false to disable
     * @return void
     */
    public function setOrganizationRegistrationEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['organizationRegistrationEnabled' => $enabled]);
    }

    /**
     * Sets whether organization activation emails are enabled
     *
     * @param bool $enabled True to enable organization activation emails, false to disable
     * @return void
     */
    public function setOrganizationActivationEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['organizationActivationEnabled' => $enabled]);
    }

    /**
     * Sets whether user creation emails are enabled
     *
     * @param bool $enabled True to enable user creation emails, false to disable
     * @return void
     */
    public function setUserCreationEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['userCreationEnabled' => $enabled]);
    }

    /**
     * Sets whether user password emails are enabled
     *
     * @param bool $enabled True to enable user password emails, false to disable
     * @return void
     */
    public function setUserPasswordEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['userPasswordEnabled' => $enabled]);
    }
} 