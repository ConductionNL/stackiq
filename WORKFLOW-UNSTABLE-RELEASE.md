# Unstable Release Workflow - Flow Documentation

## Overview
This document explains how the unstable release workflow operates when you merge/push to the `development` branch.

## Complete Flow

### Step 1: Push to Development Branch
**Trigger:** Developer merges PR or pushes directly to `development`

**What happens:**
- Changes are committed to `development` branch
- `sync-dev.yaml` workflow is triggered

---

### Step 2: Sync to Development-Release (`sync-dev.yaml`)
**File:** `.github/workflows/sync-dev.yaml`

**Process:**
1. Fetches all branches from remote
2. **Preserves** the existing version from `development-release` branch (if it exists)
   - Example: If `development-release` has `0.1.140-unstable.2`, it stores this
3. Resets `development-release` to match `development` content (all files except version)
4. **Restores** the preserved version back to `appinfo/info.xml`
5. Commits with message: "Restored development-release version to X.X.X-unstable.X"
6. Force pushes to `development-release`

**Key Point:** This workflow syncs all code changes but keeps the unstable version intact!

---

### Step 3: Release Unstable (`release-unstable.yaml`)
**File:** `.github/workflows/release-unstable.yaml`
**Trigger:** Push to `development-release` branch (from step 2)

**Version Calculation Logic:**

```bash
# Example: main = 0.1.139, current = 0.1.138
main_version = "0.1.139"
current_version = "0.1.138"  # or "0.1.140-unstable.1"

# Calculate next patch from main
next_patch = 139 + 1 = 140

# Check if current version already has unstable suffix
if current_version matches "-unstable.X":
    extract current_patch from current_version
    if current_patch == next_patch:
        # Same base version, increment counter
        unstable_counter = X + 1
    else:
        # Different base version, reset counter
        unstable_counter = 1
else:
    # No unstable suffix, start fresh
    unstable_counter = 1

new_version = "0.1.{next_patch}-unstable.{unstable_counter}"
```

**Process:**
1. Fetches `main` branch to get stable version
2. Gets current version from `development-release`
3. Calculates new unstable version (see logic above)
4. Updates `appinfo/info.xml` with new version
5. Commits with `[skip ci]` to prevent infinite loop
6. Pushes version bump back to `development-release`
7. Builds the app (npm, composer)
8. Creates tarball and signs it
9. Creates GitHub prerelease with tag `vX.X.X-unstable.X`
10. Uploads to Nextcloud App Store as nightly build

---

## Version Examples

### Scenario 1: First Unstable Release
- **Main:** `0.1.139`
- **Current development-release:** `0.1.138` (no unstable suffix)
- **Result:** `0.1.140-unstable.1` ✓

### Scenario 2: Incrementing Unstable Counter
- **Main:** `0.1.139`
- **Current development-release:** `0.1.140-unstable.1`
- **Result:** `0.1.140-unstable.2` ✓

### Scenario 3: Multiple Increments
- **Main:** `0.1.139`
- **Current development-release:** `0.1.140-unstable.5`
- **Result:** `0.1.140-unstable.6` ✓

### Scenario 4: Main Version Bumped
- **Main:** `0.1.140` (was released to main)
- **Current development-release:** `0.1.140-unstable.3`
- **Result:** `0.1.141-unstable.1` ✓ (new series starts)

---

## Key Fixes Applied

### Fix 1: sync-dev.yaml (Line 56)
**Before:**
```yaml
Restored development-release version to ${DEV_RELEASE_VERSION} [skip ci]
```

**After:**
```yaml
Restored development-release version to ${DEV_RELEASE_VERSION}
```

**Reason:** Removed `[skip ci]` so the release workflow triggers properly

---

### Fix 2: release-unstable.yaml (Line 33)
**Before:**
```bash
current_patch=$(echo $current_version | grep -oP '^[0-9]+\.[0-9]+\.(\d+)' | cut -d. -f3)
```

**After:**
```bash
current_patch=$(echo $current_version | grep -oP '^[0-9]+\.[0-9]+\.[0-9]+' | cut -d. -f3)
```

**Reason:** The `\d+` pattern doesn't work in grep -P, changed to `[0-9]+`

---

## Testing the Workflow

To test locally (simulation only, no release):
```powershell
# Set test values
$mainVersion = "0.1.136"
$currentVersion = "0.1.137-unstable.1"

# Extract parts
$parts = $mainVersion.Split('.')
$nextPatch = [int]$parts[2] + 1

# Check for unstable suffix
$unstableCounter = 1
if ($currentVersion -match '-unstable\.(\d+)$') {
    $currentPatch = ($currentVersion -split '\.')[2] -replace '-.*', ''
    if ($currentPatch -eq $nextPatch) {
        $unstableCounter = [int]$matches[1] + 1
    }
}

$unstableVersion = "$($parts[0]).$($parts[1]).$nextPatch-unstable.$unstableCounter"
Write-Host "Next version: $unstableVersion"
```

---

## Important Notes

1. **[skip ci] is required** in the version bump commit to prevent infinite loop
2. **Version preservation** in sync-dev ensures continuity across syncs
3. **Force push** is safe in sync-dev because development-release is a controlled branch
4. **Prerelease flag** marks GitHub releases as unstable
5. **Nightly flag** marks Nextcloud App Store uploads as development builds

---

## Workflow Dependencies

```
development (push)
    ↓
sync-dev.yaml (syncs code, preserves version)
    ↓
development-release (updated)
    ↓
release-unstable.yaml (bumps version, builds, releases)
    ↓
GitHub Release (prerelease)
Nextcloud App Store (nightly)
```

---

Generated: 2026-02-19
