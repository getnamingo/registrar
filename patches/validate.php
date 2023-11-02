<?php
/**
 * Namingo Registrar
 *
 * Written in 2023 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

// Set up database connection
$db_host = 'your_db_host';
$db_name = 'your_db_name';
$db_user = 'your_db_user';
$db_pass = 'your_db_password';
$db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);

// Check if UUID is provided in URL
if (isset($_GET['uuid'])) {
  $uuid = $_GET['uuid'];

  // Look up UUID in database
  $stmt = $db->prepare("SELECT * FROM contacts WHERE uuid = :uuid");
  $stmt->bindParam(':uuid', $uuid);
  $stmt->execute();
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  // If UUID is found and not yet validated, update database and display success message
  if ($row && $row['validated'] == 0) {
    $contact_id = $row['id'];
    $stmt = $db->prepare("UPDATE contacts SET validated = 1 WHERE id = :id");
    $stmt->bindParam(':id', $contact_id);
    $stmt->execute();
    $message = 'Contact information validated successfully!';
  }
  // If UUID is not found or already validated, display error message
  else {
    $message = 'Error: Invalid or already validated validation link.';
  }
}
// If UUID is not provided in URL, display default message
else {
  $message = 'Please provide a validation link.';
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Contact Validation</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
</head>
<body>
  <div class="container">
    <h1 class="my-5">Contact Validation</h1>
    <?php if (isset($message)) { ?>
      <div class="alert alert-info"><?php echo $message; ?></div>
    <?php } ?>
  </div>
</body>
</html>
