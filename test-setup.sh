#!/bin/bash
# Test Environment Setup Script for GEMMA Softwarecatalogus
# Creates test organizations, contact persons, and links users.
# Run after clean-env.sh and user account creation.
#
# Prerequisites:
#   - Nextcloud running at localhost:8080 with admin:admin
#   - OpenRegister app enabled with voorzieningen register loaded
#   - User accounts already created (see create-test-users section below)
#
# Usage:
#   bash Softwarecatalogus/test-setup.sh
#   BACKEND_URL="https://example.com" ADMIN_USER="user" ADMIN_PASS="pass" bash Softwarecatalogus/test-setup.sh
#   FORCE_BUILD=1 bash Softwarecatalogus/test-setup.sh          # Rebuild all frontend apps
#   CLEANUP_DUPLICATES=1 bash Softwarecatalogus/test-setup.sh   # Remove duplicate test objects from previous runs

set -euo pipefail

NC_URL="${BACKEND_URL:-http://localhost:8080}"
BASE_URL="${NC_URL}/index.php/apps/openregister/api"
ADMIN_AUTH="${ADMIN_USER:-admin}:${ADMIN_PASS:-admin}"
PASSWORD="WelcomeToTest2026"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE_DIR="$(dirname "$SCRIPT_DIR")"

echo "=== Softwarecatalogus Test Setup ==="
echo ""

# ─────────────────────────────────────────────
# Step 0: Pre-flight checks
# Stop conflicting containers, build frontend apps.
# ─────────────────────────────────────────────
echo "--- Step 0: Pre-flight checks ---"

# 0a. Stop tilburg-woo-ui-hot if running (frees port 3000 for the SPA)
if docker ps --format '{{.Names}}' 2>/dev/null | grep -q 'tilburg-woo-ui-hot'; then
    echo "  Stopping tilburg-woo-ui-hot container (frees port 3000)..."
    docker stop openregister-tilburg-woo-ui-hot 2>/dev/null || true
    echo "  Stopped."
else
    echo "  tilburg-woo-ui-hot not running (port 3000 is free)."
fi

# 0b. Build frontend apps if sources are newer than builds
build_if_needed() {
    local app_dir="$1"
    local app_name="$2"
    local build_dir="${app_dir}/js"

    if [ ! -d "$app_dir" ]; then
        echo "  SKIP: ${app_name} directory not found"
        return
    fi

    # Check if build output exists
    if [ -d "$build_dir" ] && [ -n "$(ls -A "$build_dir" 2>/dev/null)" ]; then
        echo "  ${app_name}: build exists (use FORCE_BUILD=1 to rebuild)"
    else
        echo "  ${app_name}: no build found, building..."
        (cd "$app_dir" && npm run build 2>&1 | tail -1) || echo "  WARN: ${app_name} build failed"
    fi
}

if [ "${FORCE_BUILD:-0}" = "1" ]; then
    echo "  FORCE_BUILD=1 — rebuilding all frontend apps..."
    for app in openregister opencatalogi; do
        app_dir="${WORKSPACE_DIR}/${app}"
        if [ -d "$app_dir/src" ]; then
            echo "  Building ${app}..."
            (cd "$app_dir" && npm run build 2>&1 | tail -1) || echo "  WARN: ${app} build failed"
        fi
    done
    # tilburg-woo-ui uses a different build command
    twui_dir="${WORKSPACE_DIR}/tilburg-woo-ui"
    if [ -d "$twui_dir" ]; then
        echo "  Building tilburg-woo-ui..."
        docker exec openregister-tilburg-woo-ui sh -c "npm run build:web" 2>&1 | tail -1 || echo "  WARN: tilburg-woo-ui build failed"
    fi
else
    build_if_needed "${WORKSPACE_DIR}/openregister" "openregister"
    build_if_needed "${WORKSPACE_DIR}/opencatalogi" "opencatalogi"
    # tilburg-woo-ui builds inside its container
    if docker ps --format '{{.Names}}' 2>/dev/null | grep -q 'openregister-tilburg-woo-ui'; then
        echo "  tilburg-woo-ui: container running (build managed by container)"
    else
        echo "  tilburg-woo-ui: container not running — start with docker compose up"
    fi
fi

# 0c. Verify Nextcloud is reachable
if ! curl -sf -o /dev/null "${NC_URL}/status.php" 2>/dev/null; then
    echo "  ERROR: Nextcloud not reachable at ${NC_URL}"
    echo "  Start the environment first: docker compose -f openregister/docker-compose.yml up -d"
    exit 1
fi
echo "  Nextcloud is reachable at ${NC_URL}"

echo ""

# ─────────────────────────────────────────────
# Step 0b: Initialize app configurations
# ─────────────────────────────────────────────
echo "--- Step 0b: Initializing app configurations ---"

# Initialize SWC settings (imports softwarecatalogus_register.json)
SWC_INIT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" -X POST \
  "${NC_URL}/index.php/apps/softwarecatalog/api/settings/initialize" \
  -H "Content-Type: application/json" 2>&1)
SWC_CONFIGURED=$(echo "$SWC_INIT" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('fullyConfigured', False))" 2>/dev/null || echo "false")
echo "  SWC settings: configured=$SWC_CONFIGURED"

