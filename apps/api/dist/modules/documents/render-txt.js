"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.renderTxt = renderTxt;
function block(b) {
    switch (b.t) {
        case 'title':
            return [b.text.toUpperCase(), b.sub].filter(Boolean).join('\n');
        case 'meta':
            return `${b.left}    ${b.right}`;
        case 'h':
            return `\n${b.text}`;
        case 'p':
            return b.text;
        case 'kv':
            return b.rows.map(([k, v]) => `${k}: ${v}`).join('\n');
        case 'table':
            return [
                b.head.join(' | '),
                ...b.rows.map((r, i) => `${i + 1}. ${r.map((c) => c.replace(/\n/g, ' — ')).join(' | ')}`),
                ...b.totals.map((t) => `${t.label}: ${t.value}`),
            ].join('\n');
        case 'parties':
            return b.parties
                .map((p) => [
                `${p.role.toUpperCase()}: ${p.name}`,
                ...p.lines.map(([k, v]) => `${k}: ${v}`),
                `${p.position} ____________ / ${p.signer} /`,
            ].join('\n'))
                .join('\n\n');
        case 'sign':
            return `\n${b.position} ${b.company} ____________ / ${b.signer} /`;
    }
}
/** Текст документа — для копирования в мессенджер или почту. */
function renderTxt(doc) {
    return `${doc.brand.name}\n${doc.brand.contacts}\n\n${doc.blocks.map(block).join('\n')}\n`;
}
//# sourceMappingURL=render-txt.js.map