<?php

declare(strict_types=1);

namespace Netmafia\Modules\PettyCrime;

class PettyCrimeConfig
{
    public const SCAN_COOLDOWN_MINUTES = 5;
    public const COMMIT_COOLDOWN_MINUTES = 5;

    public const SCAN_MIN_RESULTS = 1;
    public const SCAN_MAX_RESULTS = 5;

    // Szinthatárok (összes próbálkozás alapján — CSAK az esély K szorzóját határozza meg!)
    public const LEVEL_THRESHOLDS = [
        'kezdo'        => 0,
        'kozephalado'  => 40,
        'halado'       => 120,
        'profi'        => 250,
    ];

    // K szorzók szintenként (az esélyszámításhoz)
    public const K_VALUES = [
        'kezdo'       => 9,
        'kozephalado' => 7,
        'halado'      => 5,
        'profi'       => 3,
    ];

    // Energia igény szintenként
    public const ENERGY_COST = [
        'kezdo'       => 4,
        'kozephalado' => 6,
        'halado'      => 8,
        // Profi: random 12-22 (min/max külön van)
        'profi_min'   => 12,
        'profi_max'   => 22,
    ];

    // XP jutalom szintenként (nyert / bukott)
    public const XP_WIN = [
        'kezdo'       => ['min' => 12, 'max' => 22],
        'kozephalado' => ['min' => 14, 'max' => 32],
        'halado'      => ['min' => 17, 'max' => 44],
        'profi'       => ['min' => 50, 'max' => 77],
    ];

    public const XP_FAIL_MIN = 1;
    public const XP_FAIL_MAX = 7;

    // Pénzjutalom szintenként (csak siker esetén)
    public const MONEY_WIN = [
        'kezdo'       => ['min' => 120, 'max' => 132],
        'kozephalado' => ['min' => 110, 'max' => 170],
        'halado'      => ['min' => 130, 'max' => 210],
        'profi'       => ['min' => 300, 'max' => 751],
    ];

    // Szint megjelenítési nevek (UI-hoz)
    public const LEVEL_NAMES = [
        'kezdo'       => 'Kezdő',
        'kozephalado' => 'Középhaladó',
        'halado'      => 'Haladó',
        'profi'       => 'Profi',
    ];

    /**
     * 20 bűncselekmény:
     * - id: egyedi azonosító
     * - name: megjelenített név
     * - level: szint kulcs (kezdo/kozephalado/halado/profi)
     * - base_chance: alap esély (%)
     */
    public const CRIMES = [
        1  => ['id' => 1,  'name' => 'Parkoló automata feltörése',               'level' => 'kezdo',       'base_chance' => 65],
        2  => ['id' => 2,  'name' => 'Részeg ember meglopása',                   'level' => 'kezdo',       'base_chance' => 72],
        3  => ['id' => 3,  'name' => 'Elegáns járókelő zsebmetszése',            'level' => 'kezdo',       'base_chance' => 60],
        4  => ['id' => 4,  'name' => 'Telefon kikapása járókelő kezéből',        'level' => 'kezdo',       'base_chance' => 68],
        5  => ['id' => 5,  'name' => 'Zsebtolvajlás tömegközlekedésen',          'level' => 'kezdo',       'base_chance' => 63],
        6  => ['id' => 6,  'name' => 'Kerékpár / roller elkötése',               'level' => 'kezdo',       'base_chance' => 70],
        7  => ['id' => 7,  'name' => 'Éjszakai kisbolt kasszájának kipakolása',  'level' => 'kozephalado', 'base_chance' => 50],
        8  => ['id' => 8,  'name' => 'Benzinkút fizetés nélküli elhagyása',      'level' => 'kozephalado', 'base_chance' => 55],
        9  => ['id' => 9,  'name' => 'Raktárépület éjszakai kifosztása',         'level' => 'kozephalado', 'base_chance' => 42],
        10 => ['id' => 10, 'name' => 'Besurranás egy gazdag villába',            'level' => 'kozephalado', 'base_chance' => 38],
        11 => ['id' => 11, 'name' => 'Ékszerüzlet kirakatának betörése',         'level' => 'kozephalado', 'base_chance' => 45],
        12 => ['id' => 12, 'name' => 'Múzeumi műkincs ellopása',                 'level' => 'kozephalado', 'base_chance' => 32],
        13 => ['id' => 13, 'name' => 'Átlagos személyautó feltörése és elkötése','level' => 'halado',      'base_chance' => 38],
        14 => ['id' => 14, 'name' => 'Luxusautó ellopása jeladó blokkolással',   'level' => 'halado',      'base_chance' => 26],
        15 => ['id' => 15, 'name' => 'Áruszállító kamion eltérítése',            'level' => 'halado',      'base_chance' => 28],
        16 => ['id' => 16, 'name' => 'Pénzszállító autó fegyveres megtámadása', 'level' => 'halado',      'base_chance' => 22],
        17 => ['id' => 17, 'name' => 'ATM leolvasó (skimmer) felszerelése',      'level' => 'profi',       'base_chance' => 25],
        18 => ['id' => 18, 'name' => 'Bankfiók csendes technológiai kifosztása', 'level' => 'profi',       'base_chance' => 15],
        19 => ['id' => 19, 'name' => 'Kripto-tárcák megcsapolása adathalászattal','level' => 'profi',      'base_chance' => 18],
        20 => ['id' => 20, 'name' => 'Vállalati adatbázis ellopása és váltságdíj','level' => 'profi',      'base_chance' => 10],
    ];
}
