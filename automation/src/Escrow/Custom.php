<?php

namespace Registrar\Escrow;

use LogicException;

/**
 * Template class for creating a custom Escrow backend.
 * 
 * Rename this class (e.g., to WHMCS, FOSSBilling, etc.) and implement your own logic
 * inside the generateFull() and generateHDL() methods.
 */
class Custom implements EscrowInterface {
    private $pdo;
    private $full;
    private $hdl;

    public function __construct(\PDO $pdo, $full, $hdl)
    {
        $this->pdo = $pdo;
        $this->full = $full;
        $this->hdl = $hdl;
    }

    public function generateFull(): void {
        throw new LogicException('generateFull() is not implemented. Please rename this class and implement your own backend logic.');
    }

    public function generateHDL(): void {
        throw new LogicException('generateHDL() is not implemented. Please rename this class and implement your own backend logic.');
    }
    
    public function generateRDE(int $ianaID): void {
        throw new LogicException('generateRDE() is not implemented. Please rename this class and implement your own backend logic.');
    }
}