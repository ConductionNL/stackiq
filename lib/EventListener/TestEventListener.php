<?php

/**
 * Stackiq Test Event Listener
 *
 * This file contains a simple test listener class for verifying that event
 * listeners work correctly in the Stackiq application.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/integriq
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUser;
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
 * @package  OCA\Stackiq\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/integriq
 */
class TestEventListener implements IEventListener {
	/**
	 * Constructor for TestEventListener
	 *
	 * @param LoggerInterface $logger The logger service for logging events
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function handle(Event $event): void {
		// Log that we received ANY event first.
		$this->logger->info(
			'Stackiq TestEventListener: Event received!',
			[
				'eventClass' => get_class($event),
				'timestamp' => date('Y-m-d H:i:s'),
				'microtime' => microtime(true),
			]
		);

		// Handle UserLoggedInEvent specifically.
		if (($event instanceof UserLoggedInEvent) === false) {
			$this->logUnhandled(event: $event);
			return;
		}

		$this->logLogin(event: $event);
	}//end handle()

	/**
	 * Log another event we might receive.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 */
	private function logUnhandled(Event $event): void {
		$this->logger->debug(
			'Stackiq TestEventListener: Received unhandled event',
			[
				'eventClass' => get_class($event),
				'timestamp' => date('Y-m-d H:i:s'),
			]
		);
	}//end logUnhandled()

	/**
	 * Log a login, then that the listener works.
	 *
	 * @param UserLoggedInEvent $event The login event.
	 *
	 * @return void
	 */
	private function logLogin(UserLoggedInEvent $event): void {
		$user = $event->getUser();

		$this->logger->info(
			'Stackiq TestEventListener: User logged in successfully!',
			[
				'userId' => $user->getUID(),
				'userDisplayName' => $user->getDisplayName(),
				'userEmail' => $user->getEMailAddress(),
				'timestamp' => date('Y-m-d H:i:s'),
				'eventType' => 'UserLoggedInEvent',
			]
		);

		$this->confirmWorking(user: $user, event: $event);
	}//end logLogin()

	/**
	 * Test that we can access Nextcloud services: log that the listener works, or the error.
	 *
	 * @param IUser $user The user who logged in.
	 * @param UserLoggedInEvent $event The login event.
	 *
	 * @return void
	 */
	private function confirmWorking(IUser $user, UserLoggedInEvent $event): void {
		try {
			$this->logger->debug(
				'Stackiq TestEventListener: Event listener is working correctly!',
				[
					'message' => 'This confirms that event listeners are properly registered and triggered',
					'userId' => $user->getUID(),
					'eventClass' => get_class($event),
				]
			);
		} catch (\Exception $e) {
			$this->logProcessingError(error: $e);
		}
	}//end confirmWorking()

	/**
	 * Log an error in event processing.
	 *
	 * @param \Exception $error The error.
	 *
	 * @return void
	 */
	private function logProcessingError(\Exception $error): void {
		$this->logger->error(
			'Stackiq TestEventListener: Error in event processing',
			[
				'exception' => $error->getMessage(),
				'trace' => $error->getTraceAsString(),
			]
		);
	}//end logProcessingError()
}//end class
