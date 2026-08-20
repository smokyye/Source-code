<?php
include 'koneksi.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Logika Tambah Helm (CREATE)
   if ($action == 'add') {
        $no_seri = mysqli_real_escape_string($conn, $_POST['no_seri']);
        $nama_pengguna = mysqli_real_escape_string($conn, $_POST['nama_pengguna']);
        $status = mysqli_real_escape_string($conn, $_POST['status']);

        // 1. CEK DULU: Apakah ID Helm sudah terdaftar?
        $cek_query = "SELECT no_seri FROM helmets WHERE no_seri = '$no_seri'";
        $cek_result = mysqli_query($conn, $cek_query);

        if (mysqli_num_rows($cek_result) > 0) {
            // Jika ID sudah ada, batalkan penyimpanan dan kembalikan ke index
            echo "<script>alert('GAGAL: ID Helm sudah terdaftar di sistem!'); window.location.href='index.php';</script>";
            exit;
        } else {
            // 2. Jika ID belum ada, baru jalankan INSERT
            $insert_query = "INSERT INTO helmets (no_seri, nama_pengguna, status) VALUES ('$no_seri', '$nama_pengguna', '$status')";
            if (mysqli_query($conn, $insert_query)) {
                // REDIRECT WAJIB ADA agar tidak terjadi resubmit saat halaman di-refresh (F5)
                header("Location: index.php");
                exit;
            } else {
                echo "Error: " . mysqli_error($conn);
            }
        }
    } 
    // 2. Logika Edit Helm (UPDATE)
    elseif ($action == 'edit') {
        $id_lama = mysqli_real_escape_string($conn, $_POST['id_lama']);
        $no_seri = mysqli_real_escape_string($conn, $_POST['no_seri']);
        $nama_pengguna = mysqli_real_escape_string($conn, $_POST['nama_pengguna']);
        $status = mysqli_real_escape_string($conn, $_POST['status']);

        $query = "UPDATE helmets SET no_seri='$no_seri', nama_pengguna='$nama_pengguna', status='$status' WHERE no_seri='$id_lama'";
        mysqli_query($conn, $query);
    } 
    // 3. Logika Hapus Helm (DELETE)
    elseif ($action == 'delete') {
        $no_seri = mysqli_real_escape_string($conn, $_POST['no_seri']);
        
        // Hapus dari tabel helmets
        mysqli_query($conn, "DELETE FROM helmets WHERE no_seri='$no_seri'");
        // Hapus juga riwayat sensornya agar database tidak penuh (Opsional)
        mysqli_query($conn, "DELETE FROM sensor_logs WHERE no_seri='$no_seri'");
    }

    // Kembalikan pengguna ke halaman utama setelah proses selesai
    header("Location: index.php");
    exit();
}
?>