"use strict";
/**
 * Модель документа (КП, договор): набор блоков, из которого собираются HTML (просмотр и печать),
 * PDF, Word и TXT. Дизайн у всех форматов один и закреплён в рендерерах.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.moneyText = exports.ZEBRA = exports.LINE = exports.MUTED = exports.INK = exports.ACCENT = void 0;
exports.longDate = longDate;
exports.shortDate = shortDate;
exports.amount = amount;
exports.fill = fill;
exports.templateBlocks = templateBlocks;
exports.ACCENT = '#2a78d6';
exports.INK = '#18181b';
exports.MUTED = '#71717a';
exports.LINE = '#e4e4e7';
exports.ZEBRA = '#f4f7fb';
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
function longDate(iso) {
    const [y, m, d] = iso.slice(0, 10).split('-');
    return `«${d}» ${MONTHS[Number(m) - 1]} ${y} г.`;
}
/** «2026-10-07» → 07.10.2026 */
function shortDate(iso) {
    const [y, m, d] = iso.slice(0, 10).split('-');
    return `${d}.${m}.${y}`;
}
/** 7500000 → «7 500 000», дробная часть только если есть. */
function amount(v) {
    const n = Number(v);
    const [i, f] = Math.abs(n).toFixed(2).split('.');
    const grouped = i.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return `${n < 0 ? '−' : ''}${grouped}${f === '00' ? '' : `,${f}`}`;
}
const moneyText = (v, currency) => `${amount(v)} ${currency === 'UZS' ? 'сум' : currency}`;
exports.moneyText = moneyText;
/** Подстановка {{key}}; неизвестный или пустой ключ — линия для заполнения от руки. */
function fill(text, values) {
    return text.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, k) => values[k]?.trim() || '________');
}
/** Шаблон договора → блоки. «# » заголовок, «## » раздел, [[services]] и [[parties]] — вставки. */
function templateBlocks(template, values, inserts, meta) {
    const out = [];
    let titleDone = false;
    const lines = template.replace(/\r\n/g, '\n').split('\n');
    for (let idx = 0; idx < lines.length; idx++) {
        const raw = lines[idx].trim();
        if (!raw)
            continue;
        if (raw === '[[services]]') {
            if (inserts.services)
                out.push(inserts.services);
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
            if (sub)
                idx++;
            out.push({ t: 'title', text, sub, center: true }, meta);
            titleDone = true;
        }
        else if (raw.startsWith('#'))
            out.push({ t: 'h', text });
        else
            out.push({ t: 'p', text });
    }
    if (!titleDone)
        out.unshift(meta);
    if (!out.some((b) => b.t === 'parties'))
        out.push(inserts.parties);
    return out;
}
//# sourceMappingURL=doc-model.js.map