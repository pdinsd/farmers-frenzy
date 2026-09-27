<?php

namespace App\Http\Controllers;

use App\Games\HayLink\GameState;
use App\Games\HayLink\HayLinkGame;
use App\Games\HayLink\MachineConfig;
use App\Games\HayLink\MachineMeters;
use App\Games\HayLink\ParSheet;
use App\Http\Requests\ChangeDenominationRequest;
use App\Http\Requests\PlayRequest;
use App\Http\Requests\ResetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class MachineController extends Controller
{
    public function __construct(
        private readonly HayLinkGame $game,
        private readonly MachineConfig $machineConfig,
        private readonly MachineMeters $meters,
    ) {}

    /**
     * Show the machine.
     */
    public function show(Request $request, ParSheet $parSheet): View
    {
        $theme = self::theme($request);
        URL::defaults(['theme' => $theme['key']]);

        return view('machine', [
            'theme' => $theme,
            'themes' => config('themes'),
            'game' => $this->clientConfig($request),
            'attendant' => AttendantController::panelData($request, $this->machineConfig, $this->meters),
            'par' => AttendantController::parData($parSheet, $this->machineConfig, $theme),
        ]);
    }

    /**
     * The theme (artwork and symbol names) for this request's machine.
     *
     * @return array{key: string, title: string, images: string, scene: string, session_key: string, names: array<string, string>, plurals: array<string, string>}
     */
    public static function theme(Request $request): array
    {
        $key = $request->route('theme') ?? array_key_first(config('themes'));

        return ['key' => $key, ...config("themes.{$key}")];
    }

    /**
     * The machine configuration the game screen needs, reflecting the attendant's settings.
     */
    public function config(Request $request): JsonResponse
    {
        return response()->json(['game' => $this->clientConfig($request)]);
    }

    /**
     * The machine's current meters and screen.
     */
    public function state(Request $request): JsonResponse
    {
        return response()->json(['state' => $this->present($this->loadState($request))]);
    }

    /**
     * Press SPIN: a paid game, a free game, or a Bale Bonus respin depending on the machine state.
     */
    public function play(PlayRequest $request): JsonResponse
    {
        $state = $this->loadState($request);

        if ($request->has('credits_per_line') && ! $state->isInFeature()) {
            $state->creditsPerLine = $request->integer('credits_per_line');
        }

        $outcome = $this->game->play($state);
        $request->session()->put($this->sessionKey($request), $state->toArray());
        $this->meters->recordPlay($outcome, $state->denomination, $this->game->totalBet($state));

        return response()->json(['outcome' => $outcome, 'state' => $this->present($state)]);
    }

    /**
     * Switch denomination between games. Ignored while a feature is in progress.
     */
    public function changeDenomination(ChangeDenominationRequest $request): JsonResponse
    {
        $state = $this->loadState($request);

        $this->game->changeDenomination($state, $request->integer('denomination'));
        $request->session()->put($this->sessionKey($request), $state->toArray());

        return response()->json(['state' => $this->present($state)]);
    }

    /**
     * Memory reset: start again with the deposited money at the current
     * denomination, clearing any feature in progress.
     */
    public function reset(ResetRequest $request): JsonResponse
    {
        $state = $this->game->newState(
            balanceCents: $request->depositCents(),
            denomination: $this->loadState($request)->denomination,
        );
        $request->session()->put($this->sessionKey($request), $state->toArray());
        $this->meters->recordDeposit($request->depositCents());

        return response()->json(['state' => $this->present($state)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientConfig(Request $request): array
    {
        $config = $this->machineConfig->effective();
        $settings = $this->machineConfig->settings();

        return [
            'denominations' => $config['denominations'],
            'creditsPerLineOptions' => $config['credits_per_line_options'],
            'paylines' => $config['paylines'],
            'paytable' => $config['paytable'],
            'scatterPays' => $config['scatter_pays'],
            'freeGames' => $config['free_games'],
            'baleBonus' => [
                'trigger_count' => $config['bale_bonus']['trigger_count'],
                'respins' => $config['bale_bonus']['respins'],
                'landing_chances' => $config['bale_bonus']['landing_chances'],
            ],
            'jackpotMultipliers' => [
                'mini' => $config['jackpots']['mini']['bet_multiplier'],
                'minor' => $config['jackpots']['minor']['bet_multiplier'],
            ],
            'autoplayEnabled' => $settings['autoplay_enabled'],
            'maxDepositCents' => $settings['max_deposit_cents'],
            'symbolNames' => self::theme($request)['names'],
            'symbolPlurals' => self::theme($request)['plurals'],
            'state' => $this->present($this->loadState($request)),
        ];
    }

    /**
     * Each themed machine keeps its own credits and screen in the session.
     */
    private function sessionKey(Request $request): string
    {
        return self::theme($request)['session_key'];
    }

    private function loadState(Request $request): GameState
    {
        $stored = $request->session()->get($this->sessionKey($request));

        $state = $stored === null ? $this->game->newState() : GameState::fromArray($stored);
        $this->game->normalize($state);

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GameState $state): array
    {
        return [
            'credits' => $state->credits,
            'credits_per_line' => $state->creditsPerLine,
            'denomination' => $state->denomination,
            'lines' => $this->game->lines($state),
            'total_bet' => $this->game->totalBet($state),
            'last_win' => $state->lastWin,
            'grid' => $state->grid,
            'bales' => $state->bales,
            'free_games' => $state->freeGames,
            'bale_bonus' => $state->baleBonus,
            'jackpots' => $this->game->jackpotValues($state),
        ];
    }
}
