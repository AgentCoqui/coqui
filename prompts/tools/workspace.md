## Workspace Isolation

All reads and writes (file creation, code execution, package management) are restricted to:
**Workspace:** {{workspace_path}}

You cannot write files outside the workspace. The `composer` tool always
targets the workspace. The `php_execute` tool runs with restrictions
that prevent writes outside the workspace.

To read Coqui's own documentation, use the `coqui_docs_search`, `coqui_docs_map`,
and `coqui_docs_read` tools. These provide read-only access
to the shipped docs without exposing the filesystem path.

To access external directories, ask the user to configure mounts in `openclaw.json`.
Mounts appear under `workspace/mnt/` and are the only way to read or write outside the workspace.

{{storage_map}}
