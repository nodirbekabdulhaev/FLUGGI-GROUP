"use strict";
/** Сумма прописью для договоров (рус.): «Семь миллионов пятьсот тысяч сумов 00 тийинов». */
Object.defineProperty(exports, "__esModule", { value: true });
exports.plural = plural;
exports.numberInWords = numberInWords;
exports.amountInWords = amountInWords;
const ONES_M = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
const ONES_F = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
const TEENS = [
    'десять',
    'одиннадцать',
    'двенадцать',
    'тринадцать',
    'четырнадцать',
    'пятнадцать',
    'шестнадцать',
    'семнадцать',
    'восемнадцать',
    'девятнадцать',
];
const TENS = [
    '',
    '',
    'двадцать',
    'тридцать',
    'сорок',
    'пятьдесят',
    'шестьдесят',
    'семьдесят',
    'восемьдесят',
    'девяносто',
];
const HUNDREDS = [
    '',
    'сто',
    'двести',
    'триста',
    'четыреста',
    'пятьсот',
    'шестьсот',
    'семьсот',
    'восемьсот',
    'девятьсот',
];
/** Форма слова для числа: 1 сум, 2 сума, 5 сумов. */
function plural(n, [one, few, many]) {
    const m10 = n % 10;
    const m100 = n % 100;
    if (m10 === 1 && m100 !== 11)
        return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14))
        return few;
    return many;
}
function triad(n, feminine) {
    const out = [];
    const h = Math.floor(n / 100);
    const rest = n % 100;
    if (h)
        out.push(HUNDREDS[h]);
    if (rest >= 10 && rest < 20)
        out.push(TEENS[rest - 10]);
    else {
        const t = Math.floor(rest / 10);
        const o = rest % 10;
        if (t)
            out.push(TENS[t]);
        if (o)
            out.push((feminine ? ONES_F : ONES_M)[o]);
    }
    return out;
}
const SCALES = [
    { forms: ['', '', ''], feminine: false },
    { forms: ['тысяча', 'тысячи', 'тысяч'], feminine: true },
    { forms: ['миллион', 'миллиона', 'миллионов'], feminine: false },
    { forms: ['миллиард', 'миллиарда', 'миллиардов'], feminine: false },
    { forms: ['триллион', 'триллиона', 'триллионов'], feminine: false },
];
/** Целое число прописью. `feminine` — для единиц женского рода. */
function numberInWords(value, feminine = false) {
    let n = Math.floor(Math.abs(value));
    if (n === 0)
        return 'ноль';
    const parts = [];
    let scale = 0;
    while (n > 0 && scale < SCALES.length) {
        const t = n % 1000;
        if (t) {
            const s = SCALES[scale];
            const words = triad(t, scale === 0 ? feminine : s.feminine);
            if (scale > 0)
                words.push(plural(t, s.forms));
            parts.unshift(words.join(' '));
        }
        n = Math.floor(n / 1000);
        scale++;
    }
    return parts.join(' ');
}
const CURRENCY_WORDS = {
    UZS: { major: ['сум', 'сума', 'сумов'], minor: ['тийин', 'тийина', 'тийинов'], feminine: false },
    USD: {
        major: ['доллар США', 'доллара США', 'долларов США'],
        minor: ['цент', 'цента', 'центов'],
        feminine: false,
    },
};
/** «7 500 000.00 UZS» → «Семь миллионов пятьсот тысяч сумов 00 тийинов». */
function amountInWords(amount, currency) {
    const fixed = Number(amount).toFixed(2);
    const [intPart, frac] = fixed.replace('-', '').split('.');
    const major = Number(intPart);
    const minor = Number(frac);
    const w = CURRENCY_WORDS[currency] ?? {
        major: [currency, currency, currency],
        minor: ['', '', ''],
        feminine: false,
    };
    const words = numberInWords(major, w.feminine);
    const text = `${words} ${plural(major, w.major)} ${frac} ${plural(minor, w.minor)}`.trim();
    return text.charAt(0).toUpperCase() + text.slice(1);
}
//# sourceMappingURL=words.js.map