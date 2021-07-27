<?php

namespace PoK\Migrations;

use PoK\SQLQueryBuilder\Conditions\Equal;
use PoK\SQLQueryBuilder\Conditions\LAnd;
use PoK\SQLQueryBuilder\SQLClientInterface;
use PoK\SQLQueryBuilder\Queries\Delete;
use PoK\SQLQueryBuilder\Queries\TableExists;
use PoK\SQLQueryBuilder\Queries\CreateTable;
use PoK\SQLQueryBuilder\Queries\Select;
use PoK\Migrations\Exception\FailedMigrationException;
use PoK\ValueObject\Collection;
use PoK\SQLQueryBuilder\Queries\Insert;
use PoK\ValueObject\TypePositiveInteger;
use PoK\ValueObject\TypeString;

class MigrationManager
{
    /**
     * @var SQLClientInterface
     */
    private $client;

    private $migrationsPath;

    public function __construct(string $migrationsPath)
    {
        $this->migrationsPath = $migrationsPath;
    }

    public function up(SQLClientInterface $client)
    {
        $this->client = $client;
        
        if (!$this->migrationsTableExists())
            $this->createMigrationsTable();
        
        return $this->commitAvailableMigrations();
    }

    public function down(SQLClientInterface $client)
    {
        $this->client = $client;

        if (!$this->migrationsTableExists())
            $this->createMigrationsTable();

        return $this->revertLastBatch();
    }
    
    private function migrationsTableExists()
    {
        return $this->client->execute(new TableExists('migrations'));
    }
    
    private function createMigrationsTable()
    {
        $query = (new CreateTable('migrations'))
            ->columns(function(CreateTable $table) {
                $table->column('migration')->string()->size(255)->notNull();
                $table->column('batch')->tinyInt()->notNull();
            });
        $this->client->execute($query);
    }

    private function commitAvailableMigrations()
    {
        $executedMigrations = $this->getExecutedMigrationNames();

        $batch = $this->getLastMigrationBatch($executedMigrations) + 1;

        $migrationFiles = $this->excludeExecutedMigrationFiles(
            $this->getMigrationFileNames(),
            $executedMigrations
        );

        $succeded = new Collection([]);
        $migrationFiles
            ->each(function ($fileName) use ($batch, $succeded) {
                try {
                    require_once $fileName;
                    $className = $this->getMigrationClassNameFromFileName($fileName);
                    $migration = new $className($this->client);
                    $migration->commit();
                    $this->client->execute(
                        (new Insert('migrations'))
                            ->columns('migration', 'batch')
                            ->addValueRow(basename($fileName, '.php'), $batch)
                    );
                    $succeded[] = $className;
                } catch (\Throwable $exception) {
                    throw new FailedMigrationException($succeded, new TypeString($fileName), new TypePositiveInteger($batch), $exception);
                }
            });

        return [$succeded, new TypePositiveInteger($batch)];
    }

    private function revertLastBatch()
    {
        $executedMigrations = $this->getExecutedMigrationNames();

        $batch = $this->getLastMigrationBatch($executedMigrations);

        $migrationFiles = $this->filterBatchMigrationFiles(
            $this->getMigrationFileNames(),
            $executedMigrations,
            $batch
        );

        $succeded = new Collection([]);
        $migrationFiles
            ->each(function ($fileName) use ($batch, $succeded) {
                try {
                    require_once $fileName;
                    $className = $this->getMigrationClassNameFromFileName($fileName);
                    $migration = new $className($this->client);
                    $migration->rollback();
                    $this->client->execute(
                        (new Delete('migrations'))
                            ->where(new LAnd(
                                new Equal(
                                    'migration',
                                    basename($fileName, '.php')
                                ),
                                new Equal(
                                    'batch',
                                    $batch
                                )
                            ))
                    );
                    $succeded[] = $className;
                } catch (\Throwable $exception) {
                    throw new FailedMigrationException($succeded, new TypeString($fileName), new TypePositiveInteger($batch), $exception);
                }
            });

        return [$succeded, new TypePositiveInteger($batch)];
    }

    private function excludeExecutedMigrationFiles(Collection $migrationFiles, Collection $executedMigrations)
    {
        $executedMigrations
            ->map(function ($executedMigration) {
                return $executedMigration['migration'];
            })
            ->each(function ($executedMigration) use (&$migrationFiles) {
                $migrationFiles = $migrationFiles->skip(function ($migrationFile) use ($executedMigration) {
                    return $executedMigration === basename($migrationFile, '.php');
                });
            });

        return $migrationFiles;
    }

    private function filterBatchMigrationFiles(Collection $migrationFiles, Collection $executedMigrations, $batch)
    {
        return $migrationFiles
            ->filter(function ($migrationFile) use ($executedMigrations, $batch) {
                return $executedMigrations->find(function ($executedMigration) use ($migrationFile, $batch) {
                    return
                        $executedMigration['migration'] === basename($migrationFile, '.php') &&
                        $executedMigration['batch'] == $batch;
                });
            });
    }

    private function getLastMigrationBatch($executedMigrations)
    {
        $lastBatch = 0;
        foreach ($executedMigrations as $executedMigration) {
            if ($executedMigration['batch'] > $lastBatch) $lastBatch = $executedMigration['batch'];
        }
        return $lastBatch;
    }

    private function getExecutedMigrationNames()
    {
        return $this->client->execute(new Select('migrations'));
    }
    
    private function getMigrationFileNames()
    {
        return new Collection(glob($this->migrationsPath.'/'.'*.php'));
    }
    
    private function getMigrationClassNameFromFileName($fileName)
    {
        $fileName = basename($fileName, '.php');
        $nameParts = new Collection(explode('_', $fileName));
        
        $className = $nameParts
            ->skipFirst()
            ->map(function ($part) {
                return ucfirst($part);
            })
            ->implode('');
            
        return $className;
    }
}
