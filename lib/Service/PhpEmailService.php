<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use Psr\Log\LoggerInterface;
use OCP\IConfig;

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
     * Constructor for PhpEmailService
     *
     * @param IConfig         $config The Nextcloud configuration service
     * @param LoggerInterface $logger The logger instance
     */
    public function __construct(
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sends a welcome email to a new organization
     *
     * @param array $organization The organization data containing name and email
     * @return bool True if email was sent successfully, false otherwise
     * @throws \Exception If email sending fails
     */
    public function sendOrganizationWelcomeEmail(array $organization): bool
    {
        $organizationName = $organization['name'] ?? 'Onbekende Organisatie';
        $organizationEmail = $organization['email'] ?? null;

        if (!$organizationEmail || !$this->validateEmail($organizationEmail)) {
            $this->logger->warning('Cannot send welcome email to organization without valid email address', [
                'organization' => $organization
            ]);
            return false;
        }

        // Prepare email content
        $subject = 'Welkom bij de Software Catalogus - ' . $organizationName;
        $htmlBody = str_replace(
            ['{ORGANIZATION_NAME}'],
            [$organizationName],
            self::ORGANIZATION_WELCOME_TEMPLATE
        );

        // Create and send email
        return $this->sendEmail(
            $organizationEmail,
            $organizationName,
            $subject,
            $htmlBody
        );
    }

    /**
     * Sends a welcome email to a new user (gebruiker)
     *
     * @param array $gebruiker The user data containing name and email
     * @return bool True if email was sent successfully, false otherwise
     * @throws \Exception If email sending fails
     */
    public function sendGebruikerWelcomeEmail(array $gebruiker): bool
    {
        $userName = $gebruiker['name'] ?? 'Gebruiker';
        $userEmail = $gebruiker['email'] ?? null;

        if (!$userEmail || !$this->validateEmail($userEmail)) {
            $this->logger->warning('Cannot send welcome email to user without valid email address', [
                'gebruiker' => $gebruiker
            ]);
            return false;
        }

        // Prepare email content
        $subject = 'Welkom bij de Software Catalogus - ' . $userName;
        $htmlBody = str_replace(
            ['{USER_NAME}', '{USER_EMAIL}'],
            [$userName, $userEmail],
            self::GEBRUIKER_WELCOME_TEMPLATE
        );

        // Create and send email
        return $this->sendEmail(
            $userEmail,
            $userName,
            $subject,
            $htmlBody
        );
    }

    /**
     * Sends a welcome email to a new contact
     *
     * @param array $contact The contact data containing name and email
     * @return bool True if email was sent successfully, false otherwise
     * @throws \Exception If email sending fails
     */
    public function sendContactWelcomeEmail(array $contact): bool
    {
        $contactName = $contact['name'] ?? 'Contact';
        $contactEmail = $contact['email'] ?? null;

        if (!$contactEmail || !$this->validateEmail($contactEmail)) {
            $this->logger->warning('Cannot send welcome email to contact without valid email address', [
                'contact' => $contact
            ]);
            return false;
        }

        // Prepare email content
        $subject = 'Welkom bij de Software Catalogus - ' . $contactName;
        $htmlBody = str_replace(
            ['{CONTACT_NAME}', '{CONTACT_EMAIL}'],
            [$contactName, $contactEmail],
            self::CONTACT_WELCOME_TEMPLATE
        );

        // Create and send email
        return $this->sendEmail(
            $contactEmail,
            $contactName,
            $subject,
            $htmlBody
        );
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
            // Get sender configuration
            $senderEmail = $this->config->getAppValue('softwarecatalog', 'sender_email', self::DEFAULT_SENDER);
            $senderName = $this->config->getAppValue('softwarecatalog', 'sender_name', self::DEFAULT_SENDER_NAME);

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
        return $this->config->getAppValue('softwarecatalog', 'sender_email', self::DEFAULT_SENDER);
    }

    /**
     * Gets the configured sender name
     *
     * @return string The sender name
     */
    public function getSenderName(): string
    {
        return $this->config->getAppValue('softwarecatalog', 'sender_name', self::DEFAULT_SENDER_NAME);
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
        $this->config->setAppValue('softwarecatalog', 'sender_email', $email);
    }

    /**
     * Sets the sender name in app configuration
     *
     * @param string $name The sender name
     * @return void
     */
    public function setSenderName(string $name): void
    {
        $this->config->setAppValue('softwarecatalog', 'sender_name', $name);
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
} 