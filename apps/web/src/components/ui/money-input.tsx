'use client';

import * as React from 'react';
import { Input } from './input';

/** «5000000.5» → «5 000 000.5»: пробелы между разрядами, точка — разделитель дробной части. */
export function formatMoneyInput(raw: string): string {
  if (!raw) return '';
  const [int = '', frac] = raw.split('.');
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  return frac === undefined ? grouped : `${grouped}.${frac}`;
}

/** Ввод → «сырое» число: только цифры и одна точка (запятая тоже считается точкой), 2 знака. */
export function parseMoneyInput(text: string): string {
  const cleaned = text.replace(/,/g, '.').replace(/[^\d.]/g, '');
  const [int = '', ...rest] = cleaned.split('.');
  const intPart = int.replace(/^0+(?=\d)/, '');
  if (rest.length === 0) return intPart;
  return `${intPart || '0'}.${rest.join('').slice(0, 2)}`;
}

type Props = Omit<React.InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'type'> & {
  /** Значение без пробелов, как его ждёт API: «5000000» или «5000000.50». */
  value: string;
  /** Как у обычного поля, но в e.target.value — значение без пробелов. */
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
};

/**
 * Поле суммы: пока пользователь печатает, разряды разделяются пробелами (5 000 000).
 * Наружу отдаётся значение без пробелов — формы и API не меняются.
 */
export const MoneyInput = React.forwardRef<HTMLInputElement, Props>(
  ({ value, onChange, ...props }, forwarded) => {
    const ref = React.useRef<HTMLInputElement>(null);
    React.useImperativeHandle(forwarded, () => ref.current!);
    const caret = React.useRef<number | null>(null);
    const display = formatMoneyInput(value);

    // Курсор остаётся после той же цифры, что и до форматирования.
    React.useLayoutEffect(() => {
      const el = ref.current;
      if (!el || caret.current === null || document.activeElement !== el) return;
      let digits = caret.current;
      let pos = 0;
      while (pos < display.length && digits > 0) {
        if (display[pos] !== ' ') digits -= 1;
        pos += 1;
      }
      el.setSelectionRange(pos, pos);
      caret.current = null;
    }, [display]);

    return (
      <Input
        ref={ref}
        inputMode="decimal"
        autoComplete="off"
        {...props}
        value={display}
        onChange={(e) => {
          const text = e.target.value;
          const before = text.slice(0, e.target.selectionStart ?? text.length);
          caret.current = before.replace(/[^\d.,]/g, '').length;
          // Отдаём наружу «сырое» значение; React сразу перерисует поле с пробелами.
          e.target.value = parseMoneyInput(text);
          onChange(e);
        }}
      />
    );
  },
);
MoneyInput.displayName = 'MoneyInput';
