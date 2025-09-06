<?php
class Sync_Basalam_migration_manager
{
    private $migrations = [];

    public function __construct()
    {
        $this->migrations = [
            '1.3.0' => new Sync_Basalam_Migration_1_3_0(),
            '1.3.2' => new Sync_Basalam_Migration_1_3_2(),
            '1.3.8' => new Sync_Basalam_Migration_1_3_8(),
            '1.3.9' => new Sync_Basalam_Migration_1_3_9(),
        ];
    }
    public function runMigrations($current_version, $new_version)
    {
        foreach ($this->migrations as $version => $migration) {
            if (version_compare($current_version, $version, '<')) {
                $migration->up();
            }
        }

        update_option('sync_basalam_version', $new_version);
    }
}
