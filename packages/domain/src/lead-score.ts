/**
 * Lead scoring (ТЗ §10). Чистая функция: факторы → 0–100 и уровень.
 * Каждый фактор нормирован в 0..1, итог — взвешенное среднее по известным факторам
 * (неизвестный фактор не штрафует и не завышает оценку).
 */
export type ScoreLevel = 'LOW' | 'MEDIUM' | 'HIGH' | 'HOT';
export type CompanySizeCode = 'SOLO' | 'SMALL' | 'MEDIUM' | 'LARGE';
export type PriorityCode = 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT';

export interface LeadScoreInput {
  /** Бюджет в UZS. */
  budgetUzs?: number | null;
  /** Минимальная цена выбранной услуги в UZS (если задана в каталоге). */
  serviceMinPriceUzs?: number | null;
  hasService: boolean;
  priority: PriorityCode;
  /** Сколько дней до желаемой даты старта (отрицательное — уже прошла). */
  daysToDesiredDate?: number | null;
  companySize?: CompanySizeCode | null;
  /** 1–5. */
  interest?: number | null;
  /** Индекс этапа воронки лида: 0 — новый … 4 — встреча проведена. */
  stageIndex: number;
  stageCount: number;
}

export const SCORE_WEIGHTS = {
  budget: 25,
  urgency: 15,
  serviceFit: 10,
  companySize: 10,
  interest: 20,
  stage: 20,
} as const;

const PRIORITY_URGENCY: Record<PriorityCode, number> = {
  LOW: 0.2,
  MEDIUM: 0.5,
  HIGH: 0.8,
  URGENT: 1,
};
const SIZE: Record<CompanySizeCode, number> = { SOLO: 0.25, SMALL: 0.5, MEDIUM: 0.8, LARGE: 1 };

const clamp01 = (v: number) => Math.min(1, Math.max(0, v));

export function scoreLevel(score: number): ScoreLevel {
  if (score > 80) return 'HOT';
  if (score > 60) return 'HIGH';
  if (score > 30) return 'MEDIUM';
  return 'LOW';
}

export function computeLeadScore(input: LeadScoreInput): {
  score: number;
  level: ScoreLevel;
  factors: Record<string, number>;
} {
  const factors: Record<string, number> = {};

  if (input.budgetUzs != null && input.budgetUzs > 0) {
    // Бюджет относительно минимальной цены услуги; без каталожной цены — шкала до 50 млн UZS.
    const ref =
      input.serviceMinPriceUzs && input.serviceMinPriceUzs > 0
        ? input.serviceMinPriceUzs * 2
        : 50_000_000;
    factors.budget = clamp01(input.budgetUzs / ref);
  }

  let urgency = PRIORITY_URGENCY[input.priority];
  if (input.daysToDesiredDate != null) {
    const byDate =
      input.daysToDesiredDate <= 14
        ? 1
        : input.daysToDesiredDate <= 45
          ? 0.7
          : input.daysToDesiredDate <= 90
            ? 0.4
            : 0.2;
    urgency = Math.max(urgency, byDate);
  }
  factors.urgency = urgency;

  factors.serviceFit = input.hasService ? 1 : 0.3;
  if (input.companySize) factors.companySize = SIZE[input.companySize];
  if (input.interest != null) factors.interest = clamp01((input.interest - 1) / 4);
  factors.stage = input.stageCount > 1 ? clamp01(input.stageIndex / (input.stageCount - 1)) : 0;

  let weighted = 0;
  let weights = 0;
  for (const [key, value] of Object.entries(factors)) {
    const w = SCORE_WEIGHTS[key as keyof typeof SCORE_WEIGHTS];
    weighted += w * value;
    weights += w;
  }
  const score = weights > 0 ? Math.round((weighted / weights) * 100) : 0;
  return { score, level: scoreLevel(score), factors };
}
