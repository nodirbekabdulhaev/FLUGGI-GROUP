'use client';

import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * Графики аналитики — лёгкий SVG без сторонних библиотек.
 * Цвета серий — проверенная категориальная палитра (порядок фиксирован, не по рангу):
 * подписи и значения — цветом текста, цвет несут только метки.
 */
export const SERIES = {
  blue: '#2a78d6',
  orange: '#eb6834',
  aqua: '#1baf7a',
} as const;

const GRID = '#e4e4e7';
const MUTED = '#71717a';

function useWidth<T extends HTMLElement>() {
  const ref = useRef<T>(null);
  const [width, setWidth] = useState(0);
  useEffect(() => {
    if (!ref.current) return;
    const ro = new ResizeObserver(([e]) => setWidth(Math.floor(e!.contentRect.width)));
    ro.observe(ref.current);
    return () => ro.disconnect();
  }, []);
  return [ref, width] as const;
}

/** «Круглые» деления оси: 0, 2 млн, 4 млн… */
export function niceTicks(max: number, count = 4): number[] {
  if (max <= 0) return [0, 1];
  const raw = max / count;
  const pow = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * pow).find((s) => s >= raw)!;
  const ticks: number[] = [];
  for (let v = 0; v <= max + step * 0.001; v += step) ticks.push(v);
  if (ticks[ticks.length - 1]! < max) ticks.push(ticks[ticks.length - 1]! + step);
  return ticks;
}

/** Компактные числа для осей: 1,5 млн / 300 тыс. */
export function compact(n: number): string {
  const f = (v: number) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(v);
  const a = Math.abs(n);
  if (a >= 1e9) return `${f(n / 1e9)} млрд`;
  if (a >= 1e6) return `${f(n / 1e6)} млн`;
  if (a >= 1e3) return `${f(n / 1e3)} тыс`;
  return f(n);
}

export function Legend({ items }: { items: { label: string; color: string; dashed?: boolean }[] }) {
  return (
    <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
      {items.map((i) => (
        <li key={i.label} className="flex items-center gap-1.5">
          {i.dashed ? (
            <span className="inline-block h-0 w-4 border-t-2 border-dashed border-foreground/60" />
          ) : (
            <span className="inline-block size-2.5 rounded-sm" style={{ background: i.color }} />
          )}
          {i.label}
        </li>
      ))}
    </ul>
  );
}

function Tooltip({
  x,
  y,
  width,
  children,
}: {
  x: number;
  y: number;
  width: number;
  children: React.ReactNode;
}) {
  const left = Math.min(Math.max(x, 90), width - 90);
  return (
    <div
      role="tooltip"
      className="pointer-events-none absolute z-10 min-w-40 -translate-x-1/2 -translate-y-full rounded-md border bg-surface px-3 py-2 text-xs shadow-md"
      style={{ left, top: y }}
    >
      {children}
    </div>
  );
}

export interface LineSeries {
  key: string;
  label: string;
  color: string;
  values: number[];
}

