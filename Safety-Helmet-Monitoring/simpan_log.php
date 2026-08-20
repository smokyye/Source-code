<?php
// Hubungkan ke database
include 'koneksi.php';

// Terima data berformat JSON dari JavaScript (AJAX)
$request_body = file_get_contents('php://input');
$data = json_decode($request_body, true);

if ($data) {
    // AMBIL DATA NO_SERI / ID HELM (Sangat Krusial!)
    $no_seri = isset($data['no_seri']) ? mysqli_real_escape_string($conn, $data['no_seri']) : '';

    // Ambil data yang dikirim, jika kosong beri nilai 0
    $suhu = isset($data['suhu']) ? (float)$data['suhu'] : 0;
    $kelembapan = isset($data['kelembapan']) ? (float)$data['kelembapan'] : 0;
    $o2 = isset($data['o2']) ? (float)$data['o2'] : 0;
    $co = isset($data['co']) ? (float)$data['co'] : 0;
    $co2 = isset($data['co2']) ? (float)$data['co2'] : 0;
    $h2s = isset($data['h2s']) ? (float)$data['h2s'] : 0;
    $benturan = isset($data['benturan']) ? (float)$data['benturan'] : 0;
    $status = isset($data['status']) ? mysqli_real_escape_string($conn, $data['status']) : 'OFFLINE';

    // Pastikan no_seri tidak kosong sebelum disimpan
    if (!empty($no_seri)) {
        // MASUKKAN KE DATABASE (no_seri dan nilainya sudah ditambahkan ke dalam kueri)
        $query = "INSERT INTO sensor_logs (no_seri, suhu, kelembapan, o2, co, co2, h2s, benturan, status) 
                  VALUES ('$no_seri', '$suhu', '$kelembapan', '$o2', '$co', '$co2', '$h2s', '$benturan', '$status')";

        if (mysqli_query($conn, $query)) {
            echo json_encode(["status" => "success", "message" => "Log untuk $no_seri berhasil disimpan"]);
        } else {
            echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Gagal menyimpan, no_seri/ID Helm tidak ditemukan"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Tidak ada data yang diterima"]);
}
?>