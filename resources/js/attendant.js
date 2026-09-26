/**
 * Attendant panel (left) and PAR sheet (right).
 *
 * Both panels are rendered by the server; this module sends the attendant's
 * actions, swaps in freshly rendered panels, and tells the machine when its
 * configuration has changed.
 */

const csrfToken = () => document.querySelector('meta[name="csrf-token"]').content;

async function send(url, { method = 'POST', body = null } = {}) {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body === null ? null : JSON.stringify(body),
    });

    if (response.status === 419) {
        window.location.reload();
    }

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = payload.errors ? Object.values(payload.errors)[0]?.[0] : null;
        throw new Error(firstError || payload.message || 'The request failed.');
    }

    return payload;
}

async function fetchHtml(url) {
    const response = await fetch(url, { headers: { Accept: 'text/html' } });

    return new DOMParser().parseFromString(await response.text(), 'text/html').body.firstElementChild;
}

function notifyMachine() {
    window.dispatchEvent(new CustomEvent('machine:config-changed'));
}

function showMessage(element, message) {
    if (!element) {
        return;
    }

    element.textContent = message ?? '';
    element.hidden = !message;
}

class AttendantPanel {
    constructor(container) {
        this.container = container;
        this.urls = container.dataset;
        this.meterRefreshTimer = null;

        container.addEventListener('submit', (event) => this.onSubmit(event));
        container.addEventListener('click', (event) => this.onClick(event));
        container.addEventListener('input', (event) => {
            if (event.target.matches('[data-boost-input]')) {
                container.querySelector('[data-boost-output]').textContent = `${Number(event.target.value).toFixed(1)}×`;
            }
        });

        window.addEventListener('machine:played', () => this.scheduleMeterRefresh());
    }

    get root() {
        return this.container.querySelector('[data-attendant-root]');
    }

    get unlocked() {
        return this.root?.dataset.unlocked === '1';
    }

    async refresh() {
        this.container.replaceChildren(await fetchHtml(this.urls.panelUrl));
    }

    /**
     * After games are played, update the meters and progressive values without
     * disturbing any settings the attendant is part way through editing.
     */
    scheduleMeterRefresh() {
        if (!this.unlocked) {
            return;
        }

        clearTimeout(this.meterRefreshTimer);
        this.meterRefreshTimer = setTimeout(async () => {
            const fresh = await fetchHtml(this.urls.panelUrl);

            for (const selector of ['[data-meters]', '[data-progressive-current="grand"]', '[data-progressive-current="major"]']) {
                const current = this.container.querySelector(selector);
                const replacement = fresh.querySelector(selector);

                if (current && replacement) {
                    current.replaceWith(replacement);
                }
            }
        }, 1200);
    }

    async onSubmit(event) {
        const form = event.target;
        event.preventDefault();

        if (form.matches('[data-attendant-unlock]')) {
            await this.run(form, () => send(this.urls.unlockUrl, { body: { pin: form.elements.pin.value } }));
            return;
        }

        if (form.matches('[data-attendant-settings]')) {
            const saved = await this.run(form, () => send(this.urls.settingsUrl, { method: 'PUT', body: this.settingsPayload(form) }));

            if (saved) {
                notifyMachine();
                window.dispatchEvent(new CustomEvent('machine:par-stale'));
                showMessage(this.container.querySelector('[data-attendant-status]'), 'Settings saved.');
            }
        }
    }

    async onClick(event) {
        const button = event.target.closest('button');

        if (!button) {
            return;
        }

        if (button.matches('[data-attendant-lock]')) {
            await this.run(button, () => send(this.urls.lockUrl));
        } else if (button.matches('[data-progressive-reset]')) {
            const tier = button.dataset.progressiveReset;

            if (window.confirm(`Reset the ${tier} jackpot to its reset value?`)) {
                const reset = await this.run(button, () => send(`${this.urls.progressiveResetUrl}/${tier}/reset`));

                if (reset) {
                    notifyMachine();
                }
            }
        } else if (button.matches('[data-meters-clear]')) {
            if (window.confirm('Clear all accounting meters? This cannot be undone.')) {
                await this.run(button, () => send(this.urls.metersClearUrl));
            }
        }
    }

    /**
     * Run an action with its control disabled, then re-render the panel.
     * Returns whether the action succeeded.
     */
    async run(control, action) {
        const buttons = control.matches('form') ? [...control.querySelectorAll('button')] : [control];
        buttons.forEach((button) => (button.disabled = true));

        try {
            await action();
        } catch (error) {
            showMessage(this.container.querySelector('[data-attendant-error]'), error.message);
            buttons.forEach((button) => (button.disabled = false));

            return false;
        }

        await this.refresh();

        return true;
    }