/** Линии во времени: перекрестье и подсказка по ближайшей точке. */
export function LineChart({
  labels,
  series,
  format,
  formatLabel,
  height = 240,
}: {
  labels: string[];
  series: LineSeries[];
  format: (v: number) => string;
  formatLabel: (k: string) => string;
  height?: number;
}) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const [hover, setHover] = useState<number | null>(null);
  const pad = { top: 12, right: 12, bottom: 26, left: 56 };
  const w = Math.max(0, width - pad.left - pad.right);
  const h = height - pad.top - pad.bottom;
  const max = Math.max(0, ...series.flatMap((s) => s.values));
  const ticks = niceTicks(max);
  const top = ticks[ticks.length - 1]!;
  const x = (i: number) => (labels.length <= 1 ? w / 2 : (i / (labels.length - 1)) * w);
  const y = (v: number) => h - (Math.max(0, v) / top) * h;
  const every = Math.max(1, Math.ceil(labels.length / Math.max(2, Math.floor(w / 70))));

  return (
    <div className="grid gap-3">
      {series.length > 1 ? (
        <Legend items={series.map((s) => ({ label: s.label, color: s.color }))} />
      ) : null}
      <div ref={ref} className="relative w-full" style={{ height }}>
        {width > 0 ? (
          <svg
            width={width}
            height={height}
            role="img"
            aria-label={series.map((s) => s.label).join(', ')}
            onPointerLeave={() => setHover(null)}
            onPointerMove={(e) => {
              const r = (e.currentTarget as SVGSVGElement).getBoundingClientRect();
              const px = e.clientX - r.left - pad.left;
              const i = labels.length <= 1 ? 0 : Math.round((px / w) * (labels.length - 1));
              setHover(Math.min(labels.length - 1, Math.max(0, i)));
            }}
          >
            <g transform={`translate(${pad.left},${pad.top})`}>
              {ticks.map((t) => (
                <g key={t}>
                  <line x1={0} x2={w} y1={y(t)} y2={y(t)} stroke={GRID} strokeWidth={1} />
                  <text x={-8} y={y(t)} dy="0.32em" textAnchor="end" fontSize={11} fill={MUTED}>
                    {compact(t)}
                  </text>
                </g>
              ))}
              {labels.map((l, i) =>
                i % every === 0 || i === labels.length - 1 ? (
                  <text key={l} x={x(i)} y={h + 18} textAnchor="middle" fontSize={11} fill={MUTED}>
                    {formatLabel(l)}
                  </text>
                ) : null,
              )}
              {series.map((s) => (
                <g key={s.key}>
                  <path
                    d={`M${x(0)},${h} ${s.values.map((v, i) => `L${x(i)},${y(v)}`).join(' ')} L${x(s.values.length - 1)},${h} Z`}
                    fill={s.color}
                    opacity={series.length === 1 ? 0.1 : 0}
                  />
                  <polyline
                    points={s.values.map((v, i) => `${x(i)},${y(v)}`).join(' ')}
                    fill="none"
                    stroke={s.color}
                    strokeWidth={2}
                    strokeLinejoin="round"
                    strokeLinecap="round"
                  />
                </g>
              ))}
              {hover !== null ? (
                <g>
                  <line x1={x(hover)} x2={x(hover)} y1={0} y2={h} stroke={MUTED} strokeWidth={1} />
                  {series.map((s) => (
                    <circle
                      key={s.key}
                      cx={x(hover)}
                      cy={y(s.values[hover] ?? 0)}
                      r={4}
                      fill={s.color}
                      stroke="#fff"
                      strokeWidth={2}
                    />
                  ))}
                </g>
              ) : null}
            </g>
          </svg>
        ) : null}
        {hover !== null && width > 0 ? (
          <Tooltip x={pad.left + x(hover)} y={pad.top} width={width}>
            <p className="mb-1 font-medium">{formatLabel(labels[hover]!)}</p>
            {series.map((s) => (
              <p key={s.key} className="flex items-center justify-between gap-3">
                <span className="flex items-center gap-1.5 text-muted-foreground">
                  <span
                    className="inline-block size-2 rounded-full"
                    style={{ background: s.color }}
                  />
                  {s.label}
                </span>
                <span className="tabular-nums">{format(s.values[hover] ?? 0)}</span>
              </p>
            ))}
          </Tooltip>
        ) : null}
      </div>
    </div>
  );
}

export interface BarRow {
  key: string;
  label: string;
  value: number;
  /** Подпись значения справа от полосы */
  display: string;
  /** Доп. строка под названием (конверсия, %) */
  sub?: string;
}

/** Горизонтальные полосы (воронка, источники, причины потерь): подпись — в конце полосы. */
export function BarList({
  rows,
  color = SERIES.blue,
  max: forcedMax,
}: {
  rows: BarRow[];
  color?: string;
  max?: number;
}) {
  const max = forcedMax ?? Math.max(1, ...rows.map((r) => r.value));
  return (
    <ul className="grid gap-3">
      {rows.map((r) => (
        <li key={r.key} className="grid gap-1" title={`${r.label}: ${r.display}`}>
          <div className="flex items-baseline justify-between gap-3 text-sm">
            <span className="min-w-0 truncate">{r.label}</span>
            {r.sub ? <span className="shrink-0 text-xs text-muted-foreground">{r.sub}</span> : null}
          </div>
          <div className="flex items-center gap-2">
            <div className="h-3 min-w-0 flex-1">
              <div
                className="h-3 rounded-r-[4px]"
                style={{
                  width: `${Math.max(r.value > 0 ? 1 : 0, (r.value / max) * 100)}%`,
                  background: color,
                }}
              />
            </div>
            <span className="w-28 shrink-0 text-right text-sm tabular-nums">{r.display}</span>
          </div>
        </li>
      ))}
    </ul>
  );
}

export interface StackSegment {
  key: string;
  label: string;
  color: string;
}

