<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\EmailService;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Test class for EmailService
 *
 * This class contains comprehensive tests for all email sending methods
 * in the EmailService class.
 *
 * @category Tests
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */
class EmailServiceTest extends TestCase
{
    /**
     * Mock of the IMailer service
     *
     * @var IMailer|MockObject
     */
    private IMailer|MockObject $mailer;

    /**
     * Mock of the IConfig service
     *
     * @var IConfig|MockObject
     */
    private IConfig|MockObject $config;

    /**
     * Mock of the LoggerInterface
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $logger;

    /**
     * Mock of the IMessage
     *
     * @var IMessage|MockObject
     */
    private IMessage|MockObject $message;

    /**
     * The EmailService instance under test
     *
     * @var EmailService
     */
    private EmailService $emailService;

    /**
     * Set up the test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = $this->createMock(IMailer::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->message = $this->createMock(IMessage::class);

        $this->emailService = new EmailService(
            $this->mailer,
            $this->config,
            $this->logger
        );
    }

    /**
     * Test sending organization welcome email successfully
     *
     * @return void
     */
    public function testSendOrganizationWelcomeEmailSuccess(): void
    {
        $organization = [
            'name' => 'Test Organization',
            'email' => 'test@organization.com'
        ];

        // Mock configuration
        $this->config->method('getSystemValue')
            ->willReturnMap([
                ['mail_from_address', 'noreply@softwarecatalogus.nl', 'noreply'],
                ['mail_from_name', 'Software Catalogus', 'Software Catalogus'],
                ['mail_domain', '', 'softwarecatalogus.nl']
            ]);

        // Mock mailer methods
        $this->mailer->method('createMessage')->willReturn($this->message);
        $this->message->expects($this->once())->method('setFrom')
            ->with(['noreply@softwarecatalogus.nl' => 'Software Catalogus']);
        $this->message->expects($this->once())->method('setTo')
            ->with(['test@organization.com' => 'Test Organization']);
        $this->message->expects($this->once())->method('setSubject')
            ->with('Welkom bij de Software Catalogus - Test Organization');
        $this->message->expects($this->once())->method('setHtmlBody');
        $this->message->expects($this->once())->method('setPlainBody');
        
        // Mock successful send
        $this->mailer->method('send')->willReturn([]);

        // Expect success log
        $this->logger->expects($this->once())->method('info')
            ->with('Email sent successfully', [
                'recipient' => 'test@organization.com',
                'subject' => 'Welkom bij de Software Catalogus - Test Organization'
            ]);

        $result = $this->emailService->sendOrganizationWelcomeEmail($organization);
        $this->assertTrue($result);
    }

    /**
     * Test sending organization welcome email without email address
     *
     * @return void
     */
    public function testSendOrganizationWelcomeEmailWithoutEmail(): void
    {
        $organization = [
            'name' => 'Test Organization'
            // Missing email
        ];

        // Expect warning log
        $this->logger->expects($this->once())->method('warning')
            ->with('Cannot send welcome email to organization without email address', [
                'organization' => $organization
            ]);

        // Mailer should not be called
        $this->mailer->expects($this->never())->method('createMessage');

        $result = $this->emailService->sendOrganizationWelcomeEmail($organization);
        $this->assertFalse($result);
    }

    /**
     * Test sending gebruiker welcome email successfully
     *
     * @return void
     */
    public function testSendGebruikerWelcomeEmailSuccess(): void
    {
        $gebruiker = [
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ];

        // Mock configuration
        $this->config->method('getSystemValue')
            ->willReturnMap([
                ['mail_from_address', 'noreply@softwarecatalogus.nl', 'noreply'],
                ['mail_from_name', 'Software Catalogus', 'Software Catalogus'],
                ['mail_domain', '', 'softwarecatalogus.nl']
            ]);

        // Mock mailer methods
        $this->mailer->method('createMessage')->willReturn($this->message);
        $this->message->expects($this->once())->method('setFrom')
            ->with(['noreply@softwarecatalogus.nl' => 'Software Catalogus']);
        $this->message->expects($this->once())->method('setTo')
            ->with(['john@example.com' => 'John Doe']);
        $this->message->expects($this->once())->method('setSubject')
            ->with('Welkom bij de Software Catalogus - John Doe');
        $this->message->expects($this->once())->method('setHtmlBody');
        $this->message->expects($this->once())->method('setPlainBody');
        
        // Mock successful send
        $this->mailer->method('send')->willReturn([]);

        // Expect success log
        $this->logger->expects($this->once())->method('info')
            ->with('Email sent successfully', [
                'recipient' => 'john@example.com',
                'subject' => 'Welkom bij de Software Catalogus - John Doe'
            ]);

        $result = $this->emailService->sendGebruikerWelcomeEmail($gebruiker);
        $this->assertTrue($result);
    }

