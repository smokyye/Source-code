<?php
// Hubungkan ke database
include 'koneksi.php';

// 1. TANGKAP ID HELM DARI URL
$id_helm = isset($_GET['id']) ? $_GET['id'] : 'HELM-001';

// 2. AMBIL IDENTITAS HELM & WAKTU TERAKHIR UPDATE (SINKRONISASI DENGAN HOME)
$query_info = "
    SELECT 
        h.nama_pengguna, 
        h.status as status_helm,
        (SELECT TIMESTAMPDIFF(SECOND, MAX(waktu), NOW()) FROM sensor_logs WHERE no_seri = '$id_helm') as detik_sejak_update
    FROM helmets h 
    WHERE h.no_seri = '$id_helm'
";
$result_info = mysqli_query($conn, $query_info);
$helm_info = mysqli_fetch_assoc($result_info);
$nama_pengguna = $helm_info && $helm_info['nama_pengguna'] ? $helm_info['nama_pengguna'] : 'Anonymous Worker';

// LOGIKA SINKRONISASI STATUS KONEKSI AWAL
$is_offline = true;
if ($helm_info['status_helm'] == 'dipakai' && !is_null($helm_info['detik_sejak_update']) && $helm_info['detik_sejak_update'] <= 120) {
    $is_offline = false; // Alat masih aktif dalam 2 menit terakhir
}

// 3. AMBIL DATA UNTUK TABEL RIWAYAT INSIDEN
$query_log = "SELECT * FROM sensor_logs WHERE no_seri = '$id_helm' AND (status = 'BAHAYA' OR status = 'WASPADA') ORDER BY waktu DESC LIMIT 5";
$result_log = mysqli_query($conn, $query_log);

if (mysqli_num_rows($result_log) == 0) {
    $query_log = "SELECT * FROM sensor_logs WHERE status = 'BAHAYA' OR status = 'WASPADA' ORDER BY waktu DESC LIMIT 5";
    $result_log = mysqli_query($conn, $query_log);
}

// 4. AMBIL DATA UNTUK GRAFIK (15 Titik Terakhir)
$query_grafik = "SELECT suhu, kelembapan, o2, DATE_FORMAT(waktu, '%H:%i:%s') as jam FROM (SELECT * FROM sensor_logs WHERE no_seri = '$id_helm' ORDER BY waktu DESC LIMIT 15) sub ORDER BY waktu ASC";
$result_grafik = mysqli_query($conn, $query_grafik);

if (mysqli_num_rows($result_grafik) == 0) {
    $query_grafik = "SELECT suhu, kelembapan, o2, DATE_FORMAT(waktu, '%H:%i:%s') as jam FROM (SELECT * FROM sensor_logs ORDER BY waktu DESC LIMIT 15) sub ORDER BY waktu ASC";
    $result_grafik = mysqli_query($conn, $query_grafik);
}

