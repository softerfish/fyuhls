<?php

namespace App\Core;

interface PluginInterface
{
    public function registerRoutes(Router $router): void;
    public function install(): bool;
    public function activate(): bool;
    public function deactivate(): bool;
    public function uninstall(): bool;
    
    /**
     * Define the database schema required by this plugin.
     * 
     * This method is part of the "Self-Healing Database" engine. If your plugin is installed,
     * the system will automatically ensure these tables and columns exist and match your specification.
     * 
     * Format:
     * [
     *   'tables' => [
     *      'my_plugin_table' => [
     *          'columns' => ['id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', ...],
     *          'primary' => 'id',
     *          'indexes' => ['name_idx' => 'INDEX my_name (name)'],
     *          'foreign_keys' => ['user_fk' => 'FOREIGN KEY (user_id) REFERENCES users(id)']
     *      ]
     *   ],
     *   'columns' => [
     *      'users' => [ // Inject columns into existing core tables
     *          'plugin_field' => 'VARCHAR(255) NULL'
     *      ]
     *   ]
     * ]
     * 
     * @return array The schema definition
     */
    public function getDatabaseSchema(): array;
}
