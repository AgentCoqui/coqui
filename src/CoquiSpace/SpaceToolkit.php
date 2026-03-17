<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\CoquiSpace\Installer\ComposerRunner;
use CoquiBot\Coqui\CoquiSpace\Installer\SkillInstaller;
use CoquiBot\Coqui\CoquiSpace\Installer\ToolkitInstaller;
use CoquiBot\Coqui\CoquiSpace\Tool\SpaceAccountTool;
use CoquiBot\Coqui\CoquiSpace\Tool\SpaceManageTool;
use CoquiBot\Coqui\CoquiSpace\Tool\SpaceSkillsTool;
use CoquiBot\Coqui\CoquiSpace\Tool\SpaceToolkitsTool;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Core toolkit for Coqui Space integration.
 *
 * Provides agent tools for discovering, installing, and managing skills
 * and toolkits from the Coqui Space marketplace (https://coqui.space).
 *
 * Tools:
 * - space_skills   — browse, install, and manage skills
 * - space_toolkits — browse, install, and manage toolkits
 * - space          — manage installed content, social actions, collections
 * - space_account  — authenticated user dashboard (when token set)
 */
final class SpaceToolkit implements ToolkitInterface
{
    /** @var \Closure(): string */
    private readonly \Closure $tokenResolver;

    private readonly SpaceClient $client;

    private readonly SkillInstaller $skillInstaller;

    private readonly ToolkitInstaller $toolkitInstaller;

    public function __construct(
        SpaceClient $client,
        SkillInstaller $skillInstaller,
        ToolkitInstaller $toolkitInstaller,
        \Closure $tokenResolver,
    ) {
        $this->client = $client;
        $this->skillInstaller = $skillInstaller;
        $this->toolkitInstaller = $toolkitInstaller;
        $this->tokenResolver = $tokenResolver;
    }

    /**
     * Factory that wires everything from the BootManager.
     */
    public static function create(BootManager $boot): self
    {
        $workspacePath = $boot->workspacePath();
        $discovery = $boot->discovery();
        $skillDiscovery = $boot->skillDiscovery();

        $urlResolver = static function (): string {
            $env = getenv('COQUI_SPACE_URL');
            return $env !== false && $env !== '' ? rtrim($env, '/') : SpaceRegistry::DEFAULT_BASE_URL;
        };

        $tokenResolver = static function (): string {
            $env = getenv('COQUI_SPACE_API_TOKEN');
            return $env !== false ? $env : '';
        };

        $http = HttpClient::create();
        $client = new SpaceClient($urlResolver, $tokenResolver, $http);

        $composerRunner = new ComposerRunner($workspacePath);
        $toolkitInstaller = new ToolkitInstaller($client, $composerRunner, $discovery, $workspacePath);
        $skillInstaller = new SkillInstaller($client, $skillDiscovery, $skillDiscovery->skillsDir());

        return new self($client, $skillInstaller, $toolkitInstaller, $tokenResolver);
    }

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        $tools = [
            new SpaceSkillsTool($this->client, $this->skillInstaller),
            new SpaceToolkitsTool($this->client, $this->toolkitInstaller),
            new SpaceManageTool($this->client, $this->skillInstaller, $this->toolkitInstaller),
        ];

        if (($this->tokenResolver)() !== '') {
            $tools[] = new SpaceAccountTool($this->client);
        }

        return $tools;
    }

    public function guidelines(): string
    {
        $authenticated = ($this->tokenResolver)() !== '';
        $authStatus = $authenticated ? 'authenticated' : 'anonymous (limited functionality)';

        $accountRow = $authenticated
            ? "\n| `space_account` | Your account dashboard | profile, my_skills, my_toolkits, my_collections, my_submissions, my_installs, my_analytics, my_stars |"
            : '';

        return <<<GUIDELINES
        <space_manager>
        ## Coqui Space Manager

        API Status: {$authStatus}

        ### Tools — use the right one

        | Tool | Purpose | Key actions |
        |------|---------|-------------|
        | `space_skills` | Browse, install and manage skills | search, list, details, versions, reviews, file, install, update |
        | `space_toolkits` | Browse, install and manage toolkits | search, list, popular, details, reviews, install, update |
        | `space` | Manage content, collections, reviews, notifications, tags, unified search | installed, disable, enable, remove, star, unstar, submit, tags, search_all, collections, review, notifications, health |{$accountRow}

        ### Identifier patterns

        - **Skills** are identified by `owner/name` (e.g. `carmelosantana/code-review`). Directory name after install is the skill name.
        - **Toolkits** are Composer packages identified by `vendor/package` (e.g. `coquibot/coqui-toolkit-brave-search`).
        - Any identifier with a `/` is treated as a toolkit in the `space` tool. Skill names without `/` are matched by directory name.

        ### Authentication required for

        - `star`, `unstar`, `submit`, `review`
        - `collections` (create/update/delete/add_item/remove_item)
        - `notifications`, `space_account` (all actions)
        - Anonymous access: search, details, list, versions, reviews, install, update, tags, search_all, health

        ### Workflow patterns

        **Discover -> Install:**
        1. `space_skills(action: "search", query: "code review")`
        2. `space_skills(action: "details", owner: "carmelosantana", name: "code-review")`
        3. `space_skills(action: "install", owner: "carmelosantana", name: "code-review")`

        **Collections:**
        1. `space(action: "collections", sub_action: "create", collection_name: "My Favorites", description: "...", is_public: true)`
        2. `space(action: "collections", sub_action: "add_item", collection_id: "abc", entity_type: "skill", owner: "carmelosantana", name: "code-review")`

        **Reviews:**
        1. `space(action: "review", entity_type: "skill", owner: "carmelosantana", name: "code-review", rating: 5, title: "Great!", body: "Works perfectly.")`

        **Manage installed:**
        1. `space(action: "installed")` — see everything installed
        2. `space(action: "disable", name: "code-review")` — deactivate
        3. `space(action: "remove", name: "code-review", purge: true)` — fully remove

        **Social:**
        1. `space(action: "star", entity_type: "skill", owner: "carmelosantana", name: "code-review")`
        2. `space(action: "submit", type: "toolkit", source_url: "https://github.com/user/repo")`

        ### Verified publishers

        Some skills and toolkits are from verified publishers (indicated by a badge). Prefer verified content when alternatives exist.
        </space_manager>
        GUIDELINES;
    }

    /**
     * Expose the SpaceClient for use by slash commands.
     */
    public function client(): SpaceClient
    {
        return $this->client;
    }

    /**
     * Expose the SkillInstaller for use by slash commands.
     */
    public function skillInstaller(): SkillInstaller
    {
        return $this->skillInstaller;
    }

    /**
     * Expose the ToolkitInstaller for use by slash commands.
     */
    public function toolkitInstaller(): ToolkitInstaller
    {
        return $this->toolkitInstaller;
    }
}
