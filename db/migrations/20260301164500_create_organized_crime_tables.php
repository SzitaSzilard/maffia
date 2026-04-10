<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrganizedCrimeTables extends AbstractMigration
{
    public function up(): void
    {
        // 1. DUMMY Gang Tables
        $gangs = $this->table('gangs');
        if (!$gangs->exists()) {
            $gangs->addColumn('name', 'string', ['limit' => 100])
                  ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                  ->create();
        }

        $gangMembers = $this->table('gang_members');
        if (!$gangMembers->exists()) {
            $gangMembers->addColumn('gang_id', 'integer', ['signed' => false]) // references Phinx default UNSIGNED PK
                        ->addColumn('user_id', 'integer', ['signed' => true])  // references users.id which is SIGNED
                        ->addColumn('is_leader', 'boolean', ['default' => 0])
                        ->addColumn('joined_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                        ->addForeignKey('gang_id', 'gangs', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                        ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                        ->addIndex(['user_id'], ['unique' => true])
                        ->create();
        }

        // 2. Organized Crimes tables
        $crimes = $this->table('organized_crimes');
        $crimes->addColumn('leader_id', 'integer', ['signed' => true]) // references users.id (SIGNED)
               ->addColumn('crime_type', 'enum', ['values' => ['bank', 'casino', 'money_transport'], 'default' => 'casino'])
               ->addColumn('status', 'enum', ['values' => ['gathering', 'in_progress', 'success', 'failed', 'cancelled'], 'default' => 'gathering'])
               ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
               ->addColumn('finished_at', 'datetime', ['null' => true])
               ->addForeignKey('leader_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
               ->create();

        $crimeMembers = $this->table('organized_crime_members');
        $crimeMembers->addColumn('crime_id', 'integer', ['signed' => false]) // references organized_crimes.id (UNSIGNED)
                     ->addColumn('user_id', 'integer', ['signed' => true])   // references users.id (SIGNED)
                     ->addColumn('role', 'enum', ['values' => [
                         'organizer', 'gang_leader', 'union_member', 'gunman_1', 'gunman_2', 'gunman_3', 
                         'hacker', 'driver_1', 'driver_2', 'pilot'
                     ]])
                     ->addColumn('status', 'enum', ['values' => ['invited', 'accepted', 'declined', 'kicked'], 'default' => 'invited'])
                     ->addColumn('vehicle_id', 'integer', ['null' => true])
                     ->addColumn('vehicle_name', 'string', ['limit' => 100, 'null' => true])
                     ->addColumn('chance_pct', 'integer', ['null' => true, 'comment' => 'Indításkori egyéni esély'])
                     ->addColumn('joined_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                     ->addForeignKey('crime_id', 'organized_crimes', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                     ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                     ->addIndex(['crime_id', 'user_id'], ['unique' => true])
                     ->create();
                     
        // 3. Add organized crime cooldown and stats to users table
        $users = $this->table('users');
        if (!$users->hasColumn('oc_cooldown_until')) {
            $users->addColumn('oc_cooldown_until', 'datetime', ['null' => true, 'after' => 'energy'])
                  ->addColumn('oc_success_count', 'integer', ['default' => 0, 'after' => 'losses'])
                  ->addColumn('oc_fail_count', 'integer', ['default' => 0, 'after' => 'oc_success_count'])
                  ->update();
        }

        // 4. Seed Dummy Data for Testing (Bandafőnök)
        $stmt = $this->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch();
        
        if ($user && $user['id']) {
            $userId = $user['id'];
            
            // Insert dummy gang
            $this->execute("INSERT INTO gangs (name) VALUES ('Teszt Banda')");
            $stmt = $this->query("SELECT LAST_INSERT_ID() as id");
            $gang = $stmt->fetch();
            
            if ($gang && $gang['id']) {
                $gangId = $gang['id'];
                $this->execute("INSERT IGNORE INTO gang_members (gang_id, user_id, is_leader) VALUES ({$gangId}, {$userId}, 1)");
            }
        }
    }

    public function down(): void
    {
        $this->table('organized_crime_members')->drop()->save();
        $this->table('organized_crimes')->drop()->save();
        $this->table('gang_members')->drop()->save();
        $this->table('gangs')->drop()->save();
        
        $users = $this->table('users');
        if ($users->hasColumn('oc_cooldown_until')) {
            $users->removeColumn('oc_cooldown_until')
                  ->removeColumn('oc_success_count')
                  ->removeColumn('oc_fail_count')
                  ->save();
        }
    }
}
