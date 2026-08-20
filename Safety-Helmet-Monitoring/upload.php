<?php
include 'koneksi.php'; // Pastikan terkoneksi ke database

$target_dir = "uploads/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$helm_id = isset($_POST['no_seri']) ? $_POST['no_seri'] : 'UNKNOWN';

if(isset($_FILES["imageFile"])) {
    // 1. Buat nama file unik untuk riwayat (contoh: HELM-001_1680001234.jpg)
    $waktu_sekarang = time();
    $file_name_unik = $helm_id . "_" . $waktu_sekarang . ".jpg";
    $target_file_unik = $target_dir . $file_name_unik;
    
    // 2. Buat nama file statis untuk Live Dashboard
    $file_name_latest = $helm_id . "_latest.jpg";
    $target_file_latest = $target_dir . $file_name_latest;

    if(move_uploaded_file($_FILES["imageFile"]["tmp_name"], $target_file_unik)) {
        // Copy file untuk dashboard live
        copy($target_file_unik, $target_file_latest);
        
        // Update database: cari log BAHAYA terakhir dari helm ini, lalu sisipkan nama fotonya
        $query = "UPDATE sensor_logs 
                  SET foto_insiden = '$file_name_unik' 
                  WHERE no_seri = '$helm_id' AND status = 'BAHAYA' 
                  ORDER BY waktu DESC LIMIT 1";
        mysqli_query($conn, $query);
        
        echo "OK: Foto insiden berhasil disimpan ke riwayat.";
    } else {
        echo "ERROR: Gagal memindahkan file gambar.";
    }
} else {
    echo "ERROR: Payload gambar kosong.";
}
?>