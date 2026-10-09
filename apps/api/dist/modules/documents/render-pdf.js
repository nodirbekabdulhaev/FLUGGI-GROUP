"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.renderPdf = renderPdf;
exports.pdfBuffer = pdfBuffer;
const node_path_1 = __importDefault(require("node:path"));
const pdfkit_1 = __importDefault(require("pdfkit"));
const doc_model_1 = require("./doc-model");
const FONT_DIR = node_path_1.default.join(node_path_1.default.dirname(require.resolve('dejavu-fonts-ttf/package.json')), 'ttf');
const M = 46;
/** PDF документа. Шрифт DejaVu — кириллица и узбекская латиница. */
function renderPdf(model) {
    const doc = new pdfkit_1.default({
        size: 'A4',
        margins: { top: M, bottom: M, left: M, right: M },
        info: { Title: model.title, Author: model.brand.name },
        bufferPages: true,
    });
    doc.registerFont('r', node_path_1.default.join(FONT_DIR, 'DejaVuSans.ttf'));
    doc.registerFont('b', node_path_1.default.join(FONT_DIR, 'DejaVuSans-Bold.ttf'));
    const W = doc.page.width - 2 * M;
    const bottom = () => doc.page.height - M;
    const ensure = (h) => {
        if (doc.y + h > bottom())
            doc.addPage();
    };
    // Шапка: название компании и контакты, синяя линия
    doc
        .font('b')
        .fontSize(17)
        .fillColor(doc_model_1.ACCENT)
        .text(model.brand.name, M, M, { width: W * 0.55 });
    const brandBottom = doc.y;
    doc
        .font('r')
        .fontSize(7.5)
        .fillColor(doc_model_1.MUTED)
        .text(model.brand.contacts, M + W * 0.45, M + 4, { width: W * 0.55, align: 'right' });
    doc.y = Math.max(brandBottom, doc.y) + 4;
    doc
        .moveTo(M, doc.y)
        .lineTo(M + W, doc.y)
        .lineWidth(1.6)
        .strokeColor(doc_model_1.ACCENT)
        .stroke();
    doc.y += 16;
    const text = (s, opts = {}) => doc.text(s, M, doc.y, { width: W, ...opts });
    const party = (p, x, w, y0, dry) => {
        let y = y0;
        const line = (s, font, size, color = doc_model_1.INK) => {
            doc.font(font).fontSize(size);
            const h = doc.heightOfString(s, { width: w });
            if (!dry)
                doc.fillColor(color).text(s, x, y, { width: w });
            y += h + 1.5;
        };
        line(p.role.toUpperCase(), 'b', 9, doc_model_1.ACCENT);
        line(p.name, 'b', 9);
        for (const [k, v] of p.lines)
            line(`${k}: ${v}`, 'r', 8.5);
        y += 12;
        line(p.position, 'r', 8.5);
        y += 10;
        if (!dry)
            doc
                .moveTo(x, y)
                .lineTo(x + w * 0.45, y)
                .lineWidth(0.6)
                .strokeColor(doc_model_1.INK)
                .stroke()
                .font('r')
                .fontSize(8.5)
                .fillColor(doc_model_1.INK)
                .text(`/ ${p.signer} /`, x + w * 0.47, y - 9, { width: w * 0.53 });
        y += 4;
        line('подпись · М.П.', 'r', 7, doc_model_1.MUTED);
        return y - y0;
    };
    const render = (b) => {
        switch (b.t) {
            case 'title':
                ensure(50);
                doc.font('b').fontSize(15).fillColor(doc_model_1.INK);
                text(b.text, { align: b.center ? 'center' : 'left' });
                if (b.sub) {
                    doc.font('r').fontSize(10).fillColor(doc_model_1.MUTED);
                    text(b.sub, { align: b.center ? 'center' : 'left' });
                }
                doc.y += 8;
                break;
            case 'meta': {
                ensure(20);
                const y = doc.y;
                doc
                    .font('r')
                    .fontSize(10)
                    .fillColor(doc_model_1.INK)
                    .text(b.left, M, y, { width: W / 2 });
                doc.text(b.right, M + W / 2, y, { width: W / 2, align: 'right' });
                doc.y = y + 22;
                break;
            }
            case 'h':
                ensure(40);
                doc.y += 6;
                doc.font('b').fontSize(10.5).fillColor(doc_model_1.INK);
                text(b.text);
                doc.y += 3;
                break;
            case 'p':
                doc.font('r').fontSize(9.5).fillColor(doc_model_1.INK);
                text(b.text, { align: 'justify', lineGap: 1.5 });
                doc.y += 4;
                break;
            case 'kv': {
                doc.font('r').fontSize(9.5);
                const kw = Math.max(...b.rows.map(([k]) => doc.widthOfString(k))) + 14;
                for (const [k, v] of b.rows) {
                    const h = doc.heightOfString(v, { width: W - kw });
                    ensure(h + 3);
                    const y = doc.y;
                    doc.fillColor(doc_model_1.MUTED).text(k, M, y, { width: kw });
                    doc.fillColor(doc_model_1.INK).text(v, M + kw, y, { width: W - kw });
                    doc.y = y + h + 3;
                }
                doc.y += 6;
                break;
            }
            case 'table': {
                const cols = b.widths.map((w) => w * W);
                const pad = 5;
                const row = (cells, opts) => {
                    doc.font(opts.head ? 'b' : 'r').fontSize(8.5);
                    const h = Math.max(...cells.map((c, i) => doc.heightOfString(c, { width: cols[i] - 2 * pad }))) +
                        2 * pad;
                    ensure(h);
                    const y = doc.y;
                    if (opts.head)
                        doc.rect(M, y, W, h).fill(doc_model_1.ACCENT);
                    else if (opts.zebra)
                        doc.rect(M, y, W, h).fill(doc_model_1.ZEBRA);
                    let x = M;
                    cells.forEach((c, i) => {
                        doc
                            .fillColor(opts.head ? '#ffffff' : doc_model_1.INK)
                            .text(c, x + pad, y + pad, { width: cols[i] - 2 * pad, align: b.align[i] });
                        x += cols[i];
                    });
                    if (!opts.head)
                        doc
                            .moveTo(M, y + h)
                            .lineTo(M + W, y + h)
                            .lineWidth(0.5)
                            .strokeColor(doc_model_1.LINE)
                            .stroke();
                    doc.y = y + h;
                };
                doc.y += 2;
                row(b.head, { head: true });
                b.rows.forEach((r, i) => row(r, { zebra: i % 2 === 1 }));
                doc.y += 4;
                for (const t of b.totals) {
                    ensure(18);
                    const y = doc.y;
                    if (t.strong) {
                        doc
                            .moveTo(M + W * 0.5, y)
                            .lineTo(M + W, y)
                            .lineWidth(1.4)
                            .strokeColor(doc_model_1.ACCENT)
                            .stroke();
                        doc.y = y + 4;
                    }
                    doc
                        .font(t.strong ? 'b' : 'r')
                        .fontSize(t.strong ? 11 : 9.5)
                        .fillColor(doc_model_1.INK);
                    const yy = doc.y;
                    doc.text(t.label, M, yy, { width: W * 0.72, align: 'right' });
                    doc.text(t.value, M + W * 0.72, yy, { width: W * 0.28, align: 'right' });
                    doc.y = Math.max(doc.y, yy + 12) + 2;
                }
                doc.y += 8;
                break;
            }
            case 'parties': {
                const w = (W - 24) / 2;
                const h = Math.max(party(b.parties[0], M, w, 0, true), party(b.parties[1], M, w, 0, true));
                ensure(h);
                const y = doc.y;
                party(b.parties[0], M, w, y, false);
                party(b.parties[1], M + w + 24, w, y, false);
                doc.y = y + h + 6;
                break;
            }
            case 'sign': {
                ensure(60);
                doc.y += 24;
                const y = doc.y;
                doc
                    .font('r')
                    .fontSize(9.5)
                    .fillColor(doc_model_1.INK)
                    .text(b.position, M, y, { width: W / 2 });
                doc.font('b').text(b.company, M, doc.y, { width: W / 2 });
                const ly = y + 18;
                doc
                    .moveTo(M + W * 0.55, ly)
                    .lineTo(M + W * 0.75, ly)
                    .lineWidth(0.6)
                    .strokeColor(doc_model_1.INK)
                    .stroke();
                doc.font('r').text(`/ ${b.signer} /`, M + W * 0.77, ly - 10, { width: W * 0.23 });
                doc.y = Math.max(doc.y, ly) + 8;
                break;
            }
        }
    };
    for (const b of model.blocks)
        render(b);
    // Нумерация страниц
    const range = doc.bufferedPageRange();
    for (let i = 0; i < range.count; i++) {
        doc.switchToPage(range.start + i);
        // Подпись ниже нижнего поля: без этого pdfkit добавил бы пустую страницу
        doc.page.margins.bottom = 0;
        doc
            .font('r')
            .fontSize(7.5)
            .fillColor(doc_model_1.MUTED)
            .text(`${model.title} · стр. ${i + 1} из ${range.count}`, M, doc.page.height - M + 14, {
            width: W,
            align: 'center',
            lineBreak: false,
        });
    }
    return doc;
}
/** PDF целиком в Buffer. */
function pdfBuffer(model) {
    const doc = renderPdf(model);
    const chunks = [];
    return new Promise((resolve, reject) => {
        doc.on('data', (c) => chunks.push(c));
        doc.on('end', () => resolve(Buffer.concat(chunks)));
        doc.on('error', reject);
        doc.end();
    });
}
//# sourceMappingURL=render-pdf.js.map