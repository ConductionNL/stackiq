<?php
/**
 * SoftwareCatalog Test Event Listener
 *
 * This file contains a simple test listener class for verifying that event
 * listeners work correctly in the SoftwareCatalog application.
 *
 * @category  EventListener
 * @package   OCA\SoftwareCatalog\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/OpenConnector
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\EventListener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * Test event listener for verifying event listener functionality.
 * 
 * This listener handles user login events to test that our event system 
 * is working correctly. It logs when users log in and can be easily 
 * triggered for testing purposes.
 * 
 * @category EventListener
 * @package  OCA\SoftwareCatalog\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/OpenConnector
 */
class TestEventListener implements IEventListener
{
    /**
     * Constructor for TestEventListener
     * 
     * @param LoggerInterface $logger The logger service for logging events
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handles events related to user login for testing purposes
     * 
     * This method processes UserLoggedInEvent events and logs detailed
     * information to verify that the event listener system is working
     * correctly.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        // Log that we received ANY event first
        $this->logger->info('SoftwareCatalog TestEventListener: Event received!', [
            'eventClass' => get_class($event),
            'timestamp' => date('Y-m-d H:i:s'),
            'microtime' => microtime(true)
        ]);



        // Handle UserLoggedInEvent specifically
        if ($event instanceof UserLoggedInEvent) {
            $user = $event->getUser();
            
            $this->logger->info('SoftwareCatalog TestEventListener: User logged in successfully!', [
                'userId' => $user->getUID(),
                'userDisplayName' => $user->getDisplayName(),
                'userEmail' => $user->getEMailAddress(),
                'timestamp' => date('Y-m-d H:i:s'),
                'eventType' => 'UserLoggedInEvent'
            ]);



            // Test that we can access Nextcloud services
            try {
                $this->logger->debug('SoftwareCatalog TestEventListener: Event listener is working correctly!', [
                    'message' => 'This confirms that event listeners are properly registered and triggered',
                    'userId' => $user->getUID(),
                    'eventClass' => get_class($event)
                ]);
            } catch (\Exception $e) {
                $this->logger->error('SoftwareCatalog TestEventListener: Error in event processing', [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            // Log other events we might receive
            $this->logger->debug('SoftwareCatalog TestEventListener: Received unhandled event', [
                'eventClass' => get_class($event),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }
}
