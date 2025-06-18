<?php

namespace Registrar\Escrow;

interface EscrowInterface {
    public function generateFull();
    public function generateHDL();
}