# Check if OpenCatalogi publication register exists
OC_CATALOG_SCHEMA=$(docker exec nextcloud php occ config:app:get opencatalogi catalog_schema 2>/dev/null || echo "")
if [ -z "$OC_CATALOG_SCHEMA" ]; then
  echo "  OpenCatalogi not configured — importing publication register..."

  # Import OpenCatalogi publication_register.json via OpenRegister config import
  OC_REGISTER_FILE="/var/www/html/custom_apps/opencatalogi/lib/Settings/publication_register.json"
  if docker exec nextcloud test -f "$OC_REGISTER_FILE"; then
    IMPORT_RESULT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" -X POST \
      "${NC_URL}/index.php/apps/openregister/api/configurations/import" \
      -F "file=@-" < <(docker exec nextcloud cat "$OC_REGISTER_FILE") 2>&1)
    IMPORT_OK=$(echo "$IMPORT_RESULT" | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print('OK' if d.get('message','') == 'Import successful' else 'FAIL')" 2>/dev/null || echo "FAIL")
    echo "  Publication register import: $IMPORT_OK"

    # Run repair step to trigger OpenCatalogi auto-configuration
    echo "  Running maintenance:repair for OpenCatalogi initialization..."
    docker exec nextcloud php occ maintenance:repair 2>&1 | grep -i "catalogi\|softwarecatalog" | head -5
  else
    echo "  WARN: publication_register.json not found in opencatalogi app"
  fi
else
  echo "  OpenCatalogi already configured (catalog_schema=$OC_CATALOG_SCHEMA)"
fi

# Re-initialize SWC to configure OpenCatalogi page/menu/theme settings
curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" -X POST \
  "${NC_URL}/index.php/apps/softwarecatalog/api/settings/initialize" \
  -H "Content-Type: application/json" > /dev/null 2>&1

# Verify final state
REGISTER_COUNT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" \
  "${NC_URL}/index.php/apps/openregister/api/registers" 2>&1 | \
  python3 -c "import sys,json; print(len(json.loads(sys.stdin.read()).get('results',[])))" 2>/dev/null || echo "?")
SCHEMA_COUNT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" \
  "${NC_URL}/index.php/apps/openregister/api/schemas" 2>&1 | \
  python3 -c "import sys,json; print(len(json.loads(sys.stdin.read()).get('results',[])))" 2>/dev/null || echo "?")
echo "  Registers: $REGISTER_COUNT, Schemas: $SCHEMA_COUNT"
echo "Done."

echo ""

# ─────────────────────────────────────────────
# Step 1: Create Nextcloud user accounts
# ─────────────────────────────────────────────
echo "--- Step 1: Creating Nextcloud user accounts ---"

create_user() {
    local userid="$1"
    local display="$2"
    local email="$3"

    # Create user (may already exist — that's ok)
    response=$(curl -s -o /dev/null -w "%{http_code}" \
        -X POST "${NC_URL}/ocs/v2.php/cloud/users" \
        -u "${ADMIN_AUTH}" \
        -H "OCS-APIRequest: true" \
        -d "userid=${userid}" \
        --data-urlencode "password=${PASSWORD}" \
        -d "displayName=${display}" \
        -d "email=${email}")

    if [ "$response" = "200" ]; then
        echo "  Created user: ${userid}"
    else
        # User exists — reset password to ensure it matches expected value
        curl -s -o /dev/null \
            -X PUT "${NC_URL}/ocs/v2.php/cloud/users/$(echo "$userid" | sed 's/@/%40/g')" \
            -u "${ADMIN_AUTH}" \
            -H "OCS-APIRequest: true" \
            -d "key=password" \
            --data-urlencode "value=${PASSWORD}"
        echo "  User ${userid} exists (password reset)"
    fi
}

add_to_group() {
    local userid="$1"
    local group="$2"

    curl -s -o /dev/null \
        -X POST "${NC_URL}/ocs/v2.php/cloud/users/${userid}/groups" \
        -u "${ADMIN_AUTH}" \
        -H "OCS-APIRequest: true" \
        --data-urlencode "groupid=${group}" 2>/dev/null
}

# Ensure groups exist
for group in aanbod-beheerder gebruik-beheerder functioneel-beheerder software-catalog-users software-catalog-admins; do
    curl -s -o /dev/null \
        -X POST "${NC_URL}/ocs/v2.php/cloud/groups" \
        -u "${ADMIN_AUTH}" \
        -H "OCS-APIRequest: true" \
        -d "groupid=${group}" 2>/dev/null
done
echo "  Groups ensured."

# Create users
create_user "jan.pietersen@test.nl"    "Jan Pietersen"       "jan.pietersen@test.nl"
create_user "jan.vandeberg@testleverancier.nl" "Jan van de Berg" "jan.vandeberg@testleverancier.nl"
create_user "maria.vanderberg@test.nl" "Maria van der Berg"  "maria.vanderberg@test.nl"
create_user "mark.jansen@test.nl"      "Mark Jansen"         "mark.jansen@test.nl"
create_user "linda.bakker@test.nl"     "Linda Bakker"        "linda.bakker@test.nl"
create_user "peter.vandijk@test.nl"    "Peter van Dijk"      "peter.vandijk@test.nl"
create_user "sarah.devries@test.nl"    "Dr. Sarah de Vries"  "sarah.devries@test.nl"

# Assign groups
echo "  Assigning groups..."
add_to_group "jan.pietersen@test.nl"    "aanbod-beheerder"
add_to_group "jan.pietersen@test.nl"    "software-catalog-users"

add_to_group "jan.vandeberg@testleverancier.nl" "aanbod-beheerder"
add_to_group "jan.vandeberg@testleverancier.nl" "software-catalog-users"

add_to_group "maria.vanderberg@test.nl" "gebruik-beheerder"
add_to_group "maria.vanderberg@test.nl" "software-catalog-users"

add_to_group "mark.jansen@test.nl"      "gebruik-beheerder"
add_to_group "mark.jansen@test.nl"      "software-catalog-users"

add_to_group "linda.bakker@test.nl"     "gebruik-beheerder"
add_to_group "linda.bakker@test.nl"     "software-catalog-users"

add_to_group "peter.vandijk@test.nl"    "functioneel-beheerder"
add_to_group "peter.vandijk@test.nl"    "gebruik-beheerder"
add_to_group "peter.vandijk@test.nl"    "aanbod-beheerder"
add_to_group "peter.vandijk@test.nl"    "software-catalog-admins"
add_to_group "peter.vandijk@test.nl"    "software-catalog-users"

add_to_group "sarah.devries@test.nl"    "gebruik-beheerder"
add_to_group "sarah.devries@test.nl"    "software-catalog-users"

echo "  Groups assigned."

# ─────────────────────────────────────────────
# Step 2: Clear rate limiting / brute force
# ─────────────────────────────────────────────
echo ""
echo "--- Step 2: Clearing rate limiting ---"
docker exec nextcloud php occ security:bruteforce:reset 127.0.0.1 2>/dev/null || true
docker exec nextcloud apachectl -k graceful 2>/dev/null || true
sleep 2  # Wait for Apache restart + OPcache flush
echo "  Done."

# ─────────────────────────────────────────────
# Step 3: Find or create organisations
# We need TWO types of UUIDs per org:
#   - NC UUID: Nextcloud org UUID for join/activate (RBAC system)
#   - REG UUID: Register object UUID for data fields (organisatie, geregistreerdDoor)
# ─────────────────────────────────────────────
echo ""
echo "--- Step 3: Finding/creating organisations ---"

# Cache the full Nextcloud organisations list once
ALL_NC_ORGS=$(curl -s -u "${ADMIN_AUTH}" "${BASE_URL}/organisations?_limit=500")

find_nc_org_uuid() {
    local name="$1"
    echo "$ALL_NC_ORGS" | python3 -c "
import sys, json
d = json.load(sys.stdin)
for r in d.get('results', []):
    if r.get('name', '') == '${name}':
        print(r['uuid'])
        break
" 2>/dev/null
}

create_nc_org() {
    local name="$1"
    local type="$2"
    curl -s -X POST "${BASE_URL}/organisations" \
        -H 'Content-Type: application/json' \
        -u "${ADMIN_AUTH}" \
        -d "{\"name\": \"${name}\", \"type\": \"${type}\"}" \
        | python3 -c "import sys,json; print(json.load(sys.stdin).get('organisation',{}).get('uuid',''))" 2>/dev/null
}

find_reg_org_uuid() {
    local name="$1"
    curl -s -u "${ADMIN_AUTH}" \
        "${BASE_URL}/objects/voorzieningen/organisatie?_search=$(echo "$name" | sed 's/ /+/g')&_limit=5" \
        | python3 -c "
import sys, json
d = json.load(sys.stdin)
for r in d.get('results', []):
    if r.get('naam', '') == '${name}':
        print(r.get('@self', {}).get('id', ''))
        break
" 2>/dev/null
}

create_reg_org() {
    local name="$1"
    local type="$2"
    curl -s -X POST "${BASE_URL}/objects/voorzieningen/organisatie" \
        -H 'Content-Type: application/json' \
        -u "${ADMIN_AUTH}" \
        -d "{
            \"naam\": \"${name}\",
            \"type\": \"${type}\",
            \"status\": \"Actief\"
        }" | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" 2>/dev/null
}

