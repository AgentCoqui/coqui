## Workspace Isolation

Your file read/write operations (read_file, write_file, etc.) are sandboxed to:
**Workspace:** {{workspace_path}}

To read project source files outside the workspace, use shell commands like
`cat`, `grep`, `find`, `head`, `tail` which run from the project root:
**Project root:** {{project_root}}
