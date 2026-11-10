<?php
// SPDX-License-Identifier: MIT
// Run: php automation/tests/escrow_splitter_test.php (no database or dependencies).
declare(strict_types=1);
require dirname(__DIR__) . '/src/Escrow/EscrowInterface.php';

$root = sys_get_temp_dir() . '/namingo-splitter-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$checks = 0;
function checkSplit(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
}
function csvBytes(array $row, string $eol): string {
    $stream = fopen('php://memory', 'r+');
    fputcsv($stream, $row, ',', '"', '\\', $eol);
    rewind($stream);
    $bytes = stream_get_contents($stream);
    fclose($stream);
    return $bytes;
}
try {
    foreach (['FOSS', 'WHMCS', 'LOOM', 'Custom'] as $backend) {
        require dirname(__DIR__) . '/src/Escrow/' . $backend . '.php';
        $class = new ReflectionClass('Registrar\\Escrow\\' . $backend);
        $split = $class->getMethod('splitCsvIfTooLarge');
        $instance = $class->newInstanceWithoutConstructor();
        foreach (["\n", "\r\n"] as $eol) {
            $rows = [['domain', 'name'], ['one.test', "First\nSecond\nThird"],
                ['two.test', "Quoted \"name\"\r\nСофия"], ['three.test', 'Back\\slash'], ['four.test', 'Last']];
            $records = array_map(static fn($row) => csvBytes($row, $eol), $rows);
            // Include a final record without a trailing newline.
            $records[count($records) - 1] = substr($records[count($records) - 1], 0, -strlen($eol));
            $original = implode('', $records);
            foreach (['fits', 'rows', 'bytes'] as $scenario) {
                $path = "$root/{$backend}-" . strlen($eol) . "-$scenario.csv";
                file_put_contents($path, $original);
                $maxRows = $scenario === 'rows' ? 3 : count($rows);
                $maxBytes = $scenario === 'bytes' ? strlen($records[0]) + max(array_map('strlen', $records)) : 10000;
                $split->invoke($instance, $path, $maxRows, $maxBytes);
                if ($scenario === 'fits') {
                    checkSplit(is_file($path) && file_get_contents($path) === $original, "$backend: multiline records must not cause unnecessary splitting");
                    continue;
                }
                $parts = glob(substr($path, 0, -4) . '_*.csv');
                natsort($parts);
                checkSplit(!is_file($path) && count($parts) > 1, "$backend: expected split files");
                $joined = ''; $parsed = [];
                foreach ($parts as $part) {
                    $joined .= file_get_contents($part);
                    $stream = fopen($part, 'rb'); $count = 0;
                    while (($record = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
                        checkSplit(count($record) === 2, "$backend: record split inside a quoted field");
                        $parsed[] = $record; $count++;
                    }
                    fclose($stream);
                    checkSplit($count <= $maxRows && filesize($part) <= $maxBytes, "$backend: part exceeds limit");
                }
                checkSplit($joined === $original, "$backend: split changed original CSV bytes or repeated header");
                checkSplit($parsed === $rows, "$backend: split changed CSV data");
            }
        }
        echo "PASS $backend: row/byte limits, embedded LF/CRLF, quotes, UTF-8, final record, unchanged bytes\n";
    }
    echo "$checks checks passed.\n";
} finally {
    foreach (glob($root . '/*') as $file) { unlink($file); }
    rmdir($root);
}