setup_org() {
    local name="$1"
    local type="$2"

    # Find or create Nextcloud org
    local nc_uuid=$(find_nc_org_uuid "$name")
    if [ -z "$nc_uuid" ]; then
        nc_uuid=$(create_nc_org "$name" "$type")
        if [ -n "$nc_uuid" ]; then
            echo "  Created NC org: ${name} (${nc_uuid})" >&2
        else
            echo "  WARN: Failed to create NC org: ${name}" >&2
        fi
    else
        echo "  Found NC org: ${name} (${nc_uuid})" >&2
    fi

    # Find or create register org object
    local reg_uuid=$(find_reg_org_uuid "$name")
    if [ -z "$reg_uuid" ]; then
        reg_uuid=$(create_reg_org "$name" "$type")
        if [ -n "$reg_uuid" ]; then
            echo "  Created register org: ${name} (${reg_uuid})" >&2
        else
            echo "  WARN: Failed to create register org: ${name}" >&2
        fi
    else
        echo "  Found register org: ${name} (${reg_uuid})" >&2
    fi

    # Return both UUIDs: "nc_uuid reg_uuid"
    echo "${nc_uuid} ${reg_uuid}"
}

read LEVER_NC LEVER_REG <<< "$(setup_org 'Test Leverancier BV' 'Leverancier')"
read GEMEENTE_NC GEMEENTE_REG <<< "$(setup_org 'Test Gemeente' 'Gemeente')"
read SAMENWERKING_NC SAMENWERKING_REG <<< "$(setup_org 'Test Samenwerking' 'Samenwerking')"
read LEVER2_NC LEVER2_REG <<< "$(setup_org 'Test Leverancier 2' 'Leverancier')"

echo ""
echo "  Summary:"
echo "    Test Leverancier BV  NC=${LEVER_NC} REG=${LEVER_REG}"
echo "    Test Gemeente        NC=${GEMEENTE_NC} REG=${GEMEENTE_REG}"
echo "    Test Samenwerking    NC=${SAMENWERKING_NC} REG=${SAMENWERKING_REG}"
echo "    Test Leverancier 2   NC=${LEVER2_NC} REG=${LEVER2_REG}"

# ─────────────────────────────────────────────
# Step 4: Join users to their organizations and set active
# (MUST happen before creating objects so system `organisation` field is correct)
# ─────────────────────────────────────────────
echo ""
echo "--- Step 4: Joining users to organizations ---"

join_and_activate() {
    local username="$1"
    local org_uuid="$2"
    local org_name="$3"

    # Join (user must auth as themselves)
    curl -s -X POST "${BASE_URL}/organisations/${org_uuid}/join" \
        -H 'Content-Type: application/json' \
        -u "${username}:${PASSWORD}" > /dev/null 2>&1

    # Set active
    curl -s -X POST "${BASE_URL}/organisations/${org_uuid}/set-active" \
        -H 'Content-Type: application/json' \
        -u "${username}:${PASSWORD}" > /dev/null 2>&1

    echo "  ${username} -> ${org_name} (joined + active)"
}

join_and_activate "jan.pietersen@test.nl"    "$LEVER_NC"       "Test Leverancier BV"
join_and_activate "jan.vandeberg@testleverancier.nl" "$LEVER2_NC" "Test Leverancier 2"
join_and_activate "maria.vanderberg@test.nl" "$GEMEENTE_NC"    "Test Gemeente"
join_and_activate "mark.jansen@test.nl"      "$GEMEENTE_NC"    "Test Gemeente"
join_and_activate "linda.bakker@test.nl"     "$SAMENWERKING_NC" "Test Samenwerking"

# Peter and Sarah stay in Default Organisation (admin/VNG roles)
echo "  peter.vandijk@test.nl -> Default Organisation (admin)"
echo "  sarah.devries@test.nl -> Default Organisation (VNG)"

# Seed Nextcloud user profile fields (firstName, lastName, middleName, functie)
# These are stored separately from contactpersoon objects and used by /api/user/me
echo ""
echo "  Seeding user profile fields..."

seed_profile() {
    local username="$1"
    local first="$2"
    local last="$3"
    local functie="$4"
    local middle="${5:-}"

    local data="{\"firstName\":\"${first}\",\"lastName\":\"${last}\",\"functie\":\"${functie}\""
    if [ -n "$middle" ]; then
        data="${data},\"middleName\":\"${middle}\""
    fi
    data="${data}}"

    curl -s -X PUT "${BASE_URL}/user/me" \
        -H 'Content-Type: application/json' \
        -u "${username}:${PASSWORD}" \
        -d "${data}" > /dev/null 2>&1

    echo "    ${username}: ${first} ${middle} ${last} (${functie})"
}

