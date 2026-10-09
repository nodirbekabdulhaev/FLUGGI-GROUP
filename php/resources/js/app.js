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

// PWA: service worker (кэширует только статику и офлайн-страницу)
if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

window.Alpine = Alpine;
Alpine.plugin(collapse);
Alpine.start();
