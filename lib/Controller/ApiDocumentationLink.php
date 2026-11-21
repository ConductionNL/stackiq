<?php

/**
 * Where the generated API documentation lives.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-003-the-hand-written-documentation-points-to-the-generated-documents
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

/**
 * The `documentation` entry the hand-written documentation bodies carry.
 *
 * `GET /api/views/docs` and `GET /api/aangeboden-gebruik/docs` keep their
 * bodies for the integrations that read them, and point to the API
 * documentation page and the OpenAPI document that describe every endpoint.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-003-the-hand-written-documentation-points-to-the-generated-documents
 */
final class ApiDocumentationLink {
	/**
	 * The documentation entry: the page, the OpenAPI document and why to use them.
	 *
	 * @var array{page: string, openapi: string, note: string}
	 */
	public const LINK = [
		'page' => '/apps/stackiq/api-docs',
		'openapi' => '/apps/stackiq/api/openapi.json',
		'note' => 'This hand-written description is deprecated. The API documentation page and the OpenAPI document describe every endpoint.',
	];
}//end class
