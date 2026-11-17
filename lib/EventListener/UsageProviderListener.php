<?php

/**
 * Usage Provider Listener
 *
 * Sets a usage's hidden provider to its application's supplier on every save.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\UsageProviderSync;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Before a usage is stored, its provider is set to the supplier of the
 * module it names (UsageProviderSync), in the same write: the usage form
 * hides the field, and the supplier reads usages through it.
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
 */
class UsageProviderListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Handle a pre-save event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->apply(event: $event, object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->apply(event: $event, object: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Set the provider on a usage write.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-save event.
	 * @param ObjectEntity                            $object The object being written.
	 *
	 * @return void
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		$usageSchema = $this->container->get(ObjectTypeLocatorInterface::class)->getSchemaIdForObjectType('usage');
		if ($usageSchema === null || ((int)$object->getSchema()) !== $usageSchema) {
			return;
		}

		try {
			$usage = array_merge((array)($object->getObject() ?? []), $event->getModifiedData());
			$sync  = $this->container->get(UsageProviderSync::class);
			$patch = UsageProviderSync::patch(usage: $usage, supplier: $sync->supplierFor(usage: $usage));
		} catch (Throwable $exception) {
			$this->container->get(LoggerInterface::class)->warning(
				'UsageProviderListener: could not resolve the supplier',
				['exception' => $exception->getMessage()]
			);
			return;
		}

		if ($patch !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $patch));
		}
	}//end apply()
}//end class
