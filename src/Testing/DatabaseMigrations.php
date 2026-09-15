<?php

namespace Ugarit\Lumen\Testing;

trait DatabaseMigrations
{
    /**
     * Run the database migrations for the application.
     *
     * @return void
     */
    public function runDatabaseMigrations()
    {
        $this->scribe('migrate:fresh');

        $this->beforeApplicationDestroyed(function () {
            $this->scribe('migrate:rollback');
        });
    }
}
