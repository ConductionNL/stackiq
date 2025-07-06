<?php

/**
 * Hierarchy Handler for Software Catalog
 *
 * This handler manages organizational hierarchy, beheerder assignments,
 * and manager relationships within organizations.
 *
 * @category Handler
 * @package  OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\SoftwareCatalogue;

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use Psr\Log\LoggerInterface;

/**
 * Handler for organizational hierarchy management
 *
 * @category Handler
 * @package  OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class HierarchyHandler
{
    /**
     * HierarchyHandler constructor
     *
     * @param OrganizationHandler   $_organizationHandler  Organization handler
     * @param ContactPersonHandler  $_contactPersonHandler Contact person handler
     * @param LoggerInterface       $_logger               Logger interface
     */
    public function __construct(
        private readonly OrganizationHandler $_organizationHandler,
        private readonly ContactPersonHandler $_contactPersonHandler,
        private readonly LoggerInterface $_logger,
    ) {
    }

    /**
     * Ensures organization has at least one beheerder and manages user hierarchy
     *
     * @param object $contactgegevensObject The contactgegevens object
     * @param string $username              The username being processed
     * 
     * @return void
     */
    public function ensureOrganizationBeheerder(object $contactgegevensObject, string $username): void
    {
        try {
            $objectData = $contactgegevensObject->getObject();
            $organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';
            
            if (empty($organizationUuid)) {
                $this->_logger->debug('No organization linked to contactgegevens');
                return;
            }

            // Get organization and check for existing beheerders
            $organizationBeheerders = $this->_organizationHandler->getOrganizationBeheerders($organizationUuid);
            
            if (empty($organizationBeheerders)) {
                // No beheerders found - make this user the beheerder
                $this->_contactPersonHandler->assignBeheerderRole($contactgegevensObject, $username, $organizationUuid);
                $organizationBeheerders = [$username]; // Update our list
            }
            
            // Set up manager relationships
            $this->setupManagerRelationships($username, $organizationBeheerders, $organizationUuid);
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to ensure organization beheerder: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Sets up manager relationships for users in an organization
     *
     * @param string $username                The current username being processed
     * @param array  $organizationBeheerders  Array of beheerder usernames
     * @param string $organizationUuid        The organization UUID
     * 
     * @return void
     */
    public function setupManagerRelationships(string $username, array $organizationBeheerders, string $organizationUuid): void
    {
        try {
            if (empty($organizationBeheerders)) {
                return;
            }
            
            // The oldest beheerder becomes the manager
            $primaryManager = $organizationBeheerders[0];
            
            // If current user is not a beheerder, set their manager
            if (!in_array($username, $organizationBeheerders)) {
                $this->_contactPersonHandler->setUserManager($username, $primaryManager);
            }
            
            // If there are multiple beheerders, set the primary as manager for others
            if (count($organizationBeheerders) > 1) {
                foreach ($organizationBeheerders as $beheerder) {
                    if ($beheerder !== $primaryManager) {
                        $this->_contactPersonHandler->setUserManager($beheerder, $primaryManager);
                    }
                }
            }
            
            $this->_logger->info(
                'Set up manager relationships',
                [
                    'organization' => $organizationUuid,
                    'primaryManager' => $primaryManager,
                    'allBeheerders' => $organizationBeheerders,
                    'processedUser' => $username
                ]
            );
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to setup manager relationships: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'organization' => $organizationUuid,
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Gets organizational hierarchy information for a user
     *
     * @param string $username The username to get hierarchy for
     * 
     * @return array Array containing hierarchy information
     */
    public function getUserHierarchy(string $username): array
    {
        try {
            $hierarchy = [
                'username' => $username,
                'manager' => null,
                'subordinates' => [],
                'organization' => null,
                'isBeheerder' => false,
                'isPrimaryManager' => false
            ];

            // Get user's manager
            $manager = $this->_contactPersonHandler->getUserManager($username);
            if ($manager) {
                $hierarchy['manager'] = $manager;
            }

            // Find subordinates (users who have this user as manager)
            $subordinates = $this->findSubordinates($username);
            $hierarchy['subordinates'] = $subordinates;

            // Check if user is a beheerder
            $hierarchy['isBeheerder'] = $this->isUserBeheerder($username);

            // Check if user is primary manager (has subordinates and no manager)
            $hierarchy['isPrimaryManager'] = empty($hierarchy['manager']) && !empty($hierarchy['subordinates']);

            return $hierarchy;

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to get user hierarchy: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );
            return [];
        }
    }

    /**
     * Finds all subordinates for a given user
     *
     * @param string $username The username to find subordinates for
     * 
     * @return array Array of subordinate usernames
     */
    private function findSubordinates(string $username): array
    {
        $subordinates = [];
        
        try {
            // Get all users and check their managers
            $userManager = \OC::$server->getUserManager();
            $users = $userManager->search('');
            
            foreach ($users as $user) {
                $userUsername = $user->getUID();
                $manager = $this->_contactPersonHandler->getUserManager($userUsername);
                
                if ($manager === $username) {
                    $subordinates[] = $userUsername;
                }
            }
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to find subordinates: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );
        }
        
        return $subordinates;
    }

    /**
     * Checks if a user is a beheerder
     *
     * @param string $username The username to check
     * 
     * @return bool True if user is a beheerder
     */
    private function isUserBeheerder(string $username): bool
    {
        try {
            $groupManager = \OC::$server->getGroupManager();
            $beheerderGroup = $groupManager->get('beheerder');
            
            if (!$beheerderGroup) {
                return false;
            }
            
            $userManager = \OC::$server->getUserManager();
            $user = $userManager->get($username);
            
            return $user && $beheerderGroup->inGroup($user);
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to check if user is beheerder: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );
            return false;
        }
    }

    /**
     * Gets complete organizational structure
     *
     * @param string $organizationUuid The organization UUID
     * 
     * @return array Array containing organizational structure
     */
    public function getOrganizationStructure(string $organizationUuid): array
    {
        try {
            $structure = [
                'organization' => $organizationUuid,
                'beheerders' => [],
                'primaryManager' => null,
                'hierarchy' => []
            ];

            // Get all beheerders for this organization
            $beheerders = $this->_organizationHandler->getOrganizationBeheerders($organizationUuid);
            $structure['beheerders'] = $beheerders;

            if (!empty($beheerders)) {
                $structure['primaryManager'] = $beheerders[0];
            }

            // Build hierarchy tree
            foreach ($beheerders as $beheerder) {
                $hierarchy = $this->getUserHierarchy($beheerder);
                $structure['hierarchy'][] = $hierarchy;
            }

            return $structure;

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to get organization structure: ' . $e->getMessage(),
                [
                    'organization' => $organizationUuid,
                    'exception' => $e
                ]
            );
            return [];
        }
    }
} 