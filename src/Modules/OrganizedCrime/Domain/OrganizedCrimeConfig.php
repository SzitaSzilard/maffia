<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Domain;

/**
 * Szervezett Bűnözés konfigurációs konstansok.
 * Minden magic number ide kerül.
 */
final class OrganizedCrimeConfig
{
    // --- Szerepek ---
    public const VALID_ROLES = [
        'organizer',
        'gang_leader',
        'union_member',
        'gunman_1',
        'gunman_2',
        'gunman_3',
        'hacker',
        'driver_1',
        'driver_2',
        'pilot',
    ];

    /** Érvényes bűnözés típusok */
    public const VALID_CRIME_TYPES = ['casino'];

    /** Minimálisan szükséges elfogadott tagok száma (szervezővel együtt) */
    public const REQUIRED_MEMBER_COUNT = 10;

    // --- Cooldown ---
    /** Cooldown másodpercben a bűnözés után */
    public const COOLDOWN_SECONDS = 45 * 60; // 45 perc

    // --- Esély számítás ---
    public const BASE_CHANCE_PCT = 50.0;
    public const MAX_LEVEL_BONUS_PCT = 30.0;
    public const LEVEL_BONUS_MULTIPLIER = 1.5;
    public const MAX_EXPERIENCE_BONUS_PCT = 15.0;
    public const EXPERIENCE_BONUS_MULTIPLIER = 0.2;
    public const MIN_CHANCE_PCT = 10;
    public const MAX_CHANCE_PCT = 95;
    public const MIN_ENERGY_REQUIRED = 20;

    /** Szerepenkénti nehézségi penalty */
    public const ROLE_PENALTIES = [
        'organizer'    => 15.0,
        'gang_leader'  => 10.0,
        'pilot'        => 10.0,
        'union_member' => 5.0,
        'gunman_1'     => 5.0,
        'gunman_2'     => 5.0,
        'gunman_3'     => 5.0,
        'hacker'       => 5.0,
        'driver_1'     => 5.0,
        'driver_2'     => 5.0,
    ];

    // --- Jutalmak ---
    public const REWARD_MIN = 40000;
    public const REWARD_MAX = 120000;

    public const XP_GAIN_MIN = 350;
    public const XP_GAIN_MAX = 888;

    public const ENERGY_GAIN_MIN = -7;
    public const ENERGY_GAIN_MAX = 32;

    // --- Büntetések ---
    public const FINE_MIN = 1000;
    public const FINE_MAX = 5000;

    public const ENERGY_LOSS_MIN = 12;
    public const ENERGY_LOSS_MAX = 40;

    public const HP_LOSS_ON_FAIL = 30;

    /** Szerepenkénti részesedés a zsákmányból (összeg = 1.0) */
    public const ROLE_SHARES = [
        'organizer'    => 0.13,
        'gang_leader'  => 0.11,
        'union_member' => 0.08,
        'gunman_1'     => 0.10,
        'gunman_2'     => 0.10,
        'gunman_3'     => 0.10,
        'hacker'       => 0.08,
        'driver_1'     => 0.10,
        'driver_2'     => 0.10,
        'pilot'        => 0.10,
    ];

    /** Járművet igénylő szerepek */
    public const VEHICLE_ROLES = ['driver_1', 'driver_2', 'pilot'];

    /** Minimum rang index a részvételhez */
    public const MIN_RANK_INDEX = 5;
}
