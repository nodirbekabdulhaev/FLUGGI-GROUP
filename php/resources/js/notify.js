/**
 * Уведомления в интерфейсе: звук, всплывающее окно и Web Push (системные уведомления со звуком ОС).
 * Выключить звук или push в CRM нельзя (требование владельца) — только в настройках браузера.
 *
 *  • Опрос /notifications/unread-count раз в 15 с (скрытая вкладка — раз в 30 с) и сразу при возврате на вкладку.
 *    Появилось новое непрочитанное — звук и окно с заголовком и ссылкой.
 *  • Браузеры не дают играть звук до первого действия пользователя: первый клик/клавиша/касание
 *    включает звук, до этого видна плашка «Нажмите, чтобы включить звук уведомлений».
 *  • Несколько открытых вкладок: звук играет одна (Web Locks + localStorage — id последнего озвученного).
 *  • Скрытая вкладка при включённом push молчит — звук даст системное уведомление.
 *  • Push: разрешение запрашивается только по кнопке (жест пользователя), подписка уходит на сервер.
 *
 * Настройки приходят из Blade: window.fluggiNotify = { unreadUrl, subscribeUrl, vapidKey, sound, initial, csrf }.
 */

const PLAYED_KEY = 'fluggi.notify.played';
const SYNCED_KEY = 'fluggi.push.synced';
const FRESH_MS = 2 * 60 * 1000;

const storage = {
    get(k) {
        try {
            return window.localStorage.getItem(k);
        } catch (e) {
            return null;
        }
    },
    set(k, v) {
        try {
            window.localStorage.setItem(k, v);
        } catch (e) {
            // приватный режим — дубли звука в разных вкладках возможны, это не ошибка
        }
    },
};

// ─────────────── Звук ───────────────

const sound = {
    ctx: null,
    buffer: null,
    unlocked: false,
    listeners: new Set(),

    context() {
        if (!this.ctx) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) {
                try {
                    this.ctx = new Ctx();
                } catch (e) {
                    this.ctx = null;
                }
            }
        }
        return this.ctx;
    },

    async load(url) {
        const ctx = this.context();
        if (!ctx || this.buffer || !url) return;
        try {
            const data = await (await fetch(url, { credentials: 'same-origin' })).arrayBuffer();
            this.buffer = await new Promise((resolve, reject) => ctx.decodeAudioData(data, resolve, reject));
        } catch (e) {
            this.buffer = null; // останется синтезированный сигнал
        }
    },

    setUnlocked() {
        if (this.unlocked) return;
        this.unlocked = true;
        this.listeners.forEach((fn) => fn());
    },

    /** Первый жест пользователя: разрешить звук на этой странице. */
    unlock() {
        const ctx = this.context();
        if (ctx) {
            const done = () => ctx.state === 'running' && this.setUnlocked();
            try {
                // Тихий звук в момент жеста — обязательное условие для Safari/iOS
                const src = ctx.createBufferSource();
                src.buffer = ctx.createBuffer(1, 1, 22050);
                src.connect(ctx.destination);
                src.start(0);
            } catch (e) {
                // ничего
            }
            const p = ctx.resume ? ctx.resume() : null;
            p && p.then ? p.then(done, () => {}) : done();
        } else {
            this.setUnlocked();
        }
    },

    /** Синтезированный «динь-дон», если файл не загрузился. */
    synth(ctx) {
        const t0 = ctx.currentTime + 0.01;
        [
            [1046.5, 0],
            [1568, 0.16],
        ].forEach(([f, at]) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = f;
            gain.gain.setValueAtTime(0.0001, t0 + at);
            gain.gain.exponentialRampToValueAtTime(0.35, t0 + at + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, t0 + at + 0.8);
            osc.connect(gain).connect(ctx.destination);
            osc.start(t0 + at);
            osc.stop(t0 + at + 0.85);
        });
    },

    play(url) {
        const ctx = this.context();
        if (!ctx) {
            try {
                new Audio(url).play().catch(() => {});
            } catch (e) {
                // нет звука в браузере
            }
            return;
        }
        if (ctx.state !== 'running' && ctx.resume) ctx.resume().catch(() => {});
        try {
            if (this.buffer) {
                const src = ctx.createBufferSource();
                src.buffer = this.buffer;
                src.connect(ctx.destination);
                src.start(0);
            } else {
                this.synth(ctx);
            }
        } catch (e) {
            // контекст ещё заблокирован
        }
    },
};

/** Озвучить уведомление один раз на все вкладки. */
const claim = async (id) => {
    const attempt = () => {
        if (storage.get(PLAYED_KEY) === id) return false;
        storage.set(PLAYED_KEY, id);
        return true;
    };
    if (navigator.locks && navigator.locks.request) {
        try {
            return await navigator.locks.request('fluggi-notify-sound', attempt);
        } catch (e) {
            return attempt();
        }
    }
    return attempt();
};

// ─────────────── Push ───────────────

const pushSupported = () =>
    'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;

const isIos = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

const standalone = () => window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;

