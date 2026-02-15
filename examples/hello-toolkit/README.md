# Hello Toolkit

A minimal reference toolkit for [Coqui](https://github.com/coquibot/coqui) that demonstrates how to create custom toolkits. Use this as a starting point for building your own.

## Requirements

- PHP 8.4+
- [Coqui](https://github.com/coquibot/coqui)

## Installation

Add the example as a path repository in your workspace `composer.json`, then require it:

```bash
composer require coquibot/hello-toolkit
```

When installed alongside Coqui, the toolkit is **auto-discovered** via `extra.php-agents.toolkits` — no manual registration needed.

## Tools Provided

### `hello_world`

Returns a friendly greeting, optionally personalized with a name.

| Parameter | Type   | Required | Description                        |
|-----------|--------|----------|------------------------------------|
| `name`    | string | No       | The name to greet (default: World) |

**Example output:** `Hello, Alice! 👋`

### `hello_time`

Returns the current server date and time.

| Parameter | Type   | Required | Description                                  |
|-----------|--------|----------|----------------------------------------------|
| `format`  | string | No       | PHP date format string (default: ISO 8601)   |

**Example output:** `Current time: 2026-02-14T10:30:00-05:00`

## Project Structure

```
hello-toolkit/
├── composer.json           # Package manifest with auto-discovery config
├── README.md               # This file
└── src/
    └── HelloToolkit.php    # Main toolkit class implementing ToolkitInterface
```

## How It Works

1. **`composer.json`** declares the toolkit class in `extra.php-agents.toolkits`
2. Coqui's `ToolkitDiscovery` reads this declaration at boot
3. The toolkit is instantiated and its tools are registered with the agent
4. The LLM can now call `hello_world` and `hello_time` as tool calls

## Creating Your Own Toolkit

See the [Toolkit Development Guide](../../docs/TOOLKITS.md) for a comprehensive walkthrough, or use this project as a template:

1. Copy this directory
2. Update `composer.json` with your package name, namespace, and dependencies
3. Rename and modify `src/HelloToolkit.php` to implement your tools
4. Install into Coqui with `composer require`

## License

MIT
