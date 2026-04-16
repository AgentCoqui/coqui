<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Behavioral settings and communication patterns loaded from a profile's preferences.json.
 *
 * Preferences are split into two sections:
 * - promptDirectives: key-value pairs rendered into the system prompt to guide communication style
 * - behavior: code-level settings that configure agent parameters (e.g. temperature hints, tool preferences)
 */
final readonly class ProfilePreferences
{
    /**
     * @param array<string, string> $promptDirectives Communication directives rendered into the system prompt.
     * @param array<string, mixed> $behavior Code-level settings for agent configuration.
     */
    public function __construct(
        public array $promptDirectives = [],
        public array $behavior = [],
    ) {}

    /**
     * Load preferences from a JSON file.
     *
     * Expected format:
     * {
     *   "prompt_directives": {
     *     "response_style": "concise and measured",
     *     "formatting": "prefer markdown tables over lists"
     *   },
     *   "behavior": {
     *     "temperature_hint": 0.7
     *   }
     * }
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return new self();
        }

        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self();
        }

        if (!is_array($data)) {
            return new self();
        }

        $directives = [];
        if (isset($data['prompt_directives']) && is_array($data['prompt_directives'])) {
            foreach ($data['prompt_directives'] as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $directives[$key] = $value;
                }
            }
        }

        $behavior = [];
        if (isset($data['behavior']) && is_array($data['behavior'])) {
            $behavior = $data['behavior'];
        }

        return new self(
            promptDirectives: $directives,
            behavior: $behavior,
        );
    }

    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->promptDirectives === [] && $this->behavior === [];
    }

    public function hasPromptDirectives(): bool
    {
        return $this->promptDirectives !== [];
    }

    /**
     * Render prompt directives as a Markdown section.
     *
     * Returns null when no directives are configured.
     */
    public function renderPromptSection(): ?string
    {
        if ($this->promptDirectives === []) {
            return null;
        }

        $lines = ['## Preferences', ''];

        foreach ($this->promptDirectives as $key => $value) {
            $label = ucfirst(str_replace('_', ' ', $key));
            $lines[] = "- **{$label}:** {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * Get a behavior setting by key.
     */
    public function getBehavior(string $key, mixed $default = null): mixed
    {
        return $this->behavior[$key] ?? $default;
    }
}
