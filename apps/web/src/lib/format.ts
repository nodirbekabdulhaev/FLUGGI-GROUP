import type { Currency } from '@fluggi/contracts';

const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 });

/** 9 000 000 UZS / 1 200 USD */
export function money(
  amount: string | number | null | undefined,
  currency: Currency = 'UZS',
): string {
  if (amount === null || amount === undefined || amount === '') return '—';
  return `${nf.format(Number(amount))} ${currency}`;
}

/** Компактно для карточек: 187,4 млн / 1,2 млрд. */
export function moneyShort(
  amount: string | number | null | undefined,
  currency: Currency = 'UZS',
): string {
  if (amount === null || amount === undefined || amount === '') return '—';
  const n = Number(amount);
  const abs = Math.abs(n);
  const f = (v: number) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(v);
  if (abs >= 1e9) return `${f(n / 1e9)} млрд ${currency}`;
  if (abs >= 1e6) return `${f(n / 1e6)} млн ${currency}`;
  if (abs >= 1e3 && currency === 'UZS') return `${f(n / 1e3)} тыс ${currency}`;
  return money(n, currency);
}

export function dateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('ru-RU', {
    timeZone: 'Asia/Tashkent',
    dateStyle: 'short',
    timeStyle: 'short',
  });
}

export function date(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = iso.length === 10 ? new Date(`${iso}T00:00:00+05:00`) : new Date(iso);
  return d.toLocaleDateString('ru-RU', { timeZone: 'Asia/Tashkent' });
}

/** ISO → значение для <input type="datetime-local"> в ташкентском времени. */
export function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(new Date(iso).getTime() + 5 * 3600_000);
  return d.toISOString().slice(0, 16);
}

/** <input type="datetime-local"> (Ташкент) → ISO с часовым поясом. */
export function fromLocalInput(v: string): string {
  return new Date(`${v}:00+05:00`).toISOString();
}

export function newIdempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}
