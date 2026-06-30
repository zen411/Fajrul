<?php
// Matikan tampilan error mentah HTML agar tidak merusak respons JSON jika terjadi sesuatu
error_reporting(0);
ini_set('display_errors', 0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$DB_HOST = "localhost";
$DB_USER = "root";      
$DB_PASS = "";          
$DB_NAME = "db_masjid";  

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database gagal: " . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

// CARA AMAN MEMBACA INPUT JSON TANPA MERUSAK FORMDATA UPLOAD GAMBAR
$rawInput = file_get_contents('php://input');
$data = [];
if (!empty($rawInput)) {
    $data = json_decode($rawInput, true) ?? [];
}

switch ($action) {
    case 'get_all':
        $jadwal = $pdo->query("SELECT * FROM jadwal_khotib ORDER BY id ASC")->fetchAll();
        $album = $pdo->query("SELECT * FROM album ORDER BY id DESC")->fetchAll();
        $inventaris = $pdo->query("SELECT * FROM inventaris ORDER BY id DESC")->fetchAll();
        
        $riwayat_keuangan = $pdo->query("SELECT *, tanggal as tgl_mentah, DATE_FORMAT(tanggal, '%d-%m-%Y') as tgl_indo FROM keuangan ORDER BY tanggal DESC, id DESC LIMIT 100")->fetchAll();

        $total_masuk = $pdo->query("SELECT SUM(nominal) FROM keuangan WHERE jenis = 'pemasukan'")->fetchColumn() ?? 0;
        $total_keluar = $pdo->query("SELECT SUM(nominal) FROM keuangan WHERE jenis = 'pengeluaran'")->fetchColumn() ?? 0;

        $minggu_masuk = $pdo->query("SELECT SUM(nominal) FROM keuangan WHERE jenis = 'pemasukan' AND tanggal >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn() ?? 0;
        $minggu_keluar = $pdo->query("SELECT SUM(nominal) FROM keuangan WHERE jenis = 'pengeluaran' AND tanggal >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn() ?? 0;

        $bulan_masuk = $pdo->query("SELECT SUM(nominal) FROM keuangan WHERE jenis = 'pemasukan' AND MONTH(tanggal) = MONTH(CURDATE()) AND YEAR(tanggal) = YEAR(CURDATE())")->fetchColumn() ?? 0;
        $bulan_keluar = $pdo->query("SELECT SUM(nominal) FROM keuangan WHERE jenis = 'pengeluaran' AND MONTH(tanggal) = MONTH(CURDATE()) AND YEAR(tanggal) = YEAR(CURDATE())")->fetchColumn() ?? 0;

        $tahun_masuk = $pdo->query("SELECT SUM(nominal) FROM keuangan WHERE jenis = 'pemasukan' AND YEAR(tanggal) = YEAR(CURDATE())")->fetchColumn() ?? 0;
        $tahun_keluar = $pdo->query("SELECT SUM(nominal) FROM keuangan WHERE jenis = 'pengeluaran' AND YEAR(tanggal) = YEAR(CURDATE())")->fetchColumn() ?? 0;

        echo json_encode([
            "jadwal" => $jadwal,
            "album" => $album,
            "inventaris" => $inventaris,
            "keuangan" => [
                "global" => ["pemasukan" => (float)$total_masuk, "pengeluaran" => (float)$total_keluar],
                "mingguan" => ["pemasukan" => (float)$minggu_masuk, "pengeluaran" => (float)$minggu_keluar],
                "bulanan" => ["pemasukan" => (float)$bulan_masuk, "pengeluaran" => (float)$bulan_keluar],
                "tahunan" => ["pemasukan" => (float)$tahun_masuk, "pengeluaran" => (float)$tahun_keluar],
                "riwayat" => $riwayat_keuangan
            ]
        ]);
        break;

    case 'add_transaksi_keuangan':
        $stmt = $pdo->prepare("INSERT INTO keuangan (tanggal, jenis, nominal, keterangan) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['tanggal'], $data['jenis'], $data['nominal'], $data['keterangan']]);
        echo json_encode(["status" => "success"]);
        break;

    case 'edit_transaksi_keuangan':
        $stmt = $pdo->prepare("UPDATE keuangan SET tanggal = ?, jenis = ?, nominal = ?, keterangan = ? WHERE id = ?");
        $stmt->execute([$data['tanggal'], $data['jenis'], $data['nominal'], $data['keterangan'], $data['id']]);
        echo json_encode(["status" => "success"]);
        break;

    case 'delete_transaksi_keuangan':
        $stmt = $pdo->prepare("DELETE FROM keuangan WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(["status" => "success"]);
        break;

    case 'add_album':
        if (isset($_FILES['img_file']) && $_FILES['img_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['img_file']['tmp_name'];
            $fileName = $_FILES['img_file']['name'];
            $customFileName = time() . '_' . str_replace(' ', '_', $fileName);
            
            $uploadFileDir = './images/';
            // Buat folder images secara otomatis jika belum ada di XAMPP
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }

            $dest_path = $uploadFileDir . $customFileName;
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                $dbPath = 'images/' . $customFileName;
                $stmt = $pdo->prepare("INSERT INTO album (title, img) VALUES (?, ?)");
                $stmt->execute([$_POST['title'], $dbPath]);
                echo json_encode(["status" => "success"]);
            } else {
                echo json_encode(["status" => "error", "message" => "Gagal memindahkan file."]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "File tidak valid."]);
        }
        break;

    case 'edit_album':
        $id = $_POST['id'] ?? '';
        $title = $_POST['title'] ?? '';
        
        if (empty($id)) {
            echo json_encode(["status" => "error", "message" => "ID kosong"]);
            break;
        }

        if (isset($_FILES['img_file']) && $_FILES['img_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['img_file']['tmp_name'];
            $fileName = $_FILES['img_file']['name'];
            $customFileName = time() . '_' . str_replace(' ', '_', $fileName);
            $dest_path = './image/' . $customFileName;
            
            if (move_uploaded_file($fileTmpPath, $dest_path)) {
                $dbPath = 'image/' . $customFileName;
                $stmt = $pdo->prepare("UPDATE album SET title = ?, img = ? WHERE id = ?");
                $stmt->execute([$title, $dbPath, $id]);
                echo json_encode(["status" => "success"]);
            } else {
                echo json_encode(["status" => "error", "message" => "Gagal simpan gambar baru."]);
            }
        } else {
            $stmt = $pdo->prepare("UPDATE album SET title = ? WHERE id = ?");
            $stmt->execute([$title, $id]);
            echo json_encode(["status" => "success"]);
        }
        break;

    case 'delete_album':
        $stmt = $pdo->prepare("DELETE FROM album WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(["status" => "success"]);
        break;

    // --- CASE JADWAL & INVENTARIS ---
    case 'add_jadwal':
        $stmt = $pdo->prepare("INSERT INTO jadwal_khotib (tgl, khotib, imam, muadzin) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['tgl'], $data['khotib'], $data['imam'], $data['muadzin']]);
        echo json_encode(["status" => "success"]);
        break;
    case 'edit_jadwal':
        $stmt = $pdo->prepare("UPDATE jadwal_khotib SET tgl = ?, khotib = ?, imam = ?, muadzin = ? WHERE id = ?");
        $stmt->execute([$data['tgl'], $data['khotib'], $data['imam'], $data['muadzin'], $data['id']]);
        echo json_encode(["status" => "success"]);
        break;
    case 'delete_jadwal':
        $stmt = $pdo->prepare("DELETE FROM jadwal_khotib WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(["status" => "success"]);
        break;
    case 'add_inventaris':
        // Cek apakah ada file foto inventaris yang diunggah
        if (isset($_FILES['img_file']) && $_FILES['img_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['img_file']['tmp_name'];
            $fileName = $_FILES['img_file']['name'];
            $customFileName = 'inv_' . time() . '_' . str_replace(' ', '_', $fileName);
            
            $uploadFileDir = __DIR__ . '/images/';
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }

            $dest_path = $uploadFileDir . $customFileName;
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                $dbPath = 'images/' . $customFileName;
                
                // Simpan data inventaris baru ke database menggunakan data $_POST
                $stmt = $pdo->prepare("INSERT INTO inventaris (name, qty, cond, img) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_POST['name'], $_POST['qty'], $_POST['cond'], $dbPath]);
                echo json_encode(["status" => "success"]);
            } else {
                echo json_encode(["status" => "error", "message" => "Gagal memindahkan file ke folder images."]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "File foto inventaris wajib diunggah."]);
        }
        break;

    case 'edit_inventaris':
        $id = $_POST['id'] ?? '';
        $name = $_POST['name'] ?? '';
        $qty = $_POST['qty'] ?? '';
        $cond = $_POST['cond'] ?? '';
        
        if (empty($id)) {
            echo json_encode(["status" => "error", "message" => "ID Inventaris kosong."]);
            break;
        }

        // Cek jika admin mengganti foto inventaris dengan file baru
        if (isset($_FILES['img_file']) && $_FILES['img_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['img_file']['tmp_name'];
            $fileName = $_FILES['img_file']['name'];
            $customFileName = 'inv_' . time() . '_' . str_replace(' ', '_', $fileName);
            $dest_path = __DIR__ . '/images/' . $customFileName;
            
            if (move_uploaded_file($fileTmpPath, $dest_path)) {
                $dbPath = 'images/' . $customFileName;
                // Update teks SEKALIGUS foto baru
                $stmt = $pdo->prepare("UPDATE inventaris SET name = ?, qty = ?, cond = ?, img = ? WHERE id = ?");
                $stmt->execute([$name, $qty, $cond, $dbPath, $id]);
                echo json_encode(["status" => "success"]);
            } else {
                echo json_encode(["status" => "error", "message" => "Gagal menyimpan foto baru."]);
            }
        } else {
            // Jika tidak pilih foto baru, cukup update data teksnya saja
            $stmt = $pdo->prepare("UPDATE inventaris SET name = ?, qty = ?, cond = ? WHERE id = ?");
            $stmt->execute([$name, $qty, $cond, $id]);
            echo json_encode(["status" => "success"]);
        }
        break;
    case 'delete_inventaris':
        $stmt = $pdo->prepare("DELETE FROM inventaris WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(["status" => "success"]);
        break;
    default:
        echo json_encode(["status" => "error", "message" => "Aksi tidak valid"]);
    case 'add_pendaftaran_yayasan':
        // Cek berkas upload bukti bayar dari pendaftar
        if (isset($_FILES['img_file']) && $_FILES['img_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['img_file']['tmp_name'];
            $fileName = $_FILES['img_file']['name'];
            
            // Format penamaan file unik agar membedakan dari berkas inventaris/album
            $customFileName = 'bukti_daftar_' . time() . '_' . str_replace(' ', '_', $fileName);
            $uploadFileDir = __DIR__ . '/images/';
            
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }

            $dest_path = $uploadFileDir . $customFileName;
            
            if (move_uploaded_file($fileTmpPath, $dest_path)) {
                $dbPath = 'images/' . $customFileName;
                
                // Eksekusi insert data ke tabel pendaftaran_yayasan
                $stmt = $pdo->prepare("INSERT INTO pendaftaran_yayasan (nama_lengkap, pilihan_jenjang, nomor_hp, asal_sekolah, bukti_bayar) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['nama'],
                    $_POST['jenjang'],
                    $_POST['hp'],
                    $_POST['asal_sekolah'],
                    $dbPath
                ]);
                
                echo json_encode(["status" => "success"]);
            } else {
                echo json_encode(["status" => "error", "message" => "Gagal memindahkan file bukti pembayaran ke penyimpanan server."]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "Berkas file bukti transfer tidak valid atau belum dipilih."]);
        }
        break;
    // --- PROSES LOGIN ADMIN ---
case 'login_admin':
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    
    // Mencocokkan dengan MD5 sesuai isi insert database di atas
    $stmt = $pdo->prepare("SELECT * FROM admin_yayasan WHERE username = ? AND password = MD5(?)");
    $stmt->execute([$user, $pass]);
    $admin = $stmt->fetch();

    if ($admin) {
        echo json_encode(["status" => "success", "message" => "Login Berhasil!"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Username atau Password salah."]);
    }
    break;

// --- AMBIL SEMUA KONTEN UNTUK DITAMPILKAN DI WEB ---
case 'get_all_konten':
    $stmt = $pdo->query("SELECT * FROM konten_yayasan");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mengubah hasil array menjadi format key-value agar mudah dibaca JavaScript
    $konten = [];
    foreach ($data as $row) {
        $konten[$row['nama_menu']] = [
            "judul" => $row['judul'],
            "isi" => $row['isi_konten']
        ];
    }
    echo json_encode(["status" => "success", "data" => $konten]);
    break;

// --- UPDATE KONTEN MENU (HANYA BISA OLEH ADMIN) ---
case 'update_konten_menu':
    $menu = $_POST['nama_menu'] ?? '';
    $judul = $_POST['judul'] ?? '';
    $isi = $_POST['isi_konten'] ?? '';

    $stmt = $pdo->prepare("UPDATE konten_yayasan SET judul = ?, isi_konten = ? WHERE nama_menu = ?");
    $result = $stmt->execute([$judul, $isi, $menu]);

    if ($result) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Gagal memperbarui database."]);
    }
    break;
}
?>