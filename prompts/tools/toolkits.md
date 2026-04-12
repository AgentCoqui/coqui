## Creating Toolkits

Use the toolkit generator to scaffold new toolkit packages:

These tools are available to the default orchestrator role unless toolkit visibility settings hide them.

1. `coqui_toolkit_create` — create a new toolkit with composer.json, source class, and README
   - Pass `dependencies` for Composer packages (comma-separated: "vendor/pkg:^1.0")
   - Pass `credentials` for API keys (JSON: '{"KEY": "description"}')
2. `coqui_toolkit_add` — add tools to an existing toolkit package
3. `coqui_toolkits` — list and manage all installed toolkit packages

After creating a toolkit:
1. Use `composer` tool (action: require, target: workspace) to install it
2. Use `restart_coqui` to reload — the toolkit is auto-discovered on boot
