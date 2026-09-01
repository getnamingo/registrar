<?php

namespace Registrar\Backend;

use PDO;

abstract class AbstractDriver implements DriverInterface
{
    public function __construct(
        protected PDO $pdo,
        protected array $config,
        protected object $log
    ) {
    }

    public function getEppConfigurations(): array
    {
        throw new \RuntimeException(
            static::class . ' does not implement EPP account enumeration.'
        );
    }
}