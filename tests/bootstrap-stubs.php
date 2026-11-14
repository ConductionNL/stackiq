<?php

/**
 * Loads the stubs for server-only classes the unit tests touch.
 *
 * Each stub loads only when the real class is absent. The stubs sit two directories
 * below tests/Stubs so the early glob in tests/bootstrap.php cannot reach them:
 * loaded before Nextcloud boots, the ExpressionBuilder stub shadows Doctrine's
 * and the server cannot finish booting.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// OCP\DB\QueryBuilder\IQueryBuilder initialises its PARAM_* class constants
// from Doctrine constants at class-load, and IExpressionBuilder reads
// Doctrine's ExpressionBuilder, so mocking OCP\IDBConnection fatals without
// them. The real classes ship with the Nextcloud server at runtime.
if (class_exists(\Doctrine\DBAL\ParameterType::class) === false) {
	require_once __DIR__ . '/Stubs/Standalone/Doctrine/ParameterType.php';
	require_once __DIR__ . '/Stubs/Standalone/Doctrine/ArrayParameterType.php';
	require_once __DIR__ . '/Stubs/Standalone/Doctrine/Types.php';
}

if (class_exists(\Doctrine\DBAL\Query\Expression\ExpressionBuilder::class) === false) {
	require_once __DIR__ . '/Stubs/Standalone/Doctrine/ExpressionBuilder.php';
}

// OCP's DownloadResponse builds its Content-Disposition with Symfony's
// HeaderUtils, which only the server ships.
if (class_exists(\Symfony\Component\HttpFoundation\HeaderUtils::class) === false) {
	require_once __DIR__ . '/Stubs/Standalone/Symfony/HeaderUtils.php';
}
