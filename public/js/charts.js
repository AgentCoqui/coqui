/**
 * Coqui Dashboard — Chart.js Configuration
 *
 * Dark-themed chart rendering for token usage and tool usage.
 */

// ─── Chart.js Global Defaults ──────────────────────────────────────────────────

const chartColors = {
    primary: 'hsl(217, 91%, 60%)',
    secondary: 'hsl(263, 70%, 50%)',
    success: 'hsl(142, 71%, 45%)',
    warning: 'hsl(38, 92%, 50%)',
    destructive: 'hsl(0, 62%, 55%)',

    // Chart-specific palette
    chart1: 'hsl(220, 70%, 50%)',
    chart2: 'hsl(160, 60%, 45%)',
    chart3: 'hsl(30, 80%, 55%)',
    chart4: 'hsl(280, 65%, 60%)',
    chart5: 'hsl(340, 75%, 55%)',

    // Utilities
    border: 'hsl(240, 3.7%, 15.9%)',
    muted: 'hsl(240, 5%, 64.9%)',
    background: 'hsl(240, 10%, 3.9%)',
    card: 'hsl(240, 10%, 3.9%)',
};

const chartPalette = [
    chartColors.chart1,
    chartColors.chart2,
    chartColors.chart3,
    chartColors.chart4,
    chartColors.chart5,
    chartColors.primary,
    chartColors.secondary,
    chartColors.warning,
];

// Apply Chart.js global defaults when available
if (typeof Chart !== 'undefined') {
    Chart.defaults.color = chartColors.muted;
    Chart.defaults.borderColor = chartColors.border;
    Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
    Chart.defaults.plugins.legend.labels.padding = 16;
    Chart.defaults.plugins.tooltip.backgroundColor = 'hsl(240, 10%, 10%)';
    Chart.defaults.plugins.tooltip.borderColor = chartColors.border;
    Chart.defaults.plugins.tooltip.borderWidth = 1;
    Chart.defaults.plugins.tooltip.titleColor = '#fff';
    Chart.defaults.plugins.tooltip.bodyColor = chartColors.muted;
    Chart.defaults.plugins.tooltip.cornerRadius = 6;
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.elements.line.borderWidth = 2;
    Chart.defaults.elements.point.radius = 0;
    Chart.defaults.elements.point.hoverRadius = 5;
    Chart.defaults.elements.bar.borderRadius = 4;
}

// ─── Chart instances (for cleanup on re-render) ────────────────────────────────

const chartInstances = {};

function destroyChart(id) {
    if (chartInstances[id]) {
        chartInstances[id].destroy();
        delete chartInstances[id];
    }
}

// ─── Token Usage Line Chart ────────────────────────────────────────────────────

function renderTokenChart(canvasId, data) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas || !data || !Array.isArray(data) || data.length === 0) return;

    const labels = data.map(d => d.period || d.date || '');
    const promptTokens = data.map(d => Number(d.prompt_tokens) || 0);
    const completionTokens = data.map(d => Number(d.completion_tokens) || 0);

    chartInstances[canvasId] = new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Prompt Tokens',
                    data: promptTokens,
                    borderColor: chartColors.chart1,
                    backgroundColor: chartColors.chart1 + '22',
                    fill: true,
                    tension: 0.3,
                },
                {
                    label: 'Completion Tokens',
                    data: completionTokens,
                    borderColor: chartColors.chart2,
                    backgroundColor: chartColors.chart2 + '22',
                    fill: true,
                    tension: 0.3,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        maxTicksLimit: 12,
                        maxRotation: 0,
                    },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: chartColors.border },
                    ticks: {
                        callback: (v) => formatNumber(v),
                    },
                },
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (ctx) => ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString(),
                    },
                },
            },
        },
    });
}

// ─── Tool Usage Horizontal Bar Chart ───────────────────────────────────────────

function renderToolChart(canvasId, data) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas || !data || !Array.isArray(data) || data.length === 0) return;

    // Sort by usage count descending, take top 10
    const sorted = [...data].sort((a, b) => (b.usage_count || 0) - (a.usage_count || 0)).slice(0, 10);
    const labels = sorted.map(d => d.tool_name || d.tool || '');
    const counts = sorted.map(d => Number(d.usage_count) || 0);
    const colors = labels.map((_, i) => chartPalette[i % chartPalette.length]);

    chartInstances[canvasId] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Invocations',
                data: counts,
                backgroundColor: colors.map(c => c + 'cc'),
                borderColor: colors,
                borderWidth: 1,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: chartColors.border },
                    ticks: { precision: 0 },
                },
                y: {
                    grid: { display: false },
                    ticks: {
                        font: { family: "'JetBrains Mono', monospace", size: 11 },
                    },
                },
            },
            plugins: {
                legend: { display: false },
            },
        },
    });
}

// ─── Model Usage Doughnut Chart (optional, available for future use) ───────────

function renderModelDoughnut(canvasId, data) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas || !data || !Array.isArray(data) || data.length === 0) return;

    const labels = data.map(d => d.model || 'unknown');
    const tokens = data.map(d => Number(d.total_tokens) || 0);
    const colors = labels.map((_, i) => chartPalette[i % chartPalette.length]);

    chartInstances[canvasId] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: tokens,
                backgroundColor: colors.map(c => c + 'cc'),
                borderColor: chartColors.card,
                borderWidth: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        font: { size: 11 },
                    },
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' tokens',
                    },
                },
            },
        },
    });
}
