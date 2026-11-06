/*
 * Select2, loaded the way Chart.js already is: the chunk is fetched only when the DOM actually holds a
 * widgeted select, so the driver kiosk — which keeps its native control on purpose — pays nothing for
 * jQuery. See the three seams at the bottom of resources/js/app.js.
 *
 * The order of the four imports below is load-bearing, not stylistic. The assistant scans and mounts the
 * moment an integration registers, and a mount with no jQuery on `window` throws `select2-missing` and
 * leaves the element skipped until the next morph. Static ESM imports all run before this module's body,
 * so publishing jQuery has to happen in the body and the two Pharaonic modules have to arrive behind it as
 * dynamic imports.
 */

import jQuery from 'jquery';
import select2Attach from 'select2';
import 'select2/dist/css/select2.css';
import '../css/select2-theme.css';

// The UMD build's CommonJS branch exports a factory that attaches itself to the jQuery it is handed and
// returns it, which is what the package looks for: `window.jQuery.fn.select2` must be a function.
window.jQuery = select2Attach(window, jQuery);

// Option text is never interpolated into markup: every node below is built with textContent, because the
// strings it renders are supplier names and driver names typed by other seats.
const choice = (code, label) => {
    const wrapper = document.createElement('span');
    wrapper.className = 'fg-choice';

    if (code !== null && code !== '') {
        const figure = document.createElement('span');
        figure.className = 'fg-choice__code';
        figure.textContent = code;
        wrapper.append(figure);
    }

    const text = document.createElement('span');
    text.textContent = label;
    wrapper.append(text);

    return wrapper;
};

const boot = async () => {
    // Order is the whole trick. The assistant drains its registration queue and starts scanning during its
    // own install, so importing it first would mount every select before these templates existed — which the
    // console proved: three warnings and a teardown/remount of each widget on page load. Importing the
    // select2 bridge first parks the registration in that queue, the templates are defined behind it, and
    // the assistant then mounts everything once, already shaped.
    const { define } = await import('../../vendor/pharaonic/livewire-select2/dist/livewire-select2.esm.js');

    // A chart of accounts is a wall of numbers with words hanging off them, and the code is both what a
    // clerk searches for and what they read back to confirm the line. The option keeps its full
    // "4120 — Fuel" text so the search still matches either half; only the leading code is lifted into
    // the monospace slot, which is why the prefix is stripped here rather than at the call site.
    define('fg-code', (state) => {
        const element = state.element;

        if (!element || typeof element.getAttribute !== 'function') {
            return state.text;
        }

        const code = element.getAttribute('data-code') ?? '';
        const label = code !== '' && state.text.startsWith(code)
            ? state.text.slice(code.length).replace(/^[\s—-]+/, '')
            : state.text;

        return choice(code, label);
    });

    // Three pages pick a driver by whether their papers cover a day (vehicle handover, dispatch board,
    // inspection attribution). Each already puts the tone on the `<option>` and the reason in the label,
    // and each says the same thing about why: a coloured option is invisible on a native select and not
    // there at all on Safari. Under the widget the row can finally be a row — name, then the reason set
    // apart and coloured — while the closed box keeps just the name, because the page's own line under
    // the control is what the seat reads after choosing.
    define('fg-paper', (state) => {
        const element = state.element;

        if (!element || typeof element.getAttribute !== 'function') {
            return state.text;
        }

        const row = document.createElement('span');
        row.className = 'fg-paper';

        const name = document.createElement('span');
        name.className = 'fg-paper__name';
        name.textContent = element.getAttribute('data-name') || state.text;
        row.append(name);

        const note = element.getAttribute('data-note') ?? '';

        if (note !== '') {
            const why = document.createElement('span');
            why.className = 'fg-paper__note';

            const tone = element.getAttribute('data-tone') ?? '';

            if (tone !== '') {
                why.dataset.tone = tone;
            }

            why.textContent = note;
            row.append(why);
        }

        return row;
    });

    define('fg-name', (state) => state.element?.getAttribute?.('data-name') || state.text);

    await import('../../vendor/pharaonic/livewire-assistant/dist/livewire-assistant.esm.js');
};

let started = null;

// Called from the same three seams as Chart.js (resources/js/app.js). The guard keeps a morph from
// re-scanning the document; after this runs, the assistant's own Livewire hooks mount anything new.
export const mount = () => started ??= boot();
