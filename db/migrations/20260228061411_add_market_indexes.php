<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddMarketIndexes extends AbstractMigration
{
    public function change(): void
    {
        // Index a market_history.sold_at oszlopra (ORDER BY sold_at DESC LIMIT 30 gyorsítása)
        $this->table('market_history')
             ->addIndex(['sold_at'])
             ->update();

        // Index a market_items.category oszlopra (WHERE category = ? szűrés gyorsítása)
        if (!$this->table('market_items')->hasIndex(['category'])) {
            $this->table('market_items')
                 ->addIndex(['category'])
                 ->update();
        }

        // Összetett index a market_items-re az összevonás lekérdezéshez
        // (seller_id, category, price, currency) - listItemOnMarket aggregáció
        if (!$this->table('market_items')->hasIndex(['seller_id', 'category', 'price', 'currency'])) {
            $this->table('market_items')
                 ->addIndex(['seller_id', 'category', 'price', 'currency'])
                 ->update();
        }
    }
}
