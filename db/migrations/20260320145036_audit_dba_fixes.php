<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AuditDbaFixes extends AbstractMigration
{
    public function up(): void
    {
        // 1. Triggers
        $triggers = [
            'log_money_changes' => ['col' => 'money', 'table' => 'money_change_log'],
            'log_credit_changes' => ['col' => 'credits', 'table' => 'credit_change_log'],
            'log_xp_changes' => ['col' => 'xp', 'table' => 'xp_change_log'],
            'log_health_changes' => ['col' => 'health', 'table' => 'health_change_log'],
            'log_energy_changes' => ['col' => 'energy', 'table' => 'energy_change_log']
        ];

        foreach ($triggers as $triggerName => $data) {
            $col = $data['col'];
            $table = $data['table'];
            
            $this->execute("DROP TRIGGER IF EXISTS {$triggerName}");
            // Use dynamic query construction
            $sql = "
                CREATE TRIGGER `{$triggerName}` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
                    DECLARE log_source VARCHAR(255);
                    SET log_source = IFNULL(@audit_source, 'direct_db_bypass');
                    IF OLD.{$col} != NEW.{$col} THEN
                        INSERT INTO {$table} (user_id, old_{$col}, new_{$col}, change_amount, change_source)
                        VALUES (NEW.id, OLD.{$col}, NEW.{$col}, CAST(NEW.{$col} AS SIGNED) - CAST(OLD.{$col} AS SIGNED), log_source);
                    END IF;
                END
            ";
            $this->execute($sql);
        }

        // 2. Idegen Kulcsok (Foreign Keys)
        $this->table('bank_transactions')
             ->addForeignKey('counterparty_account_id', 'bank_accounts', 'id', ['delete' => 'SET_NULL'])
             ->update();

        $this->table('building_income_log')
             ->addForeignKey('owner_id', 'users', 'id', ['delete' => 'SET_NULL'])
             ->update();

        // 3. Index optimalizálás
        if ($this->table('bank_accounts')->hasIndex('account_number_2')) {
            $this->table('bank_accounts')->removeIndexByName('account_number_2')->update();
        }

        $this->table('user_buffs')
             ->addIndex(['user_id', 'effect_type', 'expires_at'], ['name' => 'idx_user_buffs_active'])
             ->update();

        // 4. Pénzügyi Naplózás Konzisztenciája (is_valid flag)
        $this->execute("ALTER TABLE money_transactions MODIFY is_valid TINYINT(1) GENERATED ALWAYS AS (amount > 0 AND type IS NOT NULL) STORED");
    }

    public function down(): void
    {
        // Revert is_valid
        $this->execute("ALTER TABLE money_transactions MODIFY is_valid TINYINT(1) DEFAULT 1");

        // Revert indexes
        $this->table('user_buffs')
             ->removeIndexByName('idx_user_buffs_active')
             ->update();
             
        // Only re-add account_number_2 if it doesn't exist
        if (!$this->table('bank_accounts')->hasIndex('account_number_2')) {
            $this->table('bank_accounts')
                 ->addIndex(['account_number'], ['name' => 'account_number_2'])
                 ->update();
        }

        // Revert FKs
        $this->table('building_income_log')
             ->dropForeignKey('owner_id')
             ->update();

        $this->table('bank_transactions')
             ->dropForeignKey('counterparty_account_id')
             ->update();

        // Revert triggers (fallback to the original hardcoded 'direct_db') 
        $triggers = [
            'log_money_changes' => ['col' => 'money', 'table' => 'money_change_log'],
            'log_credit_changes' => ['col' => 'credits', 'table' => 'credit_change_log'],
            'log_xp_changes' => ['col' => 'xp', 'table' => 'xp_change_log'],
            'log_health_changes' => ['col' => 'health', 'table' => 'health_change_log'],
            'log_energy_changes' => ['col' => 'energy', 'table' => 'energy_change_log']
        ];

        foreach ($triggers as $triggerName => $data) {
            $col = $data['col'];
            $table = $data['table'];
            
            $this->execute("DROP TRIGGER IF EXISTS {$triggerName}");
            $sql = "
                CREATE TRIGGER `{$triggerName}` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
                    DECLARE log_source VARCHAR(255);
                    SET log_source = IFNULL(@audit_source, 'direct_db');
                    IF OLD.{$col} != NEW.{$col} THEN
                        INSERT INTO {$table} (user_id, old_{$col}, new_{$col}, change_amount, change_source)
                        VALUES (NEW.id, OLD.{$col}, NEW.{$col}, CAST(NEW.{$col} AS SIGNED) - CAST(OLD.{$col} AS SIGNED), log_source);
                    END IF;
                END
            ";
            $this->execute($sql);
        }
    }
}
