<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# ArchiMate import and export: the round-trip check

Admin settings ▸ ArchiMate imports and exports a model in the ArchiMate Model
Exchange File Format. Under the export sits **Check a model file**, which
answers one question before or after an import: does this exchange file
survive stackiq's import and export without losing or changing anything?

The check writes nothing. It runs the same conversion the import runs
(`ArchiMateImportService::convertFileToObjects()`) and the same generation the
export runs (`ArchiMateExportService::generateXmlFromObjects()`), so what it
reports is what a real import and export would do.

Specification: [`openspec/changes/architecture-round-trip-check/`](https://github.com/ConductionNL/stackiq/tree/development/openspec/changes/architecture-round-trip-check).

## The two modes

| Mode | What it compares | Finds |
| --- | --- | --- |
| **Before import (nothing is written)** | The uploaded file with the file stackiq would export after converting it | Loss in the import's conversion and the export's generation |
| **Against the imported model** | The uploaded file with the file stackiq exports from the stored objects of that model | Also what was lost when OpenRegister stored the objects |

Against the imported model reads the model identifier from the root of the
file and uses only the stored objects that carry it. When none does, the check
answers **The model is not imported**. A file without a model identifier can
only be checked before import, because an import gives such a file a new
identifier.

## Reading the report

The report has a row per category: elements, relationships, views, view
nodes, property definitions and organizations. Each row shows how many items
the file holds, how many came back after the round trip, and how many are
missing, extra or changed. Items are matched by identifier, so the order of a
file never counts as a difference, and a relationship whose target changed is
reported as changed even when the counts are equal.

Click a category to see up to 50 examples with their identifier, name and, for
a changed item, the fields that differ. The full counts are always shown. The
summary above the table reads **No losses found**, or names the categories
with losses.

A check on a GEMMA release takes about as long as an import of it.

## Who may run it

Only a Nextcloud admin. The check takes an uploaded file (`POST
/api/archimate/round-trip-check`, field `archiMateFile`, `mode` before-import
or against-imported) and refuses a `file_path`, so it never reads a file on the
server. It replaces the old round-trip endpoint, which every signed-in user
could call and which wrote a test model into the register.
