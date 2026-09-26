<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Hay Link · {{ $theme['title'] }}</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="slot-body theme-{{ $theme['key'] }}">
        <div class="slot-layout">
        <aside
            class="slot-side slot-side--left"
            aria-label="Attendant panel"
            data-attendant
            data-panel-url="{{ route('attendant.panel') }}"
            data-unlock-url="{{ route('attendant.unlock') }}"
            data-lock-url="{{ route('attendant.lock') }}"
            data-settings-url="{{ route('attendant.settings') }}"
            data-meters-clear-url="{{ route('attendant.meters.clear') }}"
            data-progressive-reset-url="{{ url('attendant/progressives') }}"
        >
            @include('machine.attendant', $attendant)
        </aside>

        <main class="slot-main">
        @if (count($themes) > 1)
        <nav class="slot-machines" aria-label="Machines">
            @foreach ($themes as $key => $machine)
                <a href="{{ $key === array_key_first($themes) ? route('machine.show') : route('machine.theme', ['theme' => $key]) }}" @if ($key === $theme['key']) aria-current="page" @endif>{{ $machine['title'] }}</a>
            @endforeach
        </nav>
        @endif
        <div
            id="machine"
            class="slot-cabinet"
            data-game='@json($game)'
            data-play-url="{{ route('machine.play') }}"
            data-reset-url="{{ route('machine.reset') }}"
            data-denomination-url="{{ route('machine.denomination') }}"
            data-config-url="{{ route('machine.config') }}"
            data-image-base="{{ asset($theme['images']) }}"
            style="--door-image: url('{{ asset($theme['images'].'/door.png') }}')"
        >
            <header class="slot-topbox">
                <img class="slot-logo" src="{{ asset($theme['images'].'/logo.png') }}" alt="Hay Link">

                <div class="slot-meter slot-meter--grand">
                    <img src="{{ asset($theme['images'].'/badge-grand.png') }}" alt="" class="slot-badge">
                    <span class="slot-meter__value" data-jackpot="grand">$0.00</span>
                    <img src="{{ asset($theme['images'].'/badge-grand.png') }}" alt="" class="slot-badge">
                </div>

                <div class="slot-meter slot-meter--major">
                    <img src="{{ asset($theme['images'].'/badge-major.png') }}" alt="" class="slot-badge">
                    <span class="slot-meter__value" data-jackpot="major">$0.00</span>
                    <img src="{{ asset($theme['images'].'/badge-major.png') }}" alt="" class="slot-badge">
                </div>

                <div class="slot-meter-pair">
                    <div class="slot-meter slot-meter--minor">
                        <img src="{{ asset($theme['images'].'/badge-minor.png') }}" alt="" class="slot-badge slot-badge--small">
                        <span class="slot-meter__value" data-jackpot="minor">$0.00</span>
                    </div>
                    <div class="slot-meter slot-meter--mini">
                        <span class="slot-meter__value" data-jackpot="mini">$0.00</span>
                        <img src="{{ asset($theme['images'].'/badge-mini.png') }}" alt="" class="slot-badge slot-badge--small">
                    </div>
                </div>
            </header>

            <section class="slot-scene" style="background-image: url('{{ asset($theme['images'].'/'.$theme['scene']) }}')">
                <div class="slot-banner" data-banner hidden>
                    <div class="slot-banner__title" data-banner-title></div>
                    <div class="slot-banner__detail" data-banner-detail></div>
                </div>
                <div class="slot-feature-status" data-feature-status hidden></div>
            </section>

            <section class="slot-reels-frame">
                <div class="slot-reels" data-reels></div>
                <div class="slot-hold-grid" data-hold-grid hidden></div>
                <svg class="slot-lines" data-lines viewBox="0 0 500 300" preserveAspectRatio="none"></svg>
            </section>

            <div class="slot-message" data-message>1 credit per line</div>

            <footer class="slot-panel">
                <div class="slot-meters">
                    <div class="slot-lines-badge"><strong data-lines-count>50</strong><span>LINES</span></div>
                    <div class="slot-panel-meter">
                        <span class="slot-panel-meter__label">CREDIT</span>
                        <span class="slot-panel-meter__value" data-credits>0</span>
                        <span class="slot-panel-meter__cash" data-credits-cash>$0.00</span>
                    </div>
                    <div class="slot-panel-meter">
                        <span class="slot-panel-meter__label">BET</span>
                        <span class="slot-panel-meter__value" data-bet>0</span>
                    </div>
                    <div class="slot-panel-meter">
                        <span class="slot-panel-meter__label">WIN</span>
                        <span class="slot-panel-meter__value slot-panel-meter__value--win" data-win>0</span>
                    </div>
                    <div class="slot-denom" data-denom-label>1&cent;</div>
                </div>

                <div class="slot-buttons">
                    <div class="slot-denom-buttons" data-denom-buttons></div>
                    <div class="slot-bet-buttons" data-bet-buttons></div>
                    <div class="slot-actions">
                        <button type="button" class="slot-button slot-button--ghost" data-action="paytable">PAYS</button>
                        <button type="button" class="slot-button slot-button--ghost" data-action="sound" aria-pressed="true">SOUND</button>
                        <button type="button" class="slot-button slot-button--ghost" data-action="reset">MEMORY RESET</button>
                        <button type="button" class="slot-button slot-button--auto" data-action="auto" aria-pressed="false">AUTO</button>
                        <button type="button" class="slot-button slot-button--spin" data-action="spin">SPIN</button>
                    </div>
                </div>
            </footer>
        </div>
        </main>

        <aside
            class="slot-side slot-side--right"
            aria-label="PAR sheet"
            data-par
            data-par-url="{{ route('machine.par-sheet') }}"
            data-par-live-url="{{ route('machine.par-live') }}"
            data-simulate-url="{{ route('attendant.simulate') }}"
        >
            @include('machine.par-sheet', $par)
        </aside>
        </div>

        <dialog class="slot-paytable" data-paytable>
            <div class="slot-paytable__inner">
                <button type="button" class="slot-paytable__close" data-action="close-paytable" aria-label="Close">&times;</button>
                <h2>Pays</h2>
                <p class="slot-paytable__note" data-paytable-note></p>
                <div class="slot-paytable__grid" data-paytable-grid></div>
                <h3>Features</h3>
                <div class="slot-paytable__features" data-paytable-features></div>
                <h3>Paylines</h3>
                <div class="slot-paytable__lines" data-paytable-lines></div>
            </div>
        </dialog>

        <dialog class="slot-paytable slot-deposit" data-deposit aria-labelledby="slot-deposit-title">
            <form class="slot-paytable__inner" data-deposit-form novalidate>
                <button type="button" class="slot-paytable__close" data-action="close-deposit" aria-label="Cancel">&times;</button>
                <h2 id="slot-deposit-title">Memory Reset</h2>
                <p class="slot-paytable__note">How much money do you want to deposit? Your credits, any feature in progress and the win meter are cleared.</p>

                <div class="slot-deposit__presets" data-deposit-presets>
                    @foreach ([20, 50, 100, 500, 1000] as $amount)
                        <button type="button" class="slot-button slot-button--denom" data-deposit-preset="{{ $amount }}">${{ number_format($amount) }}</button>
                    @endforeach
                </div>

                <label class="slot-deposit__field">
                    <span>Amount</span>
                    <span class="slot-deposit__input">
                        <span aria-hidden="true">$</span>
                        <input type="number" name="deposit" inputmode="decimal" min="1" max="{{ $game['maxDepositCents'] / 100 }}" step="0.01" value="100.00" required data-deposit-input>
                    </span>
                </label>
                <p class="slot-deposit__error" data-deposit-error role="alert" hidden></p>

                <div class="slot-deposit__actions">
                    <button type="button" class="slot-button slot-button--ghost" data-action="close-deposit">CANCEL</button>
                    <button type="submit" class="slot-button slot-button--spin">DEPOSIT</button>
                </div>
            </form>
        </dialog>
    </body>
</html>