seed_profile "jan.pietersen@test.nl"    "Jan"    "Pietersen"  "CEO"
seed_profile "jan.vandeberg@testleverancier.nl" "Jan" "Berg" "Directeur" "van de"
seed_profile "maria.vanderberg@test.nl" "Maria"  "Berg"       "ICT-coördinator" "van der"
seed_profile "mark.jansen@test.nl"      "Mark"   "Jansen"     "Security Officer"
seed_profile "linda.bakker@test.nl"     "Linda"  "Bakker"     "Coördinator"
seed_profile "peter.vandijk@test.nl"    "Peter"  "Dijk"       "Functioneel Beheerder" "van"
seed_profile "sarah.devries@test.nl"    "Sarah"  "Vries"      "Enterprise Architect"  "de"

# ─────────────────────────────────────────────
# Step 5: Create contact persons (as the user themselves, NOT admin)
# Using user credentials ensures the system `organisation` field matches
# the user's active org — this is what RBAC filters on.
# ─────────────────────────────────────────────
echo ""
echo "--- Step 5: Creating contact persons ---"

create_contact() {
    local voornaam="$1"
    local achternaam="$2"
    local email="$3"
    local telefoon="$4"
    local functie="$5"
    local org_uuid="$6"
    local tussenvoegsel="${7:-}"
    local user_auth="${email}:${PASSWORD}"

    # Check if contact already exists by searching objects as the user
    existing=$(curl -s -u "${user_auth}" \
        "${BASE_URL}/objects?register=3&schema=14&_search=$(echo "$email" | sed 's/@/%40/g')&_limit=5" \
        | python3 -c "
import sys,json
d=json.load(sys.stdin)
for r in d.get('results',[]):
    if r.get('e-mailadres','') == '${email}':
        print(r.get('@self',{}).get('id',''))
        break
" 2>/dev/null)

    if [ -n "$existing" ] && [ "$existing" != "" ]; then
        echo "  Contact already exists: ${voornaam} ${achternaam} (${existing})" >&2
        echo "$existing"
        return
    fi

    local tv_field=""
    if [ -n "$tussenvoegsel" ]; then
        tv_field="\"tussenvoegsel\": \"${tussenvoegsel}\","
    fi

    # Create as the user themselves so system `organisation` is set correctly by RBAC
    uuid=$(curl -s -X POST "${BASE_URL}/objects/voorzieningen/contactpersoon" \
        -H 'Content-Type: application/json' \
        -u "${user_auth}" \
        -d "{
            \"voornaam\": \"${voornaam}\",
            ${tv_field}
            \"achternaam\": \"${achternaam}\",
            \"e-mailadres\": \"${email}\",
            \"telefoonnummer\": \"${telefoon}\",
            \"functie\": \"${functie}\",
            \"organisatie\": \"${org_uuid}\",
            \"rollen\": [\"Gebruik-beheerder\"]
        }" | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" 2>/dev/null)

    if [ -n "$uuid" ] && [ "$uuid" != "" ]; then
        echo "  Created contact: ${voornaam} ${achternaam} (${uuid})" >&2
    else
        echo "  WARN: Failed to create contact for ${voornaam} ${achternaam}" >&2
    fi
    echo "$uuid"
}

JAN_CONTACT=$(create_contact "Jan" "Pietersen" "jan.pietersen@test.nl" "+31 6 12345678" "CEO" "$LEVER_REG")
MARIA_CONTACT=$(create_contact "Maria" "Berg" "maria.vanderberg@test.nl" "+31 6 23456789" "Beheerder" "$GEMEENTE_REG" "van der")
MARK_CONTACT=$(create_contact "Mark" "Jansen" "mark.jansen@test.nl" "+31 6 34567890" "Beheerder" "$GEMEENTE_REG")
LINDA_CONTACT=$(create_contact "Linda" "Bakker" "linda.bakker@test.nl" "+31 6 45678901" "Beheerder" "$SAMENWERKING_REG")

# ─────────────────────────────────────────────
# Step 6: Link contact persons to organizations
# ─────────────────────────────────────────────
echo ""
echo "--- Step 6: Linking contact persons to organizations ---"

# Test Leverancier BV
if [ -n "$LEVER_REG" ] && [ -n "$JAN_CONTACT" ]; then
    curl -s -X PUT "${BASE_URL}/objects/voorzieningen/organisatie/${LEVER_REG}" \
        -H 'Content-Type: application/json' \
        -u "${ADMIN_AUTH}" \
        -d "{\"naam\": \"Test Leverancier BV\", \"type\": \"Leverancier\", \"status\": \"Actief\", \"contactpersonen\": [\"${JAN_CONTACT}\"]}" > /dev/null 2>&1
    echo "  Linked Jan -> Test Leverancier BV"
fi

# Test Gemeente
if [ -n "$GEMEENTE_REG" ] && [ -n "$MARIA_CONTACT" ]; then
    curl -s -X PUT "${BASE_URL}/objects/voorzieningen/organisatie/${GEMEENTE_REG}" \
        -H 'Content-Type: application/json' \
        -u "${ADMIN_AUTH}" \
        -d "{\"naam\": \"Test Gemeente\", \"type\": \"Gemeente\", \"status\": \"Actief\", \"contactpersonen\": [\"${MARIA_CONTACT}\", \"${MARK_CONTACT}\"]}" > /dev/null 2>&1
    echo "  Linked Maria + Mark -> Test Gemeente"
fi

# Test Samenwerking
if [ -n "$SAMENWERKING_REG" ] && [ -n "$LINDA_CONTACT" ]; then
    curl -s -X PUT "${BASE_URL}/objects/voorzieningen/organisatie/${SAMENWERKING_REG}" \
        -H 'Content-Type: application/json' \
        -u "${ADMIN_AUTH}" \
        -d "{\"naam\": \"Test Samenwerking\", \"type\": \"Samenwerking\", \"status\": \"Actief\", \"contactpersonen\": [\"${LINDA_CONTACT}\"]}" > /dev/null 2>&1
    echo "  Linked Linda -> Test Samenwerking"
fi

# ─────────────────────────────────────────────
# Step 7: Create test objects (as the owning user, NOT admin)
# Using user credentials ensures RBAC system `organisation` is correct.
# ─────────────────────────────────────────────
echo ""
echo "--- Step 7: Creating test objects ---"

