<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddXpGainToCombatLog extends AbstractMigration
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
        $table = $this->table('combat_log');
        
        if (!$table->hasColumn('attacker_xp_gain')) {
            $table->addColumn('attacker_xp_gain', 'integer', ['default' => 0, 'null' => false, 'after' => 'defender_xp_snapshot'])
                  ->update();
        }
        
        if (!$table->hasColumn('defender_xp_gain')) {
            $table->addColumn('defender_xp_gain', 'integer', ['default' => 0, 'null' => false, 'after' => 'attacker_xp_gain'])
                  ->update();
        }
    }
}
