<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMarketHistoryTable extends AbstractMigration
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
        $table = $this->table('market_history');
        
        $table->addColumn('seller_id', 'integer')
              ->addColumn('buyer_id', 'integer')
              ->addColumn('category', 'enum', ['values' => ['weapon','armor','consumable','misc','vehicle','car_part','bullet','credit']])
              ->addColumn('item_id', 'integer', ['null' => true])
              ->addColumn('item_name', 'string', ['limit' => 255])
              ->addColumn('quantity', 'integer', ['default' => 1])
              ->addColumn('price', 'biginteger')
              ->addColumn('currency', 'enum', ['values' => ['money','credit'], 'default' => 'money'])
              ->addColumn('sold_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['seller_id'])
              ->addIndex(['buyer_id'])
              ->create();
    }
}
