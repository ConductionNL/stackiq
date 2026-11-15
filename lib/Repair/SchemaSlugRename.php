<?php

/**
 * One planned schema slug rename.
 *
 * The slug moved away from, the slug moved to, the app it collided with, and
 * the ids of this app's schemas found under each. Built by
 * {@see RenameCollidingSchemaSlugs} for one rename and never handed out.
 *
 * @category Repair
 * @package  OCA\Stackiq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://stackiq.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

/**
 * State of one schema slug rename.
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
 */
final class SchemaSlugRename {

	/**
	 * The slug being moved away from.
	 *
	 * @var string
	 */
	public string $from = '';

	/**
	 * The namespaced slug.
	 *
	 * @var string
	 */
	public string $to = '';

	/**
	 * The app this slug collided with.
	 *
	 * @var string
	 */
	public string $with = '';

	/**
	 * Ids of this app's schemas carrying the old slug, or null when unreadable.
	 *
	 * @var array<int, mixed>|null
	 */
	public ?array $old = null;

	/**
	 * Ids of this app's schemas carrying the new slug, or null when unreadable.
	 *
	 * @var array<int, mixed>|null
	 */
	public ?array $new = null;
}//end class
