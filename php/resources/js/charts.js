/**
 * Графики: <div x-data="chart(@js($config))" class="relative h-56"><canvas x-ref="canvas" role="img" aria-label="…"></canvas></div>
 *
 * config = {
 *   type: 'line' | 'bar' | 'hbar' | 'doughnut',
 *   labels: [...],
 *   series: [{ label, data: [...], color?, kind?: 'line' | 'bar', dashed?, fill?, stack? }],
 *   format?: 'money' | 'number' | 'pct',  unit?: 'UZS',  stacked?: bool,  legend?: bool,  cutout?: '72%'
 * }
 * Суммы приходят строками (DECIMAL) — в число превращаются только для рисования.
 * Chart.js подгружается отдельным файлом при первом графике на странице; при уходе со страницы
 * (удалении элемента) график уничтожается. Уважает prefers-reduced-motion.
 */

export const PALETTE = ['#60a5fa', '#818cf8', '#f9a8d4', '#6ee7b7', '#fcd34d', '#a5b4fc', '#fda4af', '#7dd3fc', '#c4b5fd', '#5eead4', '#fdba74', '#cbd5e1'];

let loading = null;
const loadChart = () => (loading ??= import('./charts-core.js').then((m) => m.default));

const locale = () => {
    const lang = (document.documentElement.lang || 'ru').toLowerCase();
    return lang.startsWith('uz') ? 'uz-Latn-UZ' : lang.startsWith('en') ? 'en-US' : 'ru-RU';
};

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

const num = (v) => {
    if (v === null || v === undefined) return null;
    const n = typeof v === 'number' ? v : parseFloat(String(v).replace(/\s/g, ''));
    return Number.isFinite(n) ? n : 0;
};

export const formatValue = (v, format = 'number', unit = '', compact = false) => {
    const n = num(v) ?? 0;
    if (format === 'pct') return `${new Intl.NumberFormat(locale(), { maximumFractionDigits: 1 }).format(n)}%`;
    const text = new Intl.NumberFormat(locale(), compact ? { notation: 'compact', maximumFractionDigits: 1 } : { maximumFractionDigits: format === 'money' ? 0 : 1 }).format(n);
    return format === 'money' && unit && !compact ? `${text} ${unit}` : text;
};

const alpha = (hex, a) => {
    const h = hex.replace('#', '');
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
    return `rgba(${r}, ${g}, ${b}, ${a})`;
};

const TICK = '#64748b';
const GRID = 'rgba(100, 116, 139, 0.14)';

const build = (cfg, canvas) => {
    const format = cfg.format ?? 'number';
    const unit = cfg.unit ?? '';
    const doughnut = cfg.type === 'doughnut';
    const horizontal = cfg.type === 'hbar';
    const baseType = doughnut ? 'doughnut' : cfg.type === 'line' ? 'line' : 'bar';
    const ctx = canvas.getContext('2d');

    const datasets = (cfg.series ?? []).map((s, i) => {
        const color = s.color ?? PALETTE[i % PALETTE.length];
        const kind = s.kind ?? baseType;
        const data = (s.data ?? []).map(num);
        if (doughnut) {
            const colors = data.map((_, j) => (s.colors?.[j] ?? PALETTE[j % PALETTE.length]));
            return { label: s.label, data, backgroundColor: colors, borderColor: '#ffffff', borderWidth: 2, hoverOffset: 6, borderRadius: 6, spacing: 1 };
        }
        if (kind === 'line') {
            let fill = false;
            if (s.fill !== false && !s.dashed && ctx) {
                const g = ctx.createLinearGradient(0, 0, 0, canvas.clientHeight || 240);
                g.addColorStop(0, alpha(color, 0.35));
                g.addColorStop(1, alpha(color, 0.02));
                fill = { target: 'origin', above: g };
            }
            return {
                type: 'line', label: s.label, data, borderColor: color, backgroundColor: color, fill, tension: 0.35, borderWidth: 2.5,
                pointRadius: data.length > 24 ? 0 : 3, pointHoverRadius: 5, pointBackgroundColor: '#ffffff', pointBorderWidth: 2,
                borderDash: s.dashed ? [6, 4] : undefined, order: 0,
            };
        }
        return {
            type: 'bar', label: s.label, data, backgroundColor: color, hoverBackgroundColor: alpha(color, 0.85), borderRadius: 6, borderSkipped: false,
            maxBarThickness: horizontal ? 22 : 34, stack: s.stack, order: 1,
        };
    });

    const tooltip = {
        backgroundColor: 'rgba(15, 23, 42, 0.88)', padding: 10, cornerRadius: 10, boxPadding: 4, usePointStyle: true,
        callbacks: {
            label: (c) => {
                const v = doughnut ? c.parsed : horizontal ? c.parsed.x : c.parsed.y;
                const label = doughnut ? c.label : c.dataset.label;
                if (doughnut) {
                    const total = c.dataset.data.reduce((a, b) => a + (b ?? 0), 0) || 1;
                    return ` ${label}: ${formatValue(v, format, unit)} (${formatValue((v / total) * 100, 'pct')})`;
                }
                return ` ${label}: ${formatValue(v, format, unit)}`;
            },
        },
    };

    const valueAxis = {
        beginAtZero: true, stacked: !!cfg.stacked, border: { display: false }, grid: { color: GRID },
        ticks: { color: TICK, maxTicksLimit: 5, callback: (v) => formatValue(v, format, unit, true) },
    };
    const catAxis = {
        stacked: !!cfg.stacked, border: { display: false }, grid: { display: false },
        ticks: { color: TICK, autoSkip: true, maxRotation: 0, callback(v) {
            const l = this.getLabelForValue(v);
            return typeof l === 'string' && l.length > 18 ? `${l.slice(0, 17)}…` : l;
        } },
    };

    return {
        type: baseType,
        data: { labels: cfg.labels ?? [], datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion() ? false : { duration: 600 },
            indexAxis: horizontal ? 'y' : 'x',
            cutout: doughnut ? (cfg.cutout ?? '70%') : undefined,
            interaction: doughnut ? undefined : { mode: 'index', intersect: false },
            layout: { padding: doughnut ? 4 : 0 },
            plugins: {
                legend: {
                    display: cfg.legend ?? (!doughnut && datasets.length > 1),
                    position: 'bottom',
                    labels: { color: TICK, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 14 },
                },
                tooltip,
            },
            scales: doughnut ? {} : horizontal ? { x: valueAxis, y: catAxis } : { x: catAxis, y: valueAxis },
        },
    };
};

export default function registerCharts(Alpine) {
    Alpine.data('chart', (cfg = {}) => ({
        chart: null,
        alive: true,
        async init() {
            const canvas = this.$refs.canvas ?? this.$el.querySelector('canvas');
            if (!canvas) return;
            const Chart = await loadChart();
            if (!this.alive || !canvas.isConnected) return;
            this.chart = new Chart(canvas, build(cfg, canvas));
        },
        destroy() {
            this.alive = false;
            this.chart?.destroy();
            this.chart = null;
        },
    }));
}
