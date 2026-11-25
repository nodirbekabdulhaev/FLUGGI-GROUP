/**
 * «Мой день» на главной: День | Неделя | Месяц | Год (выбор запоминается в браузере).
 * Данные — GET /dashboard/agenda?range=&date=&who= (область видимости проверяется на сервере).
 * День и неделя — список по дням; месяц — сводка по дням (на компьютере ещё и сетка месяца);
 * год — сводка по месяцам, нажатие открывает месяц.
 */

const STORE = 'fluggi.agenda';

const read = () => {
    try {
        return JSON.parse(window.localStorage.getItem(STORE) ?? '{}') ?? {};
    } catch (e) {
        return {};
    }
};
const write = (v) => {
    try {
        window.localStorage.setItem(STORE, JSON.stringify(v));
    } catch (e) {
        // приватный режим — без запоминания
    }
};

const locale = () => {
    const lang = (document.documentElement.lang || 'ru').toLowerCase();
    return lang.startsWith('uz') ? 'uz-Latn-UZ' : lang.startsWith('en') ? 'en-US' : 'ru-RU';
};
const parse = (ymd) => new Date(`${ymd}T12:00:00`);
const fmt = (ymd, opts) => {
    try {
        return new Intl.DateTimeFormat(locale(), opts).format(parse(ymd));
    } catch (e) {
        return ymd;
    }
};
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export default function agenda({ initial, url, months = [], labels = {}, kindTone = {} }) {
    return {
        labels,
        kindTone,
        data: initial,
        range: initial.range,
        who: initial.who,
        loading: false,
        error: false,
        open: {},
        showOverdue: false,

        init() {
            const saved = read();
            const range = ['day', 'week', 'month', 'year'].includes(saved.range) ? saved.range : 'day';
            const who = saved.who === 'all' && this.data.canSeeAll ? 'all' : 'me';
            if (range !== this.range || who !== this.who) {
                this.range = range;
                this.who = who;
                this.load(this.data.today);
            }
        },

        async load(date) {
            this.loading = true;
            this.error = false;
            const q = new URLSearchParams({ range: this.range, who: this.who, date: date ?? this.data.anchor });
            try {
                this.data = await window.api(`${url}?${q}`);
                this.open = {};
            } catch (e) {
                this.error = true;
            } finally {
                this.loading = false;
            }
        },

        setRange(r) {
            if (r === this.range) return;
            this.range = r;
            write({ range: r, who: this.who });
            this.load(this.data.today >= this.data.from && this.data.today <= this.data.to ? this.data.today : this.data.anchor);
        },
        setWho(w) {
            this.who = w;
            write({ range: this.range, who: w });
            this.load();
        },
        prev() {
            this.load(this.data.prev);
        },
        next() {
            this.load(this.data.next);
        },
        goToday() {
            this.load(this.data.today);
        },
        openDay(date) {
            this.range = 'day';
            write({ range: 'day', who: this.who });
            this.load(date);
        },
        openMonth(key) {
            this.range = 'month';
            write({ range: 'month', who: this.who });
            this.load(`${key}-01`);
        },

        get isCurrent() {
            return this.data.today >= this.data.from && this.data.today <= this.data.to;
        },
        get title() {
            const d = this.data;
            if (d.range === 'day') return fmt(d.from, { weekday: 'long', day: 'numeric', month: 'long' });
            if (d.range === 'week') return `${fmt(d.from, { day: 'numeric', month: 'short' })} – ${fmt(d.to, { day: 'numeric', month: 'short', year: 'numeric' })}`;
            if (d.range === 'month') return months[parse(d.from).getMonth()] ? `${months[parse(d.from).getMonth()]} ${d.from.slice(0, 4)}` : fmt(d.from, { month: 'long', year: 'numeric' });
            return d.from.slice(0, 4);
        },
        dayLabel(date) {
            return fmt(date, { weekday: 'short', day: 'numeric', month: 'short' });
        },
        // Группы по дням (день, неделя, месяц)
        get days() {
            const by = {};
            for (const it of this.data.items) (by[it.date] ??= []).push(it);
            return Object.keys(by)
                .sort()
                .map((date) => ({ date, items: by[date], count: by[date].length, today: date === this.data.today, past: date < this.data.today }));
        },
        // Год: по месяцам — сколько записей каждого вида
        get monthsSummary() {
            const out = [];
            const year = this.data.from.slice(0, 4);
            for (let m = 1; m <= 12; m++) {
                const key = `${year}-${String(m).padStart(2, '0')}`;
                const items = this.data.items.filter((i) => i.date.startsWith(key));
                const kinds = {};
                for (const i of items) kinds[i.kind] = (kinds[i.kind] ?? 0) + 1;
                out.push({ key, label: months[m - 1] ?? key, count: items.length, kinds, current: this.data.today.startsWith(key) });
            }
            return out;
        },
        kindCounts(items) {
            const kinds = {};
            for (const i of items) kinds[i.kind] = (kinds[i.kind] ?? 0) + 1;
            return kinds;
        },
        // Месяц: сетка с понедельника (только на широком экране)
        get grid() {
            const first = parse(this.data.from);
            const shift = (first.getDay() + 6) % 7;
            const start = new Date(first);
            start.setDate(first.getDate() - shift);
            const counts = {};
            for (const i of this.data.items) counts[i.date] = (counts[i.date] ?? 0) + 1;
            const cells = [];
            for (let k = 0; k < 42; k++) {
                const d = new Date(start);
                d.setDate(start.getDate() + k);
                const key = ymd(d);
                if (k >= 35 && key.slice(0, 7) !== this.data.from.slice(0, 7)) break;
                cells.push({ key, day: d.getDate(), inMonth: key.slice(0, 7) === this.data.from.slice(0, 7), count: counts[key] ?? 0, today: key === this.data.today });
            }
            return cells;
        },
        get weekdays() {
            const monday = parse('2024-01-01');
            return Array.from({ length: 7 }, (_, i) => {
                const d = new Date(monday);
                d.setDate(monday.getDate() + i);
                try {
                    return new Intl.DateTimeFormat(locale(), { weekday: 'short' }).format(d);
                } catch (e) {
                    return String(i + 1);
                }
            });
        },
    };
}