create_object() {
    local register="$1"
    local schema="$2"
    local data="$3"
    local label="$4"
    local search_name="$5"
    local auth="${6:-${ADMIN_AUTH}}"

    # Check if object already exists — match by name AND owner (the authenticated user)
    local auth_user="${auth%%:*}"
    if [ -n "$search_name" ]; then
        existing=$(curl -s -u "${auth}" \
            "${BASE_URL}/objects/${register}/${schema}?_search=$(echo "$search_name" | sed 's/ /+/g')&_limit=20" \
            | python3 -c "
import sys,json
d=json.load(sys.stdin)
for r in d.get('results',[]):
    if r.get('naam','') == '${search_name}' and r.get('@self',{}).get('owner','') == '${auth_user}':
        print(r.get('@self',{}).get('id',''))
        break
" 2>/dev/null)

        if [ -n "$existing" ] && [ "$existing" != "" ]; then
            echo "  Already exists: ${label} (${existing})" >&2
            echo "$existing"
            return
        fi
    fi

    uuid=$(curl -s -X POST "${BASE_URL}/objects/${register}/${schema}" \
        -H 'Content-Type: application/json' \
        -u "${auth}" \
        -d "${data}" | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" 2>/dev/null)

    if [ -n "$uuid" ] && [ "$uuid" != "" ]; then
        echo "  Created ${label} (${uuid})" >&2
        echo "$uuid"
    else
        echo "  WARN: Failed to create ${label}" >&2
        echo ""
    fi
}

# Applicatie for Test Leverancier BV (created as jan.pietersen)
LEVER_APP_UUID=$(create_object "voorzieningen" "module" "{
    \"naam\": \"Test Applicatie Leverancier\",
    \"beschrijvingKort\": \"Een test applicatie van Test Leverancier BV voor geautomatiseerde tests\",
    \"beschrijvingLang\": \"Deze applicatie is aangemaakt door het test setup script om de beheer-, wizard- en zoekfunctionaliteit te testen.\",
    \"geregistreerdDoor\": \"Leverancier\",
    \"referentieComponenten\": [\"e-Formulieren\", \"Zaakafhandelcomponent\"],
    \"status\": \"Actief\"
}" "applicatie for Test Leverancier BV" "Test Applicatie Leverancier" "jan.pietersen@test.nl:${PASSWORD}")

# Applicatie for Test Leverancier 2 (created as jan.vandeberg — cross-vendor testing)
LEVER2_APP_UUID=$(create_object "voorzieningen" "module" "{
    \"naam\": \"Test Applicatie Leverancier 2\",
    \"beschrijvingKort\": \"Een test applicatie van Test Leverancier 2 voor cross-vendor tests\",
    \"geregistreerdDoor\": \"Leverancier\",
    \"referentieComponenten\": [\"Zaakafhandelcomponent\"],
    \"status\": \"Actief\"
}" "applicatie for Test Leverancier 2" "Test Applicatie Leverancier 2" "jan.vandeberg@testleverancier.nl:${PASSWORD}")

# Dienst for Test Leverancier BV (created as jan.pietersen)
LEVER_DIENST_UUID=$(create_object "voorzieningen" "dienst" "{
    \"naam\": \"Test Dienst Leverancier\",
    \"beschrijvingKort\": \"Een test dienst voor geautomatiseerde tests\",
    \"type\": [\"Implementatieondersteuning\"],
    \"aanbieder\": \"${LEVER_REG}\",
    \"geregistreerdDoor\": \"Leverancier\",
    \"status\": \"Actief\"
}" "dienst for Test Leverancier BV" "Test Dienst Leverancier" "jan.pietersen@test.nl:${PASSWORD}")

# Applicatie for Test Gemeente (created as maria.vanderberg)
GEMEENTE_APP_UUID=$(create_object "voorzieningen" "module" "{
    \"naam\": \"Test Applicatie Gemeente\",
    \"beschrijvingKort\": \"Een test applicatie geregistreerd door Test Gemeente\",
    \"geregistreerdDoor\": \"Gemeente\",
    \"referentieComponenten\": [\"Zaakafhandelcomponent\", \"e-Formulieren\"],
    \"status\": \"Actief\"
}" "applicatie for Test Gemeente" "Test Applicatie Gemeente" "maria.vanderberg@test.nl:${PASSWORD}")

echo "  Test objects created."

# ─────────────────────────────────────────────
# Step 7b: Verify samenwerking org is functional
# Checks that Linda's org can access API endpoints without 404/500.
# ─────────────────────────────────────────────
echo ""
echo "--- Step 7b: Verifying samenwerking org ---"

SAMENWERKING_AUTH="linda.bakker@test.nl:${PASSWORD}"

# Check: Linda can list applicaties (should not 404/500)
SAMENWERKING_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${SAMENWERKING_AUTH}" \
    "${BASE_URL}/objects/voorzieningen/module?_limit=1" 2>/dev/null)

if [ "$SAMENWERKING_STATUS" = "200" ]; then
    echo "  PASS: Linda can list applicaties (HTTP ${SAMENWERKING_STATUS})"
else
    echo "  WARN: Linda's applicatie list returned HTTP ${SAMENWERKING_STATUS}"
fi

# Check: Linda can list diensten
DIENST_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${SAMENWERKING_AUTH}" \
    "${BASE_URL}/objects/voorzieningen/dienst?_limit=1" 2>/dev/null)

if [ "$DIENST_STATUS" = "200" ]; then
    echo "  PASS: Linda can list diensten (HTTP ${DIENST_STATUS})"
else
    echo "  WARN: Linda's diensten list returned HTTP ${DIENST_STATUS}"
fi

# Check: Linda can access her org data
ORG_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${SAMENWERKING_AUTH}" \
    "${BASE_URL}/organisations" 2>/dev/null)

if [ "$ORG_STATUS" = "200" ]; then
    echo "  PASS: Linda can list organisations (HTTP ${ORG_STATUS})"
else
    echo "  WARN: Linda's org list returned HTTP ${ORG_STATUS}"
fi

# Check: Linda can access the search/publications endpoint (frontend search)
SEARCH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${SAMENWERKING_AUTH}" \
    "${NC_URL}/index.php/apps/opencatalogi/api/publications?_limit=1" 2>/dev/null)

if [ "$SEARCH_STATUS" = "200" ]; then
    echo "  PASS: Linda can access publications search (HTTP ${SEARCH_STATUS})"
else
    echo "  WARN: Linda's publications search returned HTTP ${SEARCH_STATUS}"
fi

# ─────────────────────────────────────────────
# Step 7c: Clean up duplicate test data
# Removes objects created by test users in previous runs,
# keeping only the most recently created ones.
# ─────────────────────────────────────────────
if [ "${CLEANUP_DUPLICATES:-0}" = "1" ]; then
    echo ""
    echo "--- Step 7c: Cleaning up duplicate test objects ---"

    cleanup_duplicates() {
        local auth="$1"
        local register="$2"
        local schema="$3"
        local search_name="$4"
        local label="$5"

        # Get all matching objects
        local results=$(curl -s -u "${auth}" \
            "${BASE_URL}/objects/${register}/${schema}?_search=$(echo "$search_name" | sed 's/ /+/g')&_limit=50" 2>/dev/null)

        local count=$(echo "$results" | python3 -c "
import sys, json
d = json.load(sys.stdin)
matches = [r for r in d.get('results', []) if r.get('naam', '') == '${search_name}']
print(len(matches))
" 2>/dev/null)

        if [ "$count" -gt 1 ] 2>/dev/null; then
            echo "  Found ${count} duplicates of '${label}' — removing extras..."
            # Get all IDs except the first (newest)
            local to_delete=$(echo "$results" | python3 -c "
import sys, json
d = json.load(sys.stdin)
matches = [r for r in d.get('results', []) if r.get('naam', '') == '${search_name}']
# Skip the first (keep it), delete the rest
for r in matches[1:]:
    print(r.get('@self', {}).get('id', ''))
" 2>/dev/null)

            while IFS= read -r obj_id; do
                if [ -n "$obj_id" ]; then
                    curl -s -X DELETE "${BASE_URL}/objects/${register}/${schema}/${obj_id}" \
                        -u "${ADMIN_AUTH}" > /dev/null 2>&1
                    echo "    Deleted ${obj_id}"
                fi
            done <<< "$to_delete"
        else
            echo "  ${label}: no duplicates (${count} found)"
        fi
    }

    cleanup_duplicates "${ADMIN_AUTH}" "voorzieningen" "module" "Test Applicatie Leverancier" "Lever app"
    cleanup_duplicates "${ADMIN_AUTH}" "voorzieningen" "module" "Test Applicatie Leverancier 2" "Lever2 app"
    cleanup_duplicates "${ADMIN_AUTH}" "voorzieningen" "module" "Test Applicatie Gemeente" "Gemeente app"
    cleanup_duplicates "${ADMIN_AUTH}" "voorzieningen" "dienst" "Test Dienst Leverancier" "Lever dienst"
    echo "  Cleanup done."
fi

# ─────────────────────────────────────────────
# Step 8: Verify RBAC organisation scoping
# Compares object counts between roles to verify scoping works.
# aanbod-beheerder (Jan) should see FEWER objects than admin.
# ─────────────────────────────────────────────
echo ""
echo "--- Step 8: Verifying RBAC organisation scoping ---"

get_count() {
    local auth="$1"
    local register="$2"
    local schema="$3"

    curl -s -u "${auth}" \
        "${BASE_URL}/objects?register=${register}&schema=${schema}&_limit=1" \
        | python3 -c "import sys,json; print(json.load(sys.stdin).get('total',0))" 2>/dev/null
}

# Get admin's total counts as baseline
ADMIN_CONTACTS=$(get_count "${ADMIN_AUTH}" "3" "14")
ADMIN_APPS=$(get_count "${ADMIN_AUTH}" "3" "25")
echo "  Admin baseline: ${ADMIN_CONTACTS} contactpersonen, ${ADMIN_APPS} applicaties"

# Jan (aanbod-beheerder) should see FEWER than admin
JAN_CONTACTS=$(get_count "jan.pietersen@test.nl:${PASSWORD}" "3" "14")
JAN_APPS=$(get_count "jan.pietersen@test.nl:${PASSWORD}" "3" "25")

if [ "$JAN_CONTACTS" -lt "$ADMIN_CONTACTS" ] 2>/dev/null; then
    echo "  PASS: jan.pietersen sees ${JAN_CONTACTS} contacts (< admin's ${ADMIN_CONTACTS}) — RBAC scoping works"
else
    echo "  WARN: jan.pietersen sees ${JAN_CONTACTS} contacts (admin sees ${ADMIN_CONTACTS}) — RBAC may not be scoping"
fi

if [ "$JAN_APPS" -lt "$ADMIN_APPS" ] 2>/dev/null; then
    echo "  PASS: jan.pietersen sees ${JAN_APPS} applicaties (< admin's ${ADMIN_APPS}) — RBAC scoping works"
else
    echo "  WARN: jan.pietersen sees ${JAN_APPS} applicaties (admin sees ${ADMIN_APPS}) — RBAC may not be scoping"
fi

# Maria (gebruik-beheerder) — check if she has broader access than Jan (expected for this role)
MARIA_CONTACTS=$(get_count "maria.vanderberg@test.nl:${PASSWORD}" "3" "14")
echo "  Maria (gebruik-beheerder) sees ${MARIA_CONTACTS} contacts (Jan sees ${JAN_CONTACTS})"

# Verify Jan's contacts all belong to his org
echo ""
echo "  Checking org_sys on Jan's contacts..."
curl -s -u "jan.pietersen@test.nl:${PASSWORD}" \
    "${BASE_URL}/objects?register=3&schema=14&_limit=10" \
    | python3 -c "
import sys, json
d = json.load(sys.stdin)
orgs = set()
for r in d.get('results', []):
    org = r.get('@self', {}).get('organisation', '?')
    orgs.add(org)
if len(orgs) == 1:
    print(f'  PASS: All {len(d.get(\"results\",[]))} contacts share one org_sys ({orgs.pop()}) — consistent RBAC')
else:
    print(f'  WARN: Contacts span {len(orgs)} orgs: {orgs} — possible RBAC leak')
" 2>/dev/null

# ─────────────────────────────────────────────
# Step 8b: Import AMEF test data (ArchiMate views, elements, models)
# ─────────────────────────────────────────────
echo ""
echo "--- Step 8b: Importing AMEF test data ---"

AMEF_FILE="/var/www/html/custom_apps/softwarecatalog/data/GEMMA release.xml"
if docker exec nextcloud test -f "$AMEF_FILE"; then
    # Check if AMEF views already exist (full GEMMA release has 248 views)
    VIEW_COUNT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" \
        "${NC_URL}/index.php/apps/openregister/api/objects/vng-gemma/view?_limit=1" 2>&1 | \
        python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('total',0))" 2>/dev/null || echo "0")

    if [ "$VIEW_COUNT" = "0" ]; then
        echo "  Importing full GEMMA release AMEF data via PHP CLI..."
        # HTTP endpoint times out on the 13MB file, so use PHP CLI with no timeout
        docker exec nextcloud bash -c "cat > /tmp/import-gemma.php << 'IMPORTEOF'
<?php
require \"/var/www/html/lib/base.php\";
try {
    \\\$svc = \\OC::\\\$server->get(\\OCA\\SoftwareCatalog\\Service\\ArchiMateImportService::class);
    \\\$r = \\\$svc->importArchiMateFileFromPathOptimized([
        \"filePath\" => \"$AMEF_FILE\",
        \"fileName\" => \"GEMMA release.xml\",
        \"fileSize\" => filesize(\"$AMEF_FILE\"),
        \"updateExisting\" => true,
        \"processingMode\" => \"speed\",
    ]);
    \\\$pm = \\\$r[\"performance_metrics\"] ?? [];
    echo json_encode([\"objects\" => \\\$pm[\"objects_processed\"] ?? 0, \"time\" => \\\$pm[\"total_time_seconds\"] ?? 0]);
} catch (\\Throwable \\\$e) { echo json_encode([\"error\" => \\\$e->getMessage()]); }
IMPORTEOF" 2>/dev/null

        AMEF_RESULT=$(docker exec nextcloud php -d max_execution_time=0 -d memory_limit=4096M /tmp/import-gemma.php 2>/dev/null)
        AMEF_OBJECTS=$(echo "$AMEF_RESULT" | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print(f'{d.get(\"objects\",\"?\")} objects in {d.get(\"time\",\"?\")}s')" 2>/dev/null || echo "error")
        echo "  AMEF import: $AMEF_OBJECTS"
    else
        echo "  AMEF data already imported ($VIEW_COUNT views)"
    fi
else
    echo "  WARN: GEMMA release.xml not found at $AMEF_FILE"
fi

# ─────────────────────────────────────────────
# Step 8c: Create test koppeling and glossary objects
# ─────────────────────────────────────────────
echo ""
echo "--- Step 8c: Creating test koppelingen and glossary ---"

# Create a test koppeling (as leverancier user)
KOPPELING_CHECK=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" \
    "${NC_URL}/index.php/apps/openregister/api/objects/voorzieningen/koppeling?_limit=1" 2>&1 | \
    python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('total',0))" 2>/dev/null || echo "0")

if [ "$KOPPELING_CHECK" = "0" ]; then
    echo "  Creating test koppelingen..."

    # Get applicatie UUIDs for linking
    APP_UUID=$(curl -s -u "jan.pietersen@test.nl:${PASSWORD}" \
        "${NC_URL}/index.php/apps/openregister/api/objects/voorzieningen/module?_limit=1&_search=Test+Applicatie+Leverancier" 2>&1 | \
        python3 -c "import sys,json; r=json.loads(sys.stdin.read()).get('results',[]); print(r[0]['uuid'] if r else '')" 2>/dev/null || echo "")

    if [ -n "$APP_UUID" ]; then
        # Create koppeling
        KOPPELING_RESULT=$(curl -s -u "jan.pietersen@test.nl:${PASSWORD}" -X POST \
            "${NC_URL}/index.php/apps/openregister/api/objects/voorzieningen/koppeling" \
            -H "Content-Type: application/json" \
            -d "{
                \"naam\": \"Test Koppeling REST API\",
                \"type\": \"REST\",
                \"status\": \"Actief\",
                \"moduleA\": \"${APP_UUID}\",
                \"omschrijving\": \"Test koppeling voor API-tests\"
            }" 2>&1)
        KOPPELING_UUID=$(echo "$KOPPELING_RESULT" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('uuid',''))" 2>/dev/null || echo "")
        if [ -n "$KOPPELING_UUID" ]; then
            echo "  Created koppeling: $KOPPELING_UUID"
        else
            echo "  WARN: Failed to create koppeling"
        fi
    else
        echo "  WARN: No applicatie found to link koppeling to"
    fi
else
    echo "  Koppelingen already exist ($KOPPELING_CHECK)"
fi

# Create glossary test terms (in publication register, glossary schema)
GLOSSARY_SCHEMA=$(docker exec nextcloud php occ config:app:get opencatalogi glossary_schema 2>/dev/null || echo "")
GLOSSARY_REGISTER=$(docker exec nextcloud php occ config:app:get opencatalogi glossary_register 2>/dev/null || echo "")

if [ -n "$GLOSSARY_SCHEMA" ] && [ -n "$GLOSSARY_REGISTER" ]; then
    GLOSSARY_CHECK=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" \
        "${NC_URL}/index.php/apps/openregister/api/objects/${GLOSSARY_REGISTER}/${GLOSSARY_SCHEMA}?_limit=1" 2>&1 | \
        python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('total',0))" 2>/dev/null || echo "0")

    if [ "$GLOSSARY_CHECK" = "0" ]; then
        echo "  Creating glossary test terms..."
        for term_data in \
            '{"title":"API","definition":"Application Programming Interface - een set van protocollen en tools voor het bouwen van softwareapplicaties.","category":"Technisch"}' \
            '{"title":"GEMMA","definition":"GEMeentelijke Model Architectuur - de landelijke referentiearchitectuur voor gemeenten.","category":"Architectuur"}' \
            '{"title":"SaaS","definition":"Software as a Service - een softwaredistributiemodel waarbij applicaties worden gehost door een serviceprovider.","category":"Dienstverlening"}'; do
            TERM_RESULT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" -X POST \
                "${NC_URL}/index.php/apps/openregister/api/objects/${GLOSSARY_REGISTER}/${GLOSSARY_SCHEMA}" \
                -H "Content-Type: application/json" \
                -d "$term_data" 2>&1)
            TERM_TITLE=$(echo "$term_data" | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['title'])" 2>/dev/null)
            TERM_OK=$(echo "$TERM_RESULT" | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print('OK' if d.get('id') or d.get('uuid') else 'FAIL')" 2>/dev/null || echo "FAIL")
            echo "  Glossary term '$TERM_TITLE': $TERM_OK"
        done
    else
        echo "  Glossary terms already exist ($GLOSSARY_CHECK)"
    fi
else
    echo "  WARN: Glossary schema/register not configured (schema=$GLOSSARY_SCHEMA, register=$GLOSSARY_REGISTER)"
fi

# ─────────────────────────────────────────────
# Step 8d: Create a listing to expose data as publications
# ─────────────────────────────────────────────
echo ""
echo "--- Step 8d: Creating publication listing ---"

LISTING_SCHEMA=$(docker exec nextcloud php occ config:app:get opencatalogi listing_schema 2>/dev/null || echo "")
LISTING_REGISTER=$(docker exec nextcloud php occ config:app:get opencatalogi listing_register 2>/dev/null || echo "")
CATALOG_SCHEMA=$(docker exec nextcloud php occ config:app:get opencatalogi catalog_schema 2>/dev/null || echo "")
CATALOG_REGISTER=$(docker exec nextcloud php occ config:app:get opencatalogi catalog_register 2>/dev/null || echo "")

if [ -n "$LISTING_SCHEMA" ] && [ -n "$CATALOG_SCHEMA" ]; then
    # Check if a catalog exists
    CATALOG_COUNT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" \
        "${NC_URL}/index.php/apps/opencatalogi/api/catalogi" 2>&1 | \
        python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('total',0))" 2>/dev/null || echo "0")

    # Get voorzieningen register ID and all schema IDs from softwarecatalog config
    VOORZ_CONFIG=$(docker exec nextcloud php occ config:app:get softwarecatalog voorzieningen_config 2>/dev/null || echo "{}")
    VOORZ_REGISTER=$(echo "$VOORZ_CONFIG" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('register','3'))" 2>/dev/null || echo "3")
    # Extract all schema IDs from voorzieningen_config (dienst, module, koppeling, organisatie, etc.)
    VOORZ_SCHEMAS=$(echo "$VOORZ_CONFIG" | python3 -c "
import sys, json
cfg = json.loads(sys.stdin.read())
schemas = [v for k, v in cfg.items() if k.endswith('_schema')]
print(json.dumps(schemas))
" 2>/dev/null || echo '["19","11","7","8","9","5","4","20","21","3"]')

    if [ "$CATALOG_COUNT" = "0" ]; then
        echo "  Creating default catalog with all voorzieningen schemas..."
        CATALOG_RESULT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" -X POST \
            "${NC_URL}/index.php/apps/openregister/api/objects/${CATALOG_REGISTER}/${CATALOG_SCHEMA}" \
            -H "Content-Type: application/json" \
            -d "{
                \"title\": \"Softwarecatalogus\",
                \"slug\": \"softwarecatalogus\",
                \"description\": \"GEMMA Softwarecatalogus - de catalogus voor gemeentelijke software\",
                \"listed\": true,
                \"registers\": [\"${VOORZ_REGISTER}\"],
                \"schemas\": ${VOORZ_SCHEMAS},
                \"status\": \"stable\"
            }" 2>&1)
        CATALOG_UUID=$(echo "$CATALOG_RESULT" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('uuid',''))" 2>/dev/null || echo "")
        echo "  Catalog: ${CATALOG_UUID:-FAILED} (schemas: ${VOORZ_SCHEMAS})"
    else
        echo "  Catalog already exists ($CATALOG_COUNT)"
        CATALOG_UUID=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" \
            "${NC_URL}/index.php/apps/opencatalogi/api/catalogi" 2>&1 | \
            python3 -c "import sys,json; r=json.loads(sys.stdin.read()).get('results',[]); print(r[0]['id'] if r else '')" 2>/dev/null || echo "")

        # Ensure catalog has all voorzieningen schemas (fix missing schemas like dienst)
        if [ -n "$CATALOG_UUID" ]; then
            echo "  Updating catalog schemas to include all voorzieningen schemas..."
            curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT \
                "${NC_URL}/index.php/apps/openregister/api/objects/${CATALOG_REGISTER}/${CATALOG_SCHEMA}/${CATALOG_UUID}" \
                -H "Content-Type: application/json" \
                -d "{
                    \"registers\": [\"${VOORZ_REGISTER}\"],
                    \"schemas\": ${VOORZ_SCHEMAS}
                }" > /dev/null 2>&1
            echo "  Catalog updated with schemas: ${VOORZ_SCHEMAS}"
        fi
    fi

    # Create a listing that exposes voorzieningen/module as publications
    if [ -n "$CATALOG_UUID" ]; then
        LISTING_COUNT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" \
            "${NC_URL}/index.php/apps/opencatalogi/api/listings" 2>&1 | \
            python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('total',0))" 2>/dev/null || echo "0")

        if [ "$LISTING_COUNT" = "0" ]; then
            echo "  Creating listing for applicaties..."
            LISTING_RESULT=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASS}" -X POST \
                "${NC_URL}/index.php/apps/opencatalogi/api/listings" \
                -H "Content-Type: application/json" \
                -d "{
                    \"title\": \"Applicaties\",
                    \"catalog\": \"${CATALOG_UUID}\",
                    \"register\": 3,
                    \"schema\": 19,
                    \"status\": \"published\"
                }" 2>&1)
            LISTING_OK=$(echo "$LISTING_RESULT" | python3 -c "import sys,json; print('OK' if json.loads(sys.stdin.read()).get('uuid') else 'FAIL')" 2>/dev/null || echo "FAIL")
            echo "  Listing: $LISTING_OK"
        else
            echo "  Listings already exist ($LISTING_COUNT)"
        fi
    fi
else
    echo "  WARN: Listing/catalog schema not configured"
fi

# ─────────────────────────────────────────────
# Step 9: Clear rate limiting again (login attempts above may trigger it)
# ─────────────────────────────────────────────
echo ""
echo "--- Step 9: Final cleanup ---"
docker exec nextcloud php occ security:bruteforce:reset 127.0.0.1 2>/dev/null || true
docker exec nextcloud apachectl -k graceful 2>/dev/null || true

echo ""
echo "=== Setup Complete ==="
echo ""
echo "Organizations (NC=Nextcloud RBAC, REG=Register data):"
echo "  - Test Leverancier BV  NC=${LEVER_NC} REG=${LEVER_REG} -> jan.pietersen@test.nl"
echo "  - Test Gemeente        NC=${GEMEENTE_NC} REG=${GEMEENTE_REG} -> maria.vanderberg@test.nl, mark.jansen@test.nl"
echo "  - Test Samenwerking    NC=${SAMENWERKING_NC} REG=${SAMENWERKING_REG} -> linda.bakker@test.nl"
echo "  - Test Leverancier 2   NC=${LEVER2_NC} REG=${LEVER2_REG} -> jan.vandeberg@testleverancier.nl"
echo ""
echo "Test objects created:"
echo "  - Test Applicatie Leverancier (${LEVER_APP_UUID:-failed})"
echo "  - Test Applicatie Leverancier 2 (${LEVER2_APP_UUID:-failed})"
echo "  - Test Dienst Leverancier (${LEVER_DIENST_UUID:-failed})"
echo "  - Test Applicatie Gemeente (${GEMEENTE_APP_UUID:-failed})"
echo ""
echo "Password for all accounts: ${PASSWORD}"
echo ""
echo "Options:"
echo "  FORCE_BUILD=1 bash test-setup.sh       # Rebuild all frontend apps"
echo "  CLEANUP_DUPLICATES=1 bash test-setup.sh # Remove duplicate test objects"
echo ""
echo "Ready to run: /test-softwarecatalog"
