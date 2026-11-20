<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * A copy of OpenRegister main's RecipientResolverInterface
 * (lib/Notifications/Service/Notification/RecipientResolverInterface.php), so
 * a resolver can be tested without OpenRegister installed.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

interface RecipientResolverInterface {
	/**
	 * Resolve the recipient uids for a notification dispatch.
	 *
	 * @param ObjectEntity         $object  The object the event happened on.
	 * @param array<string, mixed> $context Trigger-specific extras.
	 *
	 * @return array<int, string> List of Nextcloud uids.
	 */
	public function resolve(ObjectEntity $object, array $context): array;
}
