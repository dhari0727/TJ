<?php 
session_start();
include("login.php");
$em = $_SESSION['eml'];

if($em == true)
{
}
else{
    header("location:login.php");
}
?>