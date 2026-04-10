<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddShopModuleTables extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        // 1. Módosítjuk az items táblát, hogy legyen benne kép, készlet és "boltban kapható" jelző
        $items = $this->table('items');
        
        if (!$items->hasColumn('image_url')) {
            $items->addColumn('image_url', 'string', ['limit' => 255, 'null' => true, 'after' => 'name'])
                  ->addColumn('stock', 'integer', ['default' => 0, 'after' => 'price'])
                  ->addColumn('is_shop_item', 'boolean', ['default' => false, 'after' => 'stock'])
                  // TYPE ENUM bővítése 'jet'-tel (magánrepülőkhöz)
                  ->changeColumn('type', 'enum', [
                      'values' => ['weapon', 'armor', 'consumable', 'misc', 'jet'],
                      'default' => 'misc'
                  ])
                  ->update();
        }

        // 2. Létrehozzuk a globális game_settings táblát (pl. a bolt következő feltöltési idejének tárolására)
        $settings = $this->table('game_settings', ['id' => false, 'primary_key' => ['setting_key']]);
        $settings->addColumn('setting_key', 'string', ['limit' => 50, 'null' => false])
                 ->addColumn('setting_value', 'text', ['null' => true])
                 ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                 ->create();
    }
}
