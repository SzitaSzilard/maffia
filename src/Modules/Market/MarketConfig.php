<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market;

final class MarketConfig
{
    /**
     * Minimális rang index az eladáshoz ("Gengszter")
     */
    public const MIN_RANK_INDEX_TO_SELL = 3;

    /**
     * Maximális egységár a piacon
     */
    public const MAX_ITEM_PRICE = 999_999_999;

    /**
     * Maximális mennyiség egy eladásnál
     */
    public const MAX_ITEM_QUANTITY = 1_000_000;

    /**
     * Elérhető piaci kategóriák
     */
    public const CATEGORIES = [
        'weapon' => 'Fegyver',
        'armor' => 'Védelem',
        'consumable' => 'Elfogyasztható',
        'misc' => 'Egyéb',
        'vehicle' => 'Jármű',
        'car_part' => 'Alkatrész',
        'bullet' => 'Töltény',
        'credit' => 'Kredit'
    ];
}
