<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTuningColumns extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('user_vehicles');
        
        if (!$table->hasColumn('tuning_engine')) {
            $table->addColumn('tuning_engine', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 0, 'null' => false])
                  ->addColumn('tuning_tires', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 0, 'null' => false])
                  ->addColumn('tuning_exhaust', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 0, 'null' => false])
                  ->addColumn('tuning_brakes', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 0, 'null' => false])
                  ->addColumn('tuning_nitros', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 0, 'null' => false])
                  ->addColumn('tuning_body', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 0, 'null' => false])
                  ->addColumn('tuning_shocks', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 0, 'null' => false])
                  ->addColumn('tuning_wheels', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 0, 'null' => false])
                  ->update();
        }
    }
}
