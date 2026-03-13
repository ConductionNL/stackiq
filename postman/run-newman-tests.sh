#!/bin/bash
##
# Softwarecatalogus Newman API Test Runner
#
# Runs the Postman collection folder-by-folder using Newman to avoid memory
# issues with large collections. Generates per-issue markdown result files
# that map to the issue documentation structure.
#
# Output:
#   test-results/api/results.md          — Overall API test results summary
#   test-results/api/issues/{num}.md     — Per-issue test result with pass/fail per AC
#   test-results/api/folders/            — Per-folder Newman JSON output
#
# References:
#   issues.md                  — Full acceptance criteria per issue
#   issues/{num}.md            — Individual issue descriptions and comments
#   aanvullende-informatie.md  — Supplementary info (data sources, templates)
#
# Usage:
#   bash Softwarecatalogus/postman/run-newman-tests.sh
#   bash Softwarecatalogus/postman/run-newman-tests.sh --folder "05 - ArchiMate & Views"
#   ENV=test bash Softwarecatalogus/postman/run-newman-tests.sh
#
# Environment variables:
#   ENV            — Environment to use: local (default), test, production
#   CONTAINER_NAME — Docker container name (default: nextcloud)
#   FOLDER         — Run only a specific test folder
#   ADMIN_PASS     — Admin password (never stored in files)
#   TEST_PASSWORD  — Test user password override
##

set -euo pipefail

# Colors for output.
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Paths.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
COLLECTION="$SCRIPT_DIR/softwarecatalogus-tests.json"

# Environment selection.
ENV="${ENV:-local}"
case "$ENV" in
    test)       ENVIRONMENT="$SCRIPT_DIR/environment-test.json" ;;
    production) ENVIRONMENT="$SCRIPT_DIR/environment-production.json" ;;
    *)          ENVIRONMENT="$SCRIPT_DIR/environment-local.json" ;;
esac

# Output directories.
OUTPUT_DIR="$PROJECT_DIR/test-results/api"
ISSUES_OUTPUT_DIR="$OUTPUT_DIR/issues"
FOLDERS_OUTPUT_DIR="$OUTPUT_DIR/folders"
RESULTS_MD="$OUTPUT_DIR/results.md"

# Container for running inside Docker (local env only).
CONTAINER_NAME="${CONTAINER_NAME:-nextcloud}"

# Parse arguments.
FOLDER="${FOLDER:-}"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --folder) FOLDER="$2"; shift 2 ;;
        --folder=*) FOLDER="${1#*=}"; shift ;;
        *) shift ;;
    esac
