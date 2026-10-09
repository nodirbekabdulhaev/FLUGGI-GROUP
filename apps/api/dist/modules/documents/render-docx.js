"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.docxBuffer = docxBuffer;
const docx_1 = require("docx");
const doc_model_1 = require("./doc-model");
const hex = (c) => c.replace('#', '');
const FONT = 'Arial';
const none = { style: docx_1.BorderStyle.NONE, size: 0, color: 'FFFFFF' };
const noBorders = { top: none, bottom: none, left: none, right: none };
// A4 минус поля 2 см с каждой стороны, в twip
const CONTENT = 11906 - 2 * 1134;
const run = (text, o = {}) => new docx_1.TextRun({
    text,
    bold: o.bold,
    size: o.size ?? 20,
    color: o.color ? hex(o.color) : undefined,
    font: FONT,
});
const para = (children, o = {}) => new docx_1.Paragraph({
    children,
    alignment: o.align,
    spacing: { after: o.after ?? 80, before: o.before ?? 0 },
});
function party(p) {
    return [
        para([run(p.role.toUpperCase(), { bold: true, size: 18, color: doc_model_1.ACCENT })], { after: 40 }),
        para([run(p.name, { bold: true, size: 18 })], { after: 40 }),
        ...p.lines.map(([k, v]) => para([run(`${k}: `, { size: 17, color: doc_model_1.MUTED }), run(v, { size: 17 })], { after: 20 })),
        para([run(p.position, { size: 17 })], { before: 240, after: 200 }),
        para([run(`____________________ / ${p.signer} /`, { size: 17 })], { after: 20 }),
        para([run('подпись · М.П.', { size: 14, color: doc_model_1.MUTED })]),
    ];
}
function blocks(b) {
    switch (b.t) {
        case 'title':
            return [
                para([run(b.text, { bold: true, size: 30 })], {
                    align: b.center ? docx_1.AlignmentType.CENTER : docx_1.AlignmentType.LEFT,
                    after: b.sub ? 20 : 160,
                }),
                ...(b.sub
                    ? [
                        para([run(b.sub, { color: doc_model_1.MUTED })], {
                            align: b.center ? docx_1.AlignmentType.CENTER : docx_1.AlignmentType.LEFT,
                            after: 160,
                        }),
                    ]
                    : []),
            ];
        case 'meta':
            return [
                new docx_1.Table({
                    width: { size: CONTENT, type: docx_1.WidthType.DXA },
                    columnWidths: [CONTENT / 2, CONTENT / 2],
                    borders: { ...noBorders, insideHorizontal: none, insideVertical: none },
                    rows: [
                        new docx_1.TableRow({
                            children: [
                                new docx_1.TableCell({ borders: noBorders, children: [para([run(b.left)])] }),
                                new docx_1.TableCell({
                                    borders: noBorders,
                                    children: [para([run(b.right)], { align: docx_1.AlignmentType.RIGHT })],
                                }),
                            ],
                        }),
                    ],
                }),
                para([], { after: 120 }),
            ];
        case 'h':
            return [para([run(b.text, { bold: true, size: 21 })], { before: 160, after: 60 })];
        case 'p':
            return [para([run(b.text, { size: 19 })], { align: docx_1.AlignmentType.JUSTIFIED })];
        case 'kv':
            return b.rows.map(([k, v]) => para([run(`${k}: `, { color: doc_model_1.MUTED, size: 19 }), run(v, { size: 19 })], { after: 30 }));
        case 'table': {
            const cols = b.widths.map((w) => Math.round(w * CONTENT));
            const border = { style: docx_1.BorderStyle.SINGLE, size: 4, color: hex(doc_model_1.LINE) };
            const cell = (text, i, o) => new docx_1.TableCell({
                width: { size: cols[i], type: docx_1.WidthType.DXA },
                shading: o.fill
                    ? { type: docx_1.ShadingType.CLEAR, color: 'auto', fill: hex(o.fill) }
                    : undefined,
                borders: { top: none, left: none, right: none, bottom: o.head ? none : border },
                margins: { top: 60, bottom: 60, left: 90, right: 90 },
                children: text.split('\n').map((line, j) => para([
                    run(line, {
                        bold: o.head,
                        size: j ? 15 : 17,
                        color: o.head ? '#ffffff' : j ? doc_model_1.MUTED : undefined,
                    }),
                ], {
                    align: b.align[i] === 'right' ? docx_1.AlignmentType.RIGHT : docx_1.AlignmentType.LEFT,
                    after: 0,
                })),
            });
            const table = new docx_1.Table({
                width: { size: CONTENT, type: docx_1.WidthType.DXA },
                columnWidths: cols,
                rows: [
                    new docx_1.TableRow({
                        tableHeader: true,
                        children: b.head.map((h, i) => cell(h, i, { head: true, fill: doc_model_1.ACCENT })),
                    }),
                    ...b.rows.map((r, ri) => new docx_1.TableRow({
                        children: r.map((c, i) => cell(c, i, { fill: ri % 2 ? doc_model_1.ZEBRA : undefined })),
                    })),
                ],
            });
            return [
                table,
                ...b.totals.map((t) => para([
                    run(`${t.label}: `, { bold: t.strong, size: t.strong ? 22 : 19 }),
                    run(t.value, { bold: t.strong, size: t.strong ? 22 : 19 }),
                ], {
                    align: docx_1.AlignmentType.RIGHT,
                    before: t.strong ? 60 : 40,
                    after: 20,
                })),
                para([], { after: 120 }),
            ];
        }
        case 'parties':
            return [
                new docx_1.Table({
                    width: { size: CONTENT, type: docx_1.WidthType.DXA },
                    columnWidths: [CONTENT / 2, CONTENT / 2],
                    borders: { ...noBorders, insideHorizontal: none, insideVertical: none },
                    rows: [
                        new docx_1.TableRow({
                            cantSplit: true,
                            children: b.parties.map((p) => new docx_1.TableCell({
                                borders: noBorders,
                                margins: { right: 200 },
                                width: { size: CONTENT / 2, type: docx_1.WidthType.DXA },
                                children: party(p),
                            })),
                        }),
                    ],
                }),
            ];
        case 'sign':
            return [
                para([run(b.position, { size: 19 })], { before: 480, after: 20 }),
                para([
                    run(b.company, { bold: true, size: 19 }),
                    run(`        ____________________ / ${b.signer} /`, { size: 19 }),
                ]),
            ];
    }
}
/** Word (.docx) с тем же дизайном: синяя шапка, таблица позиций, реквизиты сторон. */
function docxBuffer(model) {
    const doc = new docx_1.Document({
        creator: model.brand.name,
        title: model.title,
        styles: { default: { document: { run: { font: FONT, size: 20 } } } },
        sections: [
            {
                properties: { page: { margin: { top: 1134, bottom: 1134, left: 1134, right: 1134 } } },
                headers: {
                    default: new docx_1.Header({
                        children: [
                            new docx_1.Paragraph({
                                border: {
                                    bottom: { style: docx_1.BorderStyle.SINGLE, size: 12, color: hex(doc_model_1.ACCENT), space: 4 },
                                },
                                tabStops: [{ type: 'right', position: CONTENT }],
                                children: [
                                    run(model.brand.name, { bold: true, size: 30, color: doc_model_1.ACCENT }),
                                    new docx_1.TextRun({
                                        text: `\t${model.brand.contacts}`,
                                        size: 15,
                                        color: hex(doc_model_1.MUTED),
                                        font: FONT,
                                    }),
                                ],
                            }),
                        ],
                    }),
                },
                footers: {
                    default: new docx_1.Footer({
                        children: [
                            new docx_1.Paragraph({
                                alignment: docx_1.AlignmentType.CENTER,
                                children: [
                                    new docx_1.TextRun({
                                        text: `${model.title} · стр. `,
                                        size: 14,
                                        color: hex(doc_model_1.MUTED),
                                        font: FONT,
                                    }),
                                    new docx_1.TextRun({
                                        children: [docx_1.PageNumber.CURRENT],
                                        size: 14,
                                        color: hex(doc_model_1.MUTED),
                                        font: FONT,
                                    }),
                                ],
                            }),
                        ],
                    }),
                },
                children: model.blocks.flatMap(blocks),
            },
        ],
    });
    return docx_1.Packer.toBuffer(doc);
}
//# sourceMappingURL=render-docx.js.map