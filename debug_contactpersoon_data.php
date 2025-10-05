<?php
/**
 * Debug script to check what's in the database for the contactpersoon
 */

echo "=== DEBUG CONTACTPERSOON DATABASE DATA ===\n";

// The contactpersoon ID from the API response
$contactpersoonId = 'e8ba22ba-35ab-4b59-a097-91df6fa2cd3d';

echo "Contactpersoon ID: $contactpersoonId\n\n";

echo "1. Checking what's in the database for this contactpersoon...\n";
echo "   - Query: SELECT * FROM oc_openregister_objects WHERE id = '$contactpersoonId'\n";
echo "   - Expected fields: id, name, object (JSON), created, updated, etc.\n\n";

echo "2. The 'object' field should contain JSON with:\n";
echo "   - username: the Nextcloud username\n";
echo "   - email: the email address\n";
echo "   - other contactpersoon data\n\n";

echo "3. If username is empty or null, the service returns:\n";
echo "   - hasUser: false\n";
echo "   - username: ''\n";
echo "   - disabled: false (default for non-users)\n\n";

echo "4. POSSIBLE ISSUES:\n";
echo "   a) Username field is empty in database but user exists in Nextcloud\n";
echo "   b) Username field has different format than expected\n";
echo "   c) User was disabled but username was removed from contactpersoon data\n";
echo "   d) There's a mismatch between what we think the username is vs what's stored\n\n";

echo "5. TO FIX THIS:\n";
echo "   a) Check the actual database record\n";
echo "   b) Verify what username should be associated with this contactpersoon\n";
echo "   c) Update the contactpersoon data with the correct username\n";
echo "   d) Or fix the logic to handle this edge case\n\n";

echo "=== END DEBUG ===\n";
?>
