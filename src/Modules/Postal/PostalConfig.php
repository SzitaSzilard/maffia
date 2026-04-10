<?php
declare(strict_types=1);

namespace Netmafia\Modules\Postal;

class PostalConfig
{
    /** Küldési idő percben */
    public const DELIVERY_MINUTES = 15;
    
    /** Max tételek száma egy csomagban */
    public const MAX_ITEMS_PER_PACKAGE = 10;
    
    /** Max küldhető pénz */
    public const MAX_MONEY = 2_000_000_000;

    /** Max küldhető kredit */
    public const MAX_CREDITS = 1_000_000;
    
    /** Max küldési díj ($) */
    public const MAX_SHIPPING_COST = 10000;
    
    /** Pénz küldési díj (%) */
    public const MONEY_FEE_PERCENT = 5;
    
    /** Jármű küldési díj (% vételárból) */
    public const VEHICLE_FEE_PERCENT = 10;
    
    /** Teszt: Jármű fix fallback díja ha a % nem alkalmazható */
    public const VEHICLE_FEE = 5000;
    
    /** Épület küldési díj (fix $) */
    public const BUILDING_FEE = 5000;
    
    /** Kredit küldési díj (%) */
    public const CREDIT_FEE_PERCENT = 5;
    
    /** Töltény küldési díj (%) */
    public const BULLET_FEE_PERCENT = 5;
    
    /** Aktív kategóriák — kattinthatók */
    public const ACTIVE_CATEGORIES = [
        'weapon'     => 'Fegyvert',
        'armor'      => 'Védelmi eszközt',
        'consumable' => 'Elfogyasztható cuccot',
        'vehicle'    => 'Járművet',
        'money'      => 'Pénzt',
        'credits'    => 'Kreditet',
        'bullets'    => 'Töltényt',
        'building'   => 'Épületet',
    ];
    
    /** Inaktív kategóriák — nem kattinthatók, de megjelennek szürkén */
    public const INACTIVE_CATEGORIES = [
        'misc'        => 'Egyéb cuccot',
        'rooster'     => 'Kakast',
        'car_parts'   => 'Autóalkatrészt',
        'gang'        => 'Bandát',
        'business'    => 'Vállalkozást',
        'farmland'    => 'Termőföldet',
    ];
}
