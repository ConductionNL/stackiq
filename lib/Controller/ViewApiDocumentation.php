<?php

/**
 * View API documentation
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
 * @spec openspec/specs/dashboard-views-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

/**
 * The documentation body of ViewController::getApiDocumentation().
 *
 * @spec openspec/specs/dashboard-views-api/spec.md
 */
final class ViewApiDocumentation {
	/**
	 * The `include_products` parameter as documented on several endpoints.
	 *
	 * @var array<string, mixed>
	 */
	private const DOC_PARAM_INCLUDE_PRODUCTS = [
		'name' => 'include_products',
		'type' => 'boolean',
		'required' => false,
		'description' => 'Include product data in view nodes',
	];

	/**
	 * The `include_gebruik` parameter as documented on several endpoints.
	 *
	 * @var array<string, mixed>
	 */
	private const DOC_PARAM_INCLUDE_GEBRUIK = [
		'name' => 'include_gebruik',
		'type' => 'boolean',
		'required' => false,
		'description' => 'Include usage data in view nodes',
	];

	/**
	 * The `include_deelnames_gebruik` parameter as documented on several endpoints.
	 *
	 * @var array<string, mixed>
	 */
	private const DOC_PARAM_INCLUDE_DEELNAMES_GEBRUIK = [
		'name' => 'include_deelnames_gebruik',
		'type' => 'boolean',
		'required' => false,
		'description' => 'Include participation usage data in view nodes',
	];

	/**
	 * The static documentation of the View API endpoints.
	 *
	 * @var array<string, mixed>
	 */
	public const DOCUMENTATION = [
		'api_version' => '1.0.0',
		'description' => 'Stackiq View API - Query and enrich ArchiMate views',
		'base_url' => '/api/views',
		'endpoints' => [
			[
				'method' => 'GET',
				'path' => '/api/views',
				'description' => 'Get all views with optional enrichment',
				'parameters' => [
					self::DOC_PARAM_INCLUDE_PRODUCTS,
					self::DOC_PARAM_INCLUDE_GEBRUIK,
					self::DOC_PARAM_INCLUDE_DEELNAMES_GEBRUIK,
				],
				'response_example' => [
					'success' => true,
					'views' => [
						[
							'id' => 'view-lv01',
							'name' => 'LV01 BGT basisregistratie en SVB view',
							'documentation' => 'Mocked from GEMMA LV01.',
							'viewNodes' => [],
							'viewRelationships' => [],
						],
					],
					'count' => 1,
					'enrichments_applied' => [],
				],
			],
			[
				'method' => 'GET',
				'path' => '/api/views/{viewId}',
				'description' => 'Get a specific view by ID with optional enrichment',
				'parameters' => [
					[
						'name' => 'viewId',
						'type' => 'string',
						'required' => true,
						'description' => 'The view identifier (in URL path)',
					],
					self::DOC_PARAM_INCLUDE_PRODUCTS,
					self::DOC_PARAM_INCLUDE_GEBRUIK,
					self::DOC_PARAM_INCLUDE_DEELNAMES_GEBRUIK,
				],
				'response_example' => [
					'success' => true,
					'view' => [
						'id' => 'view-lv01',
						'name' => 'LV01 BGT basisregistratie en SVB view',
						'documentation' => 'Mocked from GEMMA LV01.',
						'viewNodes' => [],
						'viewRelationships' => [],
					],
					'enrichments_applied' => [],
				],
			],
			[
				'method' => 'GET',
				'path' => '/api/views/docs',
				'description' => 'Get this API documentation',
				'parameters' => [],
				'response_example' => '(this response)',
			],
		],
		'enrichment_options' => [
			[
				'name' => 'products',
				'description' => 'Adds product information to nodes with product associations',
			],
			[
				'name' => 'usage',
				'description' => 'Adds usage/usage statistics to view nodes',
			],
			[
				'name' => 'deelnames_gebruik',
				'description' => 'Adds participation-based usage data to view nodes created from participation records',
			],
		],
		'boolean_parameter_formats' => [
			'accepted_true_values' => ['true', '1', 'yes', 'on', true, 1],
			'accepted_false_values' => ['false', '0', 'no', 'off', false, 0],
			'examples' => [
				'/api/views?include_products=true',
				'/api/views?include_gebruik=1&include_products=false',
				'/api/views/view-123?include_deelnames_gebruik=yes',
			],
		],
	];
}//end class
