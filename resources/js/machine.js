/**
 * Hay Link machine front end (Farmers Frenzy, Farmers Frenzy, ...).
 *
 * The server decides every outcome; this module only animates the results
 * returned by POST /machine/play and keeps the meters in sync.
 */

const FILLER_SYMBOLS = ['tractor', 'barn', 'dog', 'milk_bottle', 'king', 'queen', 'jack', 'ten', 'nine', 'farmer', 'moon', 'bale'];
const REELS = 5;
const ROWS = 3;
const CELLS = REELS * ROWS;

/*
 * While Bale Bonus windows spin, a bale flashes past in each empty window on
 * this share of ticks, scaled by the chance the next bale really lands.
 */
const BALE_TEASE_RATE = 0.25;
const BALE_TEASE_TICK_MS = 110;
const JACKPOT_LABELS ={ mini: 'MINI', minor: 'MINOR', major: 'MAJOR', grand: 'GRAND' };

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

class Sound {
    constructor() {
        this.enabled = true;
        this.context = null;
    }

    unlock() {
        if (!this.context) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            this.context = AudioContext ? new AudioContext() : null;
        }

        this.context?.resume();
    }

    tone(frequency, duration, { type = 'sine', gain = 0.08, at = 0, slideTo = null } = {}) {
        if (!this.enabled || !this.context) {
            return;
        }

        const start = this.context.currentTime + at;
        const oscillator = this.context.createOscillator();
        const volume = this.context.createGain();

        oscillator.type = type;
        oscillator.frequency.setValueAtTime(frequency, start);
        if (slideTo) {
            oscillator.frequency.exponentialRampToValueAtTime(slideTo, start + duration);
        }
        volume.gain.setValueAtTime(gain, start);
        volume.gain.exponentialRampToValueAtTime(0.0001, start + duration);

        oscillator.connect(volume).connect(this.context.destination);
        oscillator.start(start);
        oscillator.stop(start + duration + 0.02);
    }

    reelStop() {
        this.tone(140, 0.09, { type: 'triangle', gain: 0.18, slideTo: 70 });
    }

    bale() {
        this.tone(660, 0.18, { type: 'square', gain: 0.05, slideTo: 1320 });
    }

    win() {
        [523, 659, 784].forEach((frequency, index) => this.tone(frequency, 0.18, { type: 'triangle', at: index * 0.09 }));
    }

    tick() {
        this.tone(1200, 0.03, { type: 'square', gain: 0.025 });
    }

    doors() {
        this.tone(90, 0.8, { type: 'sawtooth', gain: 0.04, slideTo: 45 });
        this.tone(1400, 0.5, { type: 'sine', gain: 0.03, at: 0.6, slideTo: 2100 });
    }

    fanfare() {
        [392, 523, 659, 784, 1047].forEach((frequency, index) =>
            this.tone(frequency, 0.35, { type: 'sawtooth', gain: 0.05, at: index * 0.12 }),
        );
    }
}

class SlotMachine {
    constructor(root) {
        this.root = root;
        this.game = JSON.parse(root.dataset.game);
        this.state = this.game.state;
        this.playUrl = root.dataset.playUrl;
        this.resetUrl = root.dataset.resetUrl;
        this.denominationUrl = root.dataset.denominationUrl;
        this.configUrl = root.dataset.configUrl;
        this.configChangePending = false;
        this.imageBase = root.dataset.imageBase;
        this.csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        this.sound = new Sound();
        this.busy = false;
        this.autoplay = false;
        this.selectedCreditsPerLine = this.state.credits_per_line;
        this.winCycleTimer = null;
        this.nextPlayTimer = null;

        this.elements = {
            reels: root.querySelector('[data-reels]'),
            holdGrid: root.querySelector('[data-hold-grid]'),
            lines: root.querySelector('[data-lines]'),
            message: root.querySelector('[data-message]'),
            banner: root.querySelector('[data-banner]'),
            bannerTitle: root.querySelector('[data-banner-title]'),
            bannerDetail: root.querySelector('[data-banner-detail]'),
            featureStatus: root.querySelector('[data-feature-status]'),
            credits: root.querySelector('[data-credits]'),
            creditsCash: root.querySelector('[data-credits-cash]'),
            bet: root.querySelector('[data-bet]'),
            win: root.querySelector('[data-win]'),
            linesCount: root.querySelector('[data-lines-count]'),
            betButtons: root.querySelector('[data-bet-buttons]'),
            denominationButtons: root.querySelector('[data-denom-buttons]'),
            denominationLabel: root.querySelector('[data-denom-label]'),
            paytable: document.querySelector('[data-paytable]'),
            deposit: document.querySelector('[data-deposit]'),
            depositInput: document.querySelector('[data-deposit-input]'),
            depositError: document.querySelector('[data-deposit-error]'),
            jackpots: Object.fromEntries(
                [...root.querySelectorAll('[data-jackpot]')].map((element) => [element.dataset.jackpot, element]),
            ),
        };

        this.buttons = Object.fromEntries(
            [...document.querySelectorAll('[data-action]')].map((button) => [button.dataset.action, button]),
        );

        this.buildReels();
        this.buildDenominationButtons();
        this.buildBetButtons();
        this.bindControls();
        this.renderGrid(this.state.grid, this.state.bales);
        this.updateMeters();
        this.updateFeatureStatus();
        this.updateControls();
        this.applyAutoplayPermission();
        this.resumeFeatureInProgress();

        window.addEventListener('machine:config-changed', () => this.reloadConfig());
    }

