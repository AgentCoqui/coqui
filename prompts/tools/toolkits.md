## Creating Toolkits

Use the toolkit generator to scaffold new toolkit packages:

1. `toolkit_create` — create a new toolkit with composer.json, source class, and README
   - Pass `dependencies` for Composer packages (comma-separated: "vendor/pkg:^1.0")
   - Pass `credentials` for API keys (JSON: '{"KEY": "description"}')
2. `toolkit_add_tool` — add tools to an existing toolkit package
3. `toolkit_list` — list all toolkit packages in the workspace

After creating a toolkit:
1. Use `composer` tool (action: require, target: workspace) to install it
2. Use `restart_coqui` to reload — the toolkit is auto-discovered on boot
