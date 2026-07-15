<?php

include("connection.php");

$na = $_GET['title'] ?? '';

// helper: run a "WHERE Title = ?" query safely
function ja_by_title($conn, $table, $title) {
    $st = mysqli_prepare($conn, "SELECT * FROM `$table` WHERE Title = ?");
    mysqli_stmt_bind_param($st, 's', $title);
    mysqli_stmt_execute($st);
    return mysqli_stmt_get_result($st);
}

$dataa   = ja_by_title($conn, 'db', $na);
$result1 = mysqli_num_rows($dataa);
$resulta = mysqli_fetch_assoc($dataa);

$d1 = ja_by_title($conn, 'db1', $na);

// Fetch data from the "db2" table
$d2 = ja_by_title($conn, 'db2', $na);
$resultb = mysqli_fetch_assoc($d2);

// Fetch data from the "db" table


// Check if there are any 
$r1 = mysqli_num_rows($d1);
$r2= mysqli_num_rows($d2);


$qu = "SELECT * FROM signup";
$dat = mysqli_query($conn, $qu);

// Fetch data from the "db" table (prepared)
$da = ja_by_title($conn, 'db', $na);

// Check if there are any rows in the "signup" table
$rr = mysqli_num_rows($dat);

// Check if there are any rows in the "db" table
$ree = mysqli_num_rows($da);

$res5 = mysqli_fetch_assoc($da);

$que = "SELECT * FROM db";
$daa = mysqli_query($conn, $que);

// Check if there are any rows in the "signup" table
$rest = mysqli_num_rows($daa);



$res4 = mysqli_fetch_assoc($daa);



?>