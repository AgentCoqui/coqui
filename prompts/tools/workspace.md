## Workspace Isolation

All writes (file creation, code execution, package management) are restricted to:
**Workspace:** {{workspace_path}}

The project root is **read-only** — accessible via `project_read`, `project_list`,
`project_search`, and shell commands (`cat`, `grep`, `find`, `head`, `tail`) which
run from:
**Project root:** {{project_root}}

You cannot modify the project's `composer.json`, write files to the project root,
or execute PHP code that writes outside the workspace. The `composer` tool always
targets the workspace. The `php_execute` tool runs with `open_basedir` restrictions
that prevent writes outside the workspace.
