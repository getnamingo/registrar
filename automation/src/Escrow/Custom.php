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

    public function generateFull(): void
    {
        throw new LogicException('generateFull() is not implemented. Please rename this class and implement your own backend logic.');
    }

    public function generateHDL(): void
    {
        throw new LogicException('generateHDL() is not implemented. Please rename this class and implement your own backend logic.');
    }
    
    public function generateRDE(int $ianaID): void
    {
        // After generating and closing the CSV file, call the splitter like this:
        // fclose($file);
        // $this->splitCsvIfTooLarge($this->full, 100000);
        throw new LogicException('generateRDE() is not implemented. Please rename this class and implement your own backend logic.');
    }

    private function splitCsvIfTooLarge(string $path, int $maxLines = 100000): void
    {
        if (!file_exists($path)) {
            return;
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return;
        }

        $header = fgets($handle);
        if ($header === false) {
            fclose($handle);
            return;
        }

        // Pre-check (count data rows, excluding header)
        $totalLines = 0;
        while (fgets($handle) !== false) {
            $totalLines++;
        }

        // If within limit, keep single original file as-is
        if ($totalLines <= $maxLines) {
            fclose($handle);
            return;
        }

        // Rewind for the actual split pass
        rewind($handle);
        $header = fgets($handle);
        if ($header === false) {
            fclose($handle);
            return;
        }

        $pi   = pathinfo($path);
        $dir  = $pi['dirname'] ?? '.';
        $name = $pi['filename'] ?? 'export';
        $ext  = isset($pi['extension']) ? '.' . $pi['extension'] : '.csv';
        $dir  = rtrim($dir, DIRECTORY_SEPARATOR);

        $lineCount = 0;
        $part = 1;     // split sequence starts at 1
        $out = null;

        while (($line = fgets($handle)) !== false) {

            if ($lineCount % $maxLines === 0) {
                if ($out) {
                    fclose($out);
                }

                $target = $dir . DIRECTORY_SEPARATOR . $name . '_' . $part . $ext;

                $out = fopen($target, 'w');
                if (!$out) {
                    fclose($handle);
                    return; // cannot write output
                }

                if ($part === 1) {
                    fwrite($out, $header); // header only in first split file
                }

                $part++;
            }

            fwrite($out, $line);
            $lineCount++;
        }

        fclose($handle);
        if ($out) {
            fclose($out);
        }

        // Remove the original unsplit file; split files are now the deposit
        @unlink($path);
    }
}