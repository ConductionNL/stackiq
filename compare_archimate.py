#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2

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

import json as json_module
import sys
import xml.etree.ElementTree as ET
from collections import Counter, namedtuple

NS = {"am": "http://www.opengroup.org/xsd/archimate/3.0/"}
XSI = "http://www.w3.org/2001/XMLSchema-instance"

# Both parsed models with the outcome of compare().
Comparison = namedtuple("Comparison", "original exported results all_pass")


def name_text(node):
    """Stripped text of the node's <name> child, or an empty string."""
    name_el = node.find("am:name", NS)
    return (name_el.text or "").strip() if name_el is not None else ""


def xsi_type(node):
    """The node's xsi:type attribute, or an empty string."""
    return node.get(f"{{{XSI}}}type", "")


def relationship_info(node):
    """Type, source and target of a relationship node."""
    return {
        "type": xsi_type(node),
        "source": node.get("source", ""),
        "target": node.get("target", ""),
    }


# Section key -> (path of its nodes, value kept per identifier).
SECTION_NODES = {
    "elements": (".//am:elements/am:element", xsi_type),
    "relationships": (".//am:relationships/am:relationship", relationship_info),
    "views": (".//am:views/am:diagrams/am:view", name_text),
    "property_definitions": (".//am:propertyDefinitions/am:propertyDefinition", name_text),
}


def parse_section(root, key):
    """Map identifier -> value for every node of a section that has an identifier."""
    path, value = SECTION_NODES[key]
    return {
        node.get("identifier", ""): value(node)
        for node in root.findall(path, NS)
        if node.get("identifier", "")
    }


def parse_organizations(root):
    """Count all organization <item>s: total, element references and folders.

    ArchiMate organizations use <item> with an optional identifierRef (an element
    reference) or a <label> (a folder item).
    """
    orgs_el = root.find("am:organizations", NS)
    items = orgs_el.findall(".//am:item", NS) if orgs_el is not None else []
    refs = [item.get("identifierRef") for item in items if item.get("identifierRef")]
    return {
        "total_items": len(items),
        "identifier_refs": sorted(refs),
        "folder_count": len(items) - len(refs),
    }


def parse_archimate(filepath):
    """Parse an ArchiMate XML file and extract structural info."""
    root = ET.parse(filepath).getroot()
    return {
        "model_id": root.get("identifier", ""),
        "model_name": name_text(root),
        "elements": parse_section(root, "elements"),
        "relationships": parse_section(root, "relationships"),
        "views": parse_section(root, "views"),
        "organizations": parse_organizations(root),
        "property_definitions": parse_section(root, "property_definitions"),
    }


def check(passed, messages):
    """A PASS result with messages[0], or a FAIL result with messages[1]."""
    return ("PASS", messages[0]) if passed else ("FAIL", messages[1])


def section_of(pair, key):
    """One section of both models; ``pair`` is (original, exported)."""
    return pair[0][key], pair[1][key]


def count_check(label, counts):
    """Check that a section has the same size in both models."""
    o, e = counts
    return check(
        o == e,
        (
            f"{label} count matches: {o}",
            f"{label} count mismatch: {o} original vs {e} exported (delta {e - o:+d})",
        ),
    )


def compare_ids(label, sections):
    """Compare the identifier sets of a section; returns (results, shared identifiers)."""
    orig_ids, exp_ids = set(sections[0].keys()), set(sections[1].keys())
    missing, extra = orig_ids - exp_ids, exp_ids - orig_ids
    results = []
    if missing:
        results.append(("FAIL", f"Missing {len(missing)} {label} IDs in export (sample: {sorted(missing)[:5]})"))
    if extra:
        results.append(("WARN", f"Extra {len(extra)} {label} IDs in export (sample: {sorted(extra)[:5]})"))
    return results or [("PASS", f"All {label} identifiers match")], orig_ids & exp_ids


def compare_distribution(label, counters):
    """Compare how often each type occurs in both models."""
    orig_types, exp_types = counters
    if orig_types == exp_types:
        return [("PASS", f"{label} type distribution matches")]
    return [
        ("FAIL", f"  {label} type '{t}': {orig_types.get(t, 0)} original vs {exp_types.get(t, 0)} exported")
        for t in sorted(set(orig_types) | set(exp_types))
        if orig_types.get(t, 0) != exp_types.get(t, 0)
    ]


