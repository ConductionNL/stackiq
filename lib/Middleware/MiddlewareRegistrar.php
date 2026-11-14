<?php

/**
 * The Middleware component's registrations
 *
 * @category  Middleware
 * @package   OCA\Stackiq\Middleware
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Middleware;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers this app's middleware. The AppInfo bootstrap calls this instead
 * of naming each middleware.
 *
 * @category Middleware
 * @package  OCA\Stackiq\Middleware
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class MiddlewareRegistrar {

	/**
	 * Register the middleware.
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// API-Version header and problem details on the JSON API (spec api-contract).
		$context->registerMiddleware(ApiContractMiddleware::class);
	}//end register()
}//end class