done

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Softwarecatalogus Newman API Tests${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "  Environment: ${YELLOW}${ENV}${NC}"
echo -e "  Collection:  ${YELLOW}$(basename "$COLLECTION")${NC}"
echo -e "  Env file:    ${YELLOW}$(basename "$ENVIRONMENT")${NC}"
if [ -n "$FOLDER" ]; then
    echo -e "  Folder:      ${YELLOW}${FOLDER}${NC}"
    echo -e "  Mode:        ${YELLOW}single folder${NC}"
else
    echo -e "  Mode:        ${YELLOW}folder-by-folder (all)${NC}"
fi
echo ""

# Create output directories (clean folders dir to prevent stale results).
mkdir -p "$OUTPUT_DIR" "$ISSUES_OUTPUT_DIR"
rm -rf "$FOLDERS_OUTPUT_DIR"
mkdir -p "$FOLDERS_OUTPUT_DIR"

# Reset brute force protection before running tests (prevents 403 cascading failures).
if [ "$ENV" = "local" ] && docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^${CONTAINER_NAME}$"; then
    echo -e "${BLUE}i${NC} Resetting brute force protection..."
    docker exec "$CONTAINER_NAME" php occ security:bruteforce:reset 127.0.0.1 2>/dev/null || true
    docker exec "$CONTAINER_NAME" php -r "apcu_clear_cache();" 2>/dev/null || true
fi

# Find Newman: local install, npx, or Docker container.
NEWMAN_CMD=""
RUN_PREFIX=""
COLLECTION_PATH="$COLLECTION"
ENVIRONMENT_PATH="$ENVIRONMENT"

if command -v newman &> /dev/null; then
    NEWMAN_CMD="newman"
elif npx newman --version &> /dev/null 2>&1; then
    NEWMAN_CMD="npx newman"
else
    echo -e "${YELLOW}!${NC} Newman not found locally, attempting Docker container..."

    if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^${CONTAINER_NAME}$"; then
        echo -e "${RED}x${NC} Container '${CONTAINER_NAME}' is not running and Newman is not installed locally."
        echo ""
        echo "Install Newman: npm install -g newman"
        echo "Or start the container: docker compose -f openregister/docker-compose.yml up -d"
        exit 1
    fi

    # Install Newman in container if needed.
    if ! docker exec "$CONTAINER_NAME" which newman &> /dev/null; then
        echo -e "${BLUE}i${NC} Installing Newman in container..."
        docker exec -u root "$CONTAINER_NAME" npm install -g newman 2>&1 | tail -1
    fi

    NEWMAN_CMD="newman"
    RUN_PREFIX="docker exec -u 33 -w /var/www/html/apps-extra $CONTAINER_NAME"
    COLLECTION_PATH="/var/www/html/apps-extra/Softwarecatalogus/postman/softwarecatalogus-tests.json"
    ENVIRONMENT_PATH="/var/www/html/apps-extra/Softwarecatalogus/postman/$(basename "$ENVIRONMENT")"
fi

NEWMAN_VERSION=$($RUN_PREFIX $NEWMAN_CMD --version 2>/dev/null || echo "unknown")
echo -e "${GREEN}v${NC} Newman ${NEWMAN_VERSION}"
echo ""

# Build base Newman arguments (shared across all runs).
BASE_NEWMAN_ARGS=(
    run "$COLLECTION_PATH"
    -e "$ENVIRONMENT_PATH"
    --reporters cli,json
    --color on
    --disable-unicode
)

# Pass credentials from environment variables (never stored in files).
if [ -n "${ADMIN_PASS:-}" ]; then
    BASE_NEWMAN_ARGS+=(--env-var "admin_pass=$ADMIN_PASS")
fi
if [ -n "${TEST_PASSWORD:-}" ]; then
    BASE_NEWMAN_ARGS+=(--env-var "test_password=$TEST_PASSWORD")
fi

# Extract folder names from the collection JSON.
get_folder_names() {
    python3 -c "
import json, sys
with open('$COLLECTION') as f:
    data = json.load(f)
for item in data.get('item', []):
    print(item.get('name', ''))
"
}

# Active environment file — updated after each folder run to persist variables.
ACTIVE_ENVIRONMENT="$ENVIRONMENT"

# Run Newman for a single folder and capture the exit code.
run_folder() {
    local folder_name="$1"
    local safe_name
    safe_name=$(echo "$folder_name" | sed 's/[^a-zA-Z0-9_-]/_/g')
    local json_output

    if [ -n "$RUN_PREFIX" ]; then
        json_output="/var/www/html/apps-extra/Softwarecatalogus/test-results/api/folders/${safe_name}.json"
    else
        json_output="$FOLDERS_OUTPUT_DIR/${safe_name}.json"
    fi

    local folder_args=(
        run "$COLLECTION_PATH"
        -e "$ACTIVE_ENVIRONMENT"
        --reporters cli,json
        --color on
        --disable-unicode
    )

    # Pass credentials from environment variables.
    if [ -n "${ADMIN_PASS:-}" ]; then
        folder_args+=(--env-var "admin_pass=$ADMIN_PASS")
    fi
    if [ -n "${TEST_PASSWORD:-}" ]; then
        folder_args+=(--env-var "test_password=$TEST_PASSWORD")
    fi

    folder_args+=(--folder "$folder_name")
    folder_args+=(--reporter-json-export "$json_output")

    # Export environment after each folder run so UUIDs from Setup persist.
    local env_export="$OUTPUT_DIR/.env_${safe_name}.json"
    folder_args+=(--export-environment "$env_export")

    echo -e "${BLUE}--- ${folder_name} ---${NC}"
    echo ""

    set +e
    $RUN_PREFIX $NEWMAN_CMD "${folder_args[@]}" 2>&1
    local exit_code=$?
    set -e

    # Use the exported environment for subsequent folders (persists pm.environment.set vars).
    if [ -f "$env_export" ]; then
        ACTIVE_ENVIRONMENT="$env_export"
    fi

    echo ""
    return $exit_code
}

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Running Tests${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

OVERALL_EXIT=0

if [ -n "$FOLDER" ]; then
    # Single folder mode.
    run_folder "$FOLDER" || OVERALL_EXIT=$?
else
    # Folder-by-folder mode: run each top-level folder separately.
    FOLDER_COUNT=0
    FOLDER_PASS=0
    FOLDER_FAIL=0

    while IFS= read -r folder_name; do
        [ -z "$folder_name" ] && continue
        FOLDER_COUNT=$((FOLDER_COUNT + 1))

        if run_folder "$folder_name"; then
            FOLDER_PASS=$((FOLDER_PASS + 1))
        else
            FOLDER_FAIL=$((FOLDER_FAIL + 1))
            OVERALL_EXIT=1
        fi
    done < <(get_folder_names)

    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "  Folders: ${FOLDER_COUNT} total, ${GREEN}${FOLDER_PASS} passed${NC}, ${RED}${FOLDER_FAIL} failed${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
fi

# Check that at least one JSON output exists.
JSON_COUNT=$(find "$FOLDERS_OUTPUT_DIR" -name "*.json" 2>/dev/null | wc -l)
if [ "$JSON_COUNT" -eq 0 ]; then
    echo -e "${RED}x${NC} No Newman JSON output files found in $FOLDERS_OUTPUT_DIR"
    echo "  Newman exited with code: $OVERALL_EXIT"
    exit 1
fi

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Generating Reports${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# Parse all per-folder JSON outputs and generate per-issue markdown + summary.
python3 - "$FOLDERS_OUTPUT_DIR" "$RESULTS_MD" "$ISSUES_OUTPUT_DIR" "$PROJECT_DIR" "$ENV" <<'PYTHON_SCRIPT'
import json
import sys
import os
import re
import glob
from collections import defaultdict
from datetime import datetime

folders_dir = sys.argv[1]
results_md_path = sys.argv[2]
issues_output_dir = sys.argv[3]
project_dir = sys.argv[4]
env_name = sys.argv[5]

# Aggregate results from all per-folder JSON files.
all_tests = []
total_requests = 0
total_assertions = 0
failed_assertions = 0
total_duration = 0
folder_summaries = []

issue_results = defaultdict(lambda: {
    'tests': [], 'folder': '', 'response_time': 0, 'request_name': ''
})

json_files = sorted(f for f in glob.glob(os.path.join(folders_dir, '*.json'))
                    if not f.endswith('_env.json'))

for json_file in json_files:
    folder_label = os.path.splitext(os.path.basename(json_file))[0]
    try:
        with open(json_file, 'r') as f:
            data = json.load(f)
    except (json.JSONDecodeError, OSError) as e:
        print(f'  Warning: Could not parse {json_file}: {e}')
        continue

    run = data.get('run', {})
    stats = run.get('stats', {})
    timings = run.get('timings', {})
    executions = run.get('executions', [])

    folder_tests = 0
    folder_passed = 0

    total_requests += stats.get('requests', {}).get('total', 0)
    total_assertions += stats.get('assertions', {}).get('total', 0)
    failed_assertions += stats.get('assertions', {}).get('failed', 0)
    started = timings.get('started', 0)
    completed = timings.get('completed', 0)
    total_duration += (completed - started) if completed > started else 0

    for execution in executions:
        item = execution.get('item', {})
        request_name = item.get('name', 'Unknown')
        response = execution.get('response', {}) or {}
        response_time = response.get('responseTime', 0)
        response_code = response.get('code', 0)

        assertions = execution.get('assertions', [])
        for assertion in assertions:
            test_name = assertion.get('assertion', 'Unknown test')
            error = assertion.get('error', None)
            passed = error is None

            issue_match = re.search(r'#(\d+)', test_name)
            issue_num = issue_match.group(1) if issue_match else None

            test_result = {
                'name': test_name,
                'passed': passed,
                'error': error.get('message', '') if error else None,
                'request_name': request_name,
                'response_time': response_time,
                'response_code': response_code,
                'issue': issue_num,
                'folder': folder_label,
            }
            all_tests.append(test_result)
            folder_tests += 1
            if passed:
                folder_passed += 1

            if issue_num:
                issue_results[issue_num]['tests'].append(test_result)
                issue_results[issue_num]['request_name'] = request_name
                issue_results[issue_num]['folder'] = folder_label
                issue_results[issue_num]['response_time'] = max(
                    issue_results[issue_num]['response_time'], response_time
                )

    folder_summaries.append({
        'name': folder_label,
        'tests': folder_tests,
        'passed': folder_passed,
        'failed': folder_tests - folder_passed,
    })

# Calculate summary stats.
total_tests = len(all_tests)
passed_tests = sum(1 for t in all_tests if t['passed'])
failed_tests = total_tests - passed_tests
pass_rate = (passed_tests / total_tests * 100) if total_tests > 0 else 0

# Issue-level pass/fail.
issue_summary = {}
for issue_num, result in sorted(issue_results.items(), key=lambda x: int(x[0])):
    tests = result['tests']
    all_passed = all(t['passed'] for t in tests)
    some_passed = any(t['passed'] for t in tests)
    if all_passed:
        status = 'PASS'
    elif some_passed:
        status = 'PARTIAL'
    else:
        status = 'FAIL'
    issue_summary[issue_num] = {
        'status': status,
        'total': len(tests),
        'passed': sum(1 for t in tests if t['passed']),
        'failed': sum(1 for t in tests if not t['passed']),
        'request': result['request_name'],
        'response_time': result['response_time'],
    }

# Check which issue files exist for cross-referencing.
issues_dir = os.path.join(project_dir, 'issues')

# --- Write per-issue markdown files ---
for issue_num, result in issue_results.items():
    issue_md_path = os.path.join(issues_output_dir, f'{issue_num}.md')
    issue_file_exists = os.path.isfile(os.path.join(issues_dir, f'{issue_num}.md'))

    tests = result['tests']
    status = issue_summary[issue_num]['status']

    with open(issue_md_path, 'w') as f:
        f.write(f'# #{issue_num} — API Test Results\n\n')
        f.write(f'**Status:** {status}\n')
        f.write(f'**Date:** {datetime.now().strftime("%Y-%m-%d %H:%M")}\n')
        f.write(f'**Environment:** {env_name}\n')
        f.write(f'**Request:** {result["request_name"]}\n')
        f.write(f'**Response Time:** {result["response_time"]}ms\n\n')

        f.write('## References\n\n')
        if issue_file_exists:
            f.write(f'- Issue description: [issues/{issue_num}.md](../../issues/{issue_num}.md)\n')
        f.write(f'- Acceptance criteria: [issues.md](../../issues.md)\n')
        f.write(f'- Supplementary info: [aanvullende-informatie.md](../../aanvullende-informatie.md)\n\n')

        f.write('## Test Results\n\n')
        f.write('| Test | Status | Details |\n')
        f.write('|------|--------|--------|\n')
        for t in tests:
            mark = 'PASS' if t['passed'] else 'FAIL'
            detail = t['error'] if t['error'] else f'{t["response_time"]}ms'
            f.write(f'| {t["name"]} | {mark} | {detail} |\n')
        f.write('\n')

# --- Write overall summary ---
with open(results_md_path, 'w') as f:
    f.write('# Softwarecatalogus — API Test Results\n\n')
    f.write(f'**Date:** {datetime.now().strftime("%Y-%m-%d %H:%M")}\n')
    f.write(f'**Environment:** {env_name}\n')
    f.write(f'**Collection:** softwarecatalogus-tests.json\n')
    f.write(f'**Duration:** {total_duration}ms\n\n')

    f.write('## References\n\n')
    f.write('- [issues.md](../../issues.md) — Full acceptance criteria per issue\n')
    f.write('- [issues/](../../issues/) — Individual issue descriptions (144 files)\n')
    f.write('- [aanvullende-informatie.md](../../aanvullende-informatie.md) — Data sources, analysis, templates\n\n')

    f.write('---\n\n')

    f.write('## Overall Statistics\n\n')
    f.write('| Metric | Value |\n')
    f.write('|--------|-------|\n')
    f.write(f'| Total requests | {total_requests} |\n')
    f.write(f'| Total assertions | {total_assertions} |\n')
    f.write(f'| Passed | {passed_tests} |\n')
    f.write(f'| Failed | {failed_tests} |\n')
    f.write(f'| Pass rate | {pass_rate:.1f}% |\n')
    f.write(f'| Duration | {total_duration}ms |\n\n')

    f.write('---\n\n')

    f.write('## Results by Folder\n\n')
    f.write('| Folder | Tests | Passed | Failed |\n')
    f.write('|--------|-------|--------|--------|\n')
    for fs in folder_summaries:
        f.write(f'| {fs["name"]} | {fs["tests"]} | {fs["passed"]} | {fs["failed"]} |\n')
    f.write('\n')

    f.write('---\n\n')

    f.write('## Results by Issue\n\n')
    f.write('| Issue | Status | Passed | Failed | Response Time | Details |\n')
    f.write('|-------|--------|--------|--------|---------------|--------|\n')
    for issue_num in sorted(issue_summary.keys(), key=int):
        s = issue_summary[issue_num]
        link = f'[#{issue_num}](issues/{issue_num}.md)'
        f.write(f'| {link} | {s["status"]} | {s["passed"]} | {s["failed"]} | {s["response_time"]}ms | {s["request"]} |\n')
    f.write('\n')

    # Failed tests section.
    failed = [t for t in all_tests if not t['passed']]
    if failed:
        f.write('---\n\n')
        f.write('## Failed Tests\n\n')
        f.write('| Issue | Test | Error | Request |\n')
        f.write('|-------|------|-------|---------|\n')
        for t in failed:
            issue_link = f'#{t["issue"]}' if t['issue'] else '-'
            error_msg = (t['error'] or '')[:80]
            f.write(f'| {issue_link} | {t["name"]} | {error_msg} | {t["request_name"]} |\n')
        f.write('\n')

    # Tests without issue numbers.
    no_issue = [t for t in all_tests if not t['issue']]
    if no_issue:
        f.write('---\n\n')
        f.write('## Tests Without Issue Reference\n\n')
        f.write('| Test | Status | Request |\n')
        f.write('|------|--------|---------|\n')
        for t in no_issue:
            mark = 'PASS' if t['passed'] else 'FAIL'
            f.write(f'| {t["name"]} | {mark} | {t["request_name"]} |\n')
        f.write('\n')

print(f'Summary written to: {results_md_path}')
print(f'Per-issue results: {issues_output_dir}/ ({len(issue_results)} issues)')

# Print summary to stdout.
print(f'\n--- Results ---')
print(f'Total: {total_tests} tests ({passed_tests} passed, {failed_tests} failed) = {pass_rate:.1f}%')
print(f'Issues covered: {len(issue_summary)}')
for issue_num in sorted(issue_summary.keys(), key=int):
    s = issue_summary[issue_num]
    mark = {'PASS': 'v', 'PARTIAL': '~', 'FAIL': 'x'}[s['status']]
    print(f'  [{mark}] #{issue_num}: {s["status"]} ({s["passed"]}/{s["total"]} tests)')
PYTHON_SCRIPT

echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Summary${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

if [ $OVERALL_EXIT -eq 0 ]; then
    echo -e "${GREEN}v All tests passed!${NC}"
else
    echo -e "${YELLOW}! Some tests failed (exit code: $OVERALL_EXIT)${NC}"
fi

echo ""
echo -e "  Results:     ${YELLOW}${RESULTS_MD}${NC}"
echo -e "  Per-issue:   ${YELLOW}${ISSUES_OUTPUT_DIR}/${NC}"
echo -e "  Per-folder:  ${YELLOW}${FOLDERS_OUTPUT_DIR}/${NC}"
echo ""
echo -e "  Cross-references:"
echo -e "    ${BLUE}issues.md${NC}                  — Acceptance criteria"
echo -e "    ${BLUE}issues/*.md${NC}                — Issue descriptions (144 files)"
echo -e "    ${BLUE}aanvullende-informatie.md${NC}  — Data sources & templates"
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

exit $OVERALL_EXIT
