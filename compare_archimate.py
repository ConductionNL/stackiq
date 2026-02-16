#!/usr/bin/env python3
"""
ArchiMate XML Semantic Comparison Tool

Compares two ArchiMate 3.x XML files for structural equivalence:
- Model identifier and name
- Element counts, identifiers, and types
- Relationship counts, identifiers, types, and source/target
- View counts and identifiers
- Organization item counts
- PropertyDefinition counts, identifiers, and names
- Type distribution matching

Usage:
    python3 compare_archimate.py <original.xml> <exported.xml> [--json]

Exit codes:
    0 = all checks passed
    1 = one or more checks failed
    2 = error (file not found, parse error, etc.)
"""

import sys
import json as json_module
import xml.etree.ElementTree as ET
from collections import Counter

NS = {"am": "http://www.opengroup.org/xsd/archimate/3.0/"}
XSI = "http://www.w3.org/2001/XMLSchema-instance"


def parse_archimate(filepath):
    """Parse an ArchiMate XML file and extract structural info."""
    tree = ET.parse(filepath)
    root = tree.getroot()

    info = {
        "model_id": root.get("identifier", ""),
        "model_name": "",
        "elements": {},
        "relationships": {},
        "views": {},
        "organizations": [],
        "property_definitions": {},
    }

    # Model name
    name_el = root.find("am:name", NS)
    if name_el is not None:
        info["model_name"] = (name_el.text or "").strip()

    # Elements
    for el in root.findall(".//am:elements/am:element", NS):
        eid = el.get("identifier", "")
        etype = el.get(f"{{{XSI}}}type", "")
        if eid:
            info["elements"][eid] = etype

    # Relationships
    for rel in root.findall(".//am:relationships/am:relationship", NS):
        rid = rel.get("identifier", "")
        rtype = rel.get(f"{{{XSI}}}type", "")
        source = rel.get("source", "")
        target = rel.get("target", "")
        if rid:
            info["relationships"][rid] = {
                "type": rtype,
                "source": source,
                "target": target,
            }

    # Views (diagrams)
    for view in root.findall(".//am:views/am:diagrams/am:view", NS):
        vid = view.get("identifier", "")
        vname_el = view.find("am:name", NS)
        vname = (vname_el.text or "").strip() if vname_el is not None else ""
        if vid:
            info["views"][vid] = vname

    # Organizations — count all <item> elements recursively.
    # ArchiMate organizations use <item> with optional identifierRef (element references)
    # or <label> (folder items). We track total count + identifierRefs for comparison.
    orgs_el = root.find("am:organizations", NS)
    if orgs_el is not None:
        all_items = orgs_el.findall(".//am:item", NS)
        info["organizations"] = {
            "total_items": len(all_items),
            "identifier_refs": sorted(
                [item.get("identifierRef") for item in all_items if item.get("identifierRef")]
            ),
            "folder_count": sum(
                1 for item in all_items if not item.get("identifierRef")
            ),
        }
    else:
        info["organizations"] = {
            "total_items": 0,
            "identifier_refs": [],
            "folder_count": 0,
        }

    # Property definitions
    for pd in root.findall(".//am:propertyDefinitions/am:propertyDefinition", NS):
        pid = pd.get("identifier", "")
        pname_el = pd.find("am:name", NS)
        pname = (pname_el.text or "").strip() if pname_el is not None else ""
        if pid:
            info["property_definitions"][pid] = pname

    return info


