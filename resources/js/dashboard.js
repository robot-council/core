/*
 * Two jobs, each small: keep the reader's place when a poll re-renders a dashboard page (#444, #472),
 * and leave a developer's initial showing when their GitHub picture fails to load (#410).
 *
 * **The place.**
 *
 * When a poll adds rows above what the reader is looking at, Livewire's morph keeps `scrollY` and
 * focus but not the row being read: the content moves underneath. Chrome's own scroll anchoring does
 * not rescue it, because the morph's non-lookahead reordering defeats it (measured on #444). So just
 * before a component's children are morphed, this records the first keyed row (`wire:key`) whose top
 * is at or below the top of the viewport, and that top; once they are morphed, it finds the same key
 * again and scrolls by the difference, so that row stays where it was.
 *
 * When the row is gone after the poll, nothing keyed is in
 * view, or the reader is at the very top of the page, it does nothing, which is the page's behavior
 * without it. A partial morph of an `@island` fires `island.morph` instead and is not covered; no
 * view uses islands. It uses only Livewire's documented
 * `morph` and `morphed` hooks, which run once around a component's whole morph. The per-element
 * `morph.updated` is too early: Alpine's morph calls it for an element before patching its children,
 * so the rows a poll adds would land after the correction.
 *
 * **The picture.** An avatar draws its initial beneath the picture, so a picture that fails leaves the
 * initial showing once the broken picture is taken away. `error` does not bubble, so one listener in
 * the capture phase catches every picture, including those a poll's morph puts back; and a picture
 * that failed before this script ran is found by what it left behind, complete with no size.
 *
 * Served as a file from the package's own route, as `dashboard.css` is, so `script-src 'self'`
 * covers it and no view carries an inline script.
 */
(() => {
    const anchors = new WeakMap();
    let registered = false;

    // The first rendered keyed element whose top is in the viewport, and where it is. Only one the
    // reader can see: a row below the fold is not what they are reading, and anchoring on it would
    // move what they are reading -- a list pushed down by an open glossary, say -- whenever a poll
    // added a row above that row.
    const anchorIn = (root) => {
        for (const el of root.querySelectorAll('[wire\\:key]')) {
            const rect = el.getBoundingClientRect();

            // Not rendered, so not something anyone is reading
            if (rect.width === 0 && rect.height === 0) continue;

            if (rect.top >= window.innerHeight) return null;

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
            // A reader at the very top is reading the newest rows, so they see new ones arrive
            // rather than having the page scroll them out of view
            const anchor = window.scrollY > 0 ? anchorIn(el) : null;

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

    // A picture that failed to load, in an avatar and nowhere else
    const failedAvatar = (el) => el instanceof HTMLImageElement && el.closest('[data-avatar]') !== null;

    document.addEventListener('error', ({ target }) => {
        if (failedAvatar(target)) target.remove();
    }, true);

    const sweep = () => {
        for (const image of document.querySelectorAll('[data-avatar] img')) {
            if (image.complete && image.naturalWidth === 0) image.remove();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sweep);
    } else {
        sweep();
    }

    // Registered before Livewire starts, which is what its documentation asks of a script that loads
    // after it; and at once when this loads after Livewire has already started
    document.addEventListener('livewire:init', register);

    if (window.Livewire && document.readyState === 'complete') {
        register();
    }
})();
