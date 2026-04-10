<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MarketModule extends AbstractMigration
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
        // 1. user_vehicles.user_id engedélyezése NULL-ra a tranzit/piac állapot miatt
        // Erre azért van szükség, mert a modul-fejlesztes leírás 2.8 pontja szigorúan tiltja a 0 ID használatát
        $userVehicles = $this->table('user_vehicles');
        $userVehicles->changeColumn('user_id', 'integer', ['null' => true])
                     ->update();

        // 2. market_items tábla létrehozása
        $marketItems = $this->table('market_items');
        $marketItems->addColumn('seller_id', 'integer', ['null' => false])
                    ->addColumn('category', 'enum', ['values' => ['weapon','armor','consumable','misc','vehicle','car_part','bullet','credit'], 'null' => false])
                    ->addColumn('item_id', 'integer', ['null' => true])
                    ->addColumn('quantity', 'integer', ['default' => 1])
                    ->addColumn('price', 'biginteger', ['null' => false])
                    ->addColumn('currency', 'enum', ['values' => ['money', 'credit'], 'default' => 'money'])
                    ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                    ->addIndex(['seller_id'])
                    ->addIndex(['category'])
                    ->addForeignKey('seller_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                    ->create();
    }
}
