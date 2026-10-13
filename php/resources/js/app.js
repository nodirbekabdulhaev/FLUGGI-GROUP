import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

/**
 * Общие помощники интерфейса. Страницы — серверные (Blade), Alpine добавляет интерактивность:
 * меню, модальные окна, Kanban, подтверждения, автообновление счётчиков.
 */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** fetch с CSRF и JSON; ошибка сервера → исключение с текстом ответа. */
window.api = async (url, { method = 'GET', body, headers = {} } = {}) => {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
            ...(body && !(body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
            ...headers,
        },
        body: body && !(body instanceof FormData) ? JSON.stringify(body) : body,
    });
    if (res.status === 401) {
        window.location.href = '/login';
        throw new Error('401');
    }
    const data = res.headers.get('content-type')?.includes('json') ? await res.json() : null;
    if (!res.ok) {
        const message = data?.message ?? `HTTP ${res.status}`;
        const error = new Error(message);
        error.status = res.status;
        error.errors = data?.errors ?? {};
        throw error;
    }
    return data;
};

/** Всплывающее сообщение: window.toast('Сохранено') / window.toast('Ошибка', 'error'). */
window.toast = (text, type = 'ok') => window.dispatchEvent(new CustomEvent('toast', { detail: { text, type } }));

document.addEventListener('alpine:init', () => {
    Alpine.data('toasts', () => ({
        items: [],
        push({ text, type }) {
            const id = Date.now() + Math.random();
            this.items.push({ id, text, type });
            setTimeout(() => (this.items = this.items.filter((i) => i.id !== id)), 4000);
        },
    }));
});

// Подтверждение опасных действий: <form data-confirm="Удалить?">
document.addEventListener('submit', (e) => {
    const message = e.target.dataset?.confirm;
    if (message && !window.confirm(message)) e.preventDefault();
});

// Повторная отправка формы двойным кликом: блокируем кнопку после первой отправки
document.addEventListener('submit', (e) => {
    if (e.defaultPrevented) return;
    e.target.querySelectorAll('button[type="submit"]').forEach((b) => {
        setTimeout(() => (b.disabled = true), 0);
    });
});

/**
 * Поля сумм: <input data-money> (и для совместимости — все input[inputmode="decimal"]).
 * Пока вводят, целая часть делится пробелами по 3 цифры («10 000 000»), дробная — через точку
 * (запятая превращается в точку), не больше data-decimals знаков (по умолчанию 2). Минус — только
 * с data-allow-negative. На сервер уходит число без пробелов (событие formdata); сервер тоже
 * принимает «10 000 000,50» — на случай выключенного JavaScript.
 */
const MONEY_SELECTOR = 'input[data-money], input[inputmode="decimal"]';
const SPACES = /[\s   ]/g;
const nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

const moneyOptions = (el) => {
    const d = parseInt(el.dataset.decimals ?? '', 10);
    return { decimals: Number.isFinite(d) && d >= 0 ? d : 2, negative: el.hasAttribute('data-allow-negative') };
};

/** «10000000,5» → «10 000 000.5». typing=false (значение с сервера/из JS): лишние знаки после точки не обрезаются, кроме нулей. */
const formatMoney = (raw, { decimals = 2, negative = false } = {}, typing = true) => {
    let s = String(raw ?? '').replace(SPACES, '').replace(/,/g, '.');
    if (s === '') return '';
    const neg = negative && s.startsWith('-');
    s = s.replace(/[^\d.]/g, '');
    const dot = s.indexOf('.');
    let int = dot === -1 ? s : s.slice(0, dot);
    let frac = dot === -1 || decimals === 0 ? null : s.slice(dot + 1).replace(/\./g, '');
    if (frac !== null && frac.length > decimals) {
        frac = typing ? frac.slice(0, decimals) : frac.replace(/0+$/, '').padEnd(decimals, '0');
    }
    int = int.replace(/^0+(?=\d)/, '');
    if (int === '' && frac !== null) int = '0';
    int = int.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

    return (neg ? '-' : '') + int + (frac !== null ? '.' + frac : '');
};

/** «10 000 000.50» → «10000000.50» (для собственных JSON-запросов из полей сумм). */
window.formatMoney = formatMoney;
window.unformatMoney = (v) => String(v ?? '').replace(SPACES, '').replace(/,/g, '.');

const enhanceMoney = (el) => {
    if (el._fluggiMoney || el.type === 'hidden') return;
    el._fluggiMoney = true;
    // Значение, которое ставит Alpine (x-model) или скрипт страницы, тоже показывается с пробелами
    Object.defineProperty(el, 'value', {
        configurable: true,
        get() {
            return nativeValue.get.call(this);
        },
        set(v) {
            nativeValue.set.call(this, formatMoney(v, moneyOptions(this), false));
        },
    });
    nativeValue.set.call(el, formatMoney(nativeValue.get.call(el), moneyOptions(el), false));
};

const enhanceMoneyIn = (root) => {
    if (root.nodeType !== 1) return;
    if (root.matches(MONEY_SELECTOR)) enhanceMoney(root);
    root.querySelectorAll(MONEY_SELECTOR).forEach(enhanceMoney);
};

// Перехват в фазе погружения — раньше x-model, чтобы модель получила уже отформатированное значение
document.addEventListener(
    'input',
    (e) => {
        const el = e.target;
        if (!(el instanceof HTMLInputElement) || !el.matches(MONEY_SELECTOR)) return;
        enhanceMoney(el);
        const before = nativeValue.get.call(el);
        const caret = el.selectionStart ?? before.length;
        // Сколько значимых символов (цифры, точка, минус) стояло до курсора
        const significant = before.slice(0, caret).replace(el.hasAttribute('data-allow-negative') ? /[^\d.,-]/g : /[^\d.,]/g, '').length;
        const after = formatMoney(before, moneyOptions(el), true);
        if (after === before) return;
        nativeValue.set.call(el, after);
        let pos = 0;
        for (let seen = 0; pos < after.length && seen < significant; pos++) {
            if (after[pos] !== ' ') seen++;
        }
        try {
            el.setSelectionRange(pos, pos);
        } catch (err) {
            // поле не поддерживает выделение — ничего не делаем
        }
    },
    true,
);

// При отправке формы (в том числе form.submit() и new FormData(form)) — числа без пробелов
document.addEventListener('formdata', (e) => {
    const names = new Set();
    for (const el of e.target.elements ?? []) {
        if (el instanceof HTMLInputElement && el.name && el.matches(MONEY_SELECTOR)) names.add(el.name);
    }
    names.forEach((name) => {
        const values = e.formData.getAll(name);
        e.formData.delete(name);
        values.forEach((v) => e.formData.append(name, typeof v === 'string' ? window.unformatMoney(v) : v));
    });
});

// Поля на странице, в модальных окнах и в строках, которые Alpine добавляет позже
const startMoney = () => {
    enhanceMoneyIn(document.body);
    new MutationObserver((mutations) => mutations.forEach((m) => m.addedNodes.forEach(enhanceMoneyIn))).observe(document.body, {
        childList: true,
        subtree: true,
    });
};
document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', startMoney) : startMoney();
// Возврат «Назад» из кэша браузера — значения могли остаться без пробелов
window.addEventListener('pageshow', () => document.querySelectorAll(MONEY_SELECTOR).forEach((el) => (el.value = nativeValue.get.call(el))));

// PWA: service worker (кэширует только статику и офлайн-страницу)
if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

window.Alpine = Alpine;
Alpine.plugin(collapse);
Alpine.start();
