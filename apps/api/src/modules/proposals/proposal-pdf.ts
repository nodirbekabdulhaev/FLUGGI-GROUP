import path from 'node:path';
import PDFDocument from 'pdfkit';
import type { ProposalDto } from '@fluggi/contracts';

const FONT_DIR = path.join(path.dirname(require.resolve('dejavu-fonts-ttf/package.json')), 'ttf');

const money = (v: string, cur: string) =>
  `${Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 2 }).replace(/ /g, ' ')} ${cur}`;

/** PDF коммерческого предложения (ТЗ §15). Шрифт DejaVu — для кириллицы и узбекской латиницы. */
export function renderProposalPdf(p: ProposalDto, company = 'Fluggi'): PDFKit.PDFDocument {
  const doc = new PDFDocument({
    size: 'A4',
    margin: 48,
    info: { Title: `${p.number} ${p.title}`, Author: company },
  });
  doc.registerFont('regular', path.join(FONT_DIR, 'DejaVuSans.ttf'));
  doc.registerFont('bold', path.join(FONT_DIR, 'DejaVuSans-Bold.ttf'));
  const muted = '#71717a';
  const width = doc.page.width - 96;

  doc.font('bold').fontSize(20).fillColor('#18181b').text(company);
  doc.moveDown(0.2).font('regular').fontSize(10).fillColor(muted).text('Коммерческое предложение');
  doc.moveDown(1.5);
  doc.font('bold').fontSize(16).fillColor('#18181b').text(p.title);
  doc
    .moveDown(0.3)
    .font('regular')
    .fontSize(10)
    .fillColor(muted)
    .text(
      `${p.number} · версия ${p.currentVersion} · ${new Date(p.updatedAt).toLocaleDateString('ru-RU', { timeZone: 'Asia/Tashkent' })}`,
    );
  doc.moveDown(0.8).fillColor('#18181b').fontSize(11).text(`Клиент: ${p.client.name}`);
  doc.text(`Менеджер: ${p.manager.name}`);
  if (p.description) doc.moveDown(0.8).fontSize(10).text(p.description, { width });

  // Таблица позиций
  doc.moveDown(1.2);
  const cols = [
    { title: 'Позиция', w: width * 0.44, align: 'left' as const },
    { title: 'Кол-во', w: width * 0.1, align: 'right' as const },
    { title: 'Цена', w: width * 0.18, align: 'right' as const },
    { title: 'Скидка', w: width * 0.1, align: 'right' as const },
    { title: 'Сумма', w: width * 0.18, align: 'right' as const },
  ];
  const row = (cells: string[], bold = false) => {
    const y = doc.y;
    let x = 48;
    let h = 0;
    doc.font(bold ? 'bold' : 'regular').fontSize(9.5);
    cells.forEach((c, i) => {
      doc.text(c, x + 2, y, { width: cols[i]!.w - 4, align: cols[i]!.align });
      h = Math.max(h, doc.y - y);
      x += cols[i]!.w;
    });
    doc.y = y + h + 6;
    doc
      .moveTo(48, doc.y - 3)
      .lineTo(48 + width, doc.y - 3)
      .strokeColor('#e4e4e7')
      .lineWidth(0.5)
      .stroke();
  };
  row(
    cols.map((c) => c.title),
    true,
  );
  for (const i of p.items) {
    row([
      i.service ? `${i.description}\n${i.service.name}` : i.description,
      Number(i.quantity).toLocaleString('ru-RU'),
      money(i.unitPrice, p.currency),
      Number(i.discountPct) ? `${Number(i.discountPct)}%` : '—',
      money(i.total, p.currency),
    ]);
  }

  doc.moveDown(0.5).font('regular').fontSize(10);
  const totals: [string, string][] = [['Сумма', money(p.subtotal, p.currency)]];
  if (Number(p.discountAmount)) totals.push(['Скидка', `− ${money(p.discountAmount, p.currency)}`]);
  for (const [k, v] of totals) doc.text(`${k}: ${v}`, 48, doc.y, { width, align: 'right' });
  doc
    .moveDown(0.2)
    .font('bold')
    .fontSize(13)
    .text(`Итого: ${money(p.total, p.currency)}`, 48, doc.y, { width, align: 'right' });

  doc.moveDown(1.5).font('regular').fontSize(10).fillColor('#18181b');
  if (p.implementationTerm) doc.text(`Срок реализации: ${p.implementationTerm}`);
  if (p.paymentTerms) doc.text(`Условия оплаты: ${p.paymentTerms}`, { width });
  if (p.validUntil)
    doc.text(
      `Предложение действительно до: ${new Date(`${p.validUntil}T00:00:00+05:00`).toLocaleDateString('ru-RU')}`,
    );
  return doc;
}
