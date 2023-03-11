<?php
/**
 * Indera Registrar System
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 *
 * @license MIT
 */

$brand = '';

$file = fopen("dnl-latest.csv", "r");
while (($data = fgetcsv($file)) !== FALSE) {
    // Check if the first column in the current row is equal to "DNL"
    if ($data[0] == "DNL") {
        // Store the header row in a variable
        $headers = $data;
    } else {
        // Check if the first column in the current row is equal to "1675pou"
        if ($data[0] == $brand) {
            // Create an associative array using the headers as keys
            $row = array_combine($headers, $data);
            // Store the value of the "lookup-key" column in a variable
            $lookupKey = $row['lookup-key'];
            break;
        }
    }
}

fclose($file);

$url = "https://test.tmcnis.org/cnis/".$lookupKey.".xml";
$username = "";
$password = "@";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$xml = curl_exec($ch);

if (curl_errno($ch)) {
	throw new Exception(curl_error($ch));
}
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$xml_object = simplexml_load_string($xml);
$xml_object->registerXPathNamespace("tmNotice", "urn:ietf:params:xml:ns:tmNotice-1.0");
$claims = $xml_object->xpath('//tmNotice:claim');


$note = "This message is a notification that you have applied for a domain name that matches a trademark record submitted to the Trademark Clearinghouse. Your eligibility to register this domain name will depend on your intended use and if it is similar or relates to the trademarks listed below.".PHP_EOL;

$note .= "Please be aware that your rights to register this domain name may not be protected as a noncommercial use or 'fair use' in accordance with the laws of your country. It is crucial that you read and understand the trademark information provided, including the trademarks, jurisdictions, and goods and services for which the trademarks are registered.".PHP_EOL;

$note .= "It's also important to note that not all jurisdictions review trademark applications closely, so some of the trademark information may exist in a national or regional registry that does not conduct a thorough review of trademark rights prior to registration. If you have any questions, it's recommended that you consult with a legal expert or attorney on trademarks and intellectual property for guidance.".PHP_EOL;

$note .= "By continuing with this registration, you're representing that you have received this notice and understand it and, to the best of your knowledge, your registration and use of the requested domain name will not infringe on the trademark rights listed below.".PHP_EOL;

$note .= "The following ".count($claims)." marks are listed in the Trademark Clearinghouse:".PHP_EOL;

$markName = $xml_object->xpath('//tmNotice:markName');
$jurDesc = $xml_object->xpath('//tmNotice:jurDesc');
$class_desc = $xml_object->xpath('//tmNotice:classDesc');


$note .= PHP_EOL;

$claims = $xml_object->xpath('//tmNotice:claim');
foreach($claims as $claim){
    $elements = $claim->xpath('.//*');
    $first_element_a = true;
    $first_element_b = true;
    foreach ($elements as $element) {
        $element_name = trim($element->getName());
        $element_text = trim((string)$element);
        if (!empty($element_name) && !empty($element_text)) {
            if ($element->xpath('..')[0]->getName() == "holder" && $first_element_a) {
                $note .= "Trademark Registrant: ". PHP_EOL;
                $first_element_a = false;
            }
            if ($element->xpath('..')[0]->getName() == "contact" && $first_element_b) {
                $note .= "Trademark Contact: ". PHP_EOL;
                $first_element_b = false;
            }
            $note .= $element_name . ": " . $element_text . PHP_EOL;
        }
    }
    $note .= PHP_EOL;
}

echo $note;
