<?php

namespace JordanMiguel\Wuz\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use JordanMiguel\Wuz\WuzServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            WuzServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('wuz.enabled', true);
        $app['config']->set('wuz.api_url', 'http://localhost:8080');
        $app['config']->set('wuz.admin_token', 'test-admin-token');
        $app['config']->set('wuz.phone.default_country_code', '55');
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUpDatabase(): void
    {
        $migration = include __DIR__ . '/../database/migrations/create_wuz_devices_table.php.stub';
        $migration->up();

        $migration = include __DIR__ . '/../database/migrations/create_wuz_device_messages_table.php.stub';
        $migration->up();

        $migration = include __DIR__ . '/../database/migrations/create_wuz_callback_logs_table.php.stub';
        $migration->up();

        $migration = include __DIR__ . '/../database/migrations/create_wuz_device_webhooks_table.php.stub';
        $migration->up();

        $migration = include __DIR__ . '/../database/migrations/create_wuz_phone_jids_table.php.stub';
        $migration->up();

        $this->app['db']->connection()->getSchemaBuilder()->create('test_owners', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
