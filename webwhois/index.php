<!DOCTYPE html>
<html>
<head>
    <title>WHOIS Lookup</title>
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }

        th, td {
            text-align: left;
            padding: 8px;
        }

        th {
            background-color: #4CAF50;
            color: white;
        }

        tr:nth-child(even){background-color: #f2f2f2}
    </style>
</head>
<body>

<h1>WHOIS Lookup</h1>

<form method="post">
    <label for="domain">Domain Name:</label>
    <input type="text" id="domain" name="domain" required>
    <label for="server">WHOIS Server:</label>
    <button type="submit">Check Availability</button>
</form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domain = $_POST['domain'];
    $whois_server = "whois.example.com";
    $result = '';

    $fp = fsockopen($whois_server, 43, $errno, $errstr, 10);
    if (!$fp) {
        echo '<p>Error: Could not connect to WHOIS server</p>';
    } else {
        fwrite($fp, $domain."\r\n");
        while (!feof($fp)) {
            $result .= fgets($fp, 128);
        }
        fclose($fp);

        $result = nl2br(htmlentities($result, ENT_QUOTES, 'UTF-8'));
        echo '<h2>WHOIS Results for '.$domain.'</h2>';
        echo '<table>';
        echo '<tr><th>Property</th><th>Value</th></tr>';

        $lines = explode("\n", $result);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;

            if (preg_match('/^(.*?)\s*:\s*(.*)$/', $line, $matches)) {
                $property = $matches[1];
                $value = $matches[2];
                echo '<tr><td>'.$property.'</td><td>'.$value.'</td></tr>';
            }
        }
        echo '</table>';
    }
}
?>

</body>
</html>
