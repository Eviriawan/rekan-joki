<?php
// testimoni.php - Halaman testimoni Rekan Joki dengan form tambah testimoni langsung.
// Data disimpan di data/testimoni.json (dibuat otomatis). Folder harus bisa ditulis oleh server.

session_start();

$brand = 'Rekan Joki';
$wa    = '6281528301205';

// true  = testimoni baru menunggu disetujui admin dulu
// false = testimoni baru langsung tampil
$moderasi = false;

// Password admin untuk hapus/setujui testimoni (buka: testimoni.php?admin=1).
// WAJIB diganti. Selama masih nilai bawaan, halaman admin dinonaktifkan.
$adminPassword = 'rekanjoki1234';

$labels   = ['desain' => 'Joki Desain', 'peta' => 'Joki Peta', 'tugas' => 'Joki Tugas'];
$dataDir  = __DIR__ . '/data';
$dataFile = $dataDir . '/testimoni.json';
// Gambar testimoni disimpan di sini (dibuat otomatis, harus bisa ditulis oleh server)
$uploadDir = __DIR__ . '/uploads/testimoni';
$uploadUrl = 'uploads/testimoni';
$adminOn  = $adminPassword !== 'GANTI_PASSWORD_ADMIN' && $adminPassword !== '';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function wa_link(string $wa, string $text): string { return 'https://wa.me/' . $wa . '?text=' . rawurlencode($text); }
function initials(string $name): string {
    $p = preg_split('/\s+/', trim($name));
    $i = mb_strtoupper(mb_substr($p[0] ?? '', 0, 1));
    if (count($p) > 1) $i .= mb_strtoupper(mb_substr(end($p), 0, 1));
    return $i;
}
function tgl(int $ts): string {
    $b = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    return $b[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}
function load_data(string $file): array {
    if (!is_file($file)) return [];
    $d = json_decode((string)file_get_contents($file), true);
    return is_array($d) ? $d : [];
}
// Baca-ubah-tulis dengan kunci file supaya aman saat banyak yang kirim bersamaan
function mutate(string $dir, string $file, callable $fn): bool {
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    $fh = @fopen($file, 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = json_decode($raw ?: '[]', true);
    $data = $fn(is_array($data) ? $data : []);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(array_values($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}
// Validasi gambar, perbaiki rotasi, kecilkan, lalu simpan ulang sebagai JPG.
// Simpan ulang membuang metadata (lokasi GPS, dll) dan kode tersembunyi di dalam file.
// Mengembalikan nama file, atau '' (dan mengisi $err bila ada masalah).
function save_image(array $f, string $dir, string &$err): string {
    $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_NO_FILE) return '';
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) { $err = 'Ukuran gambar terlalu besar (maksimal 3 MB).'; return ''; }
    if ($code !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $err = 'Gambar gagal diunggah. Coba lagi.'; return ''; }
    if ($f['size'] > 3 * 1024 * 1024) { $err = 'Ukuran gambar terlalu besar (maksimal 3 MB).'; return ''; }
    if (!function_exists('imagecreatetruecolor')) { $err = 'Server belum mendukung unggah gambar.'; return ''; }

    $info = @getimagesize($f['tmp_name']);
    $mime = $info['mime'] ?? '';
    $fn   = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$mime] ?? null;
    if (!$info || !$fn || !function_exists($fn)) { $err = 'Format gambar harus JPG, PNG, atau WebP.'; return ''; }
    if ($info[0] * $info[1] > 25000000) { $err = 'Resolusi gambar terlalu besar.'; return ''; }

    $src = @$fn($f['tmp_name']);
    if (!$src) { $err = 'Gambar tidak bisa dibaca. Coba file lain.'; return ''; }

    if ($mime === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
        $ex  = @exif_read_data($f['tmp_name']);
        $ori = (int)($ex['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$ori] ?? 0;
        if ($deg) { $rot = imagerotate($src, $deg, 0); if ($rot) { imagedestroy($src); $src = $rot; } }
    }

    $w = imagesx($src); $h = imagesy($src);
    $scale = min(1, 1200 / max($w, $h));
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $err = 'Folder gambar tidak bisa dibuat. Hubungi admin.'; return ''; }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Options -Indexes -ExecCGI\n<FilesMatch \"(?i)\\.(php\\d?|phtml|phar|pl|py|cgi)$\">\nRequire all denied\n</FilesMatch>\n");

    $name = bin2hex(random_bytes(10)) . '.jpg';
    $ok = @imagejpeg($dst, $dir . '/' . $name, 82);
    imagedestroy($dst);
    if (!$ok) { $err = 'Gambar belum bisa disimpan. Hubungi admin.'; return ''; }
    return $name;
}
function go(string $to): void { header('Location: ' . $to); exit; }

$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ---------- Proses form ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // isi kiriman melebihi batas post_max_size di server
        $_SESSION['flash'] = ['err', 'Ukuran gambar terlalu besar (maksimal 3 MB).'];
        go('testimoni.php#tulis');
    }
    $act = $_POST['action'] ?? '';
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $_SESSION['flash'] = ['err', 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.'];
        go('testimoni.php#tulis');
    }

    if ($act === 'add') {
        $old = [
            'nama' => trim((string)($_POST['nama'] ?? '')),
            'svc'  => (string)($_POST['svc'] ?? ''),
            'item' => trim((string)($_POST['item'] ?? '')),
            'rate' => (int)($_POST['rate'] ?? 0),
            'isi'  => trim((string)($_POST['isi'] ?? '')),
        ];
        $err = '';
        if (($_POST['website'] ?? '') !== '') {
            // jebakan bot: field tersembunyi terisi, diam-diam abaikan
            go('testimoni.php');
        } elseif (time() - (int)($_SESSION['form_t'] ?? 0) < 3) {
            $err = 'Form terkirim terlalu cepat. Coba lagi sebentar.';
        } elseif (mb_strlen($old['nama']) < 2 || mb_strlen($old['nama']) > 40) {
            $err = 'Nama harus 2 sampai 40 karakter.';
        } elseif (!isset($labels[$old['svc']])) {
            $err = 'Pilih jenis layanan yang Anda pesan.';
        } elseif ($old['rate'] < 1 || $old['rate'] > 5) {
            $err = 'Pilih rating bintang 1 sampai 5.';
        } elseif (mb_strlen($old['isi']) < 10 || mb_strlen($old['isi']) > 500) {
            $err = 'Isi testimoni harus 10 sampai 500 karakter.';
        } elseif (mb_strlen($old['item']) > 40) {
            $err = 'Nama layanan maksimal 40 karakter.';
        } elseif (preg_match('~https?://|www\.~i', $old['isi'] . ' ' . $old['nama'] . ' ' . $old['item'])) {
            $err = 'Tautan tidak diperbolehkan di testimoni.';
        }

        $imgName = '';
        if ($err === '') $imgName = save_image($_FILES['foto'] ?? [], $uploadDir, $err);

        if ($err === '') {
            $hash  = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . __FILE__);
            $limit = '';
            $ok = mutate($dataDir, $dataFile, function (array $d) use ($old, $hash, $moderasi, $imgName, &$limit) {
                $now = time(); $recent = 0; $day = 0;
                foreach ($d as $r) {
                    if (($r['h'] ?? '') === $hash) {
                        if ($now - (int)$r['ts'] < 60) $recent++;
                        if ($now - (int)$r['ts'] < 86400) $day++;
                    }
                }
                if ($recent > 0) { $limit = 'Tunggu satu menit sebelum mengirim lagi.'; return $d; }
                if ($day >= 5)   { $limit = 'Batas kiriman hari ini sudah tercapai.'; return $d; }
                $d[] = [
                    'id' => bin2hex(random_bytes(6)), 'nama' => $old['nama'], 'svc' => $old['svc'],
                    'item' => $old['item'], 'rate' => $old['rate'], 'isi' => $old['isi'],
                    'img' => $imgName, 'ts' => $now, 'ok' => !$moderasi, 'h' => $hash,
                ];
                return $d;
            });
            if (!$ok)              $err = 'Testimoni belum bisa disimpan. Hubungi admin.';
            elseif ($limit !== '') $err = $limit;
            if ($err !== '' && $imgName !== '') @unlink($uploadDir . '/' . $imgName);
        }

        if ($err !== '') {
            $_SESSION['flash'] = ['err', $err];
            $_SESSION['old']   = $old;
            go('testimoni.php#tulis');
        }
        unset($_SESSION['old']);
        $_SESSION['flash'] = ['ok', $moderasi
            ? 'Terima kasih! Testimoni Anda akan tampil setelah disetujui admin.'
            : 'Terima kasih! Testimoni Anda sudah tampil di halaman ini.'];
        go('testimoni.php#tulis');
    }

    // ----- aksi admin -----
    if ($adminOn && $act === 'login') {
        if (hash_equals($adminPassword, (string)($_POST['pw'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf']  = bin2hex(random_bytes(16));
        } else {
            $_SESSION['flash'] = ['err', 'Password salah.'];
        }
        go('testimoni.php?admin=1');
    }
    if ($adminOn && !empty($_SESSION['admin'])) {
        $id = (string)($_POST['id'] ?? '');
        if ($act === 'logout') { unset($_SESSION['admin']); go('testimoni.php'); }
        if ($act === 'approve' || $act === 'delete') {
            mutate($dataDir, $dataFile, function (array $d) use ($act, $id, $uploadDir) {
                $out = [];
                foreach ($d as $r) {
                    if (($r['id'] ?? '') === $id) {
                        if ($act === 'delete') {
                            if (!empty($r['img'])) @unlink($uploadDir . '/' . basename($r['img']));
                            continue;
                        }
                        $r['ok'] = true;
                    }
                    $out[] = $r;
                }
                return $out;
            });
            go('testimoni.php?admin=1');
        }
    }
    go('testimoni.php');
}

// ---------- Siapkan tampilan ----------
$_SESSION['form_t'] = time(); // dipakai untuk menolak kiriman otomatis yang terlalu cepat
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$old   = $_SESSION['old'] ?? ['nama' => '', 'svc' => '', 'item' => '', 'rate' => 0, 'isi' => '']; unset($_SESSION['old']);

$all       = load_data($dataFile);
$isAdmin   = $adminOn && !empty($_SESSION['admin']);
$adminView = isset($_GET['admin']);
$public    = array_values(array_filter($all, function ($r) { return !empty($r['ok']); }));
$public    = array_reverse($public);
$count     = count($public);
$avg       = $count ? array_sum(array_column($public, 'rate')) / $count : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Testimoni - <?= e($brand) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap" rel="stylesheet">
<script>document.documentElement.className='js';</script>
<style>
:root{--bg:#07050d;--fg:#fff8f1;--mut:#c2b7b0;--glass:rgba(255,255,255,.045);--gb:rgba(255,138,25,.32);--ac:#ff7900;--ac2:#ffc226;--purple:#6a1fd0;--orange:#ff8a00;--amber:#ffb627}
html{scroll-behavior:smooth}
*{box-sizing:border-box}
body{margin:0;color:var(--fg);font-family:"Segoe UI",system-ui,-apple-system,sans-serif;font-size:15px;line-height:1.55;overflow-x:hidden;position:relative;
  background-color:var(--bg);
  background-image:
    radial-gradient(60% 45% at 100% 0%, rgba(255,138,0,.55), transparent 70%),
    radial-gradient(45% 40% at 0% 18%, rgba(255,120,0,.40), transparent 70%),
    radial-gradient(55% 45% at 55% 38%, rgba(106,31,208,.50), transparent 72%),
    radial-gradient(50% 40% at 15% 100%, rgba(255,138,0,.30), transparent 70%),
    radial-gradient(40% 35% at 100% 85%, rgba(106,31,208,.35), transparent 70%);
  background-repeat:no-repeat;background-attachment:fixed}
a{color:inherit;text-decoration:none}
.dots-bg{position:fixed;inset:0;z-index:-1;pointer-events:none;background-image:radial-gradient(rgba(255,255,255,.16) 1px,transparent 1.5px);background-size:24px 24px;opacity:.5;-webkit-mask-image:linear-gradient(135deg,#000 0%,transparent 45%);mask-image:linear-gradient(135deg,#000 0%,transparent 45%)}
body::before,body::after{content:"";position:fixed;pointer-events:none;z-index:-1;border-radius:50%;border:3px solid transparent;filter:drop-shadow(0 0 14px var(--orange))}
body::before{width:130vmax;height:46vmax;left:-35vmax;bottom:-30vmax;border-top-color:var(--amber);transform:rotate(-8deg);opacity:.7;animation:swoosh 14s ease-in-out infinite alternate}
body::after{width:90vmax;height:34vmax;right:-30vmax;top:-22vmax;border-bottom-color:var(--orange);transform:rotate(-12deg);opacity:.5;animation:swoosh 18s ease-in-out infinite alternate-reverse}
@keyframes swoosh{from{translate:0 0}to{translate:3vmax -1.5vmax}}
.w{max-width:1280px;width:90%;margin:0 auto;padding:0 15px}
/* header */
header{position:sticky;top:0;z-index:30;backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);background:rgba(5,4,10,.88);border-bottom:1px solid rgba(255,255,255,.09)}
header .w{display:flex;align-items:center;justify-content:space-between;min-height:78px;padding-top:12px;padding-bottom:12px}
.brand-lockup{display:flex;align-items:center;gap:12px;font-size:27px;font-weight:950;letter-spacing:-1px;white-space:nowrap}
.brand-lockup img{height:52px;width:52px;object-fit:contain;border-radius:50%;filter:drop-shadow(0 0 14px rgba(255,110,0,.25))}
.brand-lockup span b{color:#ff9b00}
.hn{display:flex;gap:8px;align-items:center}
.hn a{padding:10px 13px;border-radius:30px;font-weight:600;font-size:14px;color:#e7ded8;transition:.2s;position:relative}
.hn a:hover{color:#fff;background:var(--glass)}
.hn a.active{color:#ffb000}
.hn a.active::after{content:"";position:absolute;left:14px;right:14px;bottom:2px;height:2px;border-radius:2px;background:var(--amber)}
.hn .cta{background:linear-gradient(90deg,var(--ac),var(--ac2));color:#111;font-weight:800;padding:12px 22px;box-shadow:0 0 24px rgba(255,135,0,.25)}
.hn .cta:hover{color:#111;box-shadow:0 0 22px rgba(255,122,0,.6)}
/* hero */
.hero{text-align:center;margin:56px 0 30px}
.hero small{display:inline-block;padding:7px 18px;border:1px solid #ffb000;border-radius:30px;letter-spacing:2px;font-size:12px;font-weight:800;background:rgba(0,0,0,.35)}
.hero h1{font-family:"Bebas Neue",Impact,"Arial Narrow",sans-serif;font-weight:400;font-style:italic;font-size:clamp(52px,9vw,104px);line-height:.95;margin:16px 0 12px;text-transform:uppercase;letter-spacing:-1px}
.hero h1 b{font-weight:400;background:linear-gradient(100deg,#ff7a00,#ffd52e 55%,#ff9c00);-webkit-background-clip:text;background-clip:text;color:transparent;filter:drop-shadow(0 0 14px rgba(255,140,0,.25))}
.hero p{margin:0 auto 22px;max-width:56ch;color:#d0c4bd}
.btn{display:inline-block;border:0;cursor:pointer;font:inherit;font-weight:900;color:#160900;background:linear-gradient(90deg,#ff7900,#ffbd20);padding:13px 28px;border-radius:30px;box-shadow:0 0 28px rgba(255,125,0,.4);transition:.2s}
.btn:hover{transform:translateY(-2px);box-shadow:0 0 38px rgba(255,125,0,.6)}
.btn.alt{background:transparent;color:#ffc000;border:1px solid #ff9b00;box-shadow:none}
.btn.sm{padding:8px 16px;font-size:12px}
.btn.del{background:transparent;color:#ff8d8d;border:1px solid #ff6b6b;box-shadow:none}
/* ringkasan */
.sum{display:flex;justify-content:center;gap:14px;flex-wrap:wrap;margin:26px 0 8px}
.sum div{min-width:150px;padding:16px 22px;text-align:center;border:1px solid rgba(255,145,20,.28);border-radius:20px;background:linear-gradient(120deg,rgba(255,105,0,.12),rgba(100,0,255,.08));backdrop-filter:blur(10px)}
.sum b{display:block;font-size:30px;font-weight:900;background:linear-gradient(90deg,var(--ac),var(--ac2));-webkit-background-clip:text;background-clip:text;color:transparent}
.sum span{font-size:12px;color:var(--mut)}
/* filter */
.chips{display:flex;justify-content:center;flex-wrap:wrap;gap:10px;margin:30px 0 26px}
.chips button{font:inherit;font-weight:700;font-size:13px;color:var(--fg);background:rgba(0,0,0,.35);border:1px solid var(--gb);padding:9px 20px;border-radius:30px;cursor:pointer;transition:.2s}
.chips button:hover{border-color:#ff9b00;color:#ffc000}
.chips button.on{background:linear-gradient(90deg,#ff8500,#ffc126);color:#130900;border-color:transparent}
button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:3px solid #fff;outline-offset:3px}
/* pencarian */
.search{display:flex;align-items:center;gap:12px;max-width:660px;margin:34px auto 0;padding:10px 18px;border:1.5px solid #ff9b00;border-radius:18px;background:rgba(0,0,0,.4)}
.search:focus-within{box-shadow:0 0 0 3px rgba(255,176,0,.35)}
.search svg{flex:none;width:24px;height:24px}
.search input{flex:1;min-width:0;border:0;outline:0;background:transparent;color:#ffd27a;font:inherit;font-size:18px;text-align:center}
.search input::placeholder{color:#a8896a}
.search button{border:0;background:none;color:#ffb000;font-size:22px;line-height:1;cursor:pointer;padding:0 2px}
/* daftar testimoni: banner panah */
.list{display:flex;flex-direction:column;gap:46px;max-width:940px;margin:0 auto;padding-top:14px}
.card{--pw:150px;--tip:56px;position:relative;display:flex;gap:16px;align-items:stretch;min-height:150px;transition:transform .25s}
.card:hover{transform:translateY(-4px)}
.card.hide{display:none}
.card::before{content:"";position:absolute;left:calc(var(--pw) + 16px);top:-22px;width:26px;height:22px;background:rgba(255,138,0,.55);clip-path:polygon(100% 0,100% 100%,0 100%);pointer-events:none}
.pic{flex:none;width:var(--pw);height:var(--pw);padding:0;border:3px solid #ff9b00;border-radius:28px;overflow:hidden;background:#0a0610;cursor:zoom-in;box-shadow:0 0 24px rgba(255,125,0,.25)}
.pic img{display:block;width:100%;height:100%;object-fit:cover;transition:transform .35s}
.pic:hover img{transform:scale(1.06)}
.pic.noimg{display:grid;place-items:center;cursor:default;font-size:44px;font-weight:900;color:#160900;background:linear-gradient(135deg,#ff7900,#ffc226)}
.ban{flex:1;min-width:0;padding:2px;background:linear-gradient(120deg,#ff9b00,rgba(255,138,0,.45) 50%,#8b3dff);clip-path:polygon(0 0,calc(100% - var(--tip)) 0,100% 50%,calc(100% - var(--tip)) 100%,0 100%)}
.ban-in{height:100%;display:flex;flex-direction:column;gap:8px;padding:20px 78px 18px 26px;background:linear-gradient(140deg,rgba(255,130,0,.14),rgba(120,0,255,.16)),#120a1c;clip-path:polygon(0 0,calc(100% - var(--tip) + 1px) 0,100% 50%,calc(100% - var(--tip) + 1px) 100%,0 100%)}
.ban-top{display:flex;justify-content:space-between;align-items:center;gap:6px 14px;flex-wrap:wrap}
.nm{font-size:18px;font-weight:900;line-height:1.2;color:#fff;overflow-wrap:anywhere}
.stars{color:#ffc226;letter-spacing:2px;font-size:20px;line-height:1}
.stars i{font-style:normal;color:rgba(255,255,255,.22)}
.ban-in p{margin:0;font-style:italic;font-size:16px;color:#eadfd8;overflow-wrap:anywhere;white-space:pre-line}
.ban-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:auto}
.ban-meta small{color:var(--mut);font-size:12px}
.tag{display:inline-block;font-size:11px;font-weight:800;color:#170b00;padding:4px 12px;border-radius:20px;background:linear-gradient(90deg,#ffb000,#ff7900)}
.empty{text-align:center;padding:44px 20px;border:1px dashed var(--gb);border-radius:24px;color:var(--mut)}
/* ajakan menulis + form pop-up */
.cta-box{max-width:640px;margin:60px auto 0;text-align:center;scroll-margin-top:96px}
.cta-box h2{margin:0 0 6px;font-size:clamp(22px,3vw,32px);font-weight:900}
.cta-box p{margin:0 0 18px;color:#c9bdb6}
.cta-box .msg{text-align:left}
dialog#fm{width:min(720px,94vw);max-height:92vh;padding:0;border:1px solid rgba(255,145,20,.55);border-radius:26px;color:var(--fg);background:linear-gradient(130deg,rgba(255,105,0,.14),rgba(100,0,255,.14)),#120a1c;overflow:auto;box-shadow:0 30px 90px -20px rgba(255,120,0,.45)}
dialog#fm::backdrop{background:rgba(0,0,0,.8);backdrop-filter:blur(4px)}
.fm-in{padding:30px}
.fm-in h2{margin:0 0 6px;padding-right:44px;font-size:clamp(22px,3vw,30px);font-weight:900}
.fm-in>p{margin:0 0 22px;color:#c9bdb6}
.fm-x{position:absolute;right:14px;top:14px;width:40px;height:40px;border-radius:50%;border:1px solid rgba(255,255,255,.3);background:rgba(0,0,0,.5);color:#fff;font-size:24px;line-height:1;cursor:pointer;z-index:2}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.fld{display:flex;flex-direction:column;gap:6px}
.fld.full{grid-column:1/-1}
.lb{font-weight:700;font-size:13px}
.lb small{font-weight:400;color:var(--mut)}
input[type=text],input[type=password],select,textarea{font:inherit;color:var(--fg);background:rgba(5,3,10,.7);border:1px solid var(--gb);border-radius:14px;padding:12px 14px;width:100%}
select option{background:#14091c;color:#fff}
textarea{min-height:130px;resize:vertical}
input::placeholder,textarea::placeholder{color:#8f847d}
.rate{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:2px;position:relative}
.rate input{position:absolute;opacity:0;width:1px;height:1px}
.rate label{font-size:34px;line-height:1;color:rgba(255,255,255,.25);cursor:pointer;transition:.15s}
.rate label:hover,.rate label:hover~label,.rate input:checked~label{color:#ffc226}
.rate input:focus-visible+label{outline:3px solid #fff;border-radius:6px}
.cnt{font-size:12px;color:var(--mut);text-align:right}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.msg{margin:0 0 18px;padding:13px 16px;border-radius:14px;font-weight:600}
.msg.ok{background:rgba(60,200,120,.14);border:1px solid rgba(60,200,120,.5);color:#a8f0c6}
.msg.err{background:rgba(255,90,90,.12);border:1px solid rgba(255,90,90,.5);color:#ffc0c0}
.fine{margin:14px 0 0;font-size:12px;color:var(--mut)}
/* gambar */
.shot{display:block;width:100%;margin:0;padding:0;border:1px solid rgba(255,255,255,.12);border-radius:14px;overflow:hidden;background:#0a0610;cursor:zoom-in}
.shot img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;transition:transform .35s}
.shot:hover img{transform:scale(1.04)}
input[type=file]{font:inherit;color:var(--mut);background:rgba(5,3,10,.7);border:1px dashed var(--gb);border-radius:14px;padding:12px 14px;width:100%;cursor:pointer}
input[type=file]::file-selector-button{font:inherit;font-weight:800;color:#160900;background:linear-gradient(90deg,#ff7900,#ffbd20);border:0;border-radius:20px;padding:7px 16px;margin-right:12px;cursor:pointer}
.pv img{display:block;max-width:220px;max-height:160px;border-radius:12px;border:1px solid var(--gb);margin-top:6px}
.pv:empty{display:none}
.thumb{display:block;max-width:180px;max-height:130px;margin-top:10px;border-radius:10px;border:1px solid var(--gb)}
dialog#lb{padding:0;border:1px solid var(--gb);border-radius:18px;background:#0a0610;max-width:min(92vw,1000px);overflow:hidden}
dialog#lb::backdrop{background:rgba(0,0,0,.82)}
dialog#lb img{display:block;max-width:100%;max-height:86vh;margin:0 auto}
dialog#lb button{position:absolute;right:10px;top:10px;width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,.3);background:rgba(0,0,0,.65);color:#fff;font-size:22px;line-height:1;cursor:pointer}
/* admin */
.adm{margin:44px auto;max-width:900px}
.adm h1{margin:0 0 18px}
.row{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;padding:16px;margin-bottom:12px;border:1px solid var(--gb);border-radius:18px;background:var(--glass)}
.row p{margin:6px 0 0;overflow-wrap:anywhere;white-space:pre-line}
.row .acts{display:flex;gap:8px;flex:none}
.pend{color:#ffc226;font-weight:800;font-size:12px}
/* footer */
footer{margin-top:70px;border-top:1px solid rgba(255,255,255,.1);padding:28px 0 90px;text-align:center;color:var(--mut);font-size:12px}
footer a{color:#ffb000}
.fab{position:fixed;right:18px;bottom:18px;z-index:40;display:flex;gap:8px;align-items:center;background:linear-gradient(100deg,#ff7900,#ffc226);color:#111;font-weight:800;padding:14px 20px;border-radius:40px;box-shadow:0 8px 30px rgba(255,122,0,.45);border:1px solid rgba(255,220,140,.4)}
/* animasi muncul */
@keyframes rise{from{opacity:0;transform:translateY(18px)}}
@keyframes pop{from{opacity:0;transform:translateY(26px) scale(.96)}}
@keyframes twinkle{from{opacity:0;transform:scale(0) rotate(-120deg)}}
.hero>small{animation:rise .6s ease backwards}
.hero h1{animation:rise .6s ease .1s backwards}
.hero>p{animation:rise .6s ease .3s backwards}
.hero>.btn{animation:rise .6s ease .45s backwards}
.js .card{opacity:0}
.js .card.in{opacity:1;animation:pop .6s cubic-bezier(.2,.9,.3,1.1) calc(var(--i,0)*110ms) backwards}
.stars s{text-decoration:none;display:inline-block}
.card.in .stars s{animation:twinkle .45s ease calc(var(--i,0)*110ms + 350ms + var(--k)*110ms) backwards}
@media(max-width:900px){.brand-lockup{font-size:22px}.hn a{padding:8px 9px}}
@media(max-width:700px){header .w{align-items:flex-start;gap:12px;flex-direction:column}.hn{width:100%;flex-wrap:wrap}.hn a{font-size:12px;padding:7px 9px}.brand-lockup img{height:42px;width:42px}.fg{grid-template-columns:1fr}.formbox{padding:22px}.row{flex-direction:column}}
@media(max-width:640px){.card{--pw:86px;--tip:34px;min-height:112px}.card::before{top:-18px;width:20px;height:18px}.pic{border-radius:20px;border-width:2px}.pic.noimg{font-size:28px}.ban-in{padding:14px 46px 14px 16px;gap:6px}.nm{font-size:16px}.stars{font-size:16px}.ban-in p{font-size:14.5px}.fg{grid-template-columns:1fr}.fm-in{padding:24px 18px}.search input{font-size:16px}}
@media(max-width:600px){.w{width:100%;padding:0 14px}}
@media(prefers-reduced-motion:reduce){body::before,body::after{animation:none}*{transition:none!important;animation:none!important}.js .card{opacity:1}}
</style>
</head>
<body>
<div class="dots-bg"></div>

<header><div class="w">
  <a class="brand-lockup" href="index.php"><img src="logo png.png" alt="Logo Rekan Joki"><span>Rekan <b>Joki</b></span></a>
  <nav class="hn" aria-label="Navigasi utama">
    <a href="index.php">Home</a>
    <a class="active" href="testimoni.php" aria-current="page">Testimoni</a>
    <a class="cta" target="_blank" rel="noopener" href="<?= e(wa_link($wa, "Halo $brand, saya mau tanya layanan.")) ?>">☏ Chat Sekarang</a>
  </nav>
</div></header>

<main class="w">

<?php if ($adminView): ?>
  <section class="adm">
    <h1>Admin Testimoni</h1>
    <?php if (!$adminOn): ?>
      <p class="msg err">Halaman admin nonaktif. Ubah <code>$adminPassword</code> di bagian atas testimoni.php untuk mengaktifkannya.</p>
    <?php elseif (!$isAdmin): ?>
      <?php if ($flash): ?><p class="msg <?= e($flash[0]) ?>"><?= e($flash[1]) ?></p><?php endif; ?>
      <form method="post" class="fld" style="max-width:360px;gap:12px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="login">
        <label class="lb" for="pw">Password admin</label>
        <input type="password" id="pw" name="pw" required autocomplete="current-password">
        <button class="btn" type="submit">Masuk</button>
      </form>
    <?php else: ?>
      <form method="post" style="margin-bottom:18px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="btn alt sm" type="submit">Keluar</button>
        <a class="btn alt sm" href="testimoni.php">Lihat halaman</a>
      </form>
      <?php if (!$all): ?><p class="msg ok">Belum ada testimoni.</p><?php endif; ?>
      <?php foreach (array_reverse($all) as $r): ?>
        <div class="row">
          <div>
            <b><?= e($r['nama']) ?></b> · <?= e($labels[$r['svc']] ?? $r['svc']) ?><?= $r['item'] !== '' ? ' · ' . e($r['item']) : '' ?> · <?= (int)$r['rate'] ?>★ · <?= e(tgl((int)$r['ts'])) ?>
            <?php if (empty($r['ok'])): ?> <span class="pend">MENUNGGU</span><?php endif; ?>
            <p><?= e($r['isi']) ?></p>
            <?php if (!empty($r['img'])): ?><img class="thumb" src="<?= e($uploadUrl . '/' . basename($r['img'])) ?>" alt="Gambar testimoni" loading="lazy"><?php endif; ?>
          </div>
          <form method="post" class="acts">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <?php if (empty($r['ok'])): ?><button class="btn sm" name="action" value="approve" type="submit">Setujui</button><?php endif; ?>
            <button class="btn sm del" name="action" value="delete" type="submit" onclick="return confirm('Hapus testimoni ini?')">Hapus</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

<?php else: ?>

  <section class="hero">
    <small>KATA MEREKA</small>
    <h1>Testimoni</h1>
    <p>Cerita pelanggan yang sudah memakai layanan desain, peta, dan tugas dari <?= e($brand) ?>.</p>
    <button type="button" class="btn js-open">Tulis Testimoni ↗</button>
  </section>

  <?php if ($count): ?>
  <section class="sum" aria-label="Ringkasan">
    <div><b><?= number_format($avg, 1, ',', '.') ?> ★</b><span>Rata-rata rating</span></div>
    <div><b><?= $count ?></b><span>Testimoni</span></div>
  </section>

  <div class="search" role="search">
    <svg viewBox="0 0 24 24" fill="none" stroke="#ffb000" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><circle cx="10" cy="10" r="6.5"/><path d="M15 15l6 6"/></svg>
    <input type="search" id="q" placeholder="cari testimoni..." aria-label="Cari testimoni" autocomplete="off">
    <button type="button" id="qx" aria-label="Hapus pencarian" hidden>✕</button>
  </div>

  <div class="chips" role="group" aria-label="Filter layanan">
    <button type="button" class="on" data-f="all">Semua</button>
    <?php foreach ($labels as $k => $l): ?>
      <button type="button" data-f="<?= e($k) ?>"><?= e($l) ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <section class="list" id="list">
    <?php if (!$count): ?>
      <div class="empty">Belum ada testimoni. Jadilah yang pertama menulis!</div>
    <?php endif; ?>
    <?php foreach ($public as $n => $t): $rt = max(1, min(5, (int)$t['rate'])); ?>
      <article class="card" data-svc="<?= e($t['svc']) ?>" data-q="<?= e(mb_strtolower($t['nama'] . ' ' . $t['isi'] . ' ' . $t['item'])) ?>" style="--i:<?= $n % 3 ?>">
        <?php if (!empty($t['img'])): $src = $uploadUrl . '/' . basename($t['img']); ?>
          <button type="button" class="pic" data-src="<?= e($src) ?>" aria-label="Perbesar gambar dari <?= e($t['nama']) ?>"><img src="<?= e($src) ?>" alt="Gambar dari <?= e($t['nama']) ?>" loading="lazy"></button>
        <?php else: ?>
          <div class="pic noimg" aria-hidden="true"><?= e(initials($t['nama'])) ?></div>
        <?php endif; ?>
        <div class="ban"><div class="ban-in">
          <div class="ban-top">
            <b class="nm"><?= e($t['nama']) ?></b>
            <div class="stars" role="img" aria-label="Rating <?= $rt ?> dari 5"><?php for ($k = 1; $k <= $rt; $k++): ?><s style="--k:<?= $k ?>">★</s><?php endfor; ?><i><?= str_repeat('★', 5 - $rt) ?></i></div>
          </div>
          <p>“<?= e($t['isi']) ?>”</p>
          <div class="ban-meta">
            <span class="tag"><?= e($labels[$t['svc']] ?? $t['svc']) ?><?= $t['item'] !== '' ? ' · ' . e($t['item']) : '' ?></span>
            <small><?= e(tgl((int)$t['ts'])) ?></small>
          </div>
        </div></div>
      </article>
    <?php endforeach; ?>
    <?php if ($count): ?><p class="empty" id="nores" hidden>Tidak ada testimoni yang cocok.</p><?php endif; ?>
  </section>

  <section class="cta-box" id="tulis">
    <?php if ($flash && $flash[0] === 'ok'): ?><p class="msg ok" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
    <h2>Sudah pernah pakai layanan kami?</h2>
    <p>Ceritakan pengalaman Anda, tidak perlu login.</p>
    <button type="button" class="btn js-open">＋ Tambah Testimoni</button>
  </section>

  <dialog id="fm" aria-labelledby="fmt"<?= ($flash && $flash[0] === 'err') ? ' data-open="1"' : '' ?>>
    <button type="button" class="fm-x" aria-label="Tutup form">×</button>
    <div class="fm-in">
      <h2 id="fmt">Tulis testimoni Anda</h2>
      <p>Ceritakan pengalaman Anda memakai layanan <?= e($brand) ?>.</p>
      <?php if ($flash && $flash[0] === 'err'): ?><p class="msg err" role="alert"><?= e($flash[1]) ?></p><?php endif; ?>
    <form method="post" action="testimoni.php#tulis" autocomplete="off" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="add">
      <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <div class="fg">
        <div class="fld">
          <label class="lb" for="nama">Nama</label>
          <input type="text" id="nama" name="nama" maxlength="40" required placeholder="Nama Anda" value="<?= e($old['nama']) ?>">
        </div>
        <div class="fld">
          <label class="lb" for="svc">Jenis layanan</label>
          <select id="svc" name="svc" required>
            <option value="" disabled <?= $old['svc'] === '' ? 'selected' : '' ?>>Pilih layanan</option>
            <?php foreach ($labels as $k => $l): ?>
              <option value="<?= e($k) ?>" <?= $old['svc'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld">
          <label class="lb" for="item">Yang dipesan <small>(opsional, contoh: Logo, Peta DAS)</small></label>
          <input type="text" id="item" name="item" maxlength="40" value="<?= e($old['item']) ?>">
        </div>
        <div class="fld">
          <span class="lb" id="rl">Rating</span>
          <div class="rate" role="radiogroup" aria-labelledby="rl">
            <?php for ($s = 5; $s >= 1; $s--): ?>
              <input type="radio" id="r<?= $s ?>" name="rate" value="<?= $s ?>" required <?= (int)$old['rate'] === $s ? 'checked' : '' ?>>
              <label for="r<?= $s ?>" title="<?= $s ?> bintang" aria-label="<?= $s ?> bintang">★</label>
            <?php endfor; ?>
          </div>
        </div>
        <div class="fld full">
          <label class="lb" for="isi">Testimoni</label>
          <textarea id="isi" name="isi" maxlength="500" minlength="10" required placeholder="Tulis pengalaman Anda (10 sampai 500 karakter)"><?= e($old['isi']) ?></textarea>
          <div class="cnt"><span id="c">0</span>/500</div>
        </div>
      </div>
      <p style="margin:18px 0 0"><button class="btn" type="submit">Kirim Testimoni</button></p>
      <p class="fine"><?= $moderasi ? 'Testimoni ditinjau admin sebelum ditampilkan.' : 'Testimoni langsung ditampilkan.' ?> Tautan tidak diperbolehkan. Gambar akan tampil untuk umum, jadi jangan unggah gambar berisi data pribadi.</p>
    </form>
    </div>
  </dialog>

<?php endif; ?>

</main>

<footer><div class="w">© <?= date('Y') ?> <?= e($brand) ?>. Seluruh hak cipta dilindungi. <span>•</span> <a href="<?= e(wa_link($wa, "Halo $brand, saya mau tanya layanan.")) ?>" target="_blank" rel="noopener">Hubungi kami</a></div></footer>
<a class="fab" target="_blank" rel="noopener" href="<?= e(wa_link($wa, "Halo $brand, saya mau tanya layanan.")) ?>">💬 Chat WhatsApp</a>

<dialog id="lb" aria-label="Gambar testimoni"><img alt=""><button type="button" aria-label="Tutup">×</button></dialog>

<script>
// Perbesar gambar testimoni
var dlg = document.getElementById('lb');
document.querySelectorAll('.shot,.pic[data-src]').forEach(function (b) {
  b.addEventListener('click', function () { dlg.querySelector('img').src = b.dataset.src; dlg.showModal(); });
});
dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.tagName === 'BUTTON') dlg.close(); });

// Pratinjau gambar sebelum dikirim
var fi = document.getElementById('foto'), pv = document.getElementById('pv');
if (fi && pv) {
  fi.addEventListener('change', function () {
    pv.innerHTML = '';
    var f = fi.files[0];
    if (!f) return;
    if (f.size > 3 * 1024 * 1024) { pv.textContent = 'Gambar terlalu besar (maksimal 3 MB).'; fi.value = ''; return; }
    var im = new Image(); im.src = URL.createObjectURL(f); im.alt = 'Pratinjau gambar';
    pv.appendChild(im);
  });
}

// Filter layanan + pencarian
var chips = document.querySelectorAll('.chips button'), cards = document.querySelectorAll('.card');
var qi = document.getElementById('q'), qx = document.getElementById('qx'), nores = document.getElementById('nores');
var fsvc = 'all', fq = '';
function applyFilter() {
  var shown = 0;
  cards.forEach(function (c) {
    var ok = (fsvc === 'all' || c.dataset.svc === fsvc) && (fq === '' || c.dataset.q.indexOf(fq) > -1);
    c.classList.toggle('hide', !ok);
    if (ok) shown++;
  });
  if (nores) nores.hidden = shown > 0;
  if (qx) qx.hidden = fq === '';
}
chips.forEach(function (b) {
  b.addEventListener('click', function () {
    chips.forEach(function (x) { x.classList.toggle('on', x === b); });
    fsvc = b.dataset.f; applyFilter();
  });
});
if (qi) {
  qi.addEventListener('input', function () { fq = qi.value.trim().toLowerCase(); applyFilter(); });
  qx.addEventListener('click', function () { qi.value = ''; fq = ''; applyFilter(); qi.focus(); });
}

// Munculkan kartu dengan animasi saat masuk layar
var io = ('IntersectionObserver' in window) ? new IntersectionObserver(function (es) {
  es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
}, { threshold: .12 }) : null;
cards.forEach(function (c) { if (io) io.observe(c); else c.classList.add('in'); });

// Form testimoni muncul sebagai pop-up setelah tombol ditekan
var fm = document.getElementById('fm');
if (fm) {
  document.querySelectorAll('.js-open').forEach(function (b) {
    b.addEventListener('click', function () { fm.showModal(); });
  });
  fm.querySelector('.fm-x').addEventListener('click', function () { fm.close(); });
  fm.addEventListener('click', function (e) { if (e.target === fm) fm.close(); });
  if (fm.dataset.open) fm.showModal();
}

// Penghitung karakter
var ta = document.getElementById('isi'), cn = document.getElementById('c');
if (ta && cn) { var up = function () { cn.textContent = ta.value.length; }; ta.addEventListener('input', up); up(); }
</script>
</body>
</html>