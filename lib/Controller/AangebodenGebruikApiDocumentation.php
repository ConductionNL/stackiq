<?php

/**
 * AangebodenGebruik API documentation
 *
 * The static documentation served by the docs endpoint.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

/**
 * The documentation body of AangebodenGebruikController::getApiDocumentation().
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */
final class AangebodenGebruikApiDocumentation {
	/**
	 * The `limit` parameter as documented on several endpoints.
	 *
	 * @var array<string, mixed>
	 */
	private const DOC_PARAM_LIMIT = [
		'name' => 'limit',
		'type' => 'integer',
		'required' => false,
		'description' => 'Maximum number of results to return',
	];

	/**
	 * The `offset` parameter as documented on several endpoints.
	 *
	 * @var array<string, mixed>
	 */
	private const DOC_PARAM_OFFSET = [
		'name' => 'offset',
		'type' => 'integer',
		'required' => false,
		'description' => 'Number of results to skip for pagination',
	];

	/**
	 * The `status` parameter as documented on several endpoints.
	 *
	 * @var array<string, mixed>
	 */
	private const DOC_PARAM_STATUS = [
		'name' => 'status',
		'type' => 'string',
		'required' => false,
		'description' => 'Filter by usage status',
	];

	/**
	 * The `organisation` parameter as documented on several endpoints.
	 *
	 * @var array<string, mixed>
	 */
	private const DOC_PARAM_ORGANISATION = [
		'name' => 'organisation',
		'type' => 'string',
		'required' => false,
		'description' => 'Filter by organization UUID (only for ambtenaar users)',
	];

	/**
	 * The static documentation of the AangebodenGebruik API endpoints.
	 *
	 * @var array<string, mixed>
	 */
	public const DOCUMENTATION = [
		'api_version' => '2.0.0',
		'description' => 'Stackiq AangebodenGebruik API',
		'base_url' => '/api/aangeboden-gebruik',
		'endpoints' => [
			[
				'method' => 'GET',
				'path' => '/api/aangeboden-gebruik/afnemer',
				'description' => 'Get all gebruiks objects where the active organization is the afnemer (consumer)',
				'parameters' => [
					self::DOC_PARAM_LIMIT,
					self::DOC_PARAM_OFFSET,
					self::DOC_PARAM_STATUS,
					[
						'name' => 'product',
						'type' => 'string',
						'required' => false,
						'description' => 'Filter by product ID',
					],
					[
						'name' => 'startDate',
						'type' => 'string',
						'required' => false,
						'description' => 'Filter by start date (ISO 8601 format)',
					],
					[
						'name' => 'endDate',
						'type' => 'string',
						'required' => false,
						'description' => 'Filter by end date (ISO 8601 format)',
					],
				],
			],
			[
				'method' => 'GET',
				'path' => '/api/aangeboden-gebruik/deelnemers',
				'description' => 'Get gebruiks with active org in deelnemers',
				'parameters' => [
					self::DOC_PARAM_LIMIT,
					self::DOC_PARAM_OFFSET,
					self::DOC_PARAM_STATUS,
				],
			],
			[
				'method' => 'PUT',
				'path' => '/api/aangeboden-gebruik/{gebruikId}/set-self',
				'description' => 'Set the @self property of a gebruik or koppeling to the active organization',
				'parameters' => [
					[
						'name' => 'gebruikId',
						'type' => 'string',
						'required' => true,
						'description' => 'The UUID of the gebruik object to update (in URL path)',
					],
				],
			],
			[
				'method' => 'GET',
				'path' => '/api/aangeboden-gebruik/docs',
				'description' => 'Get this API documentation',
				'parameters' => [],
			],
			[
				'method' => 'GET',
				'path' => '/api/koppelingen-gebruik',
				'description' => 'Get all koppelingen and gebruiks objects with extended access control.',
				'parameters' => [
					self::DOC_PARAM_ORGANISATION,
					self::DOC_PARAM_LIMIT,
					self::DOC_PARAM_OFFSET,
				],
			],
			[
				'method' => 'GET',
				'path' => '/api/koppelingen-gebruik/{uuid}',
				'description' => 'Get koppelingen and gebruiks for a specific application/module UUID.',
				'parameters' => [
					[
						'name' => 'uuid',
						'type' => 'string',
						'required' => true,
						'description' => 'The UUID of the application/module (in URL path)',
					],
					self::DOC_PARAM_ORGANISATION,
				],
			],
		],
		'security' => [
			'afnemer_filtering' => 'Uses standard RBAC filtering based on organization association',
			'deelnemers_filtering' => 'Uses RBAC-disabled search to find participation records',
			'self_update_permission' => 'Only allowed if active organization is the afnemer or aanbieder',
		],
		'error_codes' => [
			400 => 'Bad Request - Invalid parameters or missing required fields',
			403 => 'Forbidden - Operation not allowed',
			404 => 'Not Found - Gebruik object not found',
			500 => 'Internal Server Error - Server-side error occurred',
		],
	];
}//end class
