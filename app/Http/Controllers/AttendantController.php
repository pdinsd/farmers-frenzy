<?php

namespace App\Http\Controllers;

use App\Games\HayLink\Jackpots\JackpotBank;
use App\Games\HayLink\JackpotTier;
use App\Games\HayLink\MachineConfig;
use App\Games\HayLink\MachineMeters;
use App\Games\HayLink\ParSheet;
use App\Games\HayLink\Simulator;
use App\Http\Middleware\EnsureAttendantUnlocked;
use App\Http\Requests\RunSimulationRequest;
use App\Http\Requests\UnlockAttendantRequest;
use App\Http\Requests\UpdateMachineSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AttendantController extends Controller
{
    public const SIMULATION_CACHE_KEY = 'hay_link.par_simulation';

    public function __construct(
        private readonly MachineConfig $machineConfig,
        private readonly MachineMeters $meters,
    ) {}

    /**
     * Everything the attendant panel shows.
     *
     * @return array<string, mixed>
     */
    public static function panelData(Request $request, MachineConfig $machineConfig, MachineMeters $meters): array
    {
        return [
            'unlocked' => $request->session()->get(EnsureAttendantUnlocked::SESSION_KEY) === true,
            'settings' => $machineConfig->settings(),
            'programs' => array_keys($machineConfig->rtpPrograms()),
            'denominations' => $machineConfig->base('denominations'),
            'creditsPerLineOptions' => $machineConfig->base('credits_per_line_options'),
            'progressives' => app(JackpotBank::class)->progressiveValues(),
            'meters' => $meters->current(),
        ];
    }

    /**
     * Everything the PAR sheet shows: exact figures and the latest simulation.
     *
     * @param  array{title: string, names: array<string, string>, plurals: array<string, string>}  $theme  The machine theme, for its names.
     * @return array<string, mixed>
     */
    public static function parData(ParSheet $parSheet, MachineConfig $machineConfig, array $theme): array
    {
        $simulation = Cache::get(self::SIMULATION_CACHE_KEY);

        return [
            'sheet' => $parSheet->build(),
            'simulation' => $simulation,
            'simulationIsCurrent' => $simulation !== null && $simulation['fingerprint'] === $machineConfig->mathFingerprint(),
            'meters' => app(MachineMeters::class)->current(),
            'maxSimulationSpins' => RunSimulationRequest::MAX_SPINS,
            'theme' => $theme,
        ];
    }

    /**
     * The attendant panel, rendered for the page to swap in.
     */
    public function panel(Request $request): View
    {
        return view('machine.attendant', self::panelData($request, $this->machineConfig, $this->meters));
    }

    /**
     * The PAR sheet, rendered for the page to swap in.
     */
    /**
     * Just the PAR sheet's live performance section, refreshed after each spin.
     */
    public function parLive(Request $request, ParSheet $parSheet): View
    {
        return view('machine.par-live', self::parData($parSheet, $this->machineConfig, MachineController::theme($request)));
    }

    public function parSheet(Request $request, ParSheet $parSheet): View
    {
        return view('machine.par-sheet', self::parData($parSheet, $this->machineConfig, MachineController::theme($request)));
    }

    public function unlock(UnlockAttendantRequest $request): JsonResponse
    {
        if (! $request->pinMatches()) {
            return response()->json(['message' => 'Incorrect attendant PIN.', 'errors' => ['pin' => ['Incorrect attendant PIN.']]], 422);
        }

        $request->session()->put(EnsureAttendantUnlocked::SESSION_KEY, true);
        $request->session()->regenerate();

        return response()->json(['unlocked' => true]);
    }

    public function lock(Request $request): JsonResponse
    {
        $request->session()->forget(EnsureAttendantUnlocked::SESSION_KEY);

        return response()->json(['unlocked' => false]);
    }

    public function updateSettings(UpdateMachineSettingsRequest $request): JsonResponse
    {
        $this->machineConfig->update($request->machineSettings());

        return response()->json(['settings' => $this->machineConfig->settings()]);
    }

    /**
     * Put the Major or Grand back to its (possibly newly set) reset value.
     */
    public function resetProgressive(JackpotTier $tier): JsonResponse
    {
        abort_unless($tier->isProgressive(), 404);

        app(JackpotBank::class)->resetToSeed($tier);

        return response()->json(['progressives' => app(JackpotBank::class)->progressiveValues()]);
    }

    public function clearMeters(): JsonResponse
    {
        $this->meters->clear();

        return response()->json(['meters' => $this->meters->current()]);
    }

    /**
     * Run a simulation of the current settings for the PAR sheet.
     */
    public function simulate(RunSimulationRequest $request, Simulator $simulator): JsonResponse
    {
        set_time_limit(300);

        $result = $simulator->run(
            spins: $request->integer('spins'),
            creditsPerLine: $request->integer('credits_per_line'),
            denomination: $request->integer('denomination'),
        );

        Cache::forever(self::SIMULATION_CACHE_KEY, $result);

        return response()->json(['simulation' => $result]);
    }
}