$label_grafik = [];
$suhu_grafik = [];
$hum_grafik = [];
$o2_grafik = [];
if ($result_grafik && mysqli_num_rows($result_grafik) > 0) {
    while ($row = mysqli_fetch_assoc($result_grafik)) {
        $label_grafik[] = $row['jam'];
        $suhu_grafik[] = (float)$row['suhu'];
        $hum_grafik[] = (float)$row['kelembapan'];
        $o2_grafik[] = (float)$row['o2'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Monitor - <?= htmlspecialchars($id_helm) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/mqtt/dist/mqtt.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        mainbg: '#f8fafc',      // Latar belakang aplikasi (slate-50)
                        cardbg: '#ffffff',      // Latar belakang kartu (putih)
                        cardborder: '#e2e8f0',  // Border kartu (slate-200)
                        brandgreen: '#16a34a',  // Hijau terang yang lebih pas
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-mainbg text-slate-800 font-sans p-4 md:p-6 min-h-screen">

    <div class="w-full space-y-6 mx-auto px-2 md:px-4">

        <header class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col md:flex-row justify-between items-center shadow-sm gap-4">
            <div class="flex items-center gap-4">
                <a href="index.php" class="p-2.5 bg-slate-100 rounded-lg border border-slate-200 hover:bg-slate-200 hover:text-brandgreen transition-colors text-slate-500 group">
                    <svg class="w-6 h-6 group-hover:-translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                </a>
                <div class="w-12 h-12 bg-slate-100 border border-slate-200 rounded-lg flex items-center justify-center shrink-0">
                    <img src="assets/logo.png" alt="Logo" class="w-8 h-8 object-contain">
                </div>
                <div class="flex flex-col">
                    <h1 class="text-xl md:text-2xl font-bold text-slate-900 tracking-wide"><?= htmlspecialchars($nama_pengguna) ?></h1>
                    <div class="flex items-center gap-2 mt-0.5">
                        <span class="text-xs font-mono bg-green-50 text-brandgreen px-2 py-0.5 rounded border border-green-200">ID: <?= htmlspecialchars($id_helm) ?></span>
                        <span class="text-[10px] text-slate-400 uppercase tracking-widest">Live Monitoring Analytics</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2 bg-slate-50 px-3 py-1.5 rounded-full border border-slate-200">
                    <?php if ($is_offline): ?>
                        <span id="connection-dot" class="w-2.5 h-2.5 bg-red-500 shadow-[0_0_8px_#ef4444] rounded-full"></span>
                        <span id="connection-text" class="text-xs font-semibold text-red-600">Disconnected</span>
                    <?php else: ?>
                        <span id="connection-dot" class="w-2.5 h-2.5 bg-yellow-400 animate-pulse shadow-[0_0_8px_#facc15] rounded-full"></span>
                        <span id="connection-text" class="text-xs font-semibold text-amber-600">Waiting...</span>
                    <?php endif; ?>
                </div>
                <div class="bg-slate-50 px-4 py-2 rounded-lg border border-slate-200 font-mono text-sm text-slate-700 tracking-wider shadow-inner" id="clock">
                    -- : -- : --
                </div>
            </div>
        </header>

        <div class="bg-white border border-slate-200 shadow-sm rounded-xl p-4 flex flex-col sm:flex-row items-start sm:items-center gap-4 sm:gap-6">
            <h2 class="text-lg font-bold text-slate-800 whitespace-nowrap">Current Status :</h2>
            <div class="flex flex-wrap gap-3">
                <span id="btn-aman" class="px-6 py-1.5 rounded-md border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-400 transition-all duration-300">SAFE</span>
                <span id="btn-waspada" class="px-6 py-1.5 rounded-md border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-400 transition-all duration-300">WARNING</span>
                <span id="btn-bahaya" class="px-6 py-1.5 rounded-md border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-400 transition-all duration-300">DANGER</span>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-5 relative">
                <p class="text-sm text-slate-500 mb-1 font-medium">Ambient Temperature</p>
                <p class="text-3xl font-bold text-slate-900 mb-4" id="val-temp">0.0 <span class="text-sm text-slate-400 font-normal">°C</span></p>
                <div class="w-full bg-slate-200 rounded-full h-1.5 mb-4">
                    <div id="bar-temp" class="bg-brandgreen h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                </div>
                <div class="flex items-center gap-2 text-xs"><span class="text-slate-500"> Safe Standard :</span><span class="border border-green-200 text-brandgreen px-2 py-0.5 rounded bg-green-50">
                        < 35 °C</span>
                </div>
            </div>
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-5 relative">
                <p class="text-sm text-slate-500 mb-1 font-medium">Humidity</p>
                <p class="text-3xl font-bold text-slate-900 mb-4" id="val-hum">0.0 <span class="text-sm text-slate-400 font-normal">%</span></p>
                <div class="w-full bg-slate-200 rounded-full h-1.5 mb-4">
                    <div id="bar-hum" class="bg-brandgreen h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                </div>
                <div class="flex items-center gap-2 text-xs"><span class="text-slate-500">Safe Standard :</span><span class="border border-green-200 text-brandgreen px-2 py-0.5 rounded bg-green-50">40% - 60%</span></div>
            </div>
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-5 relative">
                <p class="text-sm text-slate-500 mb-1 font-medium">Oxygen Level (O2)</p>
                <p class="text-3xl font-bold text-slate-900 mb-4" id="val-o2">0.0 <span class="text-sm text-slate-400 font-normal">%</span></p>
                <div class="w-full bg-slate-200 rounded-full h-1.5 mb-4">
                    <div id="bar-o2" class="bg-brandgreen h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                </div>
                <div class="flex items-center gap-2 text-xs"><span class="text-slate-500">Safe Standard :</span><span class="border border-green-200 text-brandgreen px-2 py-0.5 rounded bg-green-50">> 19.5 %</span></div>
            </div>
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-5 relative">
                <p class="text-sm text-slate-500 mb-1 font-medium">Impact Detection</p>
                <p class="text-3xl font-bold text-slate-900 mb-4" id="val-gforce">0.0 <span class="text-sm text-slate-400 font-normal">G</span></p>
                <div class="w-full bg-slate-200 rounded-full h-1.5 mb-4">
                    <div id="bar-gforce" class="bg-brandgreen h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                </div>
                <div class="flex items-center gap-2 text-xs"><span class="text-slate-500">Safe Standard :</span><span class="border border-green-200 text-brandgreen px-2 py-0.5 rounded bg-green-50">
                        < 1.5 G</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-5 lg:col-span-1">
                <h3 class="text-lg font-bold text-slate-800 mb-6">Toxic Gas Detection</h3>
                <div class="space-y-5">
                    <div class="bg-slate-50 p-4 rounded-lg border border-slate-100">
                        <div class="flex justify-between items-end mb-2"><span class="text-sm font-medium text-slate-600">Carbon Monoxide (CO)</span><span class="text-brandgreen font-bold text-sm" id="val-co">0.0 ppm</span></div>
                        <div class="w-full bg-slate-200 rounded-full h-1.5 mb-1">
                            <div id="bar-co" class="bg-brandgreen h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between text-[10px] font-medium text-slate-400"><span>Min : 0 ppm</span><span>Max : 25 ppm</span></div>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-lg border border-slate-100">
                        <div class="flex justify-between items-end mb-2"><span class="text-sm font-medium text-slate-600">Carbon Dioxide (CO2)</span><span class="text-brandgreen font-bold text-sm" id="val-co2">0.0 ppm</span></div>
                        <div class="w-full bg-slate-200 rounded-full h-1.5 mb-1">
                            <div id="bar-co2" class="bg-brandgreen h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between text-[10px] font-medium text-slate-400"><span>Min : 0 ppm</span><span>Max : 1000 ppm</span></div>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-lg border border-slate-100">
                        <div class="flex justify-between items-end mb-2"><span class="text-sm font-medium text-slate-600">Hydrogen Sulfide (H2S)</span><span class="text-brandgreen font-bold text-sm" id="val-h2s">0.0 ppm</span></div>
                        <div class="w-full bg-slate-200 rounded-full h-1.5 mb-1">
                            <div id="bar-h2s" class="bg-brandgreen h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between text-[10px] font-medium text-slate-400"><span>Min : 0 ppm</span><span>Max : 10 ppm</span></div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-5 lg:col-span-2 flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2"><svg class="w-5 h-5 text-brandgreen" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                        </svg>
                        <h3 class="text-sm font-bold text-slate-700">Live Environmental Data</h3>
                    </div>
                </div>
                <div class="relative w-full flex-grow min-h-[300px]"><canvas id="envChart"></canvas></div>
                <div class="flex justify-center items-center gap-8 mt-6 pt-4 border-t border-slate-100">
                    <div class="flex items-center gap-2 text-red-500"><span class="text-sm font-medium">Temperature</span></div>
                    <div class="flex items-center gap-2 text-blue-500"><span class="text-sm font-medium">Humidity</span></div>
                    <div class="flex items-center gap-2 text-emerald-500"><span class="text-sm font-medium">Oxygen</span></div>
                </div>
            </div>
        </div>
        <!-- PANEL INCIDENT CAMERA -->
        <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-5 mb-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <h3 class="text-sm font-bold text-slate-700">Incident Camera Snapshot</h3>
                </div>
                <span id="camera-badge" class="px-2 py-1 text-[10px] font-bold rounded bg-slate-100 text-slate-500 border border-slate-200 uppercase tracking-wider">Standby</span>
            </div>
            
            <!-- Kotak Penampil Gambar -->
            <div class="relative w-full bg-slate-50 rounded-lg overflow-hidden flex items-center justify-center min-h-[300px] border border-dashed border-slate-300">
                <div id="camera-placeholder" class="text-center">
                    <svg class="w-12 h-12 text-slate-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span class="text-slate-400 text-sm font-medium">Waiting for incident trigger...</span>
                </div>
                <!-- Tag IMG disembunyikan secara default -->
                <img id="incident-image" src="" alt="Captured Incident" class="absolute inset-0 w-full h-full object-contain hidden bg-black/5 backdrop-blur-sm">
            </div>
            <p class="text-[10px] text-slate-400 mt-3 text-center">Images are captured and transmitted automatically via Wi-Fi/ESP-NOW during DANGER status.</p>
        </div>

        <div class="bg-white shadow-sm border border-slate-200 rounded-xl p-5 min-h-[150px]">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                <h3 class="text-lg font-bold text-slate-800">Incident History for <?= htmlspecialchars($id_helm) ?></h3>

                <!-- Tombol Aksi -->
                <div class="flex gap-3">
                    <a href="export_excel.php?id=<?= urlencode($id_helm) ?>" class="flex items-center gap-2 px-4 py-2 bg-green-50 text-brandgreen hover:bg-brandgreen hover:text-white border border-green-200 rounded-lg text-xs font-bold transition-all duration-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        Download Excel
                    </a>

                    <a href="log.php?id=<?= urlencode($id_helm) ?>" class="flex items-center gap-2 px-4 py-2 bg-slate-50 text-slate-600 hover:bg-slate-100 hover:text-slate-900 border border-slate-200 rounded-lg text-xs font-bold transition-all duration-300">
                        View All
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-500 text-[11px] tracking-wider uppercase bg-slate-50 rounded-t-lg">
                            <th class="p-3 font-semibold">Time</th>
                            <th class="p-3 font-semibold text-center">Temp(°C)</th>
                            <th class="p-3 font-semibold text-center">Hum (%)</th>
                            <th class="p-3 font-semibold text-center">O2 (%)</th>
                            <th class="p-3 font-semibold text-center">CO2 (PPM)</th>
                            <th class="p-3 font-semibold text-center">CO (PPM)</th>
                            <th class="p-3 font-semibold text-center">H2S(PPM)</th>
                            <th class="p-3 font-semibold text-center">Impact (G)</th>
                            <th class="p-3 font-semibold text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php
                        if (isset($result_log) && mysqli_num_rows($result_log) > 0) {
                            while ($row = mysqli_fetch_assoc($result_log)) {
                                $status_color = "text-green-600 font-medium";
                                if (strtoupper($row['status']) == 'BAHAYA' || strtoupper($row['status']) == 'ALAT TERPUTUS') $status_color = "text-red-600 font-bold";
                                elseif (strtoupper($row['status']) == 'WASPADA') $status_color = "text-yellow-600 font-bold";
                        ?>
                                <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                                    <td class="p-3 font-mono text-xs text-slate-500"><?= $row['waktu'] ?></td>
                                    <td class="p-3 text-center text-slate-700"><?= $row['suhu'] ?>°C</td>
                                    <td class="p-3 text-center text-slate-700"><?= $row['kelembapan'] ?>%</td>
                                    <td class="p-3 text-center text-slate-700"><?= $row['o2'] ?>%</td>
                                    <td class="p-3 text-center text-slate-700"><?= $row['co2'] ?></td>
                                    <td class="p-3 text-center <?= $row['co'] > 25 ? 'text-red-600 font-bold' : 'text-slate-700' ?>"><?= $row['co'] ?></td>
                                    <td class="p-3 text-center <?= $row['h2s'] > 1 ? 'text-red-600 font-bold' : 'text-slate-700' ?>"><?= $row['h2s'] ?></td>
                                    <td class="p-3 text-center <?= $row['benturan'] > 1.5 ? 'text-red-600 font-bold' : 'text-slate-700' ?>"><?= $row['benturan'] ?> G</td>
                                    <td class="p-3 text-center <?= $status_color ?>"><?= strtoupper($row['status']) ?></td>
                                </tr>
                        <?php }
                        } else {
                            echo "<tr><td colspan='9' class='p-6 text-center text-slate-400 italic'>No incident history saved.</td></tr>";
                        } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const HELMET_ID = '<?= $id_helm ?>';

        // ================= FUNGSI SIMPAN KE DATABASE =================
        function catatKeDatabase(sensorData) {
            fetch('simpan_log.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(sensorData)
            }).catch(err => console.error("Gagal menyimpan log:", err));
        }

        // ================= FUNGSI UPDATE TABEL OTOMATIS =================
        function tambahBarisTabel(waktu, data, status) {
            const tbody = document.querySelector('table tbody');

            // Hapus pesan "No incident history saved" jika ada
            if (tbody.innerText.includes("No incident history saved")) {
                tbody.innerHTML = '';
            }

            let statusWarna = "text-green-600 font-medium";
            if (status === 'BAHAYA') statusWarna = "text-red-600 font-bold";
            else if (status === 'WASPADA') statusWarna = "text-yellow-600 font-bold";

            const coWarna = (data.co > 25) ? 'text-red-600 font-bold' : 'text-slate-700';
            const h2sWarna = (data.h2s > 1) ? 'text-red-600 font-bold' : 'text-slate-700';
            const benturanWarna = (data.g_force > 1.5) ? 'text-red-600 font-bold' : 'text-slate-700';

            const barisBaru = `
                <tr class="border-b border-slate-100 hover:bg-slate-50 transition animate-pulse">
                    <td class="p-3 font-mono text-xs text-slate-500">${waktu}</td>
                    <td class="p-3 text-center text-slate-700">${data.temperature.toFixed(1)}°C</td>
                    <td class="p-3 text-center text-slate-700">${data.humidity.toFixed(0)}%</td>
                    <td class="p-3 text-center text-slate-700">${data.o2.toFixed(1)}%</td>
                    <td class="p-3 text-center text-slate-700">${data.co2.toFixed(0)}</td>
                    <td class="p-3 text-center ${coWarna}">${data.co.toFixed(0)}</td>
                    <td class="p-3 text-center ${h2sWarna}">${data.h2s.toFixed(1)}</td>
                    <td class="p-3 text-center ${benturanWarna}">${data.g_force.toFixed(1)} G</td>
                    <td class="p-3 text-center ${statusWarna}">${status}</td>
                </tr>
            `;

            // Sisipkan baris baru di paling atas tabel
            tbody.insertAdjacentHTML('afterbegin', barisBaru);

            // Hapus efek kedip setelah 3 detik
            setTimeout(() => {
                tbody.firstElementChild.classList.remove('animate-pulse');
            }, 3000);

            // Jaga agar tabel maksimal hanya menampilkan 5 baris terbaru
            if (tbody.children.length > 5) {
                tbody.removeChild(tbody.lastElementChild);
            }
        }

        // ================= UI RESET & JAM =================
        function resetIndicatorsToZero() {
            document.getElementById('val-temp').innerHTML = `0.0 <span class="text-sm text-slate-400 font-normal">°C</span>`;
            document.getElementById('val-hum').innerHTML = `0.0 <span class="text-sm text-slate-400 font-normal">%</span>`;
            document.getElementById('val-o2').innerHTML = `0.0 <span class="text-sm text-slate-400 font-normal">%</span>`;
            document.getElementById('val-gforce').innerHTML = `0.0 <span class="text-sm text-slate-400 font-normal">G</span>`;
            document.getElementById('val-co').innerHTML = `0.0 <span class="text-xs text-slate-400 font-normal">ppm</span>`;
            document.getElementById('val-co2').innerHTML = `0.0 <span class="text-xs text-slate-400 font-normal">ppm</span>`;
            document.getElementById('val-h2s').innerHTML = `0.0 <span class="text-xs text-slate-400 font-normal">ppm</span>`;

            document.getElementById('bar-temp').style.width = '0%';
            document.getElementById('bar-hum').style.width = '0%';
            document.getElementById('bar-o2').style.width = '0%';
            document.getElementById('bar-gforce').style.width = '0%';
            document.getElementById('bar-co').style.width = '0%';
            document.getElementById('bar-co2').style.width = '0%';
            document.getElementById('bar-h2s').style.width = '0%';

            const btnAman = document.getElementById('btn-aman');
            const btnWaspada = document.getElementById('btn-waspada');
            const btnBahaya = document.getElementById('btn-bahaya');
            const inactiveClass = "px-6 py-1.5 rounded-md border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-400 transition-all duration-300";
            btnAman.className = inactiveClass;
            btnWaspada.className = inactiveClass;
            btnBahaya.className = inactiveClass;
        }

        setInterval(() => {
            const now = new Date();
            document.getElementById('clock').innerText = `${String(now.getHours()).padStart(2, '0')} : ${String(now.getMinutes()).padStart(2, '0')} : ${String(now.getSeconds()).padStart(2, '0')}`;
        }, 1000);

        // ================= KONEKSI MQTT =================
        const client = mqtt.connect('wss://broker.emqx.io:8084/mqtt');
        const connDot = document.getElementById('connection-dot');
        const connText = document.getElementById('connection-text');

        function setConnectionUI(status) {
            if (!connDot || !connText) return;
            connDot.className = "w-2.5 h-2.5 rounded-full";
            if (status === 'connected') {
                connDot.classList.add('bg-green-500', 'shadow-[0_0_8px_#22c55e]');
                connText.innerText = "Connected";
                connText.className = "text-xs font-semibold text-green-600";
            } else if (status === 'disconnected') {
                connDot.classList.add('bg-red-500', 'shadow-[0_0_8px_#ef4444]', 'animate-pulse');
                connText.innerText = "Disconnected";
                connText.className = "text-xs font-semibold text-red-600";
            } else {
                connDot.classList.add('bg-yellow-400', 'shadow-[0_0_8px_#facc15]', 'animate-pulse');
                connText.innerText = "Waiting Data...";
                connText.className = "text-xs font-semibold text-amber-600";
            }
        }

        let deviceWatchdog;
        let lastIncidentSave = 0; // Timer untuk mencegah penyimpanan duplikat (spam)
        let lastSaveTimes = 0; // Timer untuk simpan rutin (misal tiap 1 menit jika diperlukan)
        const SAVE_INTERVAL = 60000;

        function resetWatchdog() {
            clearTimeout(deviceWatchdog);
            deviceWatchdog = setTimeout(() => {
                setConnectionUI('disconnected');
                resetIndicatorsToZero();
            }, 10000);
        }

        client.on('connect', function() {
            <?php if (!$is_offline): ?> setConnectionUI('connecting');
            <?php endif; ?>
            client.subscribe(`tambang/helm/+/data`);
            resetWatchdog();
        });

        client.on('close', function() {
            setConnectionUI('disconnected');
            resetIndicatorsToZero();
        });

        client.on('message', function(topic, message) {
            // Filter pesan khusus untuk ID Helm ini
            const parts = topic.split('/');
            if (parts.length >= 4 && parts[2].toLowerCase() !== HELMET_ID.toLowerCase()) return;

            setConnectionUI('connected');
            resetWatchdog();

            let data;
            try {
                data = JSON.parse(message.toString());
            } catch (e) {
                return;
            }

            // =======================================================
            // SMART FALLBACK: Mengakali perbedaan nama kunci JSON 
            // =======================================================
            let suhu = data.temperature !== undefined ? data.temperature : (data.temp !== undefined ? data.temp : 0);
            let gForce = data.g_force !== undefined ? data.g_force : (data.benturan !== undefined ? data.benturan : 0);

            // 1. Suhu
            document.getElementById('val-temp').innerHTML = `${suhu.toFixed(1)} <span class="text-sm text-slate-400 font-normal">°C</span>`;
            let barTemp = document.getElementById('bar-temp');
            if (barTemp) {
                barTemp.style.width = Math.min((suhu / 50) * 100, 100) + '%';
                barTemp.className = (suhu > 35) ? "bg-red-500 h-1.5 rounded-full transition-all duration-500" : "bg-brandgreen h-1.5 rounded-full transition-all duration-500";
            }

            // 2. Kelembaban
            if (data.humidity !== undefined) {
                document.getElementById('val-hum').innerHTML = `${data.humidity.toFixed(0)} <span class="text-sm text-slate-400 font-normal">%</span>`;
                let barHum = document.getElementById('bar-hum');
                if (barHum) {
                    barHum.style.width = Math.min(data.humidity, 100) + '%';
                    barHum.className = (data.humidity < 40 || data.humidity > 60) ? "bg-yellow-500 h-1.5 rounded-full transition-all duration-500" : "bg-brandgreen h-1.5 rounded-full transition-all duration-500";
                }
            }

            // 3. Oksigen
            if (data.o2 !== undefined) {
                document.getElementById('val-o2').innerHTML = `${data.o2.toFixed(1)} <span class="text-sm text-slate-400 font-normal">%</span>`;
                let barO2 = document.getElementById('bar-o2');
                if (barO2) {
                    barO2.style.width = Math.min((data.o2 / 25) * 100, 100) + '%';
                    barO2.className = (data.o2 < 19.5) ? "bg-red-500 h-1.5 rounded-full transition-all duration-500" : "bg-brandgreen h-1.5 rounded-full transition-all duration-500";
                }
            }

            // 4. Benturan / G-Force
            document.getElementById('val-gforce').innerHTML = `${gForce.toFixed(1)} <span class="text-sm text-slate-400 font-normal">G</span>`;
            let barG = document.getElementById('bar-gforce');
            if (barG) {
                barG.style.width = Math.min((gForce / 5) * 100, 100) + '%';
                barG.className = (gForce > 1.5) ? "bg-red-500 h-1.5 rounded-full transition-all duration-500" : "bg-brandgreen h-1.5 rounded-full transition-all duration-500";
            }

            // 5. Gas CO
            if (data.co !== undefined) {
                document.getElementById('val-co').innerHTML = `${data.co.toFixed(0)} <span class="text-xs text-slate-400 font-normal">ppm</span>`;
                document.getElementById('val-co').className = (data.co > 25) ? "text-red-600 font-bold text-sm" : "text-brandgreen font-bold text-sm";
                let barCO = document.getElementById('bar-co');
                if (barCO) {
                    barCO.style.width = Math.min((data.co / 25) * 100, 100) + '%';
                    barCO.className = (data.co > 25) ? "bg-red-500 h-1.5 rounded-full transition-all duration-500" : "bg-brandgreen h-1.5 rounded-full transition-all duration-500";
                }
            }

            // 6. Gas CO2
            if (data.co2 !== undefined) {
                document.getElementById('val-co2').innerHTML = `${data.co2.toFixed(0)} <span class="text-xs text-slate-400 font-normal">ppm</span>`;
                let barCO2 = document.getElementById('bar-co2');
                if (barCO2) {
                    barCO2.style.width = Math.min((data.co2 / 1000) * 100, 100) + '%';
                    barCO2.className = (data.co2 > 800) ? "bg-red-500 h-1.5 rounded-full transition-all duration-500" : "bg-brandgreen h-1.5 rounded-full transition-all duration-500";
                }
            }

            // 7. Gas H2S
            if (data.h2s !== undefined) {
                document.getElementById('val-h2s').innerHTML = `${data.h2s.toFixed(1)} <span class="text-xs text-slate-400 font-normal">ppm</span>`;
                document.getElementById('val-h2s').className = (data.h2s > 1) ? "text-red-600 font-bold text-sm" : "text-brandgreen font-bold text-sm";
                let barH2S = document.getElementById('bar-h2s');
                if (barH2S) {
                    barH2S.style.width = Math.min((data.h2s / 10) * 100, 100) + '%';
                    barH2S.className = (data.h2s > 5) ? "bg-red-500 h-1.5 rounded-full transition-all duration-500" : "bg-brandgreen h-1.5 rounded-full transition-all duration-500";
                }
            }

            // =======================================================
            // UPDATE GRAFIK & STATUS K3
            // =======================================================
            const now = new Date();
            if (window.myLineChart) {
                const timeLabel = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');
                window.myLineChart.data.labels.push(timeLabel);
                window.myLineChart.data.datasets[0].data.push(suhu);
                window.myLineChart.data.datasets[1].data.push(data.humidity || 0);
                window.myLineChart.data.datasets[2].data.push(data.o2 || 0);

                if (window.myLineChart.data.labels.length > 15) {
                    window.myLineChart.data.labels.shift();
                    window.myLineChart.data.datasets[0].data.shift();
                    window.myLineChart.data.datasets[1].data.shift();
                    window.myLineChart.data.datasets[2].data.shift();
                }
                window.myLineChart.update();
            }

            let statusLevelSaatIni = data.status ? data.status.toUpperCase() : 'AMAN';
            const btnAman = document.getElementById('btn-aman');
            const btnWaspada = document.getElementById('btn-waspada');
            const btnBahaya = document.getElementById('btn-bahaya');
            const inactiveClass = "px-6 py-1.5 rounded-md border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-400 transition-all duration-300";

            btnAman.className = inactiveClass;
            btnWaspada.className = inactiveClass;
            btnBahaya.className = inactiveClass;

            if (statusLevelSaatIni === 'AMAN') {
                btnAman.className = "px-6 py-1.5 rounded-md border border-green-200 bg-green-50 text-sm font-bold text-green-700 transition-all duration-300";
                document.getElementById('camera-badge').className = "px-2 py-1 text-[10px] font-bold rounded bg-slate-100 text-slate-500 border border-slate-200 uppercase tracking-wider";
                document.getElementById('camera-badge').innerText = "Standby";

            } else if (statusLevelSaatIni === 'WASPADA') {
                btnWaspada.className = "px-6 py-1.5 rounded-md border border-yellow-200 bg-yellow-50 text-sm font-bold text-yellow-700 transition-all duration-300";
                
            } else if (statusLevelSaatIni === 'BAHAYA') {
                btnBahaya.className = "px-6 py-1.5 rounded-md border border-red-200 bg-red-50 text-sm font-bold text-red-600 animate-pulse transition-all duration-300";
                
                // --- LOGIKA PEMICU KAMERA INSIDEN ---
                document.getElementById('camera-badge').className = "px-2 py-1 text-[10px] font-bold rounded bg-red-100 text-red-600 border border-red-200 uppercase tracking-wider animate-pulse";
                document.getElementById('camera-badge').innerText = "Image Captured";
                
                const imgElement = document.getElementById('incident-image');
                const placeholder = document.getElementById('camera-placeholder');
                
                // Tampilkan gambar dan sembunyikan placeholder
                imgElement.classList.remove('hidden');
                placeholder.classList.add('hidden');
                
                // Asumsi: ESP32 mengirim file dengan nama HELM-001.jpg (di-overwrite setiap insiden)
                // Parameter '?t=' digunakan sebagai cache-buster agar browser memuat ulang gambar baru, bukan gambar lama dari memori
                imgElement.src = `uploads/${HELM_ID}.jpg?t=` + new Date().getTime();
            }

            // =======================================================
            // SIMPAN KE DATABASE LOKAL
            // =======================================================
            let currentTimeMs = Date.now();
            const jamSQL = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');

            if (statusLevelSaatIni === 'BAHAYA' || statusLevelSaatIni === 'WASPADA') {
                if (currentTimeMs - lastIncidentSave > 5000) {
                    catatKeDatabase({
                        no_seri: HELMET_ID,
                        suhu: suhu,
                        kelembapan: data.humidity || 0,
                        o2: data.o2 || 0,
                        co: data.co || 0,
                        co2: data.co2 || 0,
                        h2s: data.h2s || 0,
                        benturan: gForce,
                        status: statusLevelSaatIni
                    });
                    if (typeof tambahBarisTabel === "function") {
                        tambahBarisTabel(jamSQL, {
                            temperature: suhu,
                            humidity: data.humidity || 0,
                            o2: data.o2 || 0,
                            co: data.co || 0,
                            co2: data.co2 || 0,
                            h2s: data.h2s || 0,
                            g_force: gForce
                        }, statusLevelSaatIni);
                    }
                    lastIncidentSave = currentTimeMs;
                }
            }

            if (currentTimeMs - lastSaveTimes >= SAVE_INTERVAL) {
                catatKeDatabase({
                    no_seri: HELMET_ID,
                    suhu: suhu,
                    kelembapan: data.humidity || 0,
                    o2: data.o2 || 0,
                    co: data.co || 0,
                    co2: data.co2 || 0,
                    h2s: data.h2s || 0,
                    benturan: gForce,
                    status: statusLevelSaatIni
                });
                lastSaveTimes = currentTimeMs;
            }
        });

        // ================= INISIALISASI GRAFIK =================
        const ctx = document.getElementById('envChart').getContext('2d');
        window.myLineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($label_grafik) ?>,
                datasets: [{
                        label: 'Temp (°C)',
                        data: <?= json_encode($suhu_grafik) ?>,
                        borderColor: '#ef4444',
                        backgroundColor: '#ef4444',
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 4
                    },
                    {
                        label: 'Humidity (%)',
                        data: <?= json_encode($hum_grafik) ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: '#3b82f6',
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 4
                    },
                    {
                        label: 'Oxygen (%)',
                        data: <?= json_encode($o2_grafik) ?>,
                        borderColor: '#10b981',
                        backgroundColor: '#10b981',
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: {
                            color: '#e2e8f0' // Garis bantu grafik warna abu-abu terang
                        }
                    },
                    x: {
                        grid: {
                            color: '#e2e8f0' // Garis bantu grafik warna abu-abu terang
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>