<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\Service\PhpEmailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing email settings
 *
 * This controller handles email configuration and testing
 * for the Software Catalog application.
 *
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */
class EmailSettingsController extends Controller
{
    /**
     * Constructor for EmailSettingsController
     *
     * @param string           $appName      The application name
     * @param IRequest         $request      The request object
     * @param PhpEmailService  $emailService The email service
     * @param LoggerInterface  $logger       The logger instance
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly PhpEmailService $emailService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Gets the current email settings
     *
     * @return JSONResponse The email settings
     */
    public function getSettings(): JSONResponse
    {
        try {
            $settings = [
                'senderEmail' => $this->emailService->getSenderEmail(),
                'senderName' => $this->emailService->getSenderName(),
            ];

            return new JSONResponse([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get email settings', [
                'exception' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => 'Failed to get email settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Updates the email settings
     *
     * @return JSONResponse The result of the update operation
     */
    public function updateSettings(): JSONResponse
    {
        try {
            $senderEmail = $this->request->getParam('senderEmail');
            $senderName = $this->request->getParam('senderName');

            if (!$senderEmail || !$senderName) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Both sender email and name are required'
                ], 400);
            }

            // Validate email format
            if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Invalid email address format'
                ], 400);
            }

            // Update settings
            $this->emailService->setSenderEmail($senderEmail);
            $this->emailService->setSenderName($senderName);

            $this->logger->info('Email settings updated', [
                'senderEmail' => $senderEmail,
                'senderName' => $senderName
            ]);

            return new JSONResponse([
                'success' => true,
                'message' => 'Email settings updated successfully'
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update email settings', [
                'exception' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => 'Failed to update email settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sends a test email to verify email functionality
     *
     * @return JSONResponse The result of the test email operation
     */
    public function sendTestEmail(): JSONResponse
    {
        try {
            $testEmail = $this->request->getParam('testEmail');

            if (!$testEmail) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Test email address is required'
                ], 400);
            }

            // Validate email format
            if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Invalid email address format'
                ], 400);
            }

            // Send test email
            $success = $this->emailService->sendTestEmail($testEmail);

            if ($success) {
                $this->logger->info('Test email sent successfully', [
                    'testEmail' => $testEmail
                ]);

                return new JSONResponse([
                    'success' => true,
                    'message' => 'Test email sent successfully to ' . $testEmail
                ]);
            } else {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Failed to send test email. Check server logs for details.'
                ], 500);
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception while sending test email', [
                'exception' => $e->getMessage(),
                'testEmail' => $testEmail ?? 'unknown'
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => 'Failed to send test email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Gets the email system status and configuration info
     *
     * @return JSONResponse The email system status
     */
    public function getStatus(): JSONResponse
    {
        try {
            $status = [
                'phpMailFunction' => function_exists('mail'),
                'phpVersion' => PHP_VERSION,
                'senderEmail' => $this->emailService->getSenderEmail(),
                'senderName' => $this->emailService->getSenderName(),
                'recommendations' => []
            ];

            // Add recommendations based on system configuration
            if (!$status['phpMailFunction']) {
                $status['recommendations'][] = 'PHP mail() function is not available. Email sending will not work.';
            }

            if (version_compare(PHP_VERSION, '8.0.0', '<')) {
                $status['recommendations'][] = 'Consider upgrading to PHP 8.0 or higher for better performance.';
            }

            // Check if sendmail or mail transport is configured
            $sendmailPath = ini_get('sendmail_path');
            if (empty($sendmailPath)) {
                $status['recommendations'][] = 'No sendmail path configured. You may need to configure an SMTP server or install sendmail.';
            } else {
                $status['sendmailPath'] = $sendmailPath;
            }

            return new JSONResponse([
                'success' => true,
                'data' => $status
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get email system status', [
                'exception' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => 'Failed to get email system status: ' . $e->getMessage()
            ], 500);
        }
    }
} 