def compare_model(pair):
    """Check that both models have the same identifier."""
    o, e = pair[0]["model_id"], pair[1]["model_id"]
    return [check(o == e, (f"Model identifier matches: {o[:40]}", f"Model identifier mismatch: {o[:40]} vs {e[:40]}"))]


def compare_elements(pair):
    """Element count, identifiers, shared types and type distribution."""
    orig, exp = section_of(pair, "elements")
    id_results, common = compare_ids("element", (orig, exp))
    mismatches = [eid for eid in common if orig[eid] != exp[eid]]
    return [
        count_check("Element", (len(orig), len(exp))),
        *id_results,
        check(
            len(mismatches) == 0,
            ("All shared element types match", f"{len(mismatches)} element type mismatches (sample: {mismatches[:5]})"),
        ),
        *compare_distribution("Element", (Counter(orig.values()), Counter(exp.values()))),
    ]


def endpoints(relationship):
    """Source and target of a parsed relationship."""
    return relationship["source"], relationship["target"]


def compare_relationships(pair):
    """Relationship count, identifiers, type distribution and shared endpoints."""
    orig, exp = section_of(pair, "relationships")
    id_results, common = compare_ids("relationship", (orig, exp))
    moved = sum(1 for rid in common if endpoints(orig[rid]) != endpoints(exp[rid]))
    orig_types = Counter(r["type"] for r in orig.values())
    exp_types = Counter(r["type"] for r in exp.values())
    return [
        count_check("Relationship", (len(orig), len(exp))),
        *id_results,
        *compare_distribution("Relationship", (orig_types, exp_types)),
        check(moved == 0, ("All shared relationship source/target pairs match", f"{moved} relationship source/target mismatches")),
    ]


def compare_views(pair):
    """View count and identifiers."""
    orig, exp = section_of(pair, "views")
    return [count_check("View", (len(orig), len(exp))), *compare_ids("view", (orig, exp))[0]]


def compare_organizations(pair):
    """Organization item count, element references and folder count."""
    orig, exp = section_of(pair, "organizations")
    orig_refs, exp_refs = set(orig["identifier_refs"]), set(exp["identifier_refs"])
    missing_refs = orig_refs - exp_refs
    o, e = orig["folder_count"], exp["folder_count"]
    return [
        count_check("Organization total item", (orig["total_items"], exp["total_items"])),
        check(
            len(missing_refs) == 0,
            (f"Organization identifierRefs match ({len(orig_refs)} refs)", f"Missing {len(missing_refs)} organization identifierRefs in export"),
        ),
        check(o == e, (f"Organization folder count matches: {o}", f"Organization folder count mismatch: {o} original vs {e} exported")),
    ]


def compare_property_definitions(pair):
    """PropertyDefinition count, identifiers and shared names."""
    orig, exp = section_of(pair, "property_definitions")
    id_results, common = compare_ids("propertyDefinition", (orig, exp))
    mismatches = [pid for pid in common if orig[pid] != exp[pid]]
    return [
        count_check("PropertyDefinition", (len(orig), len(exp))),
        *id_results,
        check(
            len(mismatches) == 0,
            ("All shared propertyDefinition names match", f"{len(mismatches)} propertyDefinition name mismatches (sample: {mismatches[:5]})"),
        ),
    ]


SECTION_CHECKS = (
    compare_model,
    compare_elements,
    compare_relationships,
    compare_views,
    compare_organizations,
    compare_property_definitions,
)


def compare(original, exported):
    """Compare two parsed ArchiMate structures. Returns (results, all_pass)."""
    results = [result for section in SECTION_CHECKS for result in section((original, exported))]
    return results, all(status != "FAIL" for status, _ in results)


def print_count_row(label, counts):
    """Print one row of the counts table; ``counts`` is (original, exported)."""
    o, e = counts
    delta = e - o
    delta_str = f"{delta:+d}" if delta != 0 else "0"
    marker = " *" if delta != 0 else ""
    print(f"  {label:<25s} {o:>10d} {e:>10d} {delta_str:>10s}{marker}")


