/**
 * x-hscroll — горизонтальная прокрутка досок (Kanban):
 *  • полоса прокрутки закреплена внизу экрана над «Списком дел», пока доска видна на экране;
 *  • доску можно двигать мышью: зажать на свободном месте колонки или на её заголовке и тянуть.
 * Перетаскивание карточек (draggable), кнопки, ссылки и поля ввода не перехватываются.
 */
const SKIP = 'a, button, input, select, textarea, label, [draggable="true"], [contenteditable], .no-pan';

export default function registerHscroll(Alpine) {
    Alpine.directive('hscroll', (el, _, { cleanup }) => {
        // Телефон и планшет (только касание): обычная прокрутка пальцем без своей полосы —
        // иначе две синхронизируемые прокрутки дёргают доску при инерционном пролистывании
        if (!window.matchMedia('(pointer: fine)').matches) return;
        el.classList.add('hscroll-board');

        // Закреплённая полоса: отдельный блок под доской с position: sticky
        const bar = document.createElement('div');
        bar.className = 'hscroll-bar';
        bar.setAttribute('aria-hidden', 'true');
        const inner = document.createElement('div');
        bar.appendChild(inner);
        el.after(bar);

        // Позицию передаёт только тот блок, который сейчас двигает пользователь — без «эха» туда-обратно
        let active = el;
        const own = (node) => () => { active = node; };
        ['pointerdown', 'wheel', 'touchstart', 'keydown'].forEach((ev) => {
            el.addEventListener(ev, own(el), { passive: true });
            bar.addEventListener(ev, own(bar), { passive: true });
        });
        const fromBoard = () => {
            if (active === el && Math.abs(bar.scrollLeft - el.scrollLeft) > 1) bar.scrollLeft = el.scrollLeft;
        };
        const fromBar = () => {
            if (active === bar && Math.abs(el.scrollLeft - bar.scrollLeft) > 1) el.scrollLeft = bar.scrollLeft;
        };
        const resize = () => {
            inner.style.width = el.scrollWidth + 'px';
            bar.style.width = el.clientWidth + 'px';
            bar.hidden = el.scrollWidth <= el.clientWidth + 1;
            bar.scrollLeft = el.scrollLeft;
        };
        el.addEventListener('scroll', fromBoard, { passive: true });
        bar.addEventListener('scroll', fromBar, { passive: true });
        const ro = new ResizeObserver(resize);
        ro.observe(el);
        if (el.firstElementChild) ro.observe(el.firstElementChild);
        const mo = new MutationObserver(() => requestAnimationFrame(resize));
        mo.observe(el, { childList: true, subtree: true });
        window.addEventListener('resize', resize);
        requestAnimationFrame(resize);

        // Перетаскивание доски мышью (на телефоне доска и так листается пальцем)
        let start = null;
        const down = (e) => {
            if (e.button !== 0 || e.pointerType === 'touch' || e.target.closest(SKIP)) return;
            start = { x: e.clientX, left: el.scrollLeft, moved: false };
        };
        const move = (e) => {
            if (!start) return;
            const dx = e.clientX - start.x;
            if (!start.moved && Math.abs(dx) < 5) return;
            if (!start.moved) {
                start.moved = true;
                el.classList.add('is-panning');
            }
            el.scrollLeft = start.left - dx;
            e.preventDefault();
        };
        const up = () => {
            if (!start) return;
            const moved = start.moved;
            start = null;
            el.classList.remove('is-panning');
            // после перетаскивания доски не срабатывает клик по карточке под курсором
            if (moved) {
                const stop = (ev) => { ev.stopPropagation(); ev.preventDefault(); };
                window.addEventListener('click', stop, { capture: true, once: true });
                setTimeout(() => window.removeEventListener('click', stop, { capture: true }), 0);
            }
        };
        el.addEventListener('pointerdown', down);
        window.addEventListener('pointermove', move, { passive: false });
        window.addEventListener('pointerup', up);

        cleanup(() => {
            ro.disconnect();
            mo.disconnect();
            window.removeEventListener('resize', resize);
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            bar.remove();
        });
    });
}