    /**
     * Test sending contact welcome email successfully
     *
     * @return void
     */
    public function testSendContactWelcomeEmailSuccess(): void
    {
        $contact = [
            'name' => 'Jane Smith',
            'email' => 'jane@example.com'
        ];

        // Mock configuration
        $this->config->method('getSystemValue')
            ->willReturnMap([
                ['mail_from_address', 'noreply@softwarecatalogus.nl', 'noreply'],
                ['mail_from_name', 'Software Catalogus', 'Software Catalogus'],
                ['mail_domain', '', 'softwarecatalogus.nl']
            ]);

        // Mock mailer methods
        $this->mailer->method('createMessage')->willReturn($this->message);
        $this->mailer->method('send')->willReturn([]);

        $result = $this->emailService->sendContactWelcomeEmail($contact);
        $this->assertTrue($result);
    }

    /**
     * Test email sending failure
     *
     * @return void
     */
    public function testSendEmailFailure(): void
    {
        $organization = [
            'name' => 'Test Organization',
            'email' => 'test@organization.com'
        ];

        // Mock configuration
        $this->config->method('getSystemValue')
            ->willReturnMap([
                ['mail_from_address', 'noreply@softwarecatalogus.nl', 'noreply'],
                ['mail_from_name', 'Software Catalogus', 'Software Catalogus'],
                ['mail_domain', '', 'softwarecatalogus.nl']
            ]);

        // Mock mailer methods
        $this->mailer->method('createMessage')->willReturn($this->message);
        
        // Mock failed send (returns array with failures)
        $this->mailer->method('send')->willReturn(['test@organization.com' => 'Failed to send']);

        // Expect error log
        $this->logger->expects($this->once())->method('error')
            ->with('Failed to send email', [
                'recipient' => 'test@organization.com',
                'subject' => 'Welkom bij de Software Catalogus - Test Organization',
                'failures' => ['test@organization.com' => 'Failed to send']
            ]);

        $result = $this->emailService->sendOrganizationWelcomeEmail($organization);
        $this->assertFalse($result);
    }

    /**
     * Test email sending exception
     *
     * @return void
     */
    public function testSendEmailException(): void
    {
        $organization = [
            'name' => 'Test Organization',
            'email' => 'test@organization.com'
        ];

        // Mock configuration
        $this->config->method('getSystemValue')
            ->willReturnMap([
                ['mail_from_address', 'noreply@softwarecatalogus.nl', 'noreply'],
                ['mail_from_name', 'Software Catalogus', 'Software Catalogus'],
                ['mail_domain', '', 'softwarecatalogus.nl']
            ]);

        // Mock mailer to throw exception
        $exception = new \Exception('SMTP server not available');
        $this->mailer->method('createMessage')->willThrowException($exception);

        // Expect error log
        $this->logger->expects($this->once())->method('error')
            ->with('Exception occurred while sending email', [
                'recipient' => 'test@organization.com',
                'subject' => 'Welkom bij de Software Catalogus - Test Organization',
                'exception' => 'SMTP server not available',
                'trace' => $exception->getTraceAsString()
            ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SMTP server not available');

        $this->emailService->sendOrganizationWelcomeEmail($organization);
    }

    /**
     * Test getting sender email configuration
     *
     * @return void
     */
    public function testGetSenderEmail(): void
    {
        $this->config->method('getSystemValue')
            ->willReturnMap([
                ['mail_from_address', 'noreply@softwarecatalogus.nl', 'noreply'],
                ['mail_domain', '', 'example.com']
            ]);

        $senderEmail = $this->emailService->getSenderEmail();
        $this->assertEquals('noreply@example.com', $senderEmail);
    }

    /**
     * Test getting sender name configuration
     *
     * @return void
     */
    public function testGetSenderName(): void
    {
        $this->config->method('getSystemValue')
            ->with('mail_from_name', 'Software Catalogus')
            ->willReturn('Custom Software Catalog');

        $senderName = $this->emailService->getSenderName();
        $this->assertEquals('Custom Software Catalog', $senderName);
    }
} 