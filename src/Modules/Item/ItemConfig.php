<?php
declare(strict_types=1);

namespace Netmafia\Modules\Item;

/**
 * Item modul konfigurációs állandók
 */
final class ItemConfig
{
    /**
     * Eladási ár szorzó (50% – végtelen pénz exploit megelőzése)
     * [2026-02-15] Korábban 100% volt, ami vétel-eladás ciklus exploit-ot engedett
     */
    public const ITEM_SELL_RATIO = 0.5;
}
