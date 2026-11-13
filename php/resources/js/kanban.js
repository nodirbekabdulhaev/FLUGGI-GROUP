/**
 * Kanban воронки (страницы «Воронка», «Лиды», «Сделки»): перетаскивание (компьютер) или список этапов
 * (телефон) → POST …/stage, при ошибке — откат; после переноса доска перечитывается с сервера.
 * «+» в колонке открывает форму создания с этим этапом (cfg.create — что делать для лида/сделки).
 */
export default function pipelineBoard(initial, t) {
    return {
        board: initial,
        drag: null,
        over: null,
        t,
        canMove(card) {
            return card.kind === 'lead' ? t.canLead : t.canDeal;
        },
        drop(to) {
            this.over = null;
            if (!this.drag) return;
            const { card, from } = this.drag;
            this.drag = null;
            this.move(card, from, to);
        },
        async move(card, from, to) {
            if (!to || from.code === to.code || !this.canMove(card)) return;
            if (card.kind === 'lead' && to.entity === 'DEAL') return window.toast(t.convertFirst);
            if (card.kind === 'deal' && to.entity === 'LEAD') return window.toast(t.wrongBoard, 'error');
            const prev = JSON.parse(JSON.stringify(this.board));
            from.items = from.items.filter((i) => i.id !== card.id);
            from.count--;
            to.items.unshift(card);
            to.count++;
            try {
                await window.api(t[card.kind].replace('__ID__', card.id), { method: 'POST', body: { stage: to.code } });
                window.toast(card.title + ' → ' + to.name);
            } catch (e) {
                this.board = prev;
                window.toast(e.message, 'error');
            }
            try {
                this.board = await window.api(t.data);
            } catch (e) {
                /* доска останется как есть */
            }
        },
        /** Создать запись в колонке: ссылка (страница «Воронка») или окно формы на этой же странице. */
        create(col) {
            const c = (t.create || {})[col.entity];
            if (!c || !col.canCreate) return;
            if (c.url) {
                window.location.href = c.url.replace('__STAGE__', encodeURIComponent(col.code));
                return;
            }
            window.dispatchEvent(new CustomEvent('kanban-create-stage', { detail: { entity: col.entity, stage: col.code, name: col.name } }));
            window.dispatchEvent(new CustomEvent('open-modal', { detail: c.modal }));
        },
        canCreate(col) {
            return !!col.canCreate && !!(t.create || {})[col.entity];
        },
        moreHref(col) {
            return t.moreUrl ? t.moreUrl.replace('__STAGE__', encodeURIComponent(col.code)) : null;
        },
        priorityClass(p) {
            return { LOW: 'badge-green', MEDIUM: 'badge-yellow', HIGH: 'badge-red', URGENT: 'badge-red' }[p] || 'badge-gray';
        },
    };
}
