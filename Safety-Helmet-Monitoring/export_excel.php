<?php
// Hubungkan ke database
include 'koneksi.php';

// Atur header HTTP agar browser tahu ini adalah file CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=Laporan_Helm_Pintar_' . date('Y-m-d') . '.csv');

// Buka memori file sementara untuk menulis data
$output = fopen('php://output', 'w');

// Tambahkan UTF-8 BOM agar Excel bisa membaca simbol derajat (°)
fputs($output, "\xEF\xBB\xBF");

// ========================================================================
// PERBAIKAN: Tambahkan titik koma (';') di akhir fputcsv sebagai pemisah
// ========================================================================
fputcsv($output, array('Waktu Kejadian', 'Suhu (°C)', 'Kelembapan (%)', 'Oksigen (%)', 'Karbon Dioksida (ppm)', 'Karbon Monoksida (ppm)', 'Hidrogen Sulfida (ppm)', 'Benturan (G)', 'Status'), ';');

$query = "SELECT waktu, suhu, kelembapan, o2, co2, co, h2s, benturan, status FROM sensor_logs ORDER BY waktu DESC";
$result = mysqli_query($conn, $query);

// Tulis Isi Data Baris per Baris ke dalam File CSV
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        // PERBAIKAN: Tambahkan juga titik koma (';') di sini
        fputcsv($output, $row, ';');
    }
}

// Tutup file dan akhiri proses
fclose($output);
exit();
?>