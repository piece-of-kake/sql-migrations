<?php

namespace PoK\Migrations;

use PoK\SQLQueryBuilder\SQLClientInterface;
use PoK\SQLQueryBuilder\Interfaces\CanCompile;

abstract class Migration
{

    /**
     * @var SQLClientInterface
     */
    private $client;

    public function __construct(SQLClientInterface $client)
    {
        $this->client = $client;
    }

    public function commit()
    {
        $this->client->execute($this->up());
    }

    public function rollback()
    {
        $this->client->execute($this->down());
    }

    protected abstract function up(): CanCompile;

    protected abstract function down(): CanCompile;
}
