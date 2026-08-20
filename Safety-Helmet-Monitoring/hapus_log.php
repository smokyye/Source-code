<?php
include 'koneksi.php';

if (isset($_GET['aksi'])) {
    if ($_GET['aksi'] == 'hapus_semua') {
        // Menghapus seluruh isi tabel
        $query = "TRUNCATE TABLE sensor_logs";
        $pesan = "Semua log berhasil dibersihkan.";
    } 
    elseif ($_GET['aksi'] == 'hapus_lama') {
        // Menghapus data yang lebih lama dari 30 hari
        $query = "DELETE FROM sensor_logs WHERE waktu < DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $pesan = "Data lama (30 hari+) berhasil dihapus.";
    }

    if (mysqli_query($conn, $query)) {
        echo "<script>
                alert('$pesan');
                window.location.href='log.php';
              </script>";
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}
?>