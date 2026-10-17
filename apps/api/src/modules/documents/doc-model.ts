/**
 * Модель документа (КП, договор): набор блоков, из которого собираются HTML (просмотр и печать),
 * PDF, Word и TXT. Дизайн у всех форматов один и закреплён в рендерерах.
 */

export interface Party {
  /** «Исполнитель» / «Заказчик» */
  role: string;
  name: string;
  lines: [string, string][];
  position: string;
  signer: string;
}

export interface TableTotal {
  label: string;
  value: string;
  strong?: boolean;
}

export type Block =
  | { t: 'title'; text: string; sub?: string; center?: boolean }
  | { t: 'meta'; left: string; right: string }
  | { t: 'h'; text: string }
  | { t: 'p'; text: string }
  | {
      t: 'table';
      head: string[];
      rows: string[][];
      align: ('left' | 'right')[];
      /** Доли ширины, сумма = 1 */
      widths: number[];
      totals: TableTotal[];
    }
  | { t: 'kv'; rows: [string, string][] }
  | { t: 'parties'; parties: [Party, Party] }
  | { t: 'sign'; company: string; position: string; signer: string };

export interface DocModel {
  /** Имя файла без расширения */
  fileName: string;
  title: string;
  brand: { name: string; contacts: string };
  blocks: Block[];
}

export const ACCENT = '#2a78d6';
export const INK = '#18181b';
export const MUTED = '#71717a';
export const LINE = '#e4e4e7';
export const ZEBRA = '#f4f7fb';

const MONTHS = [
  'января',
  'февраля',
  'марта',
  'апреля',
  'мая',
  'июня',
  'июля',
  'августа',
  'сентября',
  'октября',
  'ноября',
  'декабря',
];

/** «2026-10-07» → «07» октября 2026 г. */
export function longDate(iso: string): string {
  const [y, m, d] = iso.slice(0, 10).split('-');
  return `«${d}» ${MONTHS[Number(m) - 1]} ${y} г.`;
}

/** «2026-10-07» → 07.10.2026 */
export function shortDate(iso: string): string {
  const [y, m, d] = iso.slice(0, 10).split('-');
  return `${d}.${m}.${y}`;
}

/** 7500000 → «7 500 000», дробная часть только если есть. */
export function amount(v: string | number): string {
  const n = Number(v);
  const [i, f] = Math.abs(n).toFixed(2).split('.') as [string, string];
  const grouped = i.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  return `${n < 0 ? '−' : ''}${grouped}${f === '00' ? '' : `,${f}`}`;
}

export const moneyText = (v: string | number, currency: string) =>
  `${amount(v)} ${currency === 'UZS' ? 'сум' : currency}`;

/** Подстановка {{key}}; неизвестный или пустой ключ — линия для заполнения от руки. */
export function fill(text: string, values: Record<string, string>): string {
  return text.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, k: string) => values[k]?.trim() || '________');
}

/** Шаблон договора → блоки. «# » заголовок, «## » раздел, [[services]] и [[parties]] — вставки. */
export function templateBlocks(
  template: string,
  values: Record<string, string>,
  inserts: { services: Block | null; parties: Block },
  meta: Block,
): Block[] {
  const out: Block[] = [];
  let titleDone = false;
  const lines = template.replace(/\r\n/g, '\n').split('\n');
  for (let idx = 0; idx < lines.length; idx++) {
    const raw = lines[idx]!.trim();
    if (!raw) continue;
    if (raw === '[[services]]') {
      if (inserts.services) out.push(inserts.services);
      continue;
    }
    if (raw === '[[parties]]') {
      out.push(inserts.parties);
      continue;
    }
    const text = fill(raw.replace(/^#{1,2}\s+/, ''), values);
    if (raw.startsWith('# ') && !titleDone) {
      // Заголовок и подзаголовок («## » сразу после «# »), затем город и дата
      const next = lines[idx + 1]?.trim() ?? '';
      const sub = next.startsWith('## ') ? fill(next.slice(3), values) : undefined;
      if (sub) idx++;
      out.push({ t: 'title', text, sub, center: true }, meta);
      titleDone = true;
    } else if (raw.startsWith('#')) out.push({ t: 'h', text });
    else out.push({ t: 'p', text });
  }
  if (!titleDone) out.unshift(meta);
  if (!out.some((b) => b.t === 'parties')) out.push(inserts.parties);
  return out;
}
