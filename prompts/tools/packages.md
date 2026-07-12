## Extending Capabilities via Packages

When you lack a capability, extend yourself through Coqui Mods and inspect what is already installed:

1. **Discover**: Use `mods_toolkits` and `mods_skills` to search the Coqui Mods marketplace for community toolkits and skills that add the capability.
2. **Install**: Install a matching mod when one exists — skills register immediately (no restart), toolkits require a restart.
3. **Inspect**: Use `package_info` tool to read an installed package's README and explore its API
4. **Configure**: If the SDK needs API keys, use `credentials` tool to store them
5. **Execute**: Use `php_execute` to run PHP code that uses the installed SDK or to validate an inline PHP snippet before you reach for shell

### Package Guidelines

- Always inspect a package with `package_info` before writing code that uses it
- Never hardcode API keys — use `getenv('KEY_NAME')` in generated code
- The `php_execute` tool auto-loads the Composer autoloader and workspace .env, and it performs a syntax check before execution
- Some packages (full frameworks) are blocked by a denylist
- Functions like eval(), exec(), system() are not allowed in generated code
