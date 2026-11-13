/**
 * Назначение и перенос встречи: участники, ответственный за результат, слоты на выбранный день.
 * Слоты приходят с сервера (GET /sales/meetings/slots): free | busy | past и conflicts — кто из
 * остальных выбранных участников занят в это время. Выбрать можно только слот, свободный у всех.
 *
 * x-data="meetingPlanner({ url, date, time, duration, options, selected, responsible, exclude, me, labels })"
 * Перенастройка (окно изменения встречи): событие с теми же полями → reset(detail).
 */
export default function meetingPlanner(config) {
    return {
        url: config.url,
        labels: config.labels ?? {},
        me: config.me ?? null,
        date: '',
        time: '',
        duration: 60,
        options: [],
        extra: [],
        selected: [],
        responsible: '',
        exclude: null,
        rows: [],
        loading: false,
        error: '',
        seq: 0,

        init() {
            this.reset(config);
            this.$watch('selected', () => {
                if (!this.selected.includes(this.responsible)) this.responsible = this.defaultResponsible();
                this.load();
            });
            this.$watch('date', () => this.load());
        },

        reset(c) {
            this.date = c.date ?? '';
            this.time = c.time ?? '';
            this.duration = Number(c.duration ?? 60);
            this.options = config.options ?? [];
            const known = new Set(this.options.map((o) => o.id));
            this.extra = (c.extra ?? []).filter((o) => !known.has(o.id));
            this.exclude = c.exclude ?? null;
            this.selected = [...(c.selected ?? [])];
            this.responsible = c.responsible && this.selected.includes(c.responsible) ? c.responsible : this.defaultResponsible();
            this.load();
        },

        get people() {
            return [...this.options, ...this.extra];
        },

        get chosen() {
            return this.people.filter((p) => this.selected.includes(p.id));
        },

        get startsAt() {
            return this.date && this.time ? `${this.date}T${this.time}` : '';
        },

        defaultResponsible() {
            if (this.me && this.selected.includes(this.me)) return this.me;
            return this.selected[0] ?? '';
        },

        async load() {
            const seq = ++this.seq;
            this.error = '';
            if (!this.date || !this.selected.length) {
                this.rows = [];
                return;
            }
            const q = new URLSearchParams({ date: this.date });
            this.selected.forEach((id) => q.append('users[]', id));
            if (this.exclude) q.append('exclude', this.exclude);
            this.loading = true;
            try {
                const data = await window.api(`${this.url}?${q}`);
                if (seq === this.seq) this.rows = data.users ?? [];
            } catch (e) {
                if (seq === this.seq) {
                    this.rows = [];
                    this.error = e.message;
                }
            } finally {
                if (seq === this.seq) this.loading = false;
            }
        },

        label(key, names) {
            return (this.labels[key] ?? '').replace('__N__', names);
        },

        selectable(slot) {
            return slot.state === 'free' && !slot.conflicts.length;
        },

        isPicked(slot) {
            return this.startsAt === slot.value && Number(this.duration) === slot.minutes;
        },

        pick(slot) {
            if (!this.selectable(slot)) return;
            this.time = slot.time;
            this.duration = slot.minutes;
        },

        busyText(slot) {
            const names = [...new Set(slot.meetings.flatMap((m) => m.participants))].join(', ');
            return this.label('busyBy', names);
        },

        slotTitle(slot) {
            if (slot.state === 'busy') {
                return slot.meetings.map((m) => [m.time, m.subject, m.participants.join(', ')].filter(Boolean).join(' · ')).join('\n');
            }
            if (slot.state === 'past') return this.labels.past ?? '';
            if (slot.conflicts.length) return this.label('conflict', slot.conflicts.join(', '));
            return this.labels.free ?? '';
        },
    };
}
