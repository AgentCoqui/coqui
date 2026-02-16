/**
 * Coqui Dashboard — Preferences Manager
 *
 * Handles font size, color scheme, wallpapers, and stats.js FPS counter.
 * All preferences are persisted in localStorage and applied on page load.
 */

const coquiPrefs = (() => {
    const STORAGE_KEY = 'coqui-prefs';
    let _statsInstances = [];

    // ── LocalStorage ───────────────────────────────────────────────────────

    function _load() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        } catch {
            return {};
        }
    }

    function _save(prefs) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    }

    function get(key) {
        return _load()[key] ?? null;
    }

    function set(key, value) {
        const prefs = _load();
        prefs[key] = value;
        _save(prefs);
    }

    function remove(key) {
        const prefs = _load();
        delete prefs[key];
        _save(prefs);
    }

    // ── Font Size ──────────────────────────────────────────────────────────

    const FONT_SIZES = {
        small: '13px',
        medium: '14px',
        large: '16px',
        'x-large': '18px',
    };

    function applyFontSize(size) {
        const px = FONT_SIZES[size] || FONT_SIZES.medium;
        document.documentElement.style.setProperty('--font-size-base', px);
        document.documentElement.setAttribute('data-font-size', size || 'medium');
    }

    // ── Color Scheme ───────────────────────────────────────────────────────

    function applyColorScheme(scheme) {
        const root = document.documentElement;
        if (scheme === 'light') {
            root.setAttribute('data-theme', 'light');
        } else {
            root.removeAttribute('data-theme');
        }
        // Update all active CodeMirror editors
        if (typeof updateAllEditorThemes === 'function') {
            updateAllEditorThemes(scheme === 'light' ? 'default' : 'monokai');
        }
    }

    // ── Wallpaper ──────────────────────────────────────────────────────────

    function applyWallpaper(name) {
        const body = document.body;
        if (!name) {
            body.style.removeProperty('--wallpaper-url');
            body.classList.remove('has-wallpaper');
            return;
        }
        body.style.setProperty('--wallpaper-url', `url('/api/wallpapers/${encodeURIComponent(name)}/file')`);
        body.classList.add('has-wallpaper');
    }

    // ── Stats.js (FPS Counter) ─────────────────────────────────────────────

    function initStats(opts = { fps: true, ms: false, mb: false }) {
        if (typeof Stats === 'undefined') return;

        destroyStats();

        const panels = [];
        if (opts.fps) panels.push(0);
        if (opts.ms) panels.push(1);
        if (opts.mb) panels.push(2);

        panels.forEach((panel, i) => {
            const stats = new Stats();
            stats.showPanel(panel);
            stats.dom.style.cssText = `position:fixed;bottom:0;right:${i * 80}px;z-index:10000;cursor:pointer;`;
            stats.dom.style.top = 'auto';
            document.body.appendChild(stats.dom);
            _statsInstances.push(stats);
        });

        if (_statsInstances.length > 0) {
            const animate = () => {
                _statsInstances.forEach(s => s.update());
                if (_statsInstances.length > 0) requestAnimationFrame(animate);
            };
            requestAnimationFrame(animate);
        }
    }

    function destroyStats() {
        _statsInstances.forEach(s => {
            if (s.dom && s.dom.parentNode) s.dom.parentNode.removeChild(s.dom);
        });
        _statsInstances = [];
    }

    // ── Apply All (called on page load) ─────────────────────────────────

    function apply() {
        const fontSize = get('fontSize') || 'medium';
        const colorScheme = get('colorScheme') || 'default';
        const wallpaper = get('wallpaper') || '';
        const fpsEnabled = get('fpsEnabled') === 'true';

        applyFontSize(fontSize);
        applyColorScheme(colorScheme);
        applyWallpaper(wallpaper);

        if (fpsEnabled) {
            const fpsOpts = {
                fps: get('fpsShowFps') !== 'false',
                ms: get('fpsShowMs') === 'true',
                mb: get('fpsShowMb') === 'true',
            };
            // Wait for stats.js to load
            if (typeof Stats !== 'undefined') {
                initStats(fpsOpts);
            } else {
                window.addEventListener('load', () => initStats(fpsOpts));
            }
        }
    }

    return {
        get,
        set,
        remove,
        apply,
        applyFontSize,
        applyColorScheme,
        applyWallpaper,
        initStats,
        destroyStats,
    };
})();
