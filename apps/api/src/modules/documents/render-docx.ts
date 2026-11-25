import {
  AlignmentType,
  BorderStyle,
  Document,
  Footer,
  Header,
  Packer,
  PageNumber,
  Paragraph,
  ShadingType,
  Table,
  TableCell,
  TableRow,
  TextRun,
  WidthType,
  type IBorderOptions,
} from 'docx';
import { ACCENT, LINE, MUTED, ZEBRA, type Block, type DocModel, type Party } from './doc-model';

const hex = (c: string) => c.replace('#', '');
const FONT = 'Arial';
const none: IBorderOptions = { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' };
const noBorders = { top: none, bottom: none, left: none, right: none };
// A4 минус поля 2 см с каждой стороны, в twip
const CONTENT = 11906 - 2 * 1134;

const run = (text: string, o: { bold?: boolean; size?: number; color?: string } = {}) =>
  new TextRun({
    text,
    bold: o.bold,
    size: o.size ?? 20,
    color: o.color ? hex(o.color) : undefined,
    font: FONT,
  });

const para = (
  children: TextRun[],
  o: {
    align?: (typeof AlignmentType)[keyof typeof AlignmentType];
    after?: number;
    before?: number;
  } = {},
) =>
  new Paragraph({
    children,
    alignment: o.align,
    spacing: { after: o.after ?? 80, before: o.before ?? 0 },
  });

function party(p: Party): Paragraph[] {
  return [
    para([run(p.role.toUpperCase(), { bold: true, size: 18, color: ACCENT })], { after: 40 }),
    para([run(p.name, { bold: true, size: 18 })], { after: 40 }),
    ...p.lines.map(([k, v]) =>
      para([run(`${k}: `, { size: 17, color: MUTED }), run(v, { size: 17 })], { after: 20 }),
    ),
    para([run(p.position, { size: 17 })], { before: 240, after: 200 }),
    para([run(`____________________ / ${p.signer} /`, { size: 17 })], { after: 20 }),
    para([run('подпись · М.П.', { size: 14, color: MUTED })]),
  ];
}

function blocks(b: Block): (Paragraph | Table)[] {
  switch (b.t) {
    case 'title':
      return [
        para([run(b.text, { bold: true, size: 30 })], {
          align: b.center ? AlignmentType.CENTER : AlignmentType.LEFT,
          after: b.sub ? 20 : 160,
        }),
        ...(b.sub
          ? [
              para([run(b.sub, { color: MUTED })], {
                align: b.center ? AlignmentType.CENTER : AlignmentType.LEFT,
                after: 160,
              }),
            ]
          : []),
      ];
    case 'meta':
      return [
        new Table({
          width: { size: CONTENT, type: WidthType.DXA },
          columnWidths: [CONTENT / 2, CONTENT / 2],
          borders: { ...noBorders, insideHorizontal: none, insideVertical: none },
          rows: [
            new TableRow({
              children: [
                new TableCell({ borders: noBorders, children: [para([run(b.left)])] }),
                new TableCell({
                  borders: noBorders,
                  children: [para([run(b.right)], { align: AlignmentType.RIGHT })],
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
      return [para([run(b.text, { size: 19 })], { align: AlignmentType.JUSTIFIED })];
    case 'kv':
      return b.rows.map(([k, v]) =>
        para([run(`${k}: `, { color: MUTED, size: 19 }), run(v, { size: 19 })], { after: 30 }),
      );
    case 'table': {
      const cols = b.widths.map((w) => Math.round(w * CONTENT));
      const border: IBorderOptions = { style: BorderStyle.SINGLE, size: 4, color: hex(LINE) };
      const cell = (text: string, i: number, o: { head?: boolean; fill?: string }) =>
        new TableCell({
          width: { size: cols[i]!, type: WidthType.DXA },
          shading: o.fill
            ? { type: ShadingType.CLEAR, color: 'auto', fill: hex(o.fill) }
            : undefined,
          borders: { top: none, left: none, right: none, bottom: o.head ? none : border },
          margins: { top: 60, bottom: 60, left: 90, right: 90 },
          children: text.split('\n').map((line, j) =>
            para(
              [
                run(line, {
                  bold: o.head,
                  size: j ? 15 : 17,
                  color: o.head ? '#ffffff' : j ? MUTED : undefined,
                }),
              ],
              {
                align: b.align[i] === 'right' ? AlignmentType.RIGHT : AlignmentType.LEFT,
                after: 0,
              },
            ),
          ),
        });
      const table = new Table({
        width: { size: CONTENT, type: WidthType.DXA },
        columnWidths: cols,
        rows: [
          new TableRow({
            tableHeader: true,
            children: b.head.map((h, i) => cell(h, i, { head: true, fill: ACCENT })),
          }),
          ...b.rows.map(
            (r, ri) =>
              new TableRow({
                children: r.map((c, i) => cell(c, i, { fill: ri % 2 ? ZEBRA : undefined })),
              }),
          ),
        ],
      });
      return [
        table,
        ...b.totals.map((t) =>
          para(
            [
              run(`${t.label}: `, { bold: t.strong, size: t.strong ? 22 : 19 }),
              run(t.value, { bold: t.strong, size: t.strong ? 22 : 19 }),
            ],
            {
              align: AlignmentType.RIGHT,
              before: t.strong ? 60 : 40,
              after: 20,
            },
          ),
        ),
        para([], { after: 120 }),
      ];
    }
    case 'parties':
      return [
        new Table({
          width: { size: CONTENT, type: WidthType.DXA },
          columnWidths: [CONTENT / 2, CONTENT / 2],
          borders: { ...noBorders, insideHorizontal: none, insideVertical: none },
          rows: [
            new TableRow({
              cantSplit: true,
              children: b.parties.map(
                (p) =>
                  new TableCell({
                    borders: noBorders,
                    margins: { right: 200 },
                    width: { size: CONTENT / 2, type: WidthType.DXA },
                    children: party(p),
                  }),
              ),
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
export function docxBuffer(model: DocModel): Promise<Buffer> {
  const doc = new Document({
    creator: model.brand.name,
    title: model.title,
    styles: { default: { document: { run: { font: FONT, size: 20 } } } },
    sections: [
      {
        properties: { page: { margin: { top: 1134, bottom: 1134, left: 1134, right: 1134 } } },
        headers: {
          default: new Header({
            children: [
              new Paragraph({
                border: {
                  bottom: { style: BorderStyle.SINGLE, size: 12, color: hex(ACCENT), space: 4 },
                },
                tabStops: [{ type: 'right', position: CONTENT }],
                children: [
                  run(model.brand.name, { bold: true, size: 30, color: ACCENT }),
                  new TextRun({
                    text: `\t${model.brand.contacts}`,
                    size: 15,
                    color: hex(MUTED),
                    font: FONT,
                  }),
                ],
              }),
            ],
          }),
        },
        footers: {
          default: new Footer({
            children: [
              new Paragraph({
                alignment: AlignmentType.CENTER,
                children: [
                  new TextRun({
                    text: `${model.title} · стр. `,
                    size: 14,
                    color: hex(MUTED),
                    font: FONT,
                  }),
                  new TextRun({
                    children: [PageNumber.CURRENT],
                    size: 14,
                    color: hex(MUTED),
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
  return Packer.toBuffer(doc);
}