# Counts-table label -> section key, for the sections counted by their size.
COUNTED_SECTIONS = (
    ("Elements", "elements"),
    ("Relationships", "relationships"),
    ("Views", "views"),
    ("PropertyDefinitions", "property_definitions"),
)

ICONS = {"PASS": "  [PASS]", "FAIL": "  [FAIL]", "WARN": "  [WARN]"}


def count_rows(comparison):
    """(label, (original, exported)) for every row of the counts table."""
    original, exported = comparison.original, comparison.exported
    orgs_o, orgs_e = original["organizations"], exported["organizations"]
    return [
        *((label, (len(original[key]), len(exported[key]))) for label, key in COUNTED_SECTIONS),
        ("Org items (total)", (orgs_o["total_items"], orgs_e["total_items"])),
        ("Org identifierRefs", (len(orgs_o["identifier_refs"]), len(orgs_e["identifier_refs"]))),
        ("Org folders", (orgs_o["folder_count"], orgs_e["folder_count"])),
    ]


def print_counts(comparison):
    """Print the counts table of both models."""
    print("  ---- Counts ----")
    print(f"  {'Section':<25s} {'Original':>10s} {'Exported':>10s} {'Delta':>10s}")
    print(f"  {'-'*25} {'-'*10} {'-'*10} {'-'*10}")
    for label, counts in count_rows(comparison):
        print_count_row(label, counts)
    print()


def print_checks(comparison):
    """Print every check, then the summary."""
    print("  ---- Checks ----")
    for status, message in comparison.results:
        print(f"  {ICONS[status]} {message}")
    print_summary(comparison)


def print_summary(comparison):
    """Print the summary line and the overall result."""
    statuses = Counter(status for status, _ in comparison.results)
    warn_count = len(comparison.results) - statuses["PASS"] - statuses["FAIL"]
    print()
    print(f"  Summary: {statuses['PASS']} passed, {statuses['FAIL']} failed, {warn_count} warnings")
    print()
    print("  RESULT: ALL CHECKS PASSED" if comparison.all_pass else "  RESULT: SOME CHECKS FAILED")
    print("=" * 70)


def print_report(comparison, paths):
    """Print human-readable comparison report; ``paths`` is (original, exported)."""
    print("=" * 70)
    print("  ArchiMate Semantic Comparison Report")
    print("=" * 70)
    print(f"  Original : {paths[0]}")
    print(f"  Exported : {paths[1]}")
    print()
    print_counts(comparison)
    print_checks(comparison)


def print_json(comparison):
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
        "all_pass": comparison.all_pass,
        "original": counts(comparison.original),
        "exported": counts(comparison.exported),
        "checks": [{"status": s, "message": m} for s, m in comparison.results],
    }
    print(json_module.dumps(output, indent=2))


def exit_with_usage():
    """Print the usage text and exit with code 2."""
    print("Usage: compare_archimate.py <original.xml> <exported.xml> [--json]")
    print()
    print("Compares two ArchiMate 3.x XML files for structural equivalence.")
    print("Exit codes: 0 = all pass, 1 = failures, 2 = error")
    sys.exit(2)


def exit_with_error(message):
    """Print an error to stderr and exit with code 2."""
    print(message, file=sys.stderr)
    sys.exit(2)


def load_model(filepath, role):
    """Parse one model; ``role`` ("original" or "exported") names it in errors."""
    try:
        return parse_archimate(filepath)
    except FileNotFoundError:
        exit_with_error(f"ERROR: {role.capitalize()} file not found: {filepath}")
    except ET.ParseError as e:
        exit_with_error(f"ERROR: Failed to parse {role} XML: {e}")


def main():
    if len(sys.argv) < 3:
        exit_with_usage()

    original_file, exported_file = sys.argv[1], sys.argv[2]
    original = load_model(original_file, "original")
    exported = load_model(exported_file, "exported")
    comparison = Comparison(original, exported, *compare(original, exported))

    if "--json" in sys.argv:
        print_json(comparison)
    else:
        print_report(comparison, (original_file, exported_file))

    sys.exit(0 if comparison.all_pass else 1)


if __name__ == "__main__":
    main()
