<?php

namespace Plugin\ExamplePlugin;

use App\Core\PluginInterface;
use App\Core\Router;
use App\Core\PluginManager;

class ExamplePluginPlugin implements PluginInterface {
    
    public function registerRoutes(Router $router): void {
        PluginManager::addAction('header_start', function() {
            echo "<div style='background: #fef3c7; color: #92400e; padding: 0.5rem; text-align: center; font-size: 0.875rem; font-weight: 600; border-bottom: 1px solid #fde68a;'>
                    <i class='bi bi-stars me-2'></i>Example Header Plugin is Active!
                  </div>";
        });
    }

    public function install(): bool { return true; }
    public function activate(): bool { return true; }
    public function deactivate(): bool { return true; }
    public function uninstall(): bool { return true; }

    /**
     * Define the database schema required by this plugin.
     * 
     * If this plugin is installed, the "Database Repair Tool" will automatically
     * ensure these structures exist.
     */
    public function getDatabaseSchema(): array { 
        return [
            /*
            'tables' => [
                'example_data' => [
                    'columns' => [
                        'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                        'data_key' => "VARCHAR(255) NOT NULL",
                        'data_value' => "TEXT NULL"
                    ],
                    'primary' => 'id'
                ]
            ],
            'columns' => [
                'users' => [
                    'example_plugin_meta' => "TEXT NULL"
                ]
            ]
            */
        ]; 
    }
}
