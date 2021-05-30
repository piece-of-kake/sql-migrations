<?php

namespace PoK\Migrations\Exceptions;

use PoK\Exception\HasDataInterface;
use PoK\Exception\ServerError\InternalServerErrorException;
use PoK\ValueObject\Collection;
use PoK\ValueObject\TypePositiveInteger;
use PoK\ValueObject\TypeString;

class FailedMigrationException extends InternalServerErrorException implements HasDataInterface
{
    private $data;

    public function __construct(Collection $succeded, TypeString $failedAt, TypePositiveInteger $batch, \Throwable $previous = NULL) {
        parent::__construct('FAILED_MIGRATION', $previous);
        $this->data = [
            'succeded' => $succeded->toArray(),
            'failed_at' => (string) $failedAt,
            'batch' => $batch->getValue(),
            'info' => [
                'reason' => $previous->getMessage(),
                'file' => $previous->getFile(),
                'line' => $previous->getLine()
            ]
        ];
    }

    public function getData()
    {
        return $this->data;
    }
}
