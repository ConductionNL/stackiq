<?php

/**
 * Symfony Email Service for SoftwareCatalog.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use Psr\Log\LoggerInterface;
use OCP\IAppConfig;
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
 * @SuppressWarnings(PHPMD.StaticAccess)             — Transport::fromDsn is Symfony Mailer's static factory pattern
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
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
     * Email template for user password emails
     *
     * @var string User password email template
     */
    private const USER_PASSWORD_TEMPLATE = '
        <html>
        <head>
            <title>Inloggegevens - Software Catalogus</title>
            <meta charset="UTF-8">
        </head>
        <body>
            <h1>Uw inloggegevens voor de Software Catalogus</h1>
            <p>Beste {{ user.name }},</p>
            <p>Hierbij ontvangt u uw inloggegevens voor de Software Catalogus.</p>
            <p><strong>Login gegevens:</strong></p>
            <ul>
                <li>E-mailadres: {{ user.email }}</li>
                <li>Tijdelijk wachtwoord: <code>{{ user.password }}</code></li>
            </ul>
            <p><strong>Belangrijk:</strong> We raden u aan om dit tijdelijke wachtwoord te wijzigen na uw eerste inlog.</p>
            <p>U kunt inloggen op het platform en direct aan de slag met het beheren
            van software voor {{ organization.name }}.</p>
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
     * Available Symfony Mailer transport types
     *
     * @var array<string, string> Transport type labels
     */
    private const TRANSPORT_TYPES = [
        'smtp'     => 'SMTP Server',
        'sendmail' => 'Sendmail',
        'native'   => 'Native PHP Mail',
        'null'     => 'Null (No Emails)',
        'sendgrid' => 'SendGrid',
        'mailgun'  => 'Mailgun',
        'postmark' => 'Postmark',
        'ses'      => 'Amazon SES',
        'mailjet'  => 'Mailjet',
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
     * @param IAppConfig      $config          The Nextcloud configuration service
     * @param LoggerInterface $logger          The logger instance
     * @param SettingsService $settingsService The settings service for email configuration
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly LoggerInterface $logger,
        SettingsService $settingsService,
    ) {
        $this->settingsService = $settingsService;
    }//end __construct()

    /**
     * Gets or creates a Symfony Mailer instance with the configured transport.
     *
     * @return Mailer The configured Symfony Mailer instance.
     *
     * @throws \Exception If transport configuration is invalid.
     */
    private function getMailer(): Mailer
    {
        if ($this->mailer === null) {
            $transport    = $this->createTransport();
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
    private function createTransport(): TransportInterface
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        $transportType = $emailSettings['transportType'] ?? 'smtp';

        try {
            switch ($transportType) {
                case 'smtp':
                    return $this->createSmtpTransport(settings: $emailSettings);
                case 'sendmail':
                    return Transport::fromDsn('sendmail://default');
                case 'native':
                    return Transport::fromDsn('native://default');
                case 'null':
                    return Transport::fromDsn('null://null');
                case 'sendgrid':
                    return $this->createSendGridTransport(settings: $emailSettings);
                case 'mailgun':
                    return $this->createMailgunTransport(settings: $emailSettings);
                case 'postmark':
                    return $this->createPostmarkTransport(settings: $emailSettings);
                case 'ses':
                    return $this->createSesTransport(settings: $emailSettings);
                case 'mailjet':
                    return $this->createMailjetTransport(settings: $emailSettings);
                default:
                    $this->logger->warning(
                            'Unknown transport type, falling back to SMTP',
                            [
                                'transportType' => $transportType,
                            ]
                            );
                    return $this->createSmtpTransport(settings: $emailSettings);
            }//end switch
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to create email transport',
                    [
                        'transportType' => $transportType,
                        'error'         => $e->getMessage(),
                    ]
                    );
            throw $e;
        }//end try
    }//end createTransport()

    /**
     * Creates an SMTP transport.
     *
     * @param array $settings Email settings.
     *
     * @return TransportInterface SMTP transport.
     */
    private function createSmtpTransport(array $settings): TransportInterface
    {
        $host       = $settings['smtpHost'] ?? 'localhost';
        $port       = $settings['smtpPort'] ?? 587;
        $encryption = $settings['smtpEncryption'] ?? 'tls';
        $username   = $settings['smtpUsername'] ?? '';
        $password   = $settings['smtpPassword'] ?? '';

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
    }//end createSmtpTransport()

    /**
     * Creates a SendGrid transport.
     *
     * @param array $settings Email settings.
     *
     * @return TransportInterface SendGrid transport.
     */
    private function createSendGridTransport(array $settings): TransportInterface
    {
        $apiKey = ($settings['sendgridApiKey'] ?? '');
        if (empty($apiKey) === true) {
            throw new \InvalidArgumentException('SendGrid API key is required');
        }

        return Transport::fromDsn('sendgrid+api://'.urlencode($apiKey).'@default');
    }//end createSendGridTransport()

    /**
     * Creates a Mailgun transport.
     *
     * @param array $settings Email settings.
     *
     * @return TransportInterface Mailgun transport.
     */
    private function createMailgunTransport(array $settings): TransportInterface
    {
        $apiKey = ($settings['mailgunApiKey'] ?? '');
        $domain = ($settings['mailgunDomain'] ?? '');

        if (empty($apiKey) === true || empty($domain) === true) {
            throw new \InvalidArgumentException('Mailgun API key and domain are required');
        }

        return Transport::fromDsn(
                sprintf(
            'mailgun+api://%s:%s@default',
            urlencode($apiKey),
            urlencode($domain)
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
    private function createPostmarkTransport(array $settings): TransportInterface
    {
        $apiKey = ($settings['postmarkApiKey'] ?? '');
        if (empty($apiKey) === true) {
            throw new \InvalidArgumentException('Postmark API key is required');
        }

        return Transport::fromDsn('postmark+api://'.urlencode($apiKey).'@default');
    }//end createPostmarkTransport()

    /**
     * Creates an Amazon SES transport.
     *
     * @param array $settings Email settings.
     *
     * @return TransportInterface SES transport.
     */
    private function createSesTransport(array $settings): TransportInterface
    {
        $accessKey = ($settings['sesAccessKey'] ?? '');
        $secretKey = ($settings['sesSecretKey'] ?? '');
        $region    = ($settings['sesRegion'] ?? 'us-east-1');

        if (empty($accessKey) === true || empty($secretKey) === true) {
            throw new \InvalidArgumentException('Amazon SES access key and secret key are required');
        }

        return Transport::fromDsn(
                sprintf(
            'ses+api://%s:%s@default?region=%s',
            urlencode($accessKey),
            urlencode($secretKey),
            urlencode($region)
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
    private function createMailjetTransport(array $settings): TransportInterface
    {
        $apiKey    = ($settings['mailjetApiKey'] ?? '');
        $secretKey = ($settings['mailjetSecretKey'] ?? '');

        if (empty($apiKey) === true || empty($secretKey) === true) {
            throw new \InvalidArgumentException('Mailjet API key and secret key are required');
        }

        return Transport::fromDsn(
                sprintf(
            'mailjet+api://%s:%s@default',
            urlencode($apiKey),
            urlencode($secretKey)
        )
                );
    }//end createMailjetTransport()

    /**
     * Sends an organization registration email.
     *
     * @param array $organization The organization data.
     *
     * @return bool True if email was sent successfully, false otherwise.
     *
     * @throws \Exception If email sending fails.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-8
     */
    public function sendOrganizationRegistrationEmail(array $organization): bool
    {
        // Check if email system is fully configured.
        $configStatus = $this->isEmailSystemConfigured();
        if ($configStatus['configured'] === false) {
            $this->logger->info(
                    'OrganizationRegistrationEmail: Email system not configured, skipping',
                    [
                        'reason'           => $configStatus['reason'],
                        'hasCredentials'   => $configStatus['hasCredentials'],
                        'hasTemplates'     => $configStatus['hasTemplates'],
                        'organizationName' => $organization['naam'] ?? 'Unknown',
                    ]
                    );

            return false;
        }

        $emailSettings = $this->settingsService->getEmailSettings();

        // Check if organization registration emails are enabled.
        if ($emailSettings['organizationRegistrationEnabled'] === false) {
            $this->logger->info(
                    'OrganizationRegistrationEmail: Organization registration emails disabled',
                    [
                        'organizationName' => $organization['naam'] ?? 'Unknown',
                    ]
                    );
            return false;
        }

        $organizationName = $organization['naam'] ?? $organization['name'] ?? 'Onbekende Organisatie';

        // Determine recipient email.
        $recipientEmail = $this->getRecipientEmail(data: $organization);
        if ($recipientEmail === null) {
            $this->logger->warning(
                    'OrganizationRegistrationEmail: Cannot send without valid email address',
                    [
                        'organization'     => $organization,
                        'organizationName' => $organizationName,
                    ]
                    );
            return false;
        }

        $this->logger->info(
                'OrganizationRegistrationEmail: Sending registration email',
                [
                    'organizationName' => $organizationName,
                    'recipientEmail'   => $recipientEmail,
                    'transportType'    => $configStatus['transportType'],
                ]
                );

        // Prepare template data.
        $templateData = [
            'organization' => [
                'name'        => $organizationName,
                'beoordeling' => ($organization['beoordeling'] ?? 'Geregistreerd'),
                'type'        => ($organization['type'] ?? 'Organisatie'),
                'website'     => ($organization['website'] ?? ''),
            ],
        ];

        try {
            $success = $this->sendTemplatedEmail(
                recipientEmail: $recipientEmail,
                recipientName: $organizationName,
                subject: 'Welkom bij de Software Catalogus - Organisatie Geregistreerd',
                templateName: 'organization_registration',
                templateData: $templateData
            );

            if ($success === true) {
                $this->logger->info(
                        'OrganizationRegistrationEmail: Successfully sent registration email',
                        [
                            'organizationName' => $organizationName,
                            'recipientEmail'   => $recipientEmail,
                        ]
                        );
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error(
                    'OrganizationRegistrationEmail: Failed to send registration email',
                    [
                        'organizationName' => $organizationName,
                        'recipientEmail'   => $recipientEmail,
                        'error'            => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try
    }//end sendOrganizationRegistrationEmail()

    /**
     * Sends an organization activation email.
     *
     * @param array $organization The organization data.
     *
     * @return bool True if email was sent successfully, false otherwise.
     *
     * @throws \Exception If email sending fails.
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-1
     */
    public function sendOrganizationActivationEmail(array $organization): bool
    {
        // Check if email system is fully configured.
        $configStatus = $this->isEmailSystemConfigured();
        if ($configStatus['configured'] === false) {
            $this->logger->info(
                    'OrganizationActivationEmail: Email system not configured, skipping',
                    [
                        'reason'           => $configStatus['reason'],
                        'hasCredentials'   => $configStatus['hasCredentials'],
                        'hasTemplates'     => $configStatus['hasTemplates'],
                        'organizationName' => $organization['naam'] ?? 'Unknown',
                    ]
                    );
            return false;
        }

        $emailSettings = $this->settingsService->getEmailSettings();

        // Check if organization activation emails are enabled.
        if ($emailSettings['organizationActivationEnabled'] === false) {
            $this->logger->info(
                    'OrganizationActivationEmail: Organization activation emails disabled',
                    [
                        'organizationName' => $organization['naam'] ?? 'Unknown',
                    ]
                    );
            return false;
        }

        $organizationName = ($organization['naam'] ?? $organization['name'] ?? 'Onbekende Organisatie');

        // Determine recipient email.
        $recipientEmail = $this->getRecipientEmail(data: $organization);
        if ($recipientEmail === null) {
            $this->logger->warning(
                    'OrganizationActivationEmail: Cannot send without valid email address',
                    [
                        'organization'     => $organization,
                        'organizationName' => $organizationName,
                    ]
                    );
            return false;
        }

        $this->logger->info(
                'OrganizationActivationEmail: Sending activation email',
                [
                    'organizationName' => $organizationName,
                    'recipientEmail'   => $recipientEmail,
                    'transportType'    => $configStatus['transportType'],
                ]
                );

        // Prepare template data.
        $templateData = [
            'organization' => [
                'name'        => $organizationName,
                'beoordeling' => ($organization['beoordeling'] ?? 'Actief'),
                'type'        => ($organization['type'] ?? 'Organisatie'),
                'website'     => ($organization['website'] ?? ''),
            ],
        ];

        try {
            $success = $this->sendTemplatedEmail(
                recipientEmail: $recipientEmail,
                recipientName: $organizationName,
                subject: 'Software Catalogus - Organisatie Geactiveerd',
                templateName: 'organization_activation',
                templateData: $templateData
            );

            if ($success === true) {
                $this->logger->info(
                        'OrganizationActivationEmail: Successfully sent activation email',
                        [
                            'organizationName' => $organizationName,
                            'recipientEmail'   => $recipientEmail,
                        ]
                        );
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error(
                    'OrganizationActivationEmail: Failed to send activation email',
                    [
                        'organizationName' => $organizationName,
                        'recipientEmail'   => $recipientEmail,
                        'error'            => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try
    }//end sendOrganizationActivationEmail()

    /**
     * Sends a user creation email.
     *
     * @param array $user         The user data.
     * @param array $organization The organization data (optional).
     *
     * @return bool True if email was sent successfully, false otherwise.
     *
     * @throws \Exception If email sending fails.
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-1
     */
    public function sendUserCreationEmail(array $user, array $organization=[]): bool
    {
        // Check if email system is fully configured.
        $configStatus = $this->isEmailSystemConfigured();
        if ($configStatus['configured'] === false) {
            $this->logger->info(
                    'UserCreationEmail: Email system not configured, skipping',
                    [
                        'reason'         => $configStatus['reason'],
                        'hasCredentials' => $configStatus['hasCredentials'],
                        'hasTemplates'   => $configStatus['hasTemplates'],
                        'userEmail'      => $user['email'] ?? 'Unknown',
                    ]
                    );
            return false;
        }

        $emailSettings = $this->settingsService->getEmailSettings();

        // Check if user creation emails are enabled.
        if ($emailSettings['userCreationEnabled'] === false) {
            $this->logger->info(
                    'UserCreationEmail: User creation emails disabled',
                    [
                        'userEmail' => $user['email'] ?? 'Unknown',
                    ]
                    );
            return false;
        }

        $userEmail = $user['email'] ?? '';
        $userName  = $user['naam'] ?? $user['name'] ?? ($user['voornaam'] ?? '').' '.($user['achternaam'] ?? '');
        $userName  = trim($userName);

        if (empty($userEmail) === true) {
            $this->logger->warning(
                    'UserCreationEmail: Cannot send without email address',
                    [
                        'user'     => $user,
                        'userName' => $userName,
                    ]
                    );
            return false;
        }

        $this->logger->info(
                'UserCreationEmail: Sending user creation email',
                [
                    'userName'         => $userName,
                    'userEmail'        => $userEmail,
                    'organizationName' => $organization['naam'] ?? $organization['name'] ?? 'Software Catalogus',
                    'transportType'    => $configStatus['transportType'],
                ]
                );

        // Prepare template data.
            $displayName = 'Gebruiker';
        if (empty($userName) === false) {
        }

        $templateData = [
            'user'         => [
                'name'    => $displayName,
                'email'   => $userEmail,
                'functie' => ($user['functie'] ?? ''),
            ],
            'organization' => [
                'name' => ($organization['naam'] ?? $organization['name'] ?? 'Software Catalogus'),
            ],
        ];

        try {
            $success = $this->sendTemplatedEmail(
                recipientEmail: $userEmail,
                recipientName: $displayName,
                subject: 'Welkom bij de Software Catalogus - Account Aangemaakt',
                templateName: 'user_creation',
                templateData: $templateData
            );

            if ($success === true) {
                $this->logger->info(
                        'UserCreationEmail: Successfully sent user creation email',
                        [
                            'userName'  => $userName,
                            'userEmail' => $userEmail,
                        ]
                        );
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error(
                    'UserCreationEmail: Failed to send user creation email',
                    [
                        'userName'  => $userName,
                        'userEmail' => $userEmail,
                        'error'     => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try
    }//end sendUserCreationEmail()

    /**
     * Sends a user update/organisation email.
     *
     * @param array $user         The user data.
     * @param array $organization The organization data (optional).
     *
     * @return bool True if email was sent successfully, false otherwise.
     *
     * @throws \Exception If email sending fails.
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-1
     */
    public function sendUserUpdateEmail(array $user, array $organization=[]): bool
    {
        // Check if email system is fully configured.
        $configStatus = $this->isEmailSystemConfigured();
        if ($configStatus['configured'] === false) {
            $this->logger->info(
                    'UserCreationEmail: Email system not configured, skipping',
                    [
                        'reason'         => $configStatus['reason'],
                        'hasCredentials' => $configStatus['hasCredentials'],
                        'hasTemplates'   => $configStatus['hasTemplates'],
                        'userEmail'      => $user['email'] ?? 'Unknown',
                    ]
                    );
            return false;
        }

        $emailSettings = $this->settingsService->getEmailSettings();

        // Check if user creation emails are enabled.
        if ($emailSettings['userOrganisationEnabled'] === false) {
            $this->logger->info(
                    'UserOrganisationEmail: User creation emails disabled',
                    [
                        'userEmail' => $user['email'] ?? 'Unknown',
                    ]
                    );
            return false;
        }

        $userEmail = $user['email'] ?? '';
        $userName  = $user['naam'] ?? $user['name'] ?? ($user['voornaam'] ?? '').' '.($user['achternaam'] ?? '');
        $userName  = trim($userName);

        if (empty($userEmail) === true) {
            $this->logger->warning(
                    'UserCreationEmail: Cannot send without email address',
                    [
                        'user'     => $user,
                        'userName' => $userName,
                    ]
                    );
            return false;
        }

        $this->logger->info(
                'UserCreationEmail: Sending user creation email',
                [
                    'userName'         => $userName,
                    'userEmail'        => $userEmail,
                    'organizationName' => $organization['naam'] ?? $organization['name'] ?? 'Software Catalogus',
                    'transportType'    => $configStatus['transportType'],
                ]
                );

        // Prepare template data.
            $displayName = 'Gebruiker';
        if (empty($userName) === false) {
        }

        $templateData = [
            'user'         => [
                'name'    => $displayName,
                'email'   => $userEmail,
                'functie' => ($user['functie'] ?? ''),
            ],
            'organization' => [
                'name' => ($organization['naam'] ?? $organization['name'] ?? 'Software Catalogus'),
            ],
        ];

        try {
            $success = $this->sendTemplatedEmail(
                recipientEmail: $userEmail,
                recipientName: $displayName,
                subject: 'Welkom bij de Software Catalogus - Account toegevoegd aan organisatie',
                templateName: 'user_organisation',
                templateData: $templateData
            );

            if ($success === true) {
                $this->logger->info(
                        'UserCreationEmail: Successfully sent user organisation email',
                        [
                            'userName'  => $userName,
                            'userEmail' => $userEmail,
                        ]
                        );
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error(
                    'UserCreationEmail: Failed to send user organisation email',
                    [
                        'userName'  => $userName,
                        'userEmail' => $userEmail,
                        'error'     => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try
    }//end sendUserUpdateEmail()

    /**
     * Sends a user password email.
     *
     * @param array  $user         The user data.
     * @param string $password     The generated password.
     * @param array  $organization The organization data (optional).
     *
     * @return bool True if email was sent successfully, false otherwise.
     *
     * @throws \Exception If email sending fails.
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-1
     */
    public function sendUserPasswordEmail(array $user, string $password, array $organization=[]): bool
    {
        // Check if email system is fully configured.
        $configStatus = $this->isEmailSystemConfigured();
        if ($configStatus['configured'] === false) {
            $this->logger->info(
                    'UserPasswordEmail: Email system not configured, skipping',
                    [
                        'reason'         => $configStatus['reason'],
                        'hasCredentials' => $configStatus['hasCredentials'],
                        'hasTemplates'   => $configStatus['hasTemplates'],
                        'userEmail'      => $user['email'] ?? 'Unknown',
                    ]
                    );
            return false;
        }

        $emailSettings = $this->settingsService->getEmailSettings();

        // Check if user password emails are enabled.
        if ($emailSettings['userPasswordEnabled'] === false) {
            $this->logger->info(
                    'UserPasswordEmail: User password emails disabled',
                    [
                        'userEmail' => $user['email'] ?? 'Unknown',
                    ]
                    );
            return false;
        }

        $userEmail = $user['email'] ?? '';
        $userName  = $user['naam'] ?? $user['name'] ?? ($user['voornaam'] ?? '').' '.($user['achternaam'] ?? '');
        $userName  = trim($userName);

        if (empty($userEmail) === true) {
            $this->logger->warning(
                    'UserPasswordEmail: Cannot send without email address',
                    [
                        'user'     => $user,
                        'userName' => $userName,
                    ]
                    );
            return false;
        }

        $this->logger->info(
                'UserPasswordEmail: Sending user password email',
                [
                    'userName'         => $userName,
                    'userEmail'        => $userEmail,
                    'organizationName' => $organization['naam'] ?? $organization['name'] ?? 'Software Catalogus',
                    'transportType'    => $configStatus['transportType'],
                ]
                );

        // Prepare template data.
            $displayName = 'Gebruiker';
        if (empty($userName) === false) {
        }

        $templateData = [
            'user'         => [
                'name'     => $displayName,
                'email'    => $userEmail,
                'password' => $password,
                'functie'  => ($user['functie'] ?? ''),
            ],
            'organization' => [
                'name' => ($organization['naam'] ?? $organization['name'] ?? 'Software Catalogus'),
            ],
        ];

        try {
            $success = $this->sendTemplatedEmail(
                recipientEmail: $userEmail,
                recipientName: $displayName,
                subject: 'Software Catalogus - Inloggegevens',
                templateName: 'user_password',
                templateData: $templateData
            );

            if ($success === true) {
                $this->logger->info(
                        'UserPasswordEmail: Successfully sent user password email',
                        [
                            'userName'  => $userName,
                            'userEmail' => $userEmail,
                        ]
                        );
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error(
                    'UserPasswordEmail: Failed to send user password email',
                    [
                        'userName'  => $userName,
                        'userEmail' => $userEmail,
                        'error'     => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try
    }//end sendUserPasswordEmail()

    /**
     * Sends a templated email using the configured templates.
     *
     * @param string $recipientEmail The recipient email address.
     * @param string $recipientName  The recipient name.
     * @param string $subject        The email subject.
     * @param string $templateName   The template name.
     * @param array  $templateData   The template data.
     *
     * @return bool True if email was sent successfully, false otherwise.
     *
     * @throws \Exception If email sending fails.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-8
     */
    private function sendTemplatedEmail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $templateName,
        array $templateData
    ): bool {
        try {
            // Get template content from settings service.
            $emailSettings   = $this->settingsService->getEmailSettings();
            $templates       = ($emailSettings['templates'] ?? []);
            $templateContent = ($templates[$templateName] ?? $this->getDefaultTemplate(templateName: $templateName));

            // Replace template variables.
            $htmlBody = $this->processTemplate(
                template: $templateContent,
                templateData: $templateData
            );

            return $this->sendEmail(
                recipientEmail: $recipientEmail,
                recipientName: $recipientName,
                subject: $subject,
                htmlBody: $htmlBody
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to send templated email',
                    [
                        'recipient' => $recipientEmail,
                        'template'  => $templateName,
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );
            throw $e;
        }//end try
    }//end sendTemplatedEmail()

    /**
     * Gets the default template for a given template name.
     *
     * @param string $templateName The template name.
     *
     * @return string The default template content.
     */
    private function getDefaultTemplate(string $templateName): string
    {
        return match ($templateName) {
            'organization_registration' => self::ORGANIZATION_WELCOME_TEMPLATE,
            'organization_activation' => self::ORGANIZATION_ACTIVATION_TEMPLATE,
            'user_creation' => self::GEBRUIKER_WELCOME_TEMPLATE,
            'user_password' => self::USER_PASSWORD_TEMPLATE,
            'user_organisation' => self::CONTACT_ADDED_TEMPLATE,
            default => self::ORGANIZATION_WELCOME_TEMPLATE,
        };
    }//end getDefaultTemplate()

    /**
     * Processes a template by replacing variables with actual data.
     *
     * @param string $template     The template content.
     * @param array  $templateData The data to replace in the template.
     *
     * @return string The processed template.
     */
    private function processTemplate(string $template, array $templateData): string
    {
        $processed = $template;

        // Replace organization variables.
        if (isset($templateData['organization']) === true) {
            $org       = $templateData['organization'];
            $processed = str_replace(
                search: '{{ organization.name }}',
                replace: ($org['name'] ?? ''),
                subject: $processed
            );
        }

        // Replace user variables.
        if (isset($templateData['user']) === true) {
            $user      = $templateData['user'];
            $processed = str_replace(
                search: '{{ user.name }}',
                replace: ($user['name'] ?? ''),
                subject: $processed
            );
            $processed = str_replace(
                search: '{{ user.email }}',
                replace: ($user['email'] ?? ''),
                subject: $processed
            );
            $processed = str_replace(
                search: '{{ user.password }}',
                replace: ($user['password'] ?? ''),
                subject: $processed
            );
        }

        // Replace contact variables (backward compatibility).
        if (isset($templateData['user']) === true) {
            $user      = $templateData['user'];
            $processed = str_replace(
                search: '{{ user.name }}',
                replace: ($user['name'] ?? ''),
                subject: $processed
            );
            $processed = str_replace(
                search: '{{ user.email }}',
                replace: ($user['email'] ?? ''),
                subject: $processed
            );
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
    private function getRecipientEmail(array $data): ?string
    {
        // Check for test receiver override.
        $testReceiver = $this->getTestReceiverOverride();
        if ($testReceiver !== null) {
            return $testReceiver;
        }

        // Try to get email from various fields.
        $email = ($data['e-mailadres'] ?? null);

        // If no direct email, try to get from contactpersonen.
        if ($email === null && isset($data['contactpersonen']) === true && is_array($data['contactpersonen']) === true) {
            foreach ($data['contactpersonen'] as $contact) {
                if (is_array($contact) === true && empty($contact['e-mailadres']) === false) {
                    $email = $contact['e-mailadres'];
                    break;
                }
            }
        }

        if ($email !== null && $this->validateEmail(email: $email) === true) {
            return $email;
        }

        return null;
    }//end getRecipientEmail()

    /**
     * Gets the test receiver override email address.
     *
     * @return string|null The test receiver override email address or null if not set.
     */
    private function getTestReceiverOverride(): ?string
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        $override      = ($emailSettings['testReceiverOverride'] ?? '');

        if (empty($override) === false && $this->validateEmail(email: $override) === true) {
            return $override;
        }

        return null;
    }//end getTestReceiverOverride()

    /**
     * Sends an email using Symfony Mailer.
     *
     * @param string $recipientEmail The recipient email address.
     * @param string $recipientName  The recipient name.
     * @param string $subject        The email subject.
     * @param string $htmlBody       The HTML email body.
     *
     * @return bool True if email was sent successfully, false otherwise.
     *
     * @throws \Exception If email sending fails.
     */
    private function sendEmail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $htmlBody
    ): bool {
        try {
            // Get sender configuration from settings service.
            $emailSettings = $this->settingsService->getEmailSettings();
            $senderEmail   = ($emailSettings['senderEmail'] ?? self::DEFAULT_SENDER);
            $senderName    = ($emailSettings['senderName'] ?? self::DEFAULT_SENDER_NAME);

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
                        'subject'   => $subject,
                        'sender'    => $senderEmail,
                        'transport' => $emailSettings['transportType'] ?? 'smtp',
                    ]
                    );

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to send email using Symfony Mailer',
                    [
                        'recipient' => $recipientEmail,
                        'subject'   => $subject,
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
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
    private function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }//end validateEmail()

    /**
     * Gets the configured sender email address.
     *
     * @return string The sender email address.
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-2
     */
    public function getSenderEmail(): string
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        return $emailSettings['senderEmail'] ?? self::DEFAULT_SENDER;
    }//end getSenderEmail()

    /**
     * Gets the configured sender name.
     *
     * @return string The sender name.
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-2
     */
    public function getSenderName(): string
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        return $emailSettings['senderName'] ?? self::DEFAULT_SENDER_NAME;
    }//end getSenderName()

    /**
     * Gets all email settings including transport configuration.
     *
     * @return array The email settings.
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-2
     */
    public function getEmailSettings(): array
    {
        $settings = $this->settingsService->getEmailSettings();

        // Add transport information.
        $settings['availableTransports'] = self::TRANSPORT_TYPES;
        $settings['transportType']       = ($settings['transportType'] ?? 'smtp');

        return $settings;
    }//end getEmailSettings()

    /**
     * Gets available transport types.
     *
     * @return array<string, string> Available transport types.
     */
    public function getAvailableTransports(): array
    {
        return self::TRANSPORT_TYPES;
    }//end getAvailableTransports()

    /**
     * Sets the sender email address in app configuration.
     *
     * @param string $email The sender email address.
     *
     * @return void
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-2
     */
    public function setSenderEmail(string $email): void
    {
        if ($this->validateEmail(email: $email) === false) {
            throw new \InvalidArgumentException('Invalid email address: '.$email);
        }

        $this->settingsService->updateEmailSettings(['senderEmail' => $email]);
        $this->mailer = null;
        // Reset mailer to pick up new settings.
    }//end setSenderEmail()

    /**
     * Sets the sender name in app configuration.
     *
     * @param string $name The sender name.
     *
     * @return void
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-2
     */
    public function setSenderName(string $name): void
    {
        $this->settingsService->updateEmailSettings(['senderName' => $name]);
        $this->mailer = null;
        // Reset mailer to pick up new settings.
    }//end setSenderName()

    /**
     * Sets the transport type and related configuration.
     *
     * @param string $transportType   The transport type.
     * @param array  $transportConfig Transport-specific configuration.
     *
     * @return void
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-2
     */
    public function setTransportConfiguration(string $transportType, array $transportConfig=[]): void
    {
        if (isset(self::TRANSPORT_TYPES[$transportType]) === false) {
            throw new \InvalidArgumentException('Invalid transport type: '.$transportType);
        }

        $settings = ['transportType' => $transportType] + $transportConfig;
        $this->settingsService->updateEmailSettings($settings);
        $this->mailer = null;
        // Reset mailer to pick up new settings.
    }//end setTransportConfiguration()

    /**
     * Tests if the email system is working by sending a test email.
     *
     * @param string $testEmail The email address to send test email to.
     *
     * @return bool True if test email was sent successfully.
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-1
     */
    public function sendTestEmail(string $testEmail): bool
    {
        if ($this->validateEmail(email: $testEmail) === false) {
            $this->logger->error('Invalid test email address', ['email' => $testEmail]);
            return false;
        }

        $subject       = 'Software Catalogus - Test Email (Symfony Mailer)';
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
                <p><strong>Transport Type:</strong> '.htmlspecialchars($transportType).'
                    ('.htmlspecialchars(self::TRANSPORT_TYPES[$transportType] ?? 'Unknown').')</p>
                <p><strong>Datum:</strong> '.date('Y-m-d H:i:s').'</p>
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
                        'error'     => $e->getMessage(),
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
    public function setEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['enabled' => $enabled]);
    }//end setEnabled()

    /**
     * Sets the test receiver override email address.
     *
     * @param string $email The test receiver override email address.
     *
     * @return void
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-2
     */
    public function setTestReceiverOverride(string $email): void
    {
        if (empty($email) === false && $this->validateEmail(email: $email) === false) {
            throw new \InvalidArgumentException('Invalid test receiver override email address: '.$email);
        }

        $this->settingsService->updateEmailSettings(['testReceiverOverride' => $email]);
    }//end setTestReceiverOverride()

    /**
     * Sets whether organization registration emails are enabled.
     *
     * @param bool $enabled True to enable organization registration emails, false to disable.
     *
     * @return void
     */
    public function setOrganizationRegistrationEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['organizationRegistrationEnabled' => $enabled]);
    }//end setOrganizationRegistrationEnabled()

    /**
     * Sets whether organization activation emails are enabled.
     *
     * @param bool $enabled True to enable organization activation emails, false to disable.
     *
     * @return void
     */
    public function setOrganizationActivationEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['organizationActivationEnabled' => $enabled]);
    }//end setOrganizationActivationEnabled()

    /**
     * Sets whether user creation emails are enabled.
     *
     * @param bool $enabled True to enable user creation emails, false to disable.
     *
     * @return void
     */
    public function setUserCreationEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['userCreationEnabled' => $enabled]);
    }//end setUserCreationEnabled()

    /**
     * Sets whether user password emails are enabled.
     *
     * @param bool $enabled True to enable user password emails, false to disable.
     *
     * @return void
     */
    public function setUserPasswordEnabled(bool $enabled): void
    {
        $this->settingsService->updateEmailSettings(['userPasswordEnabled' => $enabled]);
    }//end setUserPasswordEnabled()

    /**
     * Checks if the email system is fully configured with credentials and templates.
     *
     * @return array Configuration status with details.
     * @spec openspec/changes/retrofit-2026-05-26-email-delivery/tasks.md#task-2
     */
    public function isEmailSystemConfigured(): array
    {
        $emailSettings = $this->settingsService->getEmailSettings();

        // Check if emails are enabled.
        if (($emailSettings['enabled'] ?? false) === false) {
            return [
                'configured'     => false,
                'reason'         => 'Email notifications are disabled',
                'hasCredentials' => false,
                'hasTemplates'   => false,
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
            'configured'     => $configured,
            'reason'         => $reason,
            'hasCredentials' => $hasCredentials,
            'hasTemplates'   => $hasTemplates,
            'transportType'  => ($emailSettings['transportType'] ?? 'smtp'),
        ];
    }//end isEmailSystemConfigured()

    /**
     * Checks if the current transport has valid credentials.
     *
     * @param array $emailSettings Email settings.
     *
     * @return bool True if credentials are valid for the current transport.
     */
    private function hasValidTransportCredentials(array $emailSettings): bool
    {
        $transportType = ($emailSettings['transportType'] ?? 'smtp');

        switch ($transportType) {
            case 'mailjet':
                return empty($emailSettings['mailjetApiKey']) === false
                    && empty($emailSettings['mailjetSecretKey']) === false;
            case 'sendgrid':
                return empty($emailSettings['sendgridApiKey']) === false;
            case 'mailgun':
                return empty($emailSettings['mailgunApiKey']) === false && empty($emailSettings['mailgunDomain']) === false;
            case 'postmark':
                return empty($emailSettings['postmarkApiKey']) === false;
            case 'ses':
                return empty($emailSettings['sesAccessKey']) === false && empty($emailSettings['sesSecretKey']) === false;
            case 'smtp':
                return empty($emailSettings['smtpHost']) === false;
            case 'sendmail':
            case 'native':
            case 'null':
                // These don't require credentials.
                return true;
            default:
                return false;
        }//end switch
    }//end hasValidTransportCredentials()

    /**
     * Checks if required templates are configured.
     *
     * @param array $emailSettings Email settings.
     *
     * @return bool True if templates are configured.
     */
    private function hasValidTemplates(array $emailSettings): bool
    {
        $templates         = ($emailSettings['templates'] ?? []);
        $requiredTemplates = ['organization_registration', 'organization_activation', 'user_creation', 'user_password'];

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
     * @param bool $hasTemplates   Whether templates are configured.
     *
     * @return string Description of configuration issues.
     */
    private function getConfigurationIssues(bool $hasCredentials, bool $hasTemplates): string
    {
        $issues = [];

        if ($hasCredentials === false) {
            $issues[] = 'missing transport credentials';
        }

        if ($hasTemplates === false) {
            $issues[] = 'missing email templates';
        }

        return 'Configuration incomplete: '.implode(separator: ', ', array: $issues);
    }//end getConfigurationIssues()
}//end class
