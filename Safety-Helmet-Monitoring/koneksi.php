<?php
// Sesuaikan dengan pengaturan database Laragon/XAMPP Anda
$host = "localhost";
$user = "root";       // Username default
$pass = "";           // Password default biasanya kosong
$db   = "helm_pintar_db"; // Ganti dengan nama database Anda

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}
?>