const urlB64ToUint8 = (s) => {
    const raw = atob((s + '='.repeat((4 - (s.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
};

const registration = async () => {
    if (!('serviceWorker' in navigator)) return null;
    try {
        return (await navigator.serviceWorker.getRegistration()) || (await navigator.serviceWorker.register('/sw.js'));
    } catch (e) {
        return null;
    }
};

/** Подписать браузер и сообщить серверу (раз в сутки или при смене адреса подписки). */
const syncSubscription = async (cfg, force = false) => {
    const reg = await registration();
    if (!reg || !reg.pushManager || !cfg.vapidKey) return false;
    const ready = await navigator.serviceWorker.ready;
    let sub = await ready.pushManager.getSubscription();
    if (sub && cfg.vapidKey) {
        // Ключ сервера сменился — старая подписка не работает
        const key = sub.options?.applicationServerKey;
        if (key) {
            const a = new Uint8Array(key);
            const b = urlB64ToUint8(cfg.vapidKey);
            if (a.length !== b.length || a.some((x, i) => x !== b[i])) {
                await sub.unsubscribe().catch(() => {});
                sub = null;
            }
        }
    }
    if (!sub) {
        sub = await ready.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(cfg.vapidKey) });
        force = true;
    }
    const json = sub.toJSON();
    const mark = json.endpoint + '|' + cfg.userId;
    const synced = storage.get(SYNCED_KEY);
    if (!force && synced) {
        const [m, at] = synced.split('#');
        if (m === mark && Date.now() - Number(at) < 24 * 3600 * 1000) return true;
    }
    await window.api(cfg.subscribeUrl, { method: 'POST', body: { endpoint: json.endpoint, keys: json.keys } });
    storage.set(SYNCED_KEY, mark + '#' + Date.now());
    return true;
};

// ─────────────── Alpine ───────────────

export default function registerNotify(Alpine) {
    const cfg = window.fluggiNotify || {};

    Alpine.data('notifyCenter', () => ({
        count: cfg.initial?.count ?? 0,
        latestId: cfg.initial?.latest?.id ?? null,
        soundLocked: true,
        permission: 'Notification' in window ? Notification.permission : 'unsupported',
        pushReady: false,
        pushAvailable: false,
        iosHint: false,
        busy: false,
        popup: null,
        popupTimer: null,
        timer: null,

        init() {
            sound.load(cfg.sound);
            const ctx = sound.context();
            if (ctx && ctx.state === 'running') sound.setUnlocked();
            this.soundLocked = !sound.unlocked;
            const gestures = ['pointerdown', 'keydown', 'touchend'];
            const unlock = () => sound.unlock();
            sound.listeners.add(() => {
                this.soundLocked = false;
                gestures.forEach((e) => document.removeEventListener(e, unlock, true));
            });
            if (this.soundLocked) gestures.forEach((e) => document.addEventListener(e, unlock, true));

            this.pushAvailable = pushSupported() && !!cfg.vapidKey;
            this.iosHint = !!cfg.vapidKey && isIos() && !standalone() && !('PushManager' in window);
            if (this.pushAvailable && this.permission === 'granted') {
                syncSubscription(cfg).then((ok) => (this.pushReady = ok), () => {});
            }

            // Новое уведомление, которое пришло совсем недавно (между переходами по страницам), — тоже озвучить
            const latest = cfg.initial?.latest;
            if (latest && latest.at && Date.now() - Date.parse(latest.at) < FRESH_MS && storage.get(PLAYED_KEY) !== latest.id) {
                this.announce(latest);
            } else if (latest) {
                storage.get(PLAYED_KEY) || storage.set(PLAYED_KEY, latest.id);
            }

            this.schedule();
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.poll();
                this.schedule();
            });
            window.addEventListener('focus', () => this.poll());
            navigator.serviceWorker?.addEventListener?.('message', (e) => {
                if (e.data && e.data.type === 'fluggi-push') this.poll();
            });
        },

        schedule() {
            clearInterval(this.timer);
            this.timer = setInterval(() => this.poll(), document.hidden ? 30000 : 15000);
        },

        async poll() {
            if (this.busy) return;
            this.busy = true;
            try {
                const r = await window.api(cfg.unreadUrl);
                const grew = r.count > this.count;
                this.count = r.count;
                window.dispatchEvent(new CustomEvent('fluggi-unread', { detail: { count: r.count } }));
                if (r.latest && r.latest.id !== this.latestId) {
                    this.latestId = r.latest.id;
                    if (grew) this.announce(r.latest);
                }
            } catch (e) {
                // сеть недоступна — попробуем при следующем опросе
            } finally {
                this.busy = false;
            }
        },

        async announce(n) {
            this.latestId = n.id;
            this.show(n);
            // Скрытая вкладка при работающем push молчит: звук даст системное уведомление
            if (document.hidden && this.pushReady && this.permission === 'granted') return;
            if (await claim(n.id)) sound.play(cfg.sound);
        },

        show(n) {
            this.popup = n;
            clearTimeout(this.popupTimer);
            this.popupTimer = setTimeout(() => (this.popup = null), 10000);
        },

        enableSound() {
            sound.unlock();
        },

        async enablePush() {
            if (!this.pushAvailable) return;
            sound.unlock();
            try {
                this.permission = await Notification.requestPermission();
            } catch (e) {
                this.permission = Notification.permission;
            }
            if (this.permission === 'granted') {
                try {
                    this.pushReady = await syncSubscription(cfg, true);
                    window.toast(cfg.texts?.pushOn ?? '');
                } catch (e) {
                    window.toast(e.message || 'Push', 'error');
                }
            }
        },
    }));
}
