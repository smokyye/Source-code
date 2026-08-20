<?php
// Hubungkan ke database
include 'koneksi.php';

// Ambil data terbaru (limit 100 agar halaman tidak terlalu berat)
// Sesuaikan nama tabel 'sensor_logs' dengan nama tabel di database Anda
$query = "SELECT * FROM sensor_logs ORDER BY waktu DESC LIMIT 100";
$result = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History Log</title>
    <script src="https://cdn.tailwindcss.com"></script>
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

    <div class="w-full space-y-6 max-w-7xl mx-auto">
        <header class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col md:flex-row justify-between items-center shadow-sm gap-4">
            <div>
                <h1 class="text-xl md:text-2xl font-bold text-slate-900 tracking-wide">Sensor History Logs</h1>
                <p class="text-xs text-slate-500 mt-1">Historical Data of Working Environment</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="index.php" class="px-4 py-2 bg-slate-100 border border-slate-200 text-slate-600 hover:bg-slate-200 hover:text-slate-900 rounded-lg text-sm font-semibold transition-colors">
                    Back to Dashboard
                </a>
                
                <a href="hapus_log.php?aksi=hapus_semua" 
                   onclick="return confirm('PERINGATAN: Semua data akan dihapus permanen. Lanjutkan?')"
                   class="px-4 py-2 bg-red-50 border border-red-200 text-red-600 hover:bg-red-600 hover:text-white rounded-lg text-sm font-bold transition-colors">
                    Clear All Logs
                </a>
                
                <a href="export_excel.php" class="px-4 py-2 bg-green-50 border border-green-200 text-brandgreen hover:bg-brandgreen hover:text-white rounded-lg text-sm font-bold transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    Download Excel (CSV)
                </a>
            </div>
        </header>

        <div class="bg-white border border-slate-200 shadow-sm rounded-xl p-5 overflow-x-auto">
            <table class="w-full text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-500 text-xs tracking-wider uppercase bg-slate-50 rounded-t-lg">
                        <th class="p-3 font-semibold">Time</th>
                        <th class="p-3 font-semibold text-center">Temp(°C)</th>
                        <th class="p-3 font-semibold text-center">Hum(%)</th>
                        <th class="p-3 font-semibold text-center">O2(%)</th>
                        <th class="p-3 font-semibold text-center">CO2(ppm)</th>
                        <th class="p-3 font-semibold text-center">CO(ppm)</th>
                        <th class="p-3 font-semibold text-center">H2S(ppm)</th>
                        <th class="p-3 font-semibold text-center">Impact(G)</th>
                        <th class="p-3 font-semibold text-center">Status</th>
                        <!-- KOLOM BARU -->
                        <th class="p-3 font-semibold text-center">Evidence</th>
                    </tr>
                </thead>
                <tbody class="text-sm">
                    <?php 
                    if (mysqli_num_rows($result) > 0) {
                        while($row = mysqli_fetch_assoc($result)) { 
                            $status_color = "text-green-600 font-medium"; 
                            if (strtoupper($row['status']) == 'BAHAYA') {
                                $status_color = "text-red-600 font-bold animate-pulse";
                            } elseif (strtoupper($row['status']) == 'WASPADA') {
                                $status_color = "text-yellow-600 font-bold";
                            }
                    ?>
                    <tr class="border-b border-slate-100 hover:bg-slate-50 transition-colors">
                        <td class="p-3 font-mono text-xs text-slate-500"><?= $row['waktu'] ?></td>
                        <td class="p-3 text-center text-slate-700"><?= $row['suhu'] ?></td>
                        <td class="p-3 text-center text-slate-700"><?= $row['kelembapan'] ?></td>
                        <td class="p-3 text-center text-slate-700"><?= $row['o2'] ?></td>
                        <td class="p-3 text-center text-slate-700"><?= $row['co2'] ?></td>
                        <td class="p-3 text-center <?= $row['co'] > 25 ? 'text-red-600 font-bold' : 'text-slate-700' ?>"><?= $row['co'] ?></td>
                        <td class="p-3 text-center <?= $row['h2s'] > 1 ? 'text-red-600 font-bold' : 'text-slate-700' ?>"><?= $row['h2s'] ?></td>
                        <td class="p-3 text-center text-slate-700"><?= $row['benturan'] ?></td>
                        <td class="p-3 text-center <?= $status_color ?>"><?= strtoupper($row['status']) ?></td>
                        
                        <!-- KOLOM TOMBOL FOTO -->
                        <td class="p-3 text-center">
                            <?php if (!empty($row['foto_insiden']) && file_exists('uploads/' . $row['foto_insiden'])): ?>
                                <a href="uploads/<?= $row['foto_insiden'] ?>" target="_blank" class="inline-flex items-center gap-1 px-3 py-1 bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white border border-blue-200 rounded text-xs font-bold transition-colors shadow-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    View
                                </a>
                            <?php else: ?>
                                <span class="text-slate-400 text-xs italic">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php 
                        }
                    } else {
                        // Kolom sekarang berjumlah 10
                        echo "<tr><td colspan='10' class='p-6 text-center text-slate-400 italic bg-slate-50/50'>Belum ada data riwayat.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>