    settingsPayload(form) {
        const value = (name) => form.elements[name].value;

        return {
            rtp_program: Number(form.querySelector('[name="rtp_program"]:checked')?.value),
            denominations_enabled: [...form.querySelectorAll('[name="denominations_enabled[]"]:checked')].map((input) => Number(input.value)),
            max_credits_per_line: Number(value('max_credits_per_line')),
            max_deposit_dollars: value('max_deposit_dollars'),
            major_seed_dollars: value('major_seed_dollars'),
            major_cap_dollars: value('major_cap_dollars'),
            major_contribution_percent: value('major_contribution_percent'),
            grand_seed_dollars: value('grand_seed_dollars'),
            grand_contribution_percent: value('grand_contribution_percent'),
            free_games_awarded: Number(value('free_games_awarded')),
            jackpot_max_bet_boost: value('jackpot_max_bet_boost'),
            grand_replaces_values: form.elements.grand_replaces_values.checked,
            autoplay_enabled: form.elements.autoplay_enabled.checked,
        };
    }
}

const LIVE_UPDATES_KEY = 'machine.par-live-updates';

function readLivePreference() {
    try {
        return window.localStorage.getItem(LIVE_UPDATES_KEY) === 'on';
    } catch {
        return false;
    }
}

function saveLivePreference(enabled) {
    try {
        window.localStorage.setItem(LIVE_UPDATES_KEY, enabled ? 'on' : 'off');
    } catch {
        // Storage can be unavailable (private windows); the choice then lasts for this page only.
    }
}

class ParSheetPanel {
    constructor(container) {
        this.container = container;
        this.urls = container.dataset;
        this.liveUpdates = readLivePreference();
        this.liveRefreshTimer = null;

        container.addEventListener('submit', (event) => this.onSubmit(event));
        container.addEventListener('change', (event) => {
            if (event.target.matches('[data-live-toggle]')) {
                this.setLiveUpdates(event.target.value === 'on');
            }
        });
        window.addEventListener('machine:par-stale', () => this.refresh());
        window.addEventListener('machine:played', () => this.scheduleLiveRefresh());

        this.applyLiveToggle();
    }

    async refresh() {
        const scrollTop = this.container.scrollTop;
        this.container.replaceChildren(await fetchHtml(this.urls.parUrl));
        this.container.scrollTop = scrollTop;
        this.applyLiveToggle();
    }

    setLiveUpdates(enabled) {
        this.liveUpdates = enabled;
        saveLivePreference(enabled);

        if (enabled) {
            this.refreshLive();
        }
    }

    /**
     * Show the remembered On / Off choice (the server always renders Off).
     */
    applyLiveToggle() {
        for (const input of this.container.querySelectorAll('[data-live-toggle]')) {
            input.checked = (input.value === 'on') === this.liveUpdates;
        }
    }

    /**
     * After each spin, refresh the live section. Rapid spins (autoplay,
     * respins) are coalesced into one refresh.
     */
    scheduleLiveRefresh() {
        if (!this.liveUpdates) {
            return;
        }

        clearTimeout(this.liveRefreshTimer);
        this.liveRefreshTimer = setTimeout(() => this.refreshLive(), 400);
    }

    async refreshLive() {
        const body = this.container.querySelector('[data-par-live-body]');

        if (!body) {
            return;
        }

        const response = await fetch(this.urls.parLiveUrl, { headers: { Accept: 'text/html' } });
        const fragment = new DOMParser().parseFromString(await response.text(), 'text/html').body;
        body.replaceChildren(...fragment.childNodes);
    }

    async onSubmit(event) {
        const form = event.target;

        if (!form.matches('[data-par-simulate]')) {
            return;
        }

        event.preventDefault();

        const status = this.container.querySelector('[data-par-status]');
        const button = form.querySelector('button');
        const spins = Number(form.elements.spins.value);

        button.disabled = true;
        showMessage(status, `Simulating ${spins.toLocaleString('en-US')} spins…`);

        try {
            await send(this.urls.simulateUrl, {
                body: {
                    spins,
                    denomination: Number(form.elements.denomination.value),
                    credits_per_line: Number(form.elements.credits_per_line.value),
                },
            });
            await this.refresh();
        } catch (error) {
            showMessage(status, error.message.includes('locked') ? 'Unlock the attendant panel to run simulations.' : error.message);
            button.disabled = false;
        }
    }
}

const attendant = document.querySelector('[data-attendant]');
const parSheet = document.querySelector('[data-par]');

if (attendant) {
    new AttendantPanel(attendant);
}

if (parSheet) {
    new ParSheetPanel(parSheet);
}