def compare(original, exported):
    """Compare two parsed ArchiMate structures. Returns (results, all_pass)."""
    results = []
    all_pass = True

    def check(passed, pass_msg, fail_msg):
        nonlocal all_pass
        if passed:
            results.append(("PASS", pass_msg))
        else:
            results.append(("FAIL", fail_msg))
            all_pass = False

    def warn(msg):
        results.append(("WARN", msg))

    def compare_ids(label, orig_dict, exp_dict):
        """Compare identifier sets for a given section."""
        nonlocal all_pass
        orig_ids = set(orig_dict.keys()) if isinstance(orig_dict, dict) else set(orig_dict)
        exp_ids = set(exp_dict.keys()) if isinstance(exp_dict, dict) else set(exp_dict)
        missing = orig_ids - exp_ids
        extra = exp_ids - orig_ids
        if not missing and not extra:
            results.append(("PASS", f"All {label} identifiers match"))
        else:
            if missing:
                sample = sorted(list(missing))[:5]
                results.append(
                    ("FAIL", f"Missing {len(missing)} {label} IDs in export (sample: {sample})")
                )
                all_pass = False
            if extra:
                sample = sorted(list(extra))[:5]
                warn(f"Extra {len(extra)} {label} IDs in export (sample: {sample})")
        return orig_ids & exp_ids

    # --- Model ---
    check(
        original["model_id"] == exported["model_id"],
        f"Model identifier matches: {original['model_id'][:40]}",
        f"Model identifier mismatch: {original['model_id'][:40]} vs {exported['model_id'][:40]}",
    )

    # --- Elements ---
    orig_n = len(original["elements"])
    exp_n = len(exported["elements"])
    check(
        orig_n == exp_n,
        f"Element count matches: {orig_n}",
        f"Element count mismatch: {orig_n} original vs {exp_n} exported (delta {exp_n - orig_n:+d})",
    )

    common_el = compare_ids("element", original["elements"], exported["elements"])

    # Element type consistency
    type_mismatches = [
        eid
        for eid in common_el
        if original["elements"][eid] != exported["elements"][eid]
    ]
    check(
        len(type_mismatches) == 0,
        "All shared element types match",
        f"{len(type_mismatches)} element type mismatches (sample: {type_mismatches[:5]})",
    )

    # Element type distribution
    orig_types = Counter(original["elements"].values())
    exp_types = Counter(exported["elements"].values())
    if orig_types == exp_types:
        results.append(("PASS", "Element type distribution matches"))
    else:
        all_types = sorted(set(list(orig_types.keys()) + list(exp_types.keys())))
        for t in all_types:
            o = orig_types.get(t, 0)
            e = exp_types.get(t, 0)
            if o != e:
                results.append(("FAIL", f"  Element type '{t}': {o} original vs {e} exported"))
                all_pass = False

    # --- Relationships ---
    orig_n = len(original["relationships"])
    exp_n = len(exported["relationships"])
    check(
        orig_n == exp_n,
        f"Relationship count matches: {orig_n}",
        f"Relationship count mismatch: {orig_n} original vs {exp_n} exported (delta {exp_n - orig_n:+d})",
    )

    common_rel = compare_ids("relationship", original["relationships"], exported["relationships"])

    # Relationship type distribution
    orig_rel_types = Counter(r["type"] for r in original["relationships"].values())
    exp_rel_types = Counter(r["type"] for r in exported["relationships"].values())
    if orig_rel_types == exp_rel_types:
        results.append(("PASS", "Relationship type distribution matches"))
    else:
        all_types = sorted(set(list(orig_rel_types.keys()) + list(exp_rel_types.keys())))
        for t in all_types:
            o = orig_rel_types.get(t, 0)
            e = exp_rel_types.get(t, 0)
            if o != e:
                results.append(("FAIL", f"  Relationship type '{t}': {o} original vs {e} exported"))
                all_pass = False

    # Relationship source/target consistency
    src_tgt_mismatches = 0
    for rid in common_rel:
        orig_r = original["relationships"][rid]
        exp_r = exported["relationships"][rid]
        if orig_r["source"] != exp_r["source"] or orig_r["target"] != exp_r["target"]:
            src_tgt_mismatches += 1
    check(
        src_tgt_mismatches == 0,
        "All shared relationship source/target pairs match",
        f"{src_tgt_mismatches} relationship source/target mismatches",
    )

    # --- Views ---
    orig_n = len(original["views"])
    exp_n = len(exported["views"])
    check(
        orig_n == exp_n,
        f"View count matches: {orig_n}",
        f"View count mismatch: {orig_n} original vs {exp_n} exported (delta {exp_n - orig_n:+d})",
    )

    compare_ids("view", original["views"], exported["views"])

    # --- Organizations ---
    orig_org = original["organizations"]
    exp_org = exported["organizations"]
    orig_n = orig_org["total_items"]
    exp_n = exp_org["total_items"]
    check(
        orig_n == exp_n,
        f"Organization total item count matches: {orig_n}",
        f"Organization total item count mismatch: {orig_n} original vs {exp_n} exported (delta {exp_n - orig_n:+d})",
    )

    # Organization identifierRefs (element references within folders)
    orig_refs = set(orig_org["identifier_refs"])
    exp_refs = set(exp_org["identifier_refs"])
    missing_refs = orig_refs - exp_refs
    check(
        len(missing_refs) == 0,
        f"Organization identifierRefs match ({len(orig_refs)} refs)",
        f"Missing {len(missing_refs)} organization identifierRefs in export",
    )

    # Organization folder count
    check(
        orig_org["folder_count"] == exp_org["folder_count"],
        f"Organization folder count matches: {orig_org['folder_count']}",
        f"Organization folder count mismatch: {orig_org['folder_count']} original vs {exp_org['folder_count']} exported",
    )

    # --- Property Definitions ---
    orig_n = len(original["property_definitions"])
    exp_n = len(exported["property_definitions"])
    check(
        orig_n == exp_n,
        f"PropertyDefinition count matches: {orig_n}",
        f"PropertyDefinition count mismatch: {orig_n} original vs {exp_n} exported (delta {exp_n - orig_n:+d})",
    )

    common_pd = compare_ids(
        "propertyDefinition", original["property_definitions"], exported["property_definitions"]
    )

    # Property definition name consistency
    name_mismatches = [
        pid
        for pid in common_pd
        if original["property_definitions"][pid] != exported["property_definitions"][pid]
    ]
    check(
        len(name_mismatches) == 0,
        "All shared propertyDefinition names match",
        f"{len(name_mismatches)} propertyDefinition name mismatches (sample: {name_mismatches[:5]})",
    )

    return results, all_pass


