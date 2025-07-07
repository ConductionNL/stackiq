<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use Psr\Log\LoggerInterface;
use OCP\IConfig;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * PHP-based email service for sending notification emails
 * 
 * This service handles sending various types of notification emails
 * using PHP's built-in mail() function, which works without requiring
 * a full SMTP server configuration.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */
class PhpEmailService
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
     * The settings service for accessing email configuration
     *
     * @var SettingsService Settings service instance
     */
    private readonly SettingsService $settingsService;

    /**
     * Constructor for PhpEmailService
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
            'Welkom bij de Software Catalogus - ' . $organizationName,
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
            'Uw organisatie is geactiveerd - ' . $organizationName,
            'organization_activation',
            $templateData
        );
    }

    /**
     * Sends a user creation email
     *
     * @param array $user The user data
     * @param array $organization Optional organization data
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

        $userName = $user['name'] ?? $user['voornaam'] . ' ' . $user['achternaam'] ?? 'Gebruiker';
        $userEmail = $user['email'] ?? null;

        if (!$userEmail || !$this->validateEmail($userEmail)) {
            $this->logger->warning('Cannot send user creation email without valid email address', [
                'user' => $user
            ]);
            return false;
        }

        // Apply test receiver override if configured
        $recipientEmail = $this->getTestReceiverOverride() ?: $userEmail;

        // Prepare template data
        $templateData = [
            'user' => [
                'name' => $userName,
                'email' => $userEmail,
                'username' => $user['username'] ?? '',
                'organization' => !empty($organization) ? [
                    'name' => $organization['naam'] ?? $organization['name'] ?? ''
                ] : null
            ]
        ];

        return $this->sendTemplatedEmail(
            $recipientEmail,
            $userName,
            'Welkom bij de Software Catalogus - ' . $userName,
            'user_creation',
            $templateData
        );
    }

    /**
     * Sends an email using a Twig template
     *
     * @param string $recipientEmail The recipient's email address
     * @param string $recipientName  The recipient's name
     * @param string $subject        The email subject
     * @param string $templateName   The template name
     * @param array  $templateData   Data to pass to the template
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
            // Get template content
            $templateContent = $this->settingsService->getEmailTemplate($templateName);
            
            if (empty($templateContent)) {
                throw new \Exception("Email template '{$templateName}' is empty or not found");
            }

            // Create Twig environment
            $loader = new ArrayLoader([$templateName => $templateContent]);
            $twig = new Environment($loader);

            // Render template
            $htmlBody = $twig->render($templateName, $templateData);
            
            // Wrap in basic HTML structure if not already present
            if (strpos($htmlBody, '<html>') === false) {
                $htmlBody = '
                <html>
                <head>
                    <title>' . htmlspecialchars($subject) . '</title>
                    <meta charset="UTF-8">
                </head>
                <body>
                    ' . $htmlBody . '
                </body>
                </html>';
            }

            return $this->sendEmail($recipientEmail, $recipientName, $subject, $htmlBody);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send templated email', [
                'templateName' => $templateName,
                'recipient' => $recipientEmail,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Gets the recipient email address, applying test receiver override if configured
     *
     * @param array $data The data containing email information
     * @return string|null The recipient email address or null if invalid
     */
    private function getRecipientEmail(array $data): ?string
    {
        // Try to find email in various possible fields
        $email = $data['email'] ?? $data['contactEmail'] ?? null;
        
        // If no direct email, try to get from contact persons
        if (!$email && isset($data['contactpersonen']) && is_array($data['contactpersonen'])) {
            foreach ($data['contactpersonen'] as $contact) {
                if (!empty($contact['email'])) {
                    $email = $contact['email'];
                    break;
                }
            }
        }

        if (!$email || !$this->validateEmail($email)) {
            return null;
        }

        // Apply test receiver override if configured
        return $this->getTestReceiverOverride() ?: $email;
    }

    /**
     * Gets the test receiver override email if configured
     *
     * @return string|null The test receiver email or null if not configured
     */
    private function getTestReceiverOverride(): ?string
    {
        $emailSettings = $this->settingsService->getEmailSettings();
        $override = $emailSettings['testReceiverOverride'] ?? '';
        
        return (!empty($override) && $this->validateEmail($override)) ? $override : null;
    }

    /**
     * Sends an email using PHP's built-in mail() function
     *
     * @param string $recipientEmail The recipient's email address
     * @param string $recipientName  The recipient's name
     * @param string $subject        The email subject
     * @param string $htmlBody       The email body in HTML format
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

            // Prepare headers
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $senderName . ' <' . $senderEmail . '>',
                'Reply-To: ' . $senderEmail,
                'X-Mailer: SoftwareCatalog PHP/' . PHP_VERSION,
                'X-Priority: 3',
                'Date: ' . date('r'),
            ];

            // Add recipient name to subject if available
            $fullRecipient = $recipientName ? $recipientName . ' <' . $recipientEmail . '>' : $recipientEmail;

            // Send email using PHP's mail() function
            $success = mail(
                $fullRecipient,
                $subject,
                $htmlBody,
                implode("\r\n", $headers)
            );

            if ($success) {
                $this->logger->info('Email sent successfully using PHP mail()', [
                    'recipient' => $recipientEmail,
                    'subject' => $subject,
                    'sender' => $senderEmail
                ]);
                return true;
            } else {
                $this->logger->error('Failed to send email using PHP mail()', [
                    'recipient' => $recipientEmail,
                    'subject' => $subject,
                    'sender' => $senderEmail,
                    'error' => error_get_last()
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception occurred while sending email', [
                'recipient' => $recipientEmail,
                'subject' => $subject,
                'exception' => $e->getMessage(),
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
     * Gets all email settings
     *
     * @return array The email settings
     */
    public function getEmailSettings(): array
    {
        return $this->settingsService->getEmailSettings();
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

        $subject = 'Software Catalogus - Test Email';
        $htmlBody = '
            <html>
            <head>
                <title>Test Email</title>
                <meta charset="UTF-8">
            </head>
            <body>
                <h1>Test Email</h1>
                <p>Dit is een test email van de Software Catalogus.</p>
                <p>Als u deze email ontvangt, werkt het email systeem correct.</p>
                <p>Datum: ' . date('Y-m-d H:i:s') . '</p>
                <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            </body>
            </html>
        ';

        return $this->sendEmail(
            $testEmail,
            'Test Recipient',
            $subject,
            $htmlBody
        );
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

    /**
     * Sends a user password email with the auto-generated password
     *
     * @param array $user The user data
     * @param string $password The auto-generated password
     * @param array $organization The organization data
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

        $userName = $user['displayName'] ?? $user['fullName'] ?? $user['name'] ?? 'Gebruiker';
        $userEmail = $user['email'] ?? $user['emailAddress'] ?? null;

        if (!$userEmail) {
            $this->logger->warning('Cannot send user password email without valid email address', [
                'user' => $user
            ]);
            return false;
        }

        $organizationName = $organization['naam'] ?? $organization['name'] ?? 'Onbekende Organisatie';

        // Prepare template data
        $templateData = [
            'user' => [
                'username' => $user['username'] ?? $user['uid'] ?? $userEmail,
                'email' => $userEmail,
                'displayName' => $userName,
                'password' => $password,
                'roles' => $user['roles'] ?? [],
            ],
            'organization' => [
                'name' => $organizationName,
            ]
        ];

        return $this->sendTemplatedEmail(
            $userEmail,
            $userName,
            'Software Catalogus - Uw wachtwoord',
            'user_password',
            $templateData
        );
    }
} 