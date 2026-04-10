<?php
declare(strict_types=1);

namespace Netmafia\Modules\Shop;

final class ShopConfig
{
    /** Érvényes tárgy típusok a boltban */
    public const VALID_TYPES = ['weapon', 'armor', 'consumable', 'jet', 'misc'];

    /** Maximális egységár */
    public const MAX_ITEM_PRICE = 999_999_999;

    /** Maximális készlet egy tárgyból */
    public const MAX_STOCK = 999_999;

    /** Maximális vásárolható mennyiség egyszerre */
    public const MAX_BUY_QUANTITY = 100;

    /** Engedélyezett képformátumok feltöltéshez */
    public const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Maximális feltöltött fájlméret (byte) */
    public const MAX_UPLOAD_SIZE = 2 * 1024 * 1024; // 2MB
}
