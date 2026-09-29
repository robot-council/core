/*
 * Keeps the reader's place when a poll re-renders a dashboard page (#444, #472).
 *
 * When a poll adds rows above what the reader is looking at, Livewire's morph keeps `scrollY` and
 * focus but not the row being read: the content moves underneath. Chrome's own scroll anchoring does
 * not rescue it, because the morph's non-lookahead reordering defeats it (measured on #444). So just
 * before a component's children are morphed, this records the first keyed row (`wire:key`) whose top
 * is at or below the top of the viewport, and that top; once they are morphed, it finds the same key
 * again and scrolls by the difference, so that row stays where it was.
 *
 * It does that one job and nothing else. When the row is gone after the poll, or nothing keyed is in
 * view, it does nothing, which is the page's behavior without it. It uses only Livewire's documented
 * `morph` and `morphed` hooks, which run once around a component's whole morph. The per-element
 * `morph.updated` is too early: Alpine's morph calls it for an element before patching its children,
 * so the rows a poll adds would land after the correction.
 *
 * Served as a file from the package's own route, as `dashboard.css` is, so `script-src 'self'`
 * covers it and no view carries an inline script.
 */
(() => {
    const anchors = new WeakMap();
    let registered = false;

    // The first rendered keyed element at or below the top of the viewport, and where it is
    const anchorIn = (root) => {
        for (const el of root.querySelectorAll('[wire\\:key]')) {
            const rect = el.getBoundingClientRect();

            // Not rendered, so not something anyone is reading
            if (rect.width === 0 && rect.height === 0) continue;

            if (rect.top >= 0) {
                return { key: el.getAttribute('wire:key'), top: rect.top };
            }
        }

        return null;
    };

    const register = () => {
        if (registered || !window.Livewire) return;
        registered = true;

        window.Livewire.hook('morph', ({ el }) => {
            const anchor = anchorIn(el);

            if (anchor) {
                anchors.set(el, anchor);
            } else {
                anchors.delete(el);
            }
        });

        window.Livewire.hook('morphed', ({ el }) => {
            const anchor = anchors.get(el);
            anchors.delete(el);

            if (!anchor) return;

            const row = el.querySelector(`[wire\\:key="${CSS.escape(anchor.key)}"]`);

            // Removed by the poll: leave the page where the morph put it
            if (!row) return;

            const moved = row.getBoundingClientRect().top - anchor.top;

            if (moved !== 0) {
                window.scrollBy(0, moved);
            }
        });
    };

    // Registered before Livewire starts, which is what its documentation asks of a script that loads
    // after it; and at once when this loads after Livewire has already started
    document.addEventListener('livewire:init', register);

    if (window.Livewire && document.readyState === 'complete') {
        register();
    }
})();
