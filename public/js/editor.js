/**
 * Coqui Dashboard — CodeMirror Editor Factory
 *
 * Provides themed editor instances for config, env, and file editing.
 * Uses CodeMirror 5 (vendored) — no build step required.
 */

/**
 * Map file extensions to CodeMirror mode names.
 */
function getEditorMode(filename) {
    if (!filename) return 'text/plain';

    const ext = filename.split('.').pop().toLowerCase();
    const map = {
        // JavaScript / TypeScript / JSON
        js: 'javascript',
        mjs: 'javascript',
        cjs: 'javascript',
        jsx: 'javascript',
        ts: 'javascript',
        tsx: 'javascript',
        json: 'application/json',
        // Web
        php: 'php',
        html: 'htmlmixed',
        htm: 'htmlmixed',
        xml: 'xml',
        svg: 'xml',
        xsl: 'xml',
        xsd: 'xml',
        css: 'css',
        scss: 'text/x-scss',
        sass: 'sass',
        less: 'text/x-less',
        // Templates
        twig: 'twig',
        jinja: 'django',
        jinja2: 'django',
        djhtml: 'django',
        // Markup / docs
        md: 'markdown',
        markdown: 'markdown',
        diff: 'diff',
        patch: 'diff',
        // Shell / system
        sh: 'shell',
        bash: 'shell',
        zsh: 'shell',
        fish: 'shell',
        // Data
        sql: 'sql',
        yaml: 'yaml',
        yml: 'yaml',
        toml: 'toml',
        ini: 'properties',
        env: 'properties',
        properties: 'properties',
        editorconfig: 'properties',
        // Python
        py: 'python',
        pyw: 'python',
        pyx: 'python',
        // C-family (clike mode)
        c: 'text/x-csrc',
        cpp: 'text/x-c++src',
        cc: 'text/x-c++src',
        cxx: 'text/x-c++src',
        h: 'text/x-csrc',
        hpp: 'text/x-c++src',
        hxx: 'text/x-c++src',
        java: 'text/x-java',
        cs: 'text/x-csharp',
        kt: 'text/x-kotlin',
        kts: 'text/x-kotlin',
        scala: 'text/x-scala',
        m: 'text/x-objectivec',
        mm: 'text/x-objectivec',
        // Other languages
        rb: 'ruby',
        go: 'go',
        rs: 'rust',
        swift: 'swift',
        lua: 'lua',
        pl: 'perl',
        pm: 'perl',
        r: 'r',
        R: 'r',
        cmake: 'cmake',
        mat: 'octave',
        // Config files
        dockerfile: 'dockerfile',
        nginx: 'nginx',
        htaccess: 'nginx',
        conf: 'nginx',
        cfg: 'properties',
        // Plain text
        txt: 'text/plain',
        log: 'text/plain',
        csv: 'text/plain',
    };

    // Check full filename for special cases
    const name = filename.split('/').pop().toLowerCase();
    if (name === 'dockerfile' || name.startsWith('dockerfile.')) return 'dockerfile';
    if (name === '.env' || name.startsWith('.env.')) return 'properties';
    if (name === 'makefile' || name === 'gnumakefile') return 'shell';
    if (name === 'gemfile' || name === 'rakefile' || name === 'vagrantfile') return 'ruby';
    if (name === 'cmakelists.txt') return 'cmake';
    if (name === '.htaccess' || name === 'httpd.conf' || name === 'apache2.conf') return 'nginx';
    if (name === 'nginx.conf') return 'nginx';
    if (name === '.editorconfig') return 'properties';
    if (name === 'composer.json' || name === 'package.json' || name === 'tsconfig.json') return 'application/json';

    const mode = map[ext] || 'text/plain';

    // Guard: if the mode isn't actually registered in CodeMirror, fall back
    // to plain text. This prevents crashes from missing mode scripts.
    if (typeof CodeMirror !== 'undefined' && mode !== 'text/plain') {
        const resolved = CodeMirror.findModeByName
            ? CodeMirror.findModeByName(mode)
            : null;
        // CodeMirror.modes contains registered mode constructors.
        // MIME types (e.g. 'application/json', 'text/x-csrc') are in CodeMirror.mimeModes.
        const isRegistered = CodeMirror.modes[mode]
            || CodeMirror.mimeModes[mode]
            || resolved;
        if (!isRegistered) {
            console.warn(`CodeMirror mode "${mode}" not loaded, falling back to plain text`);
            return 'text/plain';
        }
    }

    return mode;
}

/**
 * Determine the current theme name based on user preferences.
 */
function getEditorTheme() {
    if (typeof coquiPrefs !== 'undefined' && coquiPrefs.get('colorScheme') === 'light') {
        return 'default';
    }
    return 'monokai';
}

/**
 * Create a CodeMirror editor instance in the given container.
 * Returns the editor instance synchronously (no Promise needed).
 *
 * @param {string} containerId  DOM id of the container element
 * @param {string} value        Initial editor content
 * @param {string} mode         Language mode (e.g. 'javascript', 'application/json', 'php')
 * @returns {object|null}       CodeMirror editor instance
 */
function createCodeMirrorEditor(containerId, value = '', mode = 'text/plain') {
    const container = document.getElementById(containerId);
    if (!container) return null;

    // Clear previous editor
    container.innerHTML = '';

    if (typeof CodeMirror === 'undefined') {
        console.warn('CodeMirror not available, falling back to textarea');
        const textarea = document.createElement('textarea');
        textarea.style.cssText = "width:100%;height:100%;background:var(--background);color:var(--foreground);border:none;resize:none;padding:1rem;font-family:'JetBrains Mono',monospace;font-size:13px;";
        textarea.value = value;
        container.appendChild(textarea);
        return null;
    }

    // Create editor with matchBrackets and foldGutter DISABLED.
    // These addons try to measure line positions during construction,
    // which crashes when the container is hidden or has zero dimensions
    // (common with Alpine.js x-show panels).
    const editor = CodeMirror(container, {
        value: value,
        mode: mode,
        theme: getEditorTheme(),
        lineNumbers: true,
        matchBrackets: false,
        autoCloseBrackets: true,
        styleActiveLine: true,
        lineWrapping: true,
        indentUnit: 4,
        tabSize: 4,
        indentWithTabs: false,
        foldGutter: false,
        gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
    });

    // Store reference on container for external access
    container._cmEditor = editor;

    // Enable measurement-dependent addons after a frame, once the
    // container has had a chance to become visible and gain dimensions.
    requestAnimationFrame(() => {
        editor.refresh();
        editor.setOption('matchBrackets', true);
        editor.setOption('foldGutter', true);
    });

    return editor;
}

/**
 * Update the theme on all active CodeMirror editors.
 * Called when the user toggles light/dark mode.
 */
function updateAllEditorThemes(theme) {
    document.querySelectorAll('.editor-container').forEach(container => {
        if (container._cmEditor) {
            container._cmEditor.setOption('theme', theme);
        }
    });
}
