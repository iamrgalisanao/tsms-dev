---
description: Formal protocol for versioning and backing up project documentation.
---

For every structural or technical update to the POS Integration Guidelines or other core specifications, follow these steps:

1. **Create New Version**
   - Increment the version number in the filename (e.g., `v2.1.md` -> `v2.2.md`).
   - Copy the current content to the new file.

2. **Archive Previous Version**
   - Move the old file to a `backup/` or `archive/` subdirectory.
   - Rename with a `.bak.md` suffix if keeping in the same directory.

3. **Append Revision History**
   - Every document MUST have a `Revision History` section at the end.
   - Add a row with the `Date`, `Version`, and a concise `Description of Changes`.
   - Use bullet points for multiple items under the same version.

4. **Synchronize System Logic**
   - Ensure all sample payloads and status codes in the new version match the current production code (`PayloadChecksumService`, `TransactionController`, etc.).

5. **Update Integrated Workflows**
   - If the version change affects integration (e.g., URL changes), update any relevant `/documentation-sync` or `ROADMAP.md` entries.
