<?php

/**
 * Example usage of ContactpersoonService with user details
 *
 * This file demonstrates how to use the new getContactPersonsWithUserDetailsForOrganization
 * method to retrieve contact persons for an organization with their user details spliced in.
 *
 * @category  Example
 * @package   OCA\SoftwareCatalog\Examples
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Examples;

use OCA\SoftwareCatalog\Service\ContactpersoonService;
use Psr\Log\LoggerInterface;

/**
 * Example class demonstrating ContactpersoonService usage.
 *
 * @category Example
 * @package  OCA\SoftwareCatalog\Examples
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
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

			$this->logger->info(
				'ContactpersoonServiceExample: Retrieved contact persons',
				[
					'organizationUuid' => $organizationUuid,
					'contactPersonCount' => count($contactPersons),
				]
			);

			// Process the results.
			$processedResults = [];
			foreach ($contactPersons as $contactPerson) {
				$contactData = $contactPerson->getObject();

				$processedResults[] = [
					'contactPersonId' => $contactPerson->getId(),
					'contactPersonUuid' => $contactPerson->getUuid(),
					'name' => $contactData['naam'] ?? 'Unknown',
					'email' => $contactData['email'] ?? null,
					'username' => $contactData['username'] ?? null,
					'hasUserDetails' => $contactData['userDetails'] !== null,
					'userDetails' => $contactData['userDetails'],
				];
			}

			return $processedResults;
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
			'parameters' => [
				'organizationUuid' => [
					'type' => 'string',
					'required' => true,
					'description' => 'The UUID of the organization to get contact persons for',
				],
			],
			'response' => [
				'success' => true,
				'data' => [
					[
						'id' => 'contact_person_id',
						'uuid' => 'contact_person_uuid',
						'object' => [
							'naam' => 'John Doe',
							'email' => 'john.doe@example.com',
							'username' => 'john.doe@example.com',
							'userDetails' => [
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
							],
						],
						'register' => 6,
						'schema' => 38,
						'created' => '2024-01-01T00:00:00Z',
						'modified' => '2024-01-01T00:00:00Z',
					],
				],
				'count' => 1,
				'organizationUuid' => $organizationUuid,
			],
		];
	}//end getApiCallExample()
}//end class
