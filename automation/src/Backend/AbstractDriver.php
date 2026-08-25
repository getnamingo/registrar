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
}