    /* ---------------------------------------------------------------
     | Setup
     * --------------------------------------------------------------- */

    image(name) {
        return `${this.imageBase}/${name}.png`;
    }

    /**
     * The theme's display name for a symbol, e.g. "Tractor" or "Farmer".
     */
    symbolName(symbol) {
        return this.game.symbolNames?.[symbol] ?? symbol;
    }

    /**
     * The theme's plural for a symbol, e.g. "Hay Bales" or "Cowgirls".
     */
    symbolPlural(symbol) {
        return this.game.symbolPlurals?.[symbol] ?? `${this.symbolName(symbol)}s`;
    }

    buildReels() {
        this.reelElements = [];

        for (let reel = 0; reel < REELS; reel++) {
            const reelElement = document.createElement('div');
            reelElement.className = 'slot-reel';
            const strip = document.createElement('div');
            strip.className = 'slot-reel__strip';
            reelElement.append(strip);
            this.elements.reels.append(reelElement);
            this.reelElements.push({ reel: reelElement, strip });
        }
    }

    buildBetButtons() {
        this.betButtons = this.game.creditsPerLineOptions.map((creditsPerLine) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'slot-button slot-button--bet';
            button.addEventListener('click', () => this.selectBet(creditsPerLine));
            this.elements.betButtons.append(button);

            return { creditsPerLine, button };
        });
    }

    /**
     * Bet buttons show the total bet, which depends on how many lines the denomination plays.
     */
    refreshBetButtonLabels() {
        for (const { creditsPerLine, button } of this.betButtons) {
            button.innerHTML = `${creditsPerLine * this.state.lines}<small>${creditsPerLine} per line</small>`;
        }
    }

    buildDenominationButtons() {
        this.denominationButtons = Object.entries(this.game.denominations).map(([cents, lines]) => {
            const denomination = Number(cents);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'slot-button slot-button--denom';
            button.innerHTML = `${this.denominationLabel(denomination)}<small>${lines} lines</small>`;
            button.addEventListener('click', () => this.selectDenomination(denomination));
            this.elements.denominationButtons.append(button);

            return { denomination, button };
        });
    }

    denominationLabel(cents) {
        return cents < 100 ? `${cents}¢` : `$${cents / 100}`;
    }

    async selectDenomination(denomination) {
        if (this.busy || this.inFeature() || denomination === this.state.denomination) {
            return;
        }

        this.busy = true;
        this.updateControls();

        try {
            const { state } = await this.post(this.denominationUrl, { denomination });
            this.state = state;
            this.selectedCreditsPerLine = state.credits_per_line;
            this.clearWinDisplay();
            this.renderGrid(state.grid, state.bales);
            this.updateMeters();
            this.setMessage(`${this.denominationLabel(state.denomination)} · ${state.lines} LINES`);
        } catch (error) {
            this.setMessage(error.message);
        }

        this.busy = false;
        this.updateControls();
    }

    bindControls() {
        this.buttons.spin.addEventListener('click', () => this.spin());
        this.buttons.auto.addEventListener('click', () => this.toggleAutoplay());
        this.buttons.reset.addEventListener('click', () => this.openDepositDialog());
        this.elements.deposit.querySelectorAll('[data-action="close-deposit"]').forEach((button) => button.addEventListener('click', () => this.closeDepositDialog()));
        this.elements.deposit.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.closeDepositDialog();
        });
        this.elements.deposit.querySelectorAll('[data-deposit-preset]').forEach((button) =>
            button.addEventListener('click', () => {
                this.elements.depositInput.value = Number(button.dataset.depositPreset).toFixed(2);
                this.showDepositError(null);
            }),
        );
        this.elements.deposit.querySelector('[data-deposit-form]').addEventListener('submit', (event) => {
            event.preventDefault();
            this.memoryReset(Number(this.elements.depositInput.value));
        });
        this.buttons.paytable.addEventListener('click', () => this.openPaytable());
        this.buttons['close-paytable'].addEventListener('click', () => this.elements.paytable.close());
        this.buttons.sound.addEventListener('click', () => {
            this.sound.enabled = !this.sound.enabled;
            this.buttons.sound.setAttribute('aria-pressed', String(this.sound.enabled));
            this.buttons.sound.textContent = this.sound.enabled ? 'SOUND' : 'MUTED';
        });

        document.addEventListener('keydown', (event) => {
            if (event.code === 'Space' && !this.elements.paytable.open && !this.elements.deposit.open && event.target.tagName !== 'BUTTON') {
                event.preventDefault();
                this.spin();
            }
        });

        document.addEventListener('pointerdown', () => this.sound.unlock(), { once: true });
        document.addEventListener('keydown', () => this.sound.unlock(), { once: true });
    }

    resumeFeatureInProgress() {
        if (this.state.bale_bonus) {
            this.enterBaleBonus(this.state.bale_bonus.cells);
            this.scheduleNextPlay(1500);
        } else if (this.state.free_games) {
            this.scheduleNextPlay(1500);
        }
    }

    /* ---------------------------------------------------------------
     | Formatting
     * --------------------------------------------------------------- */

    credits(amount) {
        return Math.floor(amount).toLocaleString('en-US');
    }

    money(credits) {
        const dollars = Math.floor(credits * this.state.denomination) / 100;

        return '$' + dollars.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /* ---------------------------------------------------------------
     | Meters and controls
     * --------------------------------------------------------------- */

    updateMeters({ credits = this.state.credits, win = this.state.last_win } = {}) {
        this.elements.credits.textContent = this.credits(credits);
        this.elements.creditsCash.textContent = this.money(credits);
        this.elements.bet.textContent = this.credits(this.state.total_bet);
        this.elements.win.textContent = this.credits(win);
        this.elements.linesCount.textContent = this.state.lines;
        this.elements.denominationLabel.textContent = this.denominationLabel(this.state.denomination);
        this.refreshBetButtonLabels();

        for (const [tier, element] of Object.entries(this.elements.jackpots)) {
            element.textContent = this.money(this.state.jackpots[tier]);
        }
    }

    inFeature() {
        return Boolean(this.state.free_games || this.state.bale_bonus);
    }

    updateControls() {
        const locked = this.busy || this.inFeature();

        this.buttons.spin.disabled = locked;
        this.buttons.reset.disabled = this.busy;

        for (const { creditsPerLine, button } of this.betButtons) {
            button.disabled = locked;
            button.setAttribute('aria-pressed', String(creditsPerLine === this.selectedCreditsPerLine));
        }

        for (const { denomination, button } of this.denominationButtons) {
            button.disabled = locked;
            button.setAttribute('aria-pressed', String(denomination === this.state.denomination));
        }
    }

    updateFeatureStatus() {
        const status = this.elements.featureStatus;

        if (this.state.bale_bonus) {
            status.textContent = `SPINS REMAINING: ${this.state.bale_bonus.respins_left}`;
        } else if (this.state.free_games) {
            const { played, total, win } = this.state.free_games;
            status.textContent = `FREE GAME ${Math.min(played + 1, total)} OF ${total} · WIN ${this.credits(win)}`;
        }

        status.hidden = !this.inFeature();
    }

    selectBet(creditsPerLine) {
        if (this.busy || this.inFeature()) {
            return;
        }

        this.selectedCreditsPerLine = creditsPerLine;
        const multiplier = creditsPerLine / this.state.credits_per_line;

        this.state.credits_per_line = creditsPerLine;
        this.state.total_bet = creditsPerLine * this.state.lines;
        this.state.jackpots.mini = this.game.jackpotMultipliers.mini * this.state.total_bet;
        this.state.jackpots.minor = this.game.jackpotMultipliers.minor * this.state.total_bet;
        this.state.bales = this.state.bales.map((bale) =>
            bale.type === 'credits' ? { ...bale, amount: Math.round(bale.amount * multiplier) } : bale,
        );

        this.setMessage(`${creditsPerLine} credit${creditsPerLine > 1 ? 's' : ''} per line`);
        this.renderGrid(this.state.grid, this.state.bales);
        this.updateMeters();
        this.updateControls();
    }

    setMessage(text) {
        this.elements.message.textContent = text;
    }

    toggleAutoplay() {
        if (!this.game.autoplayEnabled) {
            return;
        }

        this.sound.unlock();
        this.autoplay = !this.autoplay;
        this.buttons.auto.setAttribute('aria-pressed', String(this.autoplay));

        if (this.autoplay && !this.busy && !this.inFeature()) {
            this.spin();
        }
    }

    stopAutoplay() {
        this.autoplay = false;
        this.buttons.auto.setAttribute('aria-pressed', 'false');
    }

    async showBanner(title, detail = '', duration = 2200) {
        this.elements.bannerTitle.textContent = title;
        this.elements.bannerDetail.textContent = detail;
        this.elements.banner.hidden = false;
        await delay(duration);
        this.elements.banner.hidden = true;
    }

    /* ---------------------------------------------------------------
     | Server
     * --------------------------------------------------------------- */

    async post(url, body = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
            },
            body: JSON.stringify(body),
        });

        if (response.status === 419) {
            window.location.reload();
        }

        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message || 'The machine is unavailable.');
        }

        return payload;
    }

    openDepositDialog() {
        if (this.busy) {
            return;
        }

        this.stopAutoplay();
        clearTimeout(this.nextPlayTimer);
        this.showDepositError(null);
        this.elements.depositInput.value = '100.00';
        this.elements.deposit.showModal();
        this.elements.depositInput.select();
    }

    closeDepositDialog() {
        this.elements.deposit.close();
        this.scheduleNextPlay();
    }

    showDepositError(message) {
        this.elements.depositError.textContent = message ?? '';
        this.elements.depositError.hidden = !message;
    }

    async memoryReset(deposit) {
        if (this.busy) {
            return;
        }

        const maxDeposit = this.game.maxDepositCents / 100;

        if (!Number.isFinite(deposit) || deposit < 1 || deposit > maxDeposit) {
            this.showDepositError(`Enter an amount between $1.00 and ${this.money(this.game.maxDepositCents / this.state.denomination)}.`);
            return;
        }

        this.busy = true;
        this.updateControls();

        let state;

        try {
            ({ state } = await this.post(this.resetUrl, { deposit: Math.round(deposit * 100) / 100 }));
        } catch (error) {
            this.showDepositError(error.message);
            this.busy = false;
            this.updateControls();
            return;
        }

        this.elements.deposit.close();
        this.clearWinDisplay();
        this.state = state;
        this.selectedCreditsPerLine = state.credits_per_line;
        this.exitBaleBonus();
        this.renderGrid(state.grid, state.bales);
        this.updateMeters();
        this.updateFeatureStatus();
        this.busy = false;
        this.updateControls();
        this.setMessage(`DEPOSITED ${this.money(state.credits)} · ${this.credits(state.credits)} CREDITS`);
    }

    /* ---------------------------------------------------------------
     | Play loop
     * --------------------------------------------------------------- */

    async spin() {
        if (this.busy) {
            return;
        }

        this.sound.unlock();

        if (!this.inFeature() && this.state.credits < this.selectedCreditsPerLine * this.state.lines) {
            this.stopAutoplay();
            this.setMessage('Insufficient credits — press MEMORY RESET');
            return;
        }

        this.busy = true;
        clearTimeout(this.nextPlayTimer);
        this.clearWinDisplay();
        this.updateControls();

        let response;

        try {
            response = await this.post(this.playUrl, { credits_per_line: this.selectedCreditsPerLine });
        } catch (error) {
            this.stopAutoplay();
            this.setMessage(error.message);
            this.busy = false;
            this.updateControls();
            return;
        }

        const { outcome, state } = response;

        if (outcome.mode === 'bale_bonus') {
            await this.playRespin(outcome, state);
        } else {
            await this.playReels(outcome, state);
        }

        this.state = state;
        this.updateMeters();
        this.updateFeatureStatus();
        this.busy = false;
        this.updateControls();
        window.dispatchEvent(new CustomEvent('machine:played'));

        if (this.configChangePending && !this.inFeature()) {
            await this.reloadConfig();
        }

        this.scheduleNextPlay();
    }

    /**
     * The attendant changed the machine settings: fetch the new configuration
     * and rebuild the denomination and bet buttons. Waits until the current
     * game and any feature have finished.
     */
    async reloadConfig() {
        if (this.busy || this.inFeature()) {
            this.configChangePending = true;
            return;
        }

        this.configChangePending = false;

        const response = await fetch(this.configUrl, { headers: { Accept: 'application/json' } });
        const { game } = await response.json();

        this.game = game;
        this.state = game.state;
        this.selectedCreditsPerLine = game.state.credits_per_line;

        this.elements.denominationButtons.replaceChildren();
        this.elements.betButtons.replaceChildren();
        this.buildDenominationButtons();
        this.buildBetButtons();
        this.applyAutoplayPermission();
        this.renderGrid(this.state.grid, this.state.bales);
        this.updateMeters();
        this.updateControls();
    }

    applyAutoplayPermission() {
        this.buttons.auto.hidden = !this.game.autoplayEnabled;

        if (!this.game.autoplayEnabled) {
            this.stopAutoplay();
        }
    }

    scheduleNextPlay(wait = null) {
        clearTimeout(this.nextPlayTimer);

        if (this.state.bale_bonus) {
            this.nextPlayTimer = setTimeout(() => this.spin(), wait ?? 700);
        } else if (this.state.free_games) {
            this.nextPlayTimer = setTimeout(() => this.spin(), wait ?? 1400);
        } else if (this.autoplay) {
            this.nextPlayTimer = setTimeout(() => this.spin(), wait ?? (this.state.last_win > 0 ? 1800 : 500));
        }
    }

    async playReels(outcome, state) {
        const isFreeGame = outcome.mode === 'free';
        const creditsBeforeWin = state.credits - outcome.win;
        const winBefore = state.last_win - outcome.win;

        this.state = { ...this.state, credits_per_line: state.credits_per_line, total_bet: state.total_bet, jackpots: state.jackpots };
        this.updateMeters({ credits: creditsBeforeWin, win: winBefore });
        this.setMessage(isFreeGame ? 'FREE GAME' : 'GOOD LUCK!');

        await this.animateSpin(outcome.grid, outcome.bales, outcome.doors);
        await this.openDoors(outcome.doors);

        if (outcome.win > 0) {
            await this.presentWins(outcome, creditsBeforeWin, winBefore);
        } else {
            this.setMessage(isFreeGame ? 'FREE GAME' : `${state.credits_per_line} credit${state.credits_per_line > 1 ? 's' : ''} per line`);
        }

        if (outcome.triggered.bale_bonus) {
            this.stopWinCycle();
            this.sound.fanfare();
            this.highlightBales(outcome.bales);
            await this.showBanner('BALE BONUS', `${outcome.bales.length} ${this.symbolPlural('bale').toUpperCase()} · ${this.game.baleBonus.respins} SPINS`, 2600);
            this.enterBaleBonus(state.bale_bonus.cells);
        }

        if (outcome.triggered.free_games > 0) {
            this.sound.fanfare();
            const retrigger = isFreeGame;
            await this.showBanner(
                retrigger ? 'RETRIGGER!' : `${outcome.triggered.free_games} FREE GAMES`,
                retrigger ? `+${outcome.triggered.free_games} FREE GAMES` : 'BONUS REELS IN PLAY',
                2600,
            );
        }

        if (outcome.free_games_completed !== null) {
            await this.finishFreeGames(outcome.free_games_completed);
        }
    }

    async finishFreeGames(total) {
        this.sound.fanfare();
        await this.showBanner('FREE GAMES COMPLETE', `TOTAL WIN ${this.credits(total)} CREDITS`, 3000);
        this.setMessage(`FREE GAMES PAID ${this.credits(total)}`);
    }

    /* ---------------------------------------------------------------
     | Reels
     * --------------------------------------------------------------- */

    baleAt(bales, reel, row) {
        return bales.find((bale) => bale.reel === reel && bale.row === row) ?? null;
    }

    /**
     * What a bale shows: at 1¢ to 50¢ the plain credit amount with no
     * separators (2000 at 10¢ is $20); at $1 and up the dollar amount ($20).
     */
    baleAmount(credits) {
        if (this.state.denomination < 100) {
            return String(Math.floor(credits));
        }

        const dollars = (credits * this.state.denomination) / 100;

        return `$${Number.isInteger(dollars) ? dollars : dollars.toFixed(2)}`;
    }

    baleLabel(value) {
        if (value.type === 'credits') {
            return `<span class="slot-bale-value">${this.baleAmount(value.amount)}</span>`;
        }

        const amount = value.amount > 0 ? `<small>${this.baleAmount(value.amount)}</small>` : '';

        return `<span class="slot-bale-value slot-bale-value--jackpot slot-bale-value--${value.type}">${JACKPOT_LABELS[value.type]}${amount}</span>`;
    }

    createCell(symbol, bale = null) {
        const cell = document.createElement('div');
        cell.className = 'slot-cell';
        cell.dataset.symbol = symbol;
        cell.innerHTML = `<img src="${this.image(symbol)}" alt="${symbol}" draggable="false">`;

        if (symbol === 'bale') {
            cell.insertAdjacentHTML(
                'beforeend',
                this.baleLabel(bale ?? { type: 'credits', amount: this.state.total_bet * (1 + Math.floor(Math.random() * 5)) }),
            );
        }

        return cell;
    }

    renderGrid(grid, bales = []) {
        this.reelElements.forEach(({ strip }, reel) => {
            strip.style.transition = 'none';
            strip.style.transform = 'translateY(0)';
            strip.replaceChildren(...grid[reel].map((symbol, row) => this.createCell(symbol, this.baleAt(bales, reel, row))));
        });
    }

    randomSymbol(includeDoors = false) {
        if (includeDoors && Math.random() < 0.15) {
            return 'door';
        }

        return FILLER_SYMBOLS[Math.floor(Math.random() * FILLER_SYMBOLS.length)];
    }

    /**
     * A closed free games door covering a cell. Its two halves slide apart to reveal the symbol underneath.
     */
    createDoor() {
        const door = document.createElement('div');
        door.className = 'slot-door';
        door.innerHTML = '<span class="slot-door__half slot-door__half--left"></span><span class="slot-door__half slot-door__half--right"></span>';

        return door;
    }

    createClosedDoorCell() {
        const cell = document.createElement('div');
        cell.className = 'slot-cell';
        cell.append(this.createDoor());

        return cell;
    }

    /**
     * Spin the reels to the given screen. Door positions land closed over the
     * revealed symbol; call openDoors() afterwards to show what is behind them.
     */
    animateSpin(grid, bales, doors = null) {
        const isDoor = (reel, row) => doors?.positions.some(([doorReel, doorRow]) => doorReel === reel && doorRow === row) ?? false;
        const includeDoors = Boolean(this.state.free_games);

        return Promise.all(
            this.reelElements.map(({ reel: reelElement, strip }, reel) => {
                const current = [...strip.children];
                const fillerCount = 12 + reel * 4;
                const finalCells = grid[reel].map((symbol, row) => {
                    const cell = this.createCell(symbol, this.baleAt(bales, reel, row));

                    if (isDoor(reel, row)) {
                        cell.append(this.createDoor());
                    }

                    return cell;
                });
                const fillerCells = Array.from({ length: fillerCount }, () => {
                    const symbol = this.randomSymbol(includeDoors);

                    return symbol === 'door' ? this.createClosedDoorCell() : this.createCell(symbol);
                });

                strip.style.transition = 'none';
                strip.replaceChildren(...finalCells, ...fillerCells, ...current);

                const cellHeight = reelElement.clientHeight / ROWS;
                const distance = (strip.children.length - ROWS) * cellHeight;
                strip.style.transform = `translateY(${-distance}px)`;
                reelElement.classList.add('is-spinning');
                strip.getBoundingClientRect();

                const duration = 650 + reel * 230;
                strip.style.transition = `transform ${duration}ms cubic-bezier(0.3, 0.05, 0.35, 1.06)`;
                strip.style.transform = 'translateY(0)';

                return new Promise((resolve) => {
                    setTimeout(() => {
                        reelElement.classList.remove('is-spinning');
                        strip.style.transition = 'none';
                        strip.replaceChildren(...finalCells);
                        this.sound.reelStop();

                        if (grid[reel].some((symbol, row) => symbol === 'bale' && !isDoor(reel, row))) {
                            this.sound.bale();
                        }

                        resolve();
                    }, duration);
                });
            }),
        );
    }

    /**
     * Slide every closed door open at once to reveal the shared symbol.
     */
    async openDoors(doors) {
        const overlays = [...this.elements.reels.querySelectorAll('.slot-door')];

        if (!doors || overlays.length === 0) {
            return;
        }

        this.setMessage(`THE ${this.symbolPlural('door').toUpperCase()} ARE OPENING…`);
        await delay(500);
        this.sound.doors();
        overlays.forEach((door) => door.classList.add('is-open'));
        await delay(900);

        if (doors.symbol === 'bale') {
            this.sound.bale();
        }

        overlays.forEach((door) => door.remove());
        const revealed = doors.symbol === 'farmer' ? 'WILDS' : (this.game.symbolPlurals?.[doors.symbol] ?? this.symbolName(doors.symbol));
        this.setMessage(`${this.symbolPlural('door').toUpperCase()} REVEAL ${revealed.toUpperCase()}`);
        await delay(400);
    }

    cellElement(reel, row) {
        return this.reelElements[reel].strip.children[row];
    }

    highlightBales(bales) {
        this.forEachCell((cell) => cell.classList.add('is-dimmed'));

        for (const { reel, row } of bales) {
            const cell = this.cellElement(reel, row);
            cell.classList.remove('is-dimmed');
            cell.classList.add('is-winning');
        }
    }

    forEachCell(callback) {
        for (let reel = 0; reel < REELS; reel++) {
            for (let row = 0; row < ROWS; row++) {
                const cell = this.cellElement(reel, row);

                if (cell) {
                    callback(cell, reel, row);
                }
            }
        }
    }

    /* ---------------------------------------------------------------
     | Wins
     * --------------------------------------------------------------- */

    lineColor(line) {
        return `hsl(${(line * 47) % 360} 95% 60%)`;
    }

    drawLines(lineNumbers) {
        const svg = this.elements.lines;
        svg.replaceChildren();

        for (const line of lineNumbers) {
            const rows = this.game.paylines[line - 1];
            const points = rows.map((row, reel) => `${reel * 100 + 50},${row * 100 + 50}`);
            const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
            polyline.setAttribute('points', [`0,${rows[0] * 100 + 50}`, ...points, `500,${rows[4] * 100 + 50}`].join(' '));
            polyline.setAttribute('stroke', this.lineColor(line));
            svg.append(polyline);
        }
    }

    highlightPositions(positions) {
        const winning = new Set(positions.map(([reel, row]) => `${reel}:${row}`));

        this.forEachCell((cell, reel, row) => {
            const isWinning = winning.has(`${reel}:${row}`);
            cell.classList.toggle('is-winning', isWinning);
            cell.classList.toggle('is-dimmed', !isWinning);
        });
    }

    clearWinDisplay() {
        this.stopWinCycle();
        this.elements.lines.replaceChildren();
        this.forEachCell((cell) => cell.classList.remove('is-winning', 'is-dimmed'));

        for (const element of Object.values(this.elements.jackpots)) {
            element.closest('.slot-meter').classList.remove('is-won');
        }
    }

    stopWinCycle() {
        clearInterval(this.winCycleTimer);
        this.winCycleTimer = null;
    }

    async presentWins(outcome, creditsBeforeWin, winBefore) {
        const wins = [...outcome.line_wins];

        if (outcome.scatter.win > 0) {
            wins.push({ line: null, symbol: 'moon', count: outcome.scatter.count, positions: outcome.scatter.positions, win: outcome.scatter.win });
        }

        this.drawLines(outcome.line_wins.map((win) => win.line));
        this.highlightPositions(wins.flatMap((win) => win.positions));
        this.setMessage(`WIN ${this.credits(outcome.win)}`);
        this.sound.win();

        await this.countUp(outcome.win, (paid) => {
            this.updateMeters({ credits: creditsBeforeWin + paid, win: winBefore + paid });
        });

        let index = 0;
        const showWin = () => {
            const win = wins[index % wins.length];
            this.drawLines(win.line ? [win.line] : []);
            this.highlightPositions(win.positions);
            this.setMessage(
                win.line
                    ? `LINE ${win.line} · ${win.count} × ${this.symbolName(win.symbol).toUpperCase()} PAYS ${this.credits(win.win)}`
                    : `${win.count} SCATTERED ${this.symbolPlural('moon').toUpperCase()} PAY ${this.credits(win.win)}`,
            );
            index++;
        };

        if (wins.length > 0) {
            await delay(500);
            showWin();
            this.winCycleTimer = setInterval(showWin, 1400);
        }
    }

    countUp(amount, onStep) {
        const totalBet = this.state.total_bet;
        const duration = Math.min(3000, 250 + (amount / totalBet) * 120);
        const started = performance.now();
        let lastTick = 0;

        return new Promise((resolve) => {
            const step = (now) => {
                const progress = Math.min(1, (now - started) / duration);
                onStep(Math.round(amount * progress));

                if (now - lastTick > 70) {
                    this.sound.tick();
                    lastTick = now;
                }

                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    resolve();
                }
            };

            requestAnimationFrame(step);
        });
    }

    /* ---------------------------------------------------------------
     | Bale Bonus
     * --------------------------------------------------------------- */

    enterBaleBonus(cells) {
        this.clearWinDisplay();
        this.elements.reels.hidden = true;
        this.elements.holdGrid.hidden = false;
        this.holdCells = [];
        this.elements.holdGrid.replaceChildren();

        cells.forEach((value, index) => {
            const element = document.createElement('div');
            element.className = 'slot-hold-cell';
            element.style.gridColumn = String(Math.floor(index / ROWS) + 1);
            element.style.gridRow = String((index % ROWS) + 1);
            this.renderHoldCell(element, value);
            this.elements.holdGrid.append(element);
            this.holdCells.push(element);
        });

        this.setMessage(`${this.symbolPlural('bale').toUpperCase()} HOLD · EACH NEW ONE RESETS SPINS`);
    }

    renderHoldCell(element, value) {
        element.innerHTML = value ? `<img src="${this.image('bale')}" alt="bale" draggable="false">${this.baleLabel(value)}` : '';
    }

    exitBaleBonus() {
        this.elements.holdGrid.hidden = true;
        this.elements.reels.hidden = false;
    }

    async playRespin(outcome, state) {
        const empty = this.holdCells.map((element, index) => (element.childElementCount === 0 ? index : null)).filter((index) => index !== null);

        empty.forEach((index) => this.holdCells[index].classList.add('is-spinning'));
        this.elements.featureStatus.textContent = 'SPINNING…';
        const stopTeasing = this.teaseBales(empty, this.landingChance(CELLS - empty.length + 1));
        await delay(1000);

        for (const [order, index] of empty.entries()) {
            await delay(Math.max(25, 90 - order * 4));
            const element = this.holdCells[index];
            stopTeasing(index);
            element.classList.remove('is-spinning');

            if (outcome.landed.includes(index)) {
                this.renderHoldCell(element, outcome.cells[index]);
                element.classList.add('is-landed');
                this.sound.bale();
            }
        }

        this.state = { ...this.state, bale_bonus: state.bale_bonus ?? { respins_left: 0 } };
        this.elements.featureStatus.textContent = `SPINS REMAINING: ${outcome.respins_left}`;
        if (!outcome.finished) {
            this.updateMeters({ credits: state.credits, win: state.last_win });
        }

        if (outcome.landed.length > 0) {
            this.setMessage(`${outcome.landed.length} NEW ${(outcome.landed.length > 1 ? this.symbolPlural('bale') : this.symbolName('bale')).toUpperCase()} · SPINS RESET TO ${this.game.baleBonus.respins}`);
        }

        if (outcome.finished) {
            await this.collectBaleBonus(outcome, state);
        }
    }

    /**
     * The chance that the Nth bale on screen lands on a respin (mirrors the server).
     */
    landingChance(nthBale) {
        const chances = this.game.baleBonus.landing_chances ?? {};
        const counts = Object.keys(chances).map(Number);

        if (counts.length === 0) {
            return 0;
        }

        return chances[nthBale] ?? (nthBale < Math.min(...counts) ? chances[Math.min(...counts)] : 0);
    }

    /**
     * Flash bales at random in the spinning empty windows: mostly blanks, with a
     * bale showing for a moment as often as the odds of one landing allow.
     * Returns a function that stops (and clears) one window.
     */
    teaseBales(indexes, landingChance) {
        const spinning = new Set(indexes);
        const chance = landingChance * BALE_TEASE_RATE;

        if (spinning.size === 0 || chance <= 0) {
            return (index) => spinning.delete(index);
        }

        const clear = (index) => this.holdCells[index].querySelector('.slot-hold-teaser')?.remove();

        const timer = setInterval(() => {
            spinning.forEach((index) => {
                clear(index);

                if (Math.random() < chance) {
                    const value = { type: 'credits', amount: this.state.total_bet * (1 + Math.floor(Math.random() * 5)) };
                    this.holdCells[index].insertAdjacentHTML(
                        'afterbegin',
                        `<div class="slot-hold-teaser"><img src="${this.image('bale')}" alt="" draggable="false">${this.baleLabel(value)}</div>`,
                    );
                }
            });
        }, BALE_TEASE_TICK_MS);

        return (index) => {
            clear(index);
            spinning.delete(index);

            if (spinning.size === 0) {
                clearInterval(timer);
            }
        };
    }

    async collectBaleBonus(outcome, state) {
        await delay(700);
        this.setMessage('COLLECTING PRIZES');

        const creditsBefore = state.credits - outcome.win;
        const winBefore = state.last_win - outcome.win;
        let paid = 0;

        if (outcome.values_replaced_by_grand) {
            this.setMessage(`ALL 15 POSITIONS FILLED · GRAND JACKPOT REPLACES ${this.symbolName('bale').toUpperCase()} VALUES`);
            this.holdCells.forEach((element) => element.classList.add('is-collecting'));
            await delay(1200);
        }

        for (const [index, value] of outcome.cells.entries()) {
            if (!value || outcome.values_replaced_by_grand) {
                continue;
            }

            const element = this.holdCells[index];
            this.renderHoldCell(element, value);
            element.classList.remove('is-collecting');
            element.getBoundingClientRect();
            element.classList.add('is-collecting');

            if (value.type !== 'credits') {
                this.flashJackpot(value.type);
                this.sound.fanfare();
                await this.showBanner(`${JACKPOT_LABELS[value.type]} JACKPOT`, `${this.credits(value.amount)} CREDITS`, 2000);
            } else {
                this.sound.tick();
            }

            paid += value.amount;
            this.updateMeters({ credits: creditsBefore + paid, win: winBefore + paid });
            await delay(220);
        }

        if (outcome.grand_won) {
            this.flashJackpot('grand');
            this.sound.fanfare();
            await this.showBanner('GRAND JACKPOT!', `${this.credits(outcome.grand)} CREDITS`, 4000);
            paid += outcome.grand;
            this.updateMeters({ credits: creditsBefore + paid, win: winBefore + paid });
        }

        this.sound.win();
        await this.showBanner('BALE BONUS WIN', `${this.credits(outcome.win)} CREDITS`, 2600);
        this.exitBaleBonus();
        this.renderGrid(state.grid, state.bales);
        this.setMessage(`BALE BONUS PAID ${this.credits(outcome.win)}`);

        if (outcome.free_games_completed !== null) {
            await this.finishFreeGames(outcome.free_games_completed);
        }
    }

    flashJackpot(tier) {
        this.elements.jackpots[tier]?.closest('.slot-meter').classList.add('is-won');
    }

    /* ---------------------------------------------------------------
     | Paytable
     * --------------------------------------------------------------- */

    openPaytable() {
        const creditsPerLine = this.selectedCreditsPerLine;
        const totalBet = creditsPerLine * this.state.lines;
        const dialog = this.elements.paytable;

        dialog.querySelector('[data-paytable-note]').textContent =
            `Pays shown in credits at ${creditsPerLine} credit${creditsPerLine > 1 ? 's' : ''} per line (${totalBet} total bet) on ${this.state.lines} lines at ${this.denominationLabel(this.state.denomination)}. Line wins pay left to right on adjacent reels.`;

        dialog.querySelector('[data-paytable-grid]').innerHTML = Object.entries(this.game.paytable)
            .map(
                ([symbol, pays]) => `
                <div class="slot-pay">
                    <img src="${this.image(symbol)}" alt="${this.symbolName(symbol)}">
                    <dl>${Object.entries(pays)
                        .reverse()
                        .map(([count, pay]) => `<dt>${count}</dt><dd>${this.credits(pay * creditsPerLine)}</dd>`)
                        .join('')}</dl>
                </div>`,
            )
            .join('');

        const scatterPays = Object.entries(this.game.scatterPays)
            .reverse()
            .map(([count, multiplier]) => `${count} pay ${this.credits(multiplier * totalBet)}`)
            .join(', ');

        const wild = this.symbolName('farmer');
        const scatters = this.symbolPlural('moon');
        const balls = this.symbolPlural('bale').toLowerCase();
        const doors = this.symbolPlural('door').toLowerCase();

        dialog.querySelector('[data-paytable-features]').innerHTML = `
            <div class="slot-feature"><img src="${this.image('farmer')}" alt="${wild}">
                <div><strong>WILD</strong> — The stacked ${wild} appears on reels 2 to 5 and substitutes for all symbols except ${scatters} and ${balls}.</div></div>
            <div class="slot-feature"><img src="${this.image('moon')}" alt="${this.symbolName('moon')}">
                <div><strong>SCATTER</strong> — ${this.game.freeGames.trigger_count} or more ${scatters} anywhere start
                ${this.game.freeGames.awarded} free games on the bonus reels. Free games can be retriggered for
                ${this.game.freeGames.retrigger_awarded} more. Scatters: ${scatterPays}.</div></div>
            <div class="slot-feature"><img src="${this.image('bale')}" alt="${this.symbolName('bale')}">
                <div><strong>BALE BONUS</strong> — ${this.game.baleBonus.trigger_count} or more ${balls} start the feature
                with ${this.game.baleBonus.respins} spins. They hold in place and every new one resets the spins.
                They pay credits or the MINI (${this.credits(this.game.jackpotMultipliers.mini * totalBet)}),
                MINOR (${this.credits(this.game.jackpotMultipliers.minor * totalBet)}) or MAJOR jackpot. Higher bets land
                more Minis and a better chance at the Major. Fill all 15 positions to win the GRAND.</div></div>
            <div class="slot-feature"><img src="${this.image('door')}" alt="${this.symbolName('door')}">
                <div><strong>BONUS REELS</strong> — During free games, ${doors} can land on any reel. After the spin they all
                slide open to reveal the same symbol: ${balls}, ${wild} wilds or any paying symbol.</div></div>`;

        dialog.querySelector('[data-paytable-lines]').innerHTML = this.game.paylines
            .slice(0, this.state.lines)
            .map(
                (rows, index) => `
                <div class="slot-line-diagram">
                    <svg viewBox="0 0 50 30">${rows
                        .map((row, reel) =>
                            [0, 1, 2]
                                .map((cellRow) => `<rect x="${reel * 10 + 1}" y="${cellRow * 10 + 1}" width="8" height="8" rx="1" fill="${cellRow === row ? this.lineColor(index + 1) : '#2c2552'}"/>`)
                                .join(''),
                        )
                        .join('')}</svg>
                    <span>${index + 1}</span>
                </div>`,
            )
            .join('');

        dialog.showModal();
    }
}

const root = document.getElementById('machine');

if (root) {
    new SlotMachine(root);
}
