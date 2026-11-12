<?php

/**
 * Example usage of ContactpersoonService with user details
 *
 * This file demonstrates how to use the new getContactPersonsWithUserDetailsForOrganization
 * method to retrieve contact persons for an organization with their user details spliced in.
 *
 * @category  Example
 * @package   OCA\Stackiq\Examples
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Examples;

use OCA\Stackiq\Service\ContactpersoonService;
use Psr\Log\LoggerInterface;

/**
 * Example class demonstrating ContactpersoonService usage.
 *
 * @category Example
 * @package  OCA\Stackiq\Examples
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 */
class ContactpersoonServiceExample {
	/**
	 * ContactpersoonServiceExample constructor
	 *
	 * @param ContactpersoonService $contactSvc The contactpersoon service
	 * @param LoggerInterface $logger Logger interface
	 */
	public function __construct(
		private readonly ContactpersoonService $contactSvc,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Example method showing how to get contact persons with user details for an organization
	 *
	 * @param string $organizationUuid The organization UUID to get contact persons for
	 *
	 * @return array Array of contact persons with user details
	 *
	 * @throws \Exception If contact person retrieval fails
	 */
	public function getContactPersonsWithUserDetailsExample(string $organizationUuid): array {
		try {
			return $this->processedContactPersons(organizationUuid: $organizationUuid);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonServiceExample: Failed to get contact persons with user details',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try
	}//end getContactPersonsWithUserDetailsExample()

	/**
	 * Fetch the organisation's contact persons and process each one into a result row.
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array Processed contact person rows
	 */
	private function processedContactPersons(string $organizationUuid): array {
		$contactPersons = $this->fetchContactPersons(organizationUuid: $organizationUuid);

		// Process the results.
		$processedResults = [];
		foreach ($contactPersons as $contactPerson) {
			$processedResults[] = $this->processContactPerson(contactPerson: $contactPerson);
		}

		return $processedResults;
	}//end processedContactPersons()

	/**
	 * Fetch the contact persons with user details, logging before and after.
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array The contact person objects
	 */
	private function fetchContactPersons(string $organizationUuid): array {
		$this->logger->info(
			'ContactpersoonServiceExample: Getting contact persons with user details',
			[
				'organizationUuid' => $organizationUuid,
			]
		);

		// Use the service method to get contact persons with user details.
		$contactPersons = $this->contactSvc->getContactPersonsWithUserDetailsForOrganization(
			organizationUuid: $organizationUuid
		);

		$this->logRetrieved(organizationUuid: $organizationUuid, contactPersons: $contactPersons);

		return $contactPersons;
	}//end fetchContactPersons()

	/**
	 * Log how many contact persons were retrieved.
	 *
	 * @param string $organizationUuid The organization UUID
	 * @param array  $contactPersons   The retrieved contact persons
	 *
	 * @return void
	 */
	private function logRetrieved(string $organizationUuid, array $contactPersons): void {
		$this->logger->info(
			'ContactpersoonServiceExample: Retrieved contact persons',
			[
				'organizationUuid' => $organizationUuid,
				'contactPersonCount' => count($contactPersons),
			]
		);
	}//end logRetrieved()

	/**
	 * Turn one contact person object into a result row.
	 *
	 * @param object $contactPerson The contact person object
	 *
	 * @return array The result row
	 */
	private function processContactPerson(object $contactPerson): array {
		$contactData = $contactPerson->getObject();

		return [
			'contactPersonId' => $contactPerson->getId(),
			'contactPersonUuid' => $contactPerson->getUuid(),
			'name' => $contactData['name'] ?? 'Unknown',
			'email' => $contactData['email'] ?? null,
			'username' => $contactData['username'] ?? null,
			'hasUserDetails' => $contactData['userDetails'] !== null,
			'userDetails' => $contactData['userDetails'],
		];
	}//end processContactPerson()

	/**
	 * Example method showing how to use the API endpoint
	 *
	 * This demonstrates how to call the new API endpoint from a frontend application
	 * or external service.
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array Example API call structure
	 */
	public function getApiCallExample(string $organizationUuid): array {
		return [
			'method' => 'GET',
			'url' => '/api/contactpersonen/organisation/' . $organizationUuid . '/with-user-details',
			'description' => 'Get all contact persons for an organization with user details spliced in',
			'parameters' => $this->apiCallParameters(),
			'response' => $this->apiCallResponse(organizationUuid: $organizationUuid),
		];
	}//end getApiCallExample()

	/**
	 * The parameters the example API call takes.
	 *
	 * @return array Parameter descriptions keyed by name
	 */
	private function apiCallParameters(): array {
		return [
			'organizationUuid' => [
				'type' => 'string',
				'required' => true,
				'description' => 'The UUID of the organization to get contact persons for',
			],
		];
	}//end apiCallParameters()

	/**
	 * The example response of the API call.
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array Example response body
	 */
	private function apiCallResponse(string $organizationUuid): array {
		return [
			'success' => true,
			'data' => [
				$this->sampleContactPerson(),
			],
			'count' => 1,
			'organizationUuid' => $organizationUuid,
		];
	}//end apiCallResponse()

	/**
	 * One sample contact person as the API returns it.
	 *
	 * @return array Sample contact person
	 */
	private function sampleContactPerson(): array {
		return [
			'id' => 'contact_person_id',
			'uuid' => 'contact_person_uuid',
			'object' => $this->sampleContactObject(),
			'register' => 6,
			'schema' => 38,
			'created' => '2024-01-01T00:00:00Z',
			'modified' => '2024-01-01T00:00:00Z',
		];
	}//end sampleContactPerson()

	/**
	 * The object data of the sample contact person.
	 *
	 * @return array Sample contact person object data
	 */
	private function sampleContactObject(): array {
		return [
			'name' => 'John Doe',
			'email' => 'john.doe@example.com',
			'username' => 'john.doe@example.com',
			'userDetails' => $this->sampleUserDetails(),
		];
	}//end sampleContactObject()

	/**
	 * The user details spliced into the sample contact person.
	 *
	 * @return array Sample user details
	 */
	private function sampleUserDetails(): array {
		return [
			'uid' => 'john.doe@example.com',
			'email' => 'john.doe@example.com',
			'displayName' => 'John Doe',
			'enabled' => true,
			'lastLogin' => 1640995200,
			'backend' => 'Database',
			'home' => '/var/www/html/data/john.doe@example.com',
			'avatarImage' => 'base64_encoded_image_data',
			'quota' => '1GB',
			'freeQuota' => '500MB',
		];
	}//end sampleUserDetails()
}//end class
