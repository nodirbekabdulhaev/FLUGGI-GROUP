import { Injectable } from '@nestjs/common';
import { financeSettingsSchema, type FinanceSettings } from '@fluggi/contracts';
import { companyDate, monthRange, overheadShare } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import { PrismaService } from '../../core/prisma/prisma.service';

const ZERO = new Prisma.Decimal(0);
const SETTINGS_KEY = 'finance';

/** Месяцы YYYY-MM от from до to включительно. */
export function monthsBetween(from: string, to: string): string[] {
  const out: string[] = [];
  let [y, m] = from.split('-').map(Number) as [number, number];
  const [ty, tm] = to.split('-').map(Number) as [number, number];
  while (y < ty || (y === ty && m <= tm)) {
    out.push(`${y}-${String(m).padStart(2, '0')}`);
    m += 1;
    if (m > 12) {
      m = 1;
      y += 1;
    }
  }
  return out;
}

interface ProjectSpan {
  id: string;
  createdAt: Date;
  completedAt: Date | null;
  status: string;
}

/**
 * Накладные расходы (аренда, офис — категории с отметкой «накладные») делятся
 * на проекты, которые были в работе в этом месяце, или на заданный в настройках делитель.
 * Это управленческая аналитика проекта: в расходах компании накладные остаются как есть.
 */
@Injectable()
export class OverheadService {
  constructor(private readonly prisma: PrismaService) {}

  async settings(): Promise<FinanceSettings> {
    const row = await this.prisma.setting.findUnique({ where: { key: SETTINGS_KEY } });
    const parsed = financeSettingsSchema.safeParse({
      overheadDivisor: null,
      ...((row?.value as object) ?? {}),
    });
    return parsed.success ? parsed.data : { overheadDivisor: null };
  }

  async saveSettings(value: FinanceSettings, userId: string): Promise<FinanceSettings> {
    await this.prisma.setting.upsert({
      where: { key: SETTINGS_KEY },
      update: { value: value as unknown as Prisma.InputJsonValue, updatedById: userId },
      create: {
        key: SETTINGS_KEY,
        value: value as unknown as Prisma.InputJsonValue,
        updatedById: userId,
      },
    });
    return this.settings();
  }

  /** Накладные по месяцам, UZS. */
  private async overheadByMonth(months: string[]) {
    const map = new Map<string, Prisma.Decimal>();
    if (!months.length) return map;
    const rows = await this.prisma.expense.findMany({
      where: {
        deletedAt: null,
        scope: 'COMPANY',
        categoryRef: { isOverhead: true },
        // Календарная дата расхода — полночь UTC
        expenseDate: {
          gte: new Date(`${months[0]}-01`),
          lt: new Date(Date.UTC(...nextMonth(months.at(-1)!))),
        },
      },
      select: { expenseDate: true, amountUzs: true },
    });
    for (const r of rows) {
      const m = r.expenseDate.toISOString().slice(0, 7);
      map.set(m, (map.get(m) ?? ZERO).add(r.amountUzs));
    }
    return map;
  }

  private async activeByMonth(months: string[]) {
    const map = new Map<string, number>();
    if (!months.length) return map;
    const last = monthRange(months.at(-1)!).to;
    const first = monthRange(months[0]!).from;
    const projects = await this.prisma.project.findMany({
      where: {
        deletedAt: null,
        status: { not: 'CANCELLED' },
        createdAt: { lt: last },
        OR: [{ completedAt: null }, { completedAt: { gte: first } }],
      },
      select: { createdAt: true, completedAt: true },
    });
    for (const m of months) {
      const r = monthRange(m);
      map.set(
        m,
        projects.filter((p) => p.createdAt < r.to && (!p.completedAt || p.completedAt >= r.from))
          .length,
      );
    }
    return map;
  }

  /** Доля накладных для каждого проекта за все месяцы его работы (до сегодня). */
  async forProjects(
    projects: ProjectSpan[],
    now = new Date(),
  ): Promise<Map<string, Prisma.Decimal>> {
    const out = new Map<string, Prisma.Decimal>();
    if (!projects.length) return out;
    const today = companyDate(now).slice(0, 7);
    const spans = projects.map((p) => ({
      id: p.id,
      months:
        p.status === 'CANCELLED'
          ? []
          : monthsBetween(
              companyDate(p.createdAt).slice(0, 7),
              p.completedAt ? companyDate(p.completedAt).slice(0, 7) : today,
            ),
    }));
    const all = [...new Set(spans.flatMap((s) => s.months))].sort();
    const [overhead, active, settings] = await Promise.all([
      this.overheadByMonth(all),
      this.activeByMonth(all),
      this.settings(),
    ]);
    for (const s of spans) {
      out.set(
        s.id,
        s.months.reduce(
          (acc, m) =>
            acc.add(
              overheadShare(
                (overhead.get(m) ?? ZERO).toString(),
                active.get(m) ?? 1,
                settings.overheadDivisor,
              ),
            ),
          ZERO,
        ),
      );
    }
    return out;
  }

  /** Оценка накладных на один проект в месяц — по последнему полному месяцу (для экономики тарифа). */
  async monthlyShareEstimate(now = new Date()): Promise<Prisma.Decimal> {
    const months = [prevMonth(companyDate(now).slice(0, 7))];
    const [overhead, active, settings] = await Promise.all([
      this.overheadByMonth(months),
      this.activeByMonth(months),
      this.settings(),
    ]);
    const m = months[0]!;
    return new Prisma.Decimal(
      overheadShare(
        (overhead.get(m) ?? ZERO).toString(),
        active.get(m) ?? 1,
        settings.overheadDivisor,
      ),
    );
  }
}

function nextMonth(m: string): [number, number, number] {
  const [y, mm] = m.split('-').map(Number) as [number, number];
  return mm === 12 ? [y + 1, 0, 1] : [y, mm, 1];
}

function prevMonth(m: string): string {
  const [y, mm] = m.split('-').map(Number) as [number, number];
  return mm === 1 ? `${y - 1}-12` : `${y}-${String(mm - 1).padStart(2, '0')}`;
}
