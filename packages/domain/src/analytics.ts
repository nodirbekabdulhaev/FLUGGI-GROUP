import { addDays, companyDate } from './tasks';
import { isoWeekday } from './people';

const DAY_MS = 86_400_000;

// ─────────────────────────── Временные ряды ───────────────────────────

export type Granularity = 'day' | 'week' | 'month';

/** Шаг графика по длине периода: до 45 дней — дни, до ~6 месяцев — недели, дальше — месяцы. */
export function granularityFor(from: Date, to: Date): Granularity {
  const days = (to.getTime() - from.getTime()) / DAY_MS;
  if (days <= 45) return 'day';
  if (days <= 190) return 'week';
  return 'month';
}

/** Ключ корзины (дата по Ташкенту): день — YYYY-MM-DD, неделя — понедельник, месяц — YYYY-MM. */
export function bucketKey(at: Date, g: Granularity): string {
  const date = companyDate(at);
  if (g === 'day') return date;
  if (g === 'month') return date.slice(0, 7);
  return addDays(date, 1 - isoWeekday(date));
}

/** Все корзины периода [from, to) — чтобы на графике не было «дыр». */
export function bucketKeys(from: Date, to: Date, g: Granularity): string[] {
  const keys: string[] = [];
  const last = companyDate(new Date(to.getTime() - 1));
  let date = companyDate(from);
  while (date <= last) {
    const k = bucketKey(new Date(`${date}T12:00:00+05:00`), g);
    if (keys[keys.length - 1] !== k) keys.push(k);
    date = addDays(date, 1);
  }
  return keys;
}

// ─────────────────────────── Здоровье клиента (ТЗ §43) ───────────────────────────

export type ClientHealthLevel = 'HEALTHY' | 'ATTENTION' | 'RISK' | 'LOST';

export interface ClientHealthInput {
  /** Дней с последнего контакта (активность, встреча, оплата); null — контактов не было. */
  daysSinceContact: number | null;
  /** Дней с создания клиента. */
  ageDays: number;
  overduePayments: number;
  overdueProjects: number;
  activeProjects: number;
  openDeals: number;
  /** Оплаченных сделок за всё время. */
  paidDeals: number;
  /** Проиграно сделок за последние 90 дней. */
  recentLostDeals: number;
}

export interface ClientHealthResult {
  score: number;
  level: ClientHealthLevel;
  /** Что снизило или повысило оценку — показывается пользователю. */
  reasons: string[];
}

/**
 * Оценка «здоровья» клиента 0–100 (ТЗ §43). Правила прозрачные — причины видны в карточке:
 *  − нет контакта 30 / 60 / 90 дней: −20 / −40 / −65
 *  − просроченная оплата: −25 за каждую (до −50); просроченный проект: −15
 *  − проигранная сделка за 90 дней: −10
 *  + повторные покупки (2+ оплаченные сделки): +10; проект в работе: +10
 * ≥70 — «Здоров», 40–69 — «Внимание», <40 — «Риск».
 * «Потерян» — нет контакта 180+ дней и ничего в работе.
 */
export function clientHealth(c: ClientHealthInput): ClientHealthResult {
  const reasons: string[] = [];
  const silent = c.daysSinceContact ?? c.ageDays;
  const busy = c.activeProjects + c.openDeals > 0;
  if (silent >= 180 && !busy)
    return { score: 0, level: 'LOST', reasons: [`Нет контакта ${silent} дн., ничего в работе`] };

  let score = 100;
  if (silent >= 90) {
    score -= 65;
    reasons.push(`Нет контакта ${silent} дн.`);
  } else if (silent >= 60) {
    score -= 40;
    reasons.push(`Нет контакта ${silent} дн.`);
  } else if (silent >= 30) {
    score -= 20;
    reasons.push(`Нет контакта ${silent} дн.`);
  }
  if (c.overduePayments > 0) {
    score -= Math.min(50, 25 * c.overduePayments);
    reasons.push(`Просрочено оплат: ${c.overduePayments}`);
  }
  if (c.overdueProjects > 0) {
    score -= 15;
    reasons.push(`Просрочен проект`);
  }
  if (c.recentLostDeals > 0) {
    score -= 10;
    reasons.push(`Проиграна сделка за 90 дней`);
  }
  if (c.paidDeals >= 2) {
    score += 10;
    reasons.push(`Повторные покупки: ${c.paidDeals}`);
  }
  if (c.activeProjects > 0) {
    score += 10;
    reasons.push(`Проектов в работе: ${c.activeProjects}`);
  }
  score = Math.max(0, Math.min(100, score));
  const level: ClientHealthLevel = score >= 70 ? 'HEALTHY' : score >= 40 ? 'ATTENTION' : 'RISK';
  return { score, level, reasons };
}
