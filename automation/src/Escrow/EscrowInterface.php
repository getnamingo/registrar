<?php

namespace Registrar\Escrow;

interface EscrowInterface {
    public function generateFull(): void;
    public function generateHDL(): void;
    public function generateRDE(int $ianaID): void;
}