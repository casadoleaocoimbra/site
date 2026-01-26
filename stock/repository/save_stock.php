<?php
include "sqlite_db.php";

$stmt = $db->prepare("INSERT INTO products (name, description, quantity) VALUES (?, ?, ?)");
$stmt->execute([
    $_POST['name'],
    $_POST['description'],
    $_POST['quantity']
]);

echo "Saved successfully!";
