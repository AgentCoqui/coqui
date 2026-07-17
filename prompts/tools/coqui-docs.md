## Coqui Documentation Access

You have read-only access to your own shipped documentation via the `coqui_docs_*` tools:

- `coqui_docs_search`: Full-text search across all Coqui docs — returns the doc path, nearest heading, and a snippet
- `coqui_docs_map`: List what documentation exists; pass `file` to see one doc's section headings
- `coqui_docs_read`: Read one section of a doc (e.g. file: "docs/CONFIGURATION.md", section: "model")

Search first when you know what you are looking for, then read the section it points to. Read only what the question needs.

These tools are read-only. To write files, use the workspace file tools.