def print_report(original, exported, results, all_pass, orig_path, exp_path):
    """Print human-readable comparison report."""
    print("=" * 70)
    print("  ArchiMate Semantic Comparison Report")
    print("=" * 70)
    print(f"  Original : {orig_path}")
    print(f"  Exported : {exp_path}")
    print()

    # Summary table
    sections = [
        ("Elements", "elements"),
        ("Relationships", "relationships"),
        ("Views", "views"),
        ("PropertyDefinitions", "property_definitions"),
    ]

    print("  ---- Counts ----")
    print(f"  {'Section':<25s} {'Original':>10s} {'Exported':>10s} {'Delta':>10s}")
    print(f"  {'-'*25} {'-'*10} {'-'*10} {'-'*10}")
    for label, key in sections:
        o = len(original[key])
        e = len(exported[key])
        delta = e - o
        delta_str = f"{delta:+d}" if delta != 0 else "0"
        marker = " *" if delta != 0 else ""
        print(f"  {label:<25s} {o:>10d} {e:>10d} {delta_str:>10s}{marker}")

    # Organizations (special structure)
    o = original["organizations"]["total_items"]
    e = exported["organizations"]["total_items"]
    delta = e - o
    delta_str = f"{delta:+d}" if delta != 0 else "0"
    marker = " *" if delta != 0 else ""
    print(f"  {'Org items (total)':<25s} {o:>10d} {e:>10d} {delta_str:>10s}{marker}")
    o = len(original["organizations"]["identifier_refs"])
    e = len(exported["organizations"]["identifier_refs"])
    delta = e - o
    delta_str = f"{delta:+d}" if delta != 0 else "0"
    marker = " *" if delta != 0 else ""
    print(f"  {'Org identifierRefs':<25s} {o:>10d} {e:>10d} {delta_str:>10s}{marker}")
    o = original["organizations"]["folder_count"]
    e = exported["organizations"]["folder_count"]
    delta = e - o
    delta_str = f"{delta:+d}" if delta != 0 else "0"
    marker = " *" if delta != 0 else ""
    print(f"  {'Org folders':<25s} {o:>10d} {e:>10d} {delta_str:>10s}{marker}")
    print()

    # Detailed checks
    print("  ---- Checks ----")
    pass_count = fail_count = warn_count = 0
    for status, message in results:
        icon = {"PASS": "  [PASS]", "FAIL": "  [FAIL]", "WARN": "  [WARN]"}[status]
        print(f"  {icon} {message}")
        if status == "PASS":
            pass_count += 1
        elif status == "FAIL":
            fail_count += 1
        else:
            warn_count += 1

    print()
    print(f"  Summary: {pass_count} passed, {fail_count} failed, {warn_count} warnings")
    print()

    if all_pass:
        print("  RESULT: ALL CHECKS PASSED")
    else:
        print("  RESULT: SOME CHECKS FAILED")
    print("=" * 70)


def print_json(original, exported, results, all_pass):
    """Print JSON comparison report."""

    def counts(info):
        return {
            "elements": len(info["elements"]),
            "relationships": len(info["relationships"]),
            "views": len(info["views"]),
            "organizations_total": info["organizations"]["total_items"],
            "organizations_refs": len(info["organizations"]["identifier_refs"]),
            "organizations_folders": info["organizations"]["folder_count"],
            "property_definitions": len(info["property_definitions"]),
        }

    output = {
        "all_pass": all_pass,
        "original": counts(original),
        "exported": counts(exported),
        "checks": [{"status": s, "message": m} for s, m in results],
    }
    print(json_module.dumps(output, indent=2))


def main():
    if len(sys.argv) < 3:
        print("Usage: compare_archimate.py <original.xml> <exported.xml> [--json]")
        print()
        print("Compares two ArchiMate 3.x XML files for structural equivalence.")
        print("Exit codes: 0 = all pass, 1 = failures, 2 = error")
        sys.exit(2)

    original_file = sys.argv[1]
    exported_file = sys.argv[2]
    json_output = "--json" in sys.argv

    try:
        original = parse_archimate(original_file)
    except FileNotFoundError:
        print(f"ERROR: Original file not found: {original_file}", file=sys.stderr)
        sys.exit(2)
    except ET.ParseError as e:
        print(f"ERROR: Failed to parse original XML: {e}", file=sys.stderr)
        sys.exit(2)

    try:
        exported = parse_archimate(exported_file)
    except FileNotFoundError:
        print(f"ERROR: Exported file not found: {exported_file}", file=sys.stderr)
        sys.exit(2)
    except ET.ParseError as e:
        print(f"ERROR: Failed to parse exported XML: {e}", file=sys.stderr)
        sys.exit(2)

    results, all_pass = compare(original, exported)

    if json_output:
        print_json(original, exported, results, all_pass)
    else:
        print_report(original, exported, results, all_pass, original_file, exported_file)

    sys.exit(0 if all_pass else 1)


if __name__ == "__main__":
    main()
