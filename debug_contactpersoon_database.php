<?php
/**
 * Debug script to check what's actually in the database for the contactpersoon
 */

echo "=== DEBUG CONTACTPERSOON DATABASE ===\n";

// The contactpersoon ID from the API response
$contactpersoonId = 'e8ba22ba-35ab-4b59-a097-91df6fa2cd3d';

echo "Contactpersoon ID: $contactpersoonId\n\n";

echo "1. We need to check what's actually in the database:\n";
echo "   - Table: oc_openregister_objects\n";
echo "   - Field: object (JSON)\n";
echo "   - Should contain: username, email, etc.\n\n";

echo "2. Current API response shows:\n";
echo "   - hasUser: false\n";
echo "   - username: ''\n";
echo "   - disabled: false\n\n";

echo "3. But user says this contactpersoon HAS a user!\n\n";

echo "4. POSSIBLE CAUSES:\n";
echo "   a) Username field is empty/null in database but user exists in Nextcloud\n";
echo "   b) Username field has different name (not 'username')\n";
echo "   c) Data structure is different than expected\n";
echo "   d) Contactpersoon data was corrupted or not properly saved\n\n";

echo "5. TO DEBUG:\n";
echo "   a) Check the actual database record\n";
echo "   b) Check if username is stored under a different field name\n";
echo "   c) Check if the user exists in Nextcloud but isn't linked properly\n";
echo "   d) Check if there's a mismatch between what we expect vs reality\n\n";

echo "6. NEXT STEPS:\n";
echo "   - Run a direct database query to see the raw data\n";
echo "   - Check if username is stored differently\n";
echo "   - Verify the user actually exists in Nextcloud\n";
echo "   - Fix the data or the logic\n\n";

echo "=== END DEBUG ===\n";
?>
