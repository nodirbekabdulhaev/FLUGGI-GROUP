import {
  ACCENT,
  INK,
  LINE,
  MUTED,
  ZEBRA,
  type Block,
  type DocModel,
  type Party,
} from './doc-model';

const esc = (s: string) =>
  s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const CSS = `
@page { size: A4; margin: 18mm 16mm; }
* { box-sizing: border-box; }
html { background: #eef0f3; }
body { margin: 0; font: 10.5pt/1.5 "DejaVu Sans", "Segoe UI", Arial, sans-serif; color: ${INK}; }
.page { background: #fff; max-width: 210mm; margin: 16px auto; padding: 18mm 16mm; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
@media print { html { background: #fff; } .page { margin: 0; padding: 0; box-shadow: none; max-width: none; } }
.brand { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; border-bottom: 2px solid ${ACCENT}; padding-bottom: 8px; margin-bottom: 22px; }
.brand b { color: ${ACCENT}; font-size: 17pt; letter-spacing: .02em; }
.brand span { color: ${MUTED}; font-size: 8.5pt; text-align: right; }
h1 { font-size: 16pt; margin: 0 0 2px; }
h1.center, .sub.center { text-align: center; }
.sub { color: ${MUTED}; margin: 0 0 14px; }
.meta { display: flex; justify-content: space-between; margin: 6px 0 16px; }
h2 { font-size: 11pt; margin: 16px 0 6px; }
p { margin: 0 0 6px; text-align: justify; }
table.items { width: 100%; border-collapse: collapse; margin: 8px 0 12px; font-size: 9.5pt; }
table.items th { background: ${ACCENT}; color: #fff; font-weight: 600; padding: 6px 8px; }
table.items td { padding: 6px 8px; border-bottom: 1px solid ${LINE}; vertical-align: top; }
table.items tbody tr:nth-child(even) td { background: ${ZEBRA}; }
table.items .r { text-align: right; white-space: nowrap; }
table.items tfoot td { border: 0; padding: 3px 8px; }
table.items tfoot .strong td { font-weight: 700; font-size: 11pt; border-top: 2px solid ${ACCENT}; padding-top: 6px; }
table.kv { border-collapse: collapse; margin: 4px 0 12px; }
table.kv td { padding: 2px 16px 2px 0; vertical-align: top; }
table.kv td:first-child { color: ${MUTED}; white-space: nowrap; }
.parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 8px; font-size: 9.5pt; }
.party h3 { margin: 0 0 4px; font-size: 10pt; color: ${ACCENT}; text-transform: uppercase; letter-spacing: .04em; }
.party .name { font-weight: 700; margin-bottom: 4px; }
.party div.l { margin: 1px 0; }
.party div.l span { color: ${MUTED}; }
.signline { margin-top: 22px; }
.signline .line { display: inline-block; width: 45%; border-bottom: 1px solid ${INK}; margin-right: 6px; }
.signline small { display: block; color: ${MUTED}; margin-top: 2px; }
.sign { margin-top: 28px; display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; }
.sign .line { display: inline-block; width: 140px; border-bottom: 1px solid ${INK}; }
`;

function party(p: Party): string {
  return `<div class="party"><h3>${esc(p.role)}</h3><div class="name">${esc(p.name)}</div>${p.lines
    .map(([k, v]) => `<div class="l"><span>${esc(k)}:</span> ${esc(v)}</div>`)
    .join('')}<div class="signline">${esc(p.position)}<br><span class="line"></span>/ ${esc(
    p.signer,
  )} /<small>подпись · М.П.</small></div></div>`;
}

function block(b: Block): string {
  switch (b.t) {
    case 'title':
      return `<h1${b.center ? ' class="center"' : ''}>${esc(b.text)}</h1>${
        b.sub ? `<p class="sub${b.center ? ' center' : ''}">${esc(b.sub)}</p>` : ''
      }`;
    case 'meta':
      return `<div class="meta"><span>${esc(b.left)}</span><span>${esc(b.right)}</span></div>`;
    case 'h':
      return `<h2>${esc(b.text)}</h2>`;
    case 'p':
      return `<p>${esc(b.text)}</p>`;
    case 'kv':
      return `<table class="kv">${b.rows
        .map(([k, v]) => `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`)
        .join('')}</table>`;
    case 'table': {
      const cls = (i: number) => (b.align[i] === 'right' ? ' class="r"' : '');
      const span = b.head.length - 1;
      return `<table class="items"><colgroup>${b.widths
        .map((w) => `<col style="width:${(w * 100).toFixed(1)}%">`)
        .join('')}</colgroup><thead><tr>${b.head
        .map((h, i) => `<th${cls(i)}>${esc(h)}</th>`)
        .join('')}</tr></thead><tbody>${b.rows
        .map(
          (r) =>
            `<tr>${r.map((c, i) => `<td${cls(i)}>${esc(c).replace(/\n/g, '<br>')}</td>`).join('')}</tr>`,
        )
        .join('')}</tbody><tfoot>${b.totals
        .map(
          (t) =>
            `<tr${t.strong ? ' class="strong"' : ''}><td colspan="${span}" class="r">${esc(
              t.label,
            )}</td><td class="r">${esc(t.value)}</td></tr>`,
        )
        .join('')}</tfoot></table>`;
    }
    case 'parties':
      return `<div class="parties">${party(b.parties[0])}${party(b.parties[1])}</div>`;
    case 'sign':
      return `<div class="sign"><div>${esc(b.position)}<br><b>${esc(b.company)}</b></div><div><span class="line"></span> / ${esc(
        b.signer,
      )} /</div></div>`;
  }
}

/** HTML документа: предпросмотр в CRM и печать в PDF из браузера. */
export function renderHtml(doc: DocModel): string {
  return `<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${esc(
    doc.title,
  )}</title><style>${CSS}</style></head><body><div class="page"><div class="brand"><b>${esc(
    doc.brand.name,
  )}</b><span>${esc(doc.brand.contacts)}</span></div>${doc.blocks.map(block).join('\n')}</div></body></html>`;
}