/** Колонки с накоплением (прогноз по месяцам) и отметкой плана. */
export function StackedColumns({
  categories,
  segments,
  values,
  marker,
  markerLabel,
  format,
  height = 260,
}: {
  categories: { key: string; label: string }[];
  segments: StackSegment[];
  /** values[i][j] — категория i, сегмент j */
  values: number[][];
  marker?: (number | null)[];
  markerLabel?: string;
  format: (v: number) => string;
  height?: number;
}) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const [hover, setHover] = useState<number | null>(null);
  const pad = { top: 16, right: 12, bottom: 26, left: 56 };
  const w = Math.max(0, width - pad.left - pad.right);
  const h = height - pad.top - pad.bottom;
  const totals = values.map((v) => v.reduce((a, b) => a + Math.max(0, b), 0));
  const max = Math.max(0, ...totals, ...(marker ?? []).map((m) => m ?? 0));
  const ticks = niceTicks(max);
  const top = ticks[ticks.length - 1]!;
  const band = categories.length ? w / categories.length : 0;
  const bw = Math.min(56, band * 0.5);
  const y = (v: number) => h - (v / top) * h;

  return (
    <div className="grid gap-3">
      <Legend
        items={[
          ...segments.map((s) => ({ label: s.label, color: s.color })),
          ...(marker && markerLabel ? [{ label: markerLabel, color: '', dashed: true }] : []),
        ]}
      />
      <div ref={ref} className="relative w-full" style={{ height }}>
        {width > 0 ? (
          <svg
            width={width}
            height={height}
            role="img"
            aria-label={segments.map((s) => s.label).join(', ')}
          >
            <g transform={`translate(${pad.left},${pad.top})`}>
              {ticks.map((t) => (
                <g key={t}>
                  <line x1={0} x2={w} y1={y(t)} y2={y(t)} stroke={GRID} strokeWidth={1} />
                  <text x={-8} y={y(t)} dy="0.32em" textAnchor="end" fontSize={11} fill={MUTED}>
                    {compact(t)}
                  </text>
                </g>
              ))}
              {categories.map((c, i) => {
                const cx = band * i + band / 2;
                let acc = 0;
                const parts = values[i]!.map((v, j) => {
                  const v0 = acc;
                  acc += Math.max(0, v);
                  return { j, v0, v1: acc };
                }).filter((p) => p.v1 > p.v0);
                const last = parts[parts.length - 1];
                return (
                  <g
                    key={c.key}
                    onPointerEnter={() => setHover(i)}
                    onPointerLeave={() => setHover(null)}
                  >
                    <rect x={cx - band / 2} y={0} width={band} height={h} fill="transparent" />
                    {parts.map((p) => {
                      const y1 = y(p.v1);
                      const y0 = y(p.v0);
                      // 2px зазор цветом фона между сегментами; скругление — только у верхнего края
                      const gap = p.j === parts[0]!.j ? 0 : 2;
                      const hh = Math.max(0, y0 - y1 - gap);
                      const r = p === last ? Math.min(4, hh) : 0;
                      return (
                        <path
                          key={p.j}
                          fill={segments[p.j]!.color}
                          d={`M${cx - bw / 2},${y1 + hh} V${y1 + r} Q${cx - bw / 2},${y1} ${cx - bw / 2 + r},${y1} H${cx + bw / 2 - r} Q${cx + bw / 2},${y1} ${cx + bw / 2},${y1 + r} V${y1 + hh} Z`}
                        />
                      );
                    })}
                    {totals[i]! > 0 ? (
                      <text
                        x={cx}
                        y={y(totals[i]!) - 6}
                        textAnchor="middle"
                        fontSize={11}
                        fill="#18181b"
                      >
                        {compact(totals[i]!)}
                      </text>
                    ) : null}
                    {marker?.[i] ? (
                      <line
                        x1={cx - bw / 2 - 8}
                        x2={cx + bw / 2 + 8}
                        y1={y(marker[i]!)}
                        y2={y(marker[i]!)}
                        stroke="#18181b"
                        strokeOpacity={0.6}
                        strokeWidth={2}
                        strokeDasharray="4 3"
                      />
                    ) : null}
                    <text x={cx} y={h + 18} textAnchor="middle" fontSize={11} fill={MUTED}>
                      {c.label}
                    </text>
                  </g>
                );
              })}
            </g>
          </svg>
        ) : null}
        {hover !== null && width > 0 ? (
          <Tooltip x={pad.left + band * hover + band / 2} y={pad.top} width={width}>
            <p className="mb-1 font-medium">{categories[hover]!.label}</p>
            {segments.map((s, j) => (
              <p key={s.key} className="flex items-center justify-between gap-3">
                <span className="flex items-center gap-1.5 text-muted-foreground">
                  <span
                    className="inline-block size-2 rounded-sm"
                    style={{ background: s.color }}
                  />
                  {s.label}
                </span>
                <span className="tabular-nums">{format(values[hover]![j] ?? 0)}</span>
              </p>
            ))}
            <p className={cn('mt-1 flex justify-between gap-3 border-t pt-1 font-medium')}>
              <span>Итого</span>
              <span className="tabular-nums">{format(totals[hover]!)}</span>
            </p>
            {marker?.[hover] ? (
              <p className="flex justify-between gap-3 text-muted-foreground">
                <span>{markerLabel}</span>
                <span className="tabular-nums">{format(marker[hover]!)}</span>
              </p>
            ) : null}
          </Tooltip>
        ) : null}
      </div>
    </div>
  );
}
