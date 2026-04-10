<?php

use Phinx\Migration\AbstractMigration;

class CreateCombatTables extends AbstractMigration
{
    public function change()
    {
        // 1. Combat Log Table
        $table = $this->table('combat_log');
        $table->addColumn('attacker_id', 'integer')
              ->addColumn('defender_id', 'integer')
              ->addColumn('winner_id', 'integer', ['null' => true]) // Null if draw (though we use 50/50 now)
              ->addColumn('attacker_xp_snapshot', 'integer', ['default' => 0])
              ->addColumn('defender_xp_snapshot', 'integer', ['default' => 0])
              ->addColumn('attacker_points', 'float', ['default' => 0]) // Points can be float due to ammo bonus? No, usually int, but let's see. logic involves %
              ->addColumn('defender_points', 'float', ['default' => 0])
              ->addColumn('money_stolen', 'integer', ['default' => 0])
              ->addColumn('damage_dealt', 'integer', ['default' => 0])
              ->addColumn('ammo_used_attacker', 'integer', ['default' => 0])
              ->addColumn('ammo_used_defender', 'integer', ['default' => 0])
              ->addColumn('vehicle_used_attacker', 'boolean', ['default' => false])
              ->addColumn('vehicle_used_defender', 'boolean', ['default' => false])
              ->addColumn('battle_report', 'json', ['null' => true]) // JSON for detailed log
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->create();

        // 2. User Combat Settings Table
        $settings = $this->table('user_combat_settings');
        $settings->addColumn('user_id', 'integer')
                 ->addColumn('use_vehicle', 'boolean', ['default' => false])
                 ->addColumn('defense_ammo', 'integer', ['default' => 0])
                 ->addIndex(['user_id'], ['unique' => true])
                 ->create();
    }
}
