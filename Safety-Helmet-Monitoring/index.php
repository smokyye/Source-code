<?php
include 'koneksi.php';

// Kueri disederhanakan, kita tidak lagi menggunakan TIMESTAMPDIFF dari PHP
$query = "
    SELECT 
        h.no_seri, 
        h.nama_pengguna, 
        h.status as status_helm,
        s.suhu, 
        s.kelembapan, 
        s.o2, 
        s.co, 
        s.co2, 
        s.h2s, 
        s.benturan, 
        s.status as status_lingkungan
    FROM helmets h
    LEFT JOIN (
        SELECT sl1.*
        FROM sensor_logs sl1
        INNER JOIN (
            SELECT no_seri, MAX(waktu) as max_waktu
            FROM sensor_logs
            GROUP BY no_seri
        ) sl2 ON sl1.no_seri = sl2.no_seri AND sl1.waktu = sl2.max_waktu
    ) s ON h.no_seri = s.no_seri
";
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Helmet Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/mqtt/dist/mqtt.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        mainbg: '#f8fafc',      // Latar belakang aplikasi (slate-50)
                        cardbg: '#ffffff',      // Latar belakang kartu (putih)
                        cardborder: '#e2e8f0',  // Border kartu (slate-200)
                        brandgreen: '#16a34a',  // Hijau terang yang lebih pas untuk light mode (green-600)
                    }
                }
            }
        }
    </script>
</head>

