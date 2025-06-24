<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\SoftwareCatalog\EventListener\SoftwareCatalogEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'softwarecatalog';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';
		
		// Register event listeners for OpenRegister events
		$context->registerEventListener(ObjectCreatedEvent::class, SoftwareCatalogEventListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, SoftwareCatalogEventListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, SoftwareCatalogEventListener::class);
		$context->registerEventListener(ObjectLockedEvent::class, SoftwareCatalogEventListener::class);
		$context->registerEventListener(ObjectUnlockedEvent::class, SoftwareCatalogEventListener::class);
		$context->registerEventListener(ObjectRevertedEvent::class, SoftwareCatalogEventListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
