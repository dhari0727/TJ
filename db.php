<?php
session_start();

if (isset($_SESSION['lastInsertedTitle'])) {
    $lastInsertedTitle = $_SESSION['lastInsertedTitle'];
    echo "Last Inserted Title: " . $lastInsertedTitle;
} else {
    echo "No last inserted title available.";
}
 $lastInsertedTitle = $_SESSION['lastInsertedTitle'];
?>