<!-- Mengubah background utama menjadi terang (mainbg) dan teks menjadi abu-abu gelap -->
<body class="bg-mainbg text-slate-800 font-sans p-4 md:p-6 min-h-screen relative">

    <div class="w-full space-y-6 mx-auto px-2 md:px-4">

        <!-- HEADER -->
        <header class="bg-cardbg border border-cardborder rounded-2xl p-4 flex flex-col md:flex-row justify-between items-center shadow-sm gap-4">
            <div class="flex items-center gap-4">
                <!-- Ikon Box -->
                <div class="w-12 h-12 bg-slate-100 border border-slate-200 rounded-lg flex items-center justify-center shrink-0">
                    <img src="assets/logo.png" alt="Helmet Logo" class="w-8 h-8 object-contain">
                </div>
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-slate-900 tracking-wide">Helmet Fleet Management</h1>
                    <p class="text-xs text-slate-500">Select a device to view comprehensive monitoring details</p>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <!-- Status Broker -->
                <div class="flex items-center gap-2 bg-slate-50 px-3 py-1.5 rounded-full border border-slate-200">
                    <span id="global-conn-dot" class="w-2.5 h-2.5 bg-slate-400 rounded-full"></span>
                    <span id="global-conn-text" class="text-xs font-semibold text-slate-600">Connecting Broker...</span>
                </div>
                <!-- Tombol Add -->
                <button onclick="openModal('addModal')" class="bg-brandgreen hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg transition text-sm flex items-center gap-2 shadow-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add Helmet
                </button>
                <!-- Jam Digital -->
                <div class="bg-slate-50 px-4 py-2 rounded-lg border border-slate-200 font-mono text-sm text-slate-700 tracking-wider shadow-inner" id="clock">-- : -- : --</div>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6" id="fleet-container">
            <?php
            if ($result && mysqli_num_rows($result) > 0) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $helm_id = $row['no_seri'];
                    $nama_pengguna = $row['nama_pengguna'];

                    $db_status = strtoupper($row['status_lingkungan'] ?? 'AMAN');
                    $status_env = 'SAFE';
                    if ($db_status == 'BAHAYA' || $db_status == 'ALAT TERPUTUS') $status_env = 'DANGER';
                    elseif ($db_status == 'WASPADA') $status_env = 'WARNING';

                    // Pewarnaan Badge Default Terang
                    $badge_color = "bg-green-100 text-green-700 border-green-200";
                    $ping_color = "bg-brandgreen";

                    if ($status_env == 'DANGER') {
                        $badge_color = "bg-red-100 text-red-700 border-red-200 animate-pulse";
                        $ping_color = "bg-red-500";
                    } elseif ($status_env == 'WARNING') {
                        $badge_color = "bg-yellow-100 text-yellow-700 border-yellow-200";
                        $ping_color = "bg-yellow-500";
                    }

                    if ($row['status_helm'] == 'dipakai') {
                        $network_text = "Waiting...";
                        $network_dot = "bg-yellow-400 animate-pulse shadow-[0_0_8px_rgba(250,204,21,0.6)]";
                        $network_text_class = "text-amber-600";
                        $card_opacity = "opacity-100";
                        $show_ping = true;

                        $val_suhu = $row['suhu'] ?? '0.0';
                        $val_hum  = $row['kelembapan'] ?? '0.0';
                        $val_o2   = $row['o2'] ?? '0.0';
                        $val_benturan = $row['benturan'] ?? '0.0';
                        $val_co   = $row['co'] ?? '0.0';
                        $val_co2  = $row['co2'] ?? '0.0';
                        $val_h2s  = $row['h2s'] ?? '0.0';

                        // Logika Merah untuk batas berbahaya
                        $col_o2 = (isset($row['o2']) && $row['o2'] < 19.5) ? 'text-red-600' : 'text-slate-800';
                        $col_benturan = (isset($row['benturan']) && $row['benturan'] > 1.5) ? 'text-red-600 animate-pulse' : 'text-slate-800';
                        $col_co = (isset($row['co']) && $row['co'] > 25) ? 'text-red-600' : 'text-slate-800';
                        $col_h2s = (isset($row['h2s']) && $row['h2s'] > 1) ? 'text-red-600' : 'text-slate-800';
                    } else {
                        // Jika alat di Rak (Standby)
                        $badge_color = "bg-slate-100 text-slate-500 border-slate-300";
                        $ping_color = "bg-slate-400";
                        $status_env = "STANDBY";
                        $card_opacity = "opacity-75 grayscale hover:grayscale-0";

                        $network_text = "Disconnected";
                        $network_dot = "bg-slate-400";
                        $network_text_class = "text-slate-500";
                        $show_ping = false;

                        $val_suhu = 'OFF';
                        $val_hum = 'OFF';
                        $val_o2 = 'OFF';
                        $val_benturan = 'OFF';
                        $val_co = 'OFF';
                        $val_co2 = 'OFF';
                        $val_h2s = 'OFF';
                        
                        $col_o2 = 'text-slate-400';
                        $col_benturan = 'text-slate-400';
                        $col_co = 'text-slate-400';
                        $col_h2s = 'text-slate-400';
                    }
            ?>
                    <div class="group h-full" id="card-<?= $helm_id ?>">
                        <!-- Background Kartu dibuat Putih dengan border yang halus -->
                        <div class="bg-cardbg border border-cardborder rounded-xl p-5 hover:border-slate-300 hover:shadow-xl transition-all duration-300 h-full flex flex-col relative overflow-hidden <?= $card_opacity ?>">
                            <!-- Aksen Ping di Atas Kartu -->
                            <div id="accent-<?= $helm_id ?>" class="absolute top-0 left-0 w-full h-1 <?= str_replace('bg-', 'bg-', $ping_color) ?> opacity-70 group-hover:opacity-100 transition-opacity"></div>
                            
                            <div class="flex justify-between items-start mb-5 mt-1">
                                <div>
                                    <p class="text-xs text-slate-500 font-mono mb-1">ID: <?= htmlspecialchars($helm_id) ?></p>
                                    <h3 class="text-xl font-bold text-slate-900 group-hover:text-brandgreen transition-colors"><?= htmlspecialchars($nama_pengguna ?: 'Anonymous Worker') ?></h3>
                                </div>
                                <div id="badge-<?= $helm_id ?>" class="px-3 py-1.5 rounded-full border text-xs font-bold flex items-center gap-2 <?= $badge_color ?>">
                                    <span class="relative flex h-2.5 w-2.5">
                                        <span id="ping-<?= $helm_id ?>" class="animate-ping absolute inline-flex h-full w-full rounded-full <?= $ping_color ?> opacity-75 <?= $show_ping ? '' : 'hidden' ?>"></span>
                                        <span id="dot-<?= $helm_id ?>" class="relative inline-flex rounded-full h-2.5 w-2.5 <?= $ping_color ?>"></span>
                                    </span>
                                    <span id="status-text-<?= $helm_id ?>"><?= $status_env ?></span>
                                </div>
                            </div>

                            <!-- KOTAK DATA SENSOR (Tema Terang) -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5 flex-grow">
                                <div class="bg-slate-50 rounded-lg p-2.5 text-center border border-slate-100">
                                    <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Temp</p>
                                    <p class="text-sm font-semibold text-slate-800" id="val-temp-<?= $helm_id ?>"><?= $val_suhu ?> <span class="text-xs text-slate-400 font-normal"><?= ($row['status_helm'] == 'dipakai') ? '°C' : '' ?></span></p>
                                </div>
                                <div class="bg-slate-50 rounded-lg p-2.5 text-center border border-slate-100">
                                    <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Humidity</p>
                                    <p class="text-sm font-semibold text-slate-800" id="val-hum-<?= $helm_id ?>"><?= $val_hum ?> <span class="text-xs text-slate-400 font-normal"><?= ($row['status_helm'] == 'dipakai') ? '%' : '' ?></span></p>
                                </div>
                                <div class="bg-slate-50 rounded-lg p-2.5 text-center border border-slate-100">
                                    <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Oxygen</p>
                                    <p class="text-sm font-semibold <?= $col_o2 ?>" id="val-o2-<?= $helm_id ?>"><?= $val_o2 ?> <span class="text-xs text-slate-400 font-normal"><?= ($row['status_helm'] == 'dipakai') ? '%' : '' ?></span></p>
                                </div>
                                <div class="bg-slate-50 rounded-lg p-2.5 text-center border border-slate-100">
                                    <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Impact</p>
                                    <p class="text-sm font-semibold <?= $col_benturan ?>" id="val-benturan-<?= $helm_id ?>"><?= $val_benturan ?> <span class="text-xs text-slate-400 font-normal"><?= ($row['status_helm'] == 'dipakai') ? 'G' : '' ?></span></p>
                                </div>
                                <div class="bg-slate-50 rounded-lg p-2.5 text-center border border-slate-100">
                                    <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">CO Gas</p>
                                    <p class="text-sm font-semibold <?= $col_co ?>" id="val-co-<?= $helm_id ?>"><?= $val_co ?> <span class="text-xs text-slate-400 font-normal"><?= ($row['status_helm'] == 'dipakai') ? 'PPM' : '' ?></span></p>
                                </div>
                                <div class="bg-slate-50 rounded-lg p-2.5 text-center border border-slate-100">
                                    <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">CO2 Gas</p>
                                    <p class="text-sm font-semibold text-slate-800" id="val-co2-<?= $helm_id ?>"><?= $val_co2 ?> <span class="text-xs text-slate-400 font-normal"><?= ($row['status_helm'] == 'dipakai') ? 'PPM' : '' ?></span></p>
                                </div>
                                <div class="bg-slate-50 rounded-lg p-2.5 text-center border border-slate-100">
                                    <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">H2S Gas</p>
                                    <p class="text-sm font-semibold <?= $col_h2s ?>" id="val-h2s-<?= $helm_id ?>"><?= $val_h2s ?> <span class="text-xs text-slate-400 font-normal"><?= ($row['status_helm'] == 'dipakai') ? 'PPM' : '' ?></span></p>
                                </div>
                                <div class="bg-transparent rounded-lg p-2.5 flex flex-col items-center justify-center border border-dashed border-slate-300">
                                    <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Network</p>
                                    <span class="text-xs font-bold <?= $network_text_class ?> flex items-center gap-1.5" id="net-container-<?= $helm_id ?>">
                                        <span class="w-2 h-2 rounded-full <?= $network_dot ?>" id="net-dot-<?= $helm_id ?>"></span>
                                        <span id="net-text-<?= $helm_id ?>"><?= $network_text ?></span>
                                    </span>
                                </div>
                            </div>

                            <div class="mt-auto flex justify-between items-center border-t border-slate-100 pt-4">
                                <div class="flex gap-2">
                                    <button onclick="openEditModal('<?= htmlspecialchars($helm_id) ?>', '<?= htmlspecialchars($nama_pengguna) ?>', '<?= htmlspecialchars($row['status_helm']) ?>')" class="p-1.5 bg-slate-100 hover:bg-blue-100 text-slate-500 hover:text-blue-600 rounded transition-colors" title="Edit Helmet">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                                        </svg>
                                    </button>
                                    <form action="crud.php" method="POST" class="inline m-0" onsubmit="return confirm('WARNING: Are you sure you want to delete Helmet <?= $helm_id ?>?')">
                                        <input type="hidden" name="action" value="delete"><input type="hidden" name="no_seri" value="<?= htmlspecialchars($helm_id) ?>">
                                        <button type="submit" class="p-1.5 bg-slate-100 hover:bg-red-100 text-slate-500 hover:text-red-600 rounded transition-colors" title="Delete Helmet">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                                <a href="detail.php?id=<?= urlencode($helm_id) ?>" class="text-sm text-brandgreen font-medium flex items-center gap-1.5 group-hover:translate-x-1 transition-transform">
                                    Open Monitor Dashboard <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
            <?php }
            } else {
                echo '<div class="col-span-full text-center py-16 bg-white border border-slate-200 rounded-xl shadow-sm"><h3 class="text-xl font-bold text-slate-500">No Devices Found</h3></div>';
            } ?>
        </div>
    </div>

    <!-- MODAL TAMBAH (CRUD) -->
    <div id="addModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center z-50 transition-opacity">
        <div class="bg-cardbg border border-cardborder w-full max-w-md rounded-2xl p-6 shadow-2xl relative">
            <h3 class="text-xl font-bold text-slate-900 mb-6">Register New Helmet</h3>
            <form action="crud.php" method="POST" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').innerText = 'Saving...';">
                <input type="hidden" name="action" value="add">
                <div class="mb-4">
                    <label class="block text-slate-600 font-semibold text-xs mb-2">Hardware ID</label>
                    <input type="text" name="no_seri" required class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-2.5 text-slate-900 focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500">
                </div>
                <div class="mb-4">
                    <label class="block text-slate-600 font-semibold text-xs mb-2">Worker Name</label>
                    <input type="text" name="nama_pengguna" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-2.5 text-slate-900 focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500">
                </div>
                <div class="mb-8">
                    <label class="block text-slate-600 font-semibold text-xs mb-2">Initial Status</label>
                    <select name="status" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-2.5 text-slate-900 focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500">
                        <option value="di_rak">Standby</option>
                        <option value="dipakai">In Use</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeModal('addModal')" class="px-5 py-2.5 bg-slate-200 text-slate-700 hover:bg-slate-300 transition-colors rounded-lg font-medium">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-brandgreen hover:bg-green-700 text-white rounded-lg font-bold">Save Device</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- MODAL EDIT (CRUD) -->
    <div id="editModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center z-50 transition-opacity">
        <div class="bg-cardbg border border-cardborder w-full max-w-md rounded-2xl p-6 shadow-2xl relative">
            <h3 class="text-xl font-bold text-slate-900 mb-6">Update Helmet</h3>
            <form action="crud.php" method="POST" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').innerText = 'Saving...';">
                <input type="hidden" name="action" value="edit"><input type="hidden" name="id_lama" id="edit_id_lama">
                <div class="mb-4">
                    <label class="block text-slate-600 font-semibold text-xs mb-2">Hardware ID</label>
                    <input type="text" name="no_seri" id="edit_no_seri" required class="w-full bg-slate-100 border border-slate-300 rounded-lg px-4 py-2.5 text-slate-500 cursor-not-allowed" readonly>
                </div>
                <div class="mb-4">
                    <label class="block text-slate-600 font-semibold text-xs mb-2">Worker Name</label>
                    <input type="text" name="nama_pengguna" id="edit_nama" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-2.5 text-slate-900 focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500">
                </div>
                <div class="mb-8">
                    <label class="block text-slate-600 font-semibold text-xs mb-2">Status</label>
                    <select name="status" id="edit_status" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-2.5 text-slate-900 focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500">
                        <option value="di_rak">Standby</option>
                        <option value="dipakai">In Use</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeModal('editModal')" class="px-5 py-2.5 bg-slate-200 text-slate-700 hover:bg-slate-300 transition-colors rounded-lg font-medium">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold transition-colors">Update Data</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
            document.getElementById(id).classList.add('flex');
        }

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
        }

        function openEditModal(id, nama, status) {
            document.getElementById('edit_id_lama').value = id;
            document.getElementById('edit_no_seri').value = id;
            document.getElementById('edit_nama').value = nama;
            document.getElementById('edit_status').value = status;
            openModal('editModal');
        }

        setInterval(() => {
            const now = new Date();
            document.getElementById('clock').innerText = `${String(now.getHours()).padStart(2,'0')} : ${String(now.getMinutes()).padStart(2,'0')} : ${String(now.getSeconds()).padStart(2,'0')}`;
        }, 1000);

        function catatKeDatabase(sensorData) {
            fetch('simpan_log.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(sensorData)
            }).catch(err => console.error(err));
        }

        const client = mqtt.connect('wss://broker.emqx.io:8084/mqtt');
        let helmetWatchdogs = {};
        let globalWatchdog;
        let lastSaveTimes = {};
        const SAVE_INTERVAL = 60 * 1000;

        function setGlobalConnectionUI(s) {
            const d = document.getElementById('global-conn-dot'),
                t = document.getElementById('global-conn-text');
            if (!d || !t) return;
            d.className = "w-2.5 h-2.5 rounded-full";
            if (s === 'connected') {
                d.classList.add('bg-green-500', 'shadow-[0_0_8px_#22c55e]');
                t.innerText = "Connected";
                t.className = "text-xs font-semibold text-green-600";
            } else if (s === 'disconnected') {
                d.classList.add('bg-red-500', 'shadow-[0_0_8px_#ef4444]', 'animate-pulse');
                t.innerText = "Disconnected";
                t.className = "text-xs font-semibold text-red-600";
            } else {
                d.classList.add('bg-yellow-400', 'shadow-[0_0_8px_#facc15]', 'animate-pulse');
                t.innerText = "Waiting Data...";
                t.className = "text-xs font-semibold text-amber-600";
            }
        }

        function resetGlobalWatchdog() {
            clearTimeout(globalWatchdog);
            globalWatchdog = setTimeout(() => {
                setGlobalConnectionUI('disconnected');
            }, 10000);
        }

        function setHelmetOffline(helmId) {
            const sensors = ['temp', 'hum', 'o2', 'benturan', 'co', 'co2', 'h2s'];
            sensors.forEach(s => {
                const el = document.getElementById(`val-${s}-${helmId}`);
                if (el) el.innerHTML = `OFF`;
            });
            const nT = document.getElementById(`net-text-${helmId}`);
            if (nT) nT.innerText = "Offline";
            const nD = document.getElementById(`net-dot-${helmId}`);
            if (nD) nD.className = "w-2 h-2 rounded-full bg-orange-500 shadow-[0_0_8px_rgba(249,115,22,0.8)]";
            const nC = document.getElementById(`net-container-${helmId}`);
            if (nC) nC.className = "text-xs font-bold text-orange-600 flex items-center gap-1.5";
            const sT = document.getElementById(`status-text-${helmId}`);
            if (sT) sT.innerText = "NO SIGNAL";
            const pE = document.getElementById(`ping-${helmId}`);
            if (pE) pE.classList.add('hidden');
            const bE = document.getElementById(`badge-${helmId}`);
            if (bE) bE.className = "px-3 py-1.5 rounded-full border text-xs font-bold flex items-center gap-2 bg-orange-100 text-orange-700 border-orange-200";
            const dE = document.getElementById(`dot-${helmId}`);
            if (dE) dE.className = "relative inline-flex rounded-full h-2.5 w-2.5 bg-orange-500";
            const aE = document.getElementById(`accent-${helmId}`);
            if (aE) aE.className = "absolute top-0 left-0 w-full h-1 bg-orange-500 opacity-70 group-hover:opacity-100 transition-opacity";
        }

        // AUTO-START WATCHDOG SAAT PAGE LOAD UNTUK SEMUA HELM "DIPAKAI"
        <?php
        if ($result && mysqli_num_rows($result) > 0) {
            mysqli_data_seek($result, 0);
            while ($row = mysqli_fetch_assoc($result)) {
                if ($row['status_helm'] == 'dipakai') {
                    echo "helmetWatchdogs['{$row['no_seri']}'] = setTimeout(() => { setHelmetOffline('{$row['no_seri']}'); }, 10000);\n";
                }
            }
        }
        ?>

        client.on('connect', function() {
            console.log("Berhasil terhubung ke Broker MQTT!");
            setGlobalConnectionUI('connecting');
            client.subscribe('tambang/helm/+/data');
            resetGlobalWatchdog();
        });

        // Tambahkan Error Handler untuk melihat masalah koneksi di Inspect Element (F12)
        client.on('error', function(err) {
            console.error('MQTT Error: ', err);
            setGlobalConnectionUI('disconnected');
        });

        client.on('close', function() {
            console.warn('Koneksi MQTT Terputus dari Broker');
        });

        client.on('message', function(topic, message) {
            setGlobalConnectionUI('connected');
            resetGlobalWatchdog();

            const parts = topic.split('/');
            if (parts.length >= 4) {
                const helmId = parts[2];
                let data;

                try {
                    data = JSON.parse(message.toString());
                    console.log("Data diterima dari " + helmId + ":", data); // Tampilkan di console F12
                } catch (e) {
                    console.error("Gagal parsing JSON: ", message.toString());
                    return;
                }

                if (document.getElementById('card-' + helmId)) {
                    // Batalkan kiamat (Timer Offline)
                    if (helmetWatchdogs[helmId]) clearTimeout(helmetWatchdogs[helmId]);
                    helmetWatchdogs[helmId] = setTimeout(() => {
                        setHelmetOffline(helmId);
                    }, 10000);

                    // Update UI Sensor
                    if (data.temperature !== undefined) document.getElementById(`val-temp-${helmId}`).innerHTML = `${data.temperature.toFixed(1)} <span class="text-xs text-slate-400 font-normal">°C</span>`;

                    if (data.humidity !== undefined) document.getElementById(`val-hum-${helmId}`).innerHTML = `${data.humidity.toFixed(0)} <span class="text-xs text-slate-400 font-normal">%</span>`;

                    if (data.o2 !== undefined) {
                        let el = document.getElementById(`val-o2-${helmId}`);
                        el.innerHTML = `${data.o2.toFixed(1)} <span class="text-xs text-slate-400 font-normal">%</span>`;
                        el.className = data.o2 < 19.5 ? "text-sm font-semibold text-red-600" : "text-sm font-semibold text-slate-800";
                    }

                    if (data.g_force !== undefined) {
                        let el = document.getElementById(`val-benturan-${helmId}`);
                        el.innerHTML = `${data.g_force.toFixed(1)} <span class="text-xs text-slate-400 font-normal">G</span>`;
                        el.className = data.g_force > 1.5 ? "text-sm font-semibold text-red-600 animate-pulse" : "text-sm font-semibold text-slate-800";
                    }

                    if (data.co !== undefined) {
                        let el = document.getElementById(`val-co-${helmId}`);
                        el.innerHTML = `${data.co.toFixed(0)} <span class="text-xs text-slate-400 font-normal">PPM</span>`;
                        el.className = data.co > 25 ? "text-sm font-semibold text-red-600" : "text-sm font-semibold text-slate-800";
                    }

                    if (data.co2 !== undefined) document.getElementById(`val-co2-${helmId}`).innerHTML = `${data.co2.toFixed(0)} <span class="text-xs text-slate-400 font-normal">PPM</span>`;

                    if (data.h2s !== undefined) {
                        let el = document.getElementById(`val-h2s-${helmId}`);
                        el.innerHTML = `${data.h2s.toFixed(1)} <span class="text-xs text-slate-400 font-normal">PPM</span>`;
                        el.className = data.h2s > 1 ? "text-sm font-semibold text-red-600" : "text-sm font-semibold text-slate-800";
                    }

                    document.getElementById(`card-${helmId}`).firstElementChild.classList.remove('grayscale', 'opacity-75');
                    document.getElementById(`net-text-${helmId}`).innerText = "Connected";
                    document.getElementById(`net-dot-${helmId}`).className = "w-2 h-2 rounded-full bg-blue-500 animate-pulse shadow-[0_0_8px_rgba(59,130,246,0.8)]";
                    document.getElementById(`net-container-${helmId}`).className = "text-xs font-bold text-blue-600 flex items-center gap-1.5";

                    // Logika Status Level 
                    let rawStatus = data.status ? data.status.toUpperCase() : 'AMAN';
                    let statusLevel = 'SAFE'; // Default

                    if (rawStatus === 'BAHAYA') {
                        statusLevel = 'DANGER';
                    } else if (rawStatus === 'WASPADA') {
                        statusLevel = 'WARNING';
                    } else {
                        statusLevel = 'SAFE';
                    }

                    const badgeEl = document.getElementById(`badge-${helmId}`);
                    const pingEl = document.getElementById(`ping-${helmId}`);
                    const dotEl = document.getElementById(`dot-${helmId}`);
                    const accentEl = document.getElementById(`accent-${helmId}`);

                    document.getElementById(`status-text-${helmId}`).innerText = statusLevel;
                    pingEl.classList.remove('hidden');

                    if (statusLevel === 'SAFE') {
                        badgeEl.className = "px-3 py-1.5 rounded-full border text-xs font-bold flex items-center gap-2 bg-green-100 text-green-700 border-green-200";
                        pingEl.className = "animate-ping absolute inline-flex h-full w-full rounded-full bg-brandgreen opacity-75";
                        dotEl.className = "relative inline-flex rounded-full h-2.5 w-2.5 bg-brandgreen";
                        accentEl.className = "absolute top-0 left-0 w-full h-1 bg-brandgreen opacity-70 group-hover:opacity-100 transition-opacity";
                    } else if (statusLevel === 'WARNING') {
                        badgeEl.className = "px-3 py-1.5 rounded-full border text-xs font-bold flex items-center gap-2 bg-yellow-100 text-yellow-700 border-yellow-200";
                        pingEl.className = "animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-500 opacity-75";
                        dotEl.className = "relative inline-flex rounded-full h-2.5 w-2.5 bg-yellow-500";
                        accentEl.className = "absolute top-0 left-0 w-full h-1 bg-yellow-500 opacity-70 group-hover:opacity-100 transition-opacity";
                    } else if (statusLevel === 'DANGER') {
                        badgeEl.className = "px-3 py-1.5 rounded-full border text-xs font-bold flex items-center gap-2 bg-red-100 text-red-700 border-red-200 animate-pulse";
                        pingEl.className = "animate-ping absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-75";
                        dotEl.className = "relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500";
                        accentEl.className = "absolute top-0 left-0 w-full h-1 bg-red-500 opacity-70 group-hover:opacity-100 transition-opacity";
                    }

                    let currentTime = Date.now();
                    if (!lastSaveTimes[helmId]) lastSaveTimes[helmId] = 0;

                    if (statusLevel === 'DANGER' || statusLevel === 'WARNING') {
                        if (!window['lastIncidentSave_' + helmId] || currentTime - window['lastIncidentSave_' + helmId] > 5000) {
                            catatKeDatabase({
                                no_seri: helmId,
                                suhu: data.temperature || 0,
                                kelembapan: data.humidity || 0,
                                o2: data.o2 || 0,
                                co: data.co || 0,
                                co2: data.co2 || 0,
                                h2s: data.h2s || 0,
                                benturan: data.g_force || 0,
                                status: statusLevel
                            });
                            window['lastIncidentSave_' + helmId] = currentTime;
                        }
                    }

                    if (currentTime - lastSaveTimes[helmId] >= SAVE_INTERVAL) {
                        catatKeDatabase({
                            no_seri: helmId,
                             suhu: data.temperature || 0,
                            kelembapan: data.humidity || 0,
                            o2: data.o2 || 0,
                            co: data.co || 0,
                            co2: data.co2 || 0,
                            h2s: data.h2s || 0,
                            benturan: data.g_force || 0,
                            status: statusLevel
                        });
                        lastSaveTimes[helmId] = currentTime;
                    }
                }
            }
        });
    </script>
</body>

</html>