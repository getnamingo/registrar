<?php

namespace Registrar\Backend;

use PDO;
use RuntimeException;

final class DriverFactory
{
    public static function create(PDO $pdo, array $config, object $log): DriverInterface
    {
        $backend = trim((string)($config['escrow']['backend'] ?? 'FOSS'));

        if ($backend === '') {
            $backend = 'FOSS';
        }

        $known = [
            'FOSS' => FOSS::class,
            'WHMCS' => WHMCS::class,
            'LOOM' => LOOM::class,
            'CUSTOM' => Custom::class,
        ];

        $class = $known[strtoupper($backend)] ?? null;

        if ($class === null) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $backend)) {
                throw new RuntimeException("Invalid backend driver name: {$backend}");
            }

            $class = __NAMESPACE__ . '\\' . $backend;
        }

        if (!class_exists($class)) {
            throw new RuntimeException("Backend driver class not found: {$class}");
        }

        $driver = new $class($pdo, $config, $log);

        if (!$driver instanceof DriverInterface) {
            throw new RuntimeException("Backend driver {$class} must implement " . DriverInterface::class);
        }

        return $driver;
    }
}