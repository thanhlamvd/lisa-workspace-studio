<?php
// Tra cứu đơn hàng cho videoshopai.vn — khách tra cứu bằng mã đơn + SĐT.
// Đơn hàng lưu ở ngoài public_html nên không ai xem được qua web.
// v11 (13/11/2026): chuẩn hóa SĐT +84 về đầu 0 trong digits_only() — khách nhập
// +84327... hay 0327... đều khớp cùng một đơn (lỗi phát hiện 13/11 khi Vo tra
// mã VS-1211-516 bằng số +84: tạo đơn lưu 0327... mà tra +84... báo không thấy).
// v12 (13/11/2026): giới hạn file 2MB -> 5MB (cả ảnh tham chiếu lẫn file giọng)
// sau khi hosting tăng upload_max_filesize/post_max_size lên 8M qua PHP Settings.
// v13 (13/11/2026): sau khi tạo đơn, thử gửi email xác nhận bằng mail() (best-effort).
// v14 (13/11/2026): mail() trên hosting trả false nên chuyển sang gửi qua SMTP
// (hộp thư thật donhang@videoshopai.vn, cấu hình trong smtp-config.php ngoài
// public_html); mail() chỉ còn là phương án dự phòng khi SMTP lỗi.
header('Content-Type: application/json; charset=utf-8');
$DATA_DIR = '/home/videosho6af5/orders-data';
$DATA_FILE = $DATA_DIR . '/orders.json';
$RATE_FILE = $DATA_DIR . '/ratelimit.json';
$UPLOAD_DIR = $DATA_DIR . '/uploads';
$ADMIN_TOKEN = 'b5493d8efc83c73aaa528ea44863a1e4';
$STATUSES = ['Mới nhận', 'Đã nhận cọc', 'Đang làm', 'Đang sửa theo yêu cầu', 'Đã giao', 'Đã hủy'];
$MAX_UPLOADS = 5;
$MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB — hosting đã tăng upload_max_filesize lên 8M (13/11)
$MAX_VOICE_SIZE = 5 * 1024 * 1024; // 5MB, 1 file giọng/đơn — khớp php.ini mới
$MAX_REF_IMAGES = 3; // tối đa 3 ảnh tham chiếu gửi kèm lúc đặt hàng
$DELIVERY_CHANNELS = ['zalo', 'telegram', 'email', 'direct'];
$VOICE_AI = ['nu-tre' => 'Nữ trẻ trung', 'nu-tram' => 'Nữ trầm ấm', 'nam-tre' => 'Nam trẻ'];
// Mime thật cho phép theo từng đuôi (finfo trả về khác nhau tùy server).
$VOICE_AUDIO = [
    'mp3'  => ['audio/mpeg', 'audio/mp3'],
    'wav'  => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
    'm4a'  => ['audio/mp4', 'audio/x-m4a', 'audio/aac'],
    'webm' => ['audio/webm', 'video/webm'],
];

if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0700, true);
if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0700, true);
if (!file_exists($DATA_FILE)) @file_put_contents($DATA_FILE, '{}');
if (!file_exists($RATE_FILE)) @file_put_contents($RATE_FILE, '{}');

function orders_load() {
    global $DATA_FILE;
    $d = json_decode(@file_get_contents($DATA_FILE), true);
    return is_array($d) ? $d : [];
}
function orders_save($d) {
    global $DATA_FILE;
    @file_put_contents($DATA_FILE, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function clean_code($s) {
    return strtoupper(trim((string)$s));
}
function valid_code($s) {
    return preg_match('/^VS-\d{4}-\d{3}$/', $s) === 1;
}
function digits_only($s) {
    $d = preg_replace('/[^0-9]/', '', (string)$s);
    // SĐT Việt Nam nhập dạng +84 (11 chữ số) -> đổi về đầu 0 để khớp với đơn
    // đã lưu dạng 0...; số đã đúng dạng 0... hoặc ngắn hơn thì giữ nguyên.
    if (strlen($d) === 11 && substr($d, 0, 2) === '84') $d = '0' . substr($d, 2);
    return $d;
}
function clean_text($s, $max) {
    return mb_substr(trim(strip_tags((string)$s)), 0, $max);
}
function clean_email($s) {
    $e = trim((string)$s);
    if ($e === '') return '';
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : false;
}
function clean_telegram($s) {
    $t = trim((string)$s);
    if ($t === '') return '';
    $t = ltrim($t, '@');
    return preg_match('/^[A-Za-z0-9_]{5,32}$/', $t) === 1 ? '@' . $t : false;
}
// Gửi email qua SMTP (hộp thư donhang@videoshopai.vn).
// Cấu hình nằm trong smtp-config.php NGOÀI public_html — không đưa file đó lên git.
// Trả về true chỉ khi máy chủ SMTP nhận thư (250 sau DATA).
function smtp_read_reply($fp) {
    $reply = '';
    while (($line = @fgets($fp, 512)) !== false) {
        $reply .= $line;
        if (preg_match('/^\d{3} /', $line)) break; // hết phần trả lời (dòng cuối có "mã + khoảng trắng")
    }
    return $reply;
}
function smtp_expect($fp, $cmd, $codes) {
    if ($cmd !== null) @fwrite($fp, $cmd . "\r\n");
    $reply = smtp_read_reply($fp);
    $code = (int)substr($reply, 0, 3);
    return in_array($code, (array)$codes, true);
}
function smtp_send_mail($to, $subject, $body) {
    $cfgfile = dirname(__DIR__) . '/smtp-config.php';
    if (!is_file($cfgfile)) return false;
    $cfg = @include $cfgfile;
    if (!is_array($cfg) || empty($cfg['host']) || empty($cfg['user']) || !isset($cfg['pass'])) return false;
    $host = $cfg['host'];
    $port = (int)($cfg['port'] ?? 465);
    $secure = $cfg['secure'] ?? 'ssl';
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if ($fp === false) return false;
    @stream_set_timeout($fp, 15);
    try {
        if (!smtp_expect($fp, null, [220])) return false; // chào máy chủ
        if (!smtp_expect($fp, 'EHLO ' . $host, [250])) return false;
        if ($secure === 'tls') {
            if (!smtp_expect($fp, 'STARTTLS', [220])) return false;
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return false;
            if (!smtp_expect($fp, 'EHLO ' . $host, [250])) return false;
        }
        if (!smtp_expect($fp, 'AUTH LOGIN', [334])) return false;
        if (!smtp_expect($fp, base64_encode($cfg['user']), [334])) return false;
        if (!smtp_expect($fp, base64_encode($cfg['pass']), [235])) return false;
        $from = $cfg['user'];
        if (!smtp_expect($fp, 'MAIL FROM:<' . $from . '>', [250])) return false;
        if (!smtp_expect($fp, 'RCPT TO:<' . $to . '>', [250, 251])) return false;
        if (!smtp_expect($fp, 'DATA', [354])) return false;
        $enc_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $msg = "From: VideoShop AI <" . $from . ">\r\n"
            . "Reply-To: hotro@videoshopai.vn\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "Subject: " . $enc_subject . "\r\n"
            . "To: " . $to . "\r\n"
            . "\r\n"
            . str_replace(["\r\n", "\n"], "\r\n", $body);
        // Dot-stuffing: dòng nào bắt đầu bằng dấu chấm phải thêm một dấu chấm.
        $msg = preg_replace('/^\./m', '..', $msg);
        @fwrite($fp, $msg . "\r\n.\r\n");
        $ok = smtp_expect($fp, null, [250]);
        @fwrite($fp, "QUIT\r\n");
        return $ok;
    } finally {
        @fclose($fp);
    }
}
// Giới hạn tốc độ: tối đa 30 yêu cầu / 10 phút cho mỗi IP (chỉ các action của khách).
function rate_ok() {
    global $RATE_FILE;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $all = json_decode(@file_get_contents($RATE_FILE), true);
    if (!is_array($all)) $all = [];
    $now = time();
    $hits = isset($all[$ip]) && is_array($all[$ip]) ? $all[$ip] : [];
    $hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 600; }));
    if (count($hits) >= 30) return false;
    $hits[] = $now;
    $all[$ip] = $hits;
    @file_put_contents($RATE_FILE, json_encode($all), LOCK_EX);
    return true;
}
// Tìm đơn theo mã + SĐT (SĐT chỉ so chữ số). Sai mã hay sai số đều trả null.
function find_order($code, $phone) {
    $all = orders_load();
    if (!isset($all[$code])) return null;
    $o = $all[$code];
    if (digits_only($o['phone'] ?? '') !== $phone) return null;
    return $o;
}
// Dữ liệu trả cho khách: chỉ các trường được phép, url ảnh đi qua action download.
function public_order($o) {
    global $VOICE_AI;
    $code = $o['code'];
    $phone = digits_only($o['phone']);
    $delivered = [];
    foreach (($o['delivered'] ?? []) as $d) {
        $delivered[] = ['label' => $d['label'], 'url' => $d['url']];
    }
    $revisions = [];
    foreach (($o['revisions'] ?? []) as $r) {
        $revisions[] = ['ts' => $r['ts'], 'message' => $r['message'], 'status' => $r['status']];
    }
    $uploads = [];
    foreach (($o['uploads'] ?? []) as $u) {
        $uploads[] = [
            'name' => $u['name'],
            'url' => 'orders.php?action=download&code=' . urlencode($code) . '&phone=' . urlencode($phone) . '&file=' . urlencode($u['stored']),
        ];
    }
    $voice = $o['voice'] ?? ['mode' => 'ai', 'ai_voice' => 'nu-tre', 'file' => null];
    $voice_out = [
        'mode' => $voice['mode'] ?? 'ai',
        'ai_voice' => $voice['ai_voice'] ?? 'nu-tre',
        'ai_label' => $VOICE_AI[$voice['ai_voice'] ?? 'nu-tre'] ?? 'Nữ trẻ trung',
        'file' => null,
    ];
    if (!empty($voice['file'])) {
        $voice_out['file'] = [
            'name' => $voice['file']['name'],
            'url' => 'orders.php?action=download&code=' . urlencode($code) . '&phone=' . urlencode($phone) . '&file=' . urlencode($voice['file']['stored']),
        ];
    }
    return [
        'code' => $code,
        'pkg' => $o['pkg'],
        'name' => $o['name'],
        'status' => $o['status'],
        'created_at' => $o['created_at'],
        'total' => (int)$o['total'],
        'deposit' => (int)$o['deposit'],
        'email' => $o['email'] ?? '',
        'telegram' => $o['telegram'] ?? '',
        'delivery' => $o['delivery'] ?? 'zalo',
        'delivered' => $delivered,
        'revisions' => $revisions,
        'uploads' => $uploads,
        'voice' => $voice_out,
    ];
}
function delete_dir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) delete_dir($p); else @unlink($p);
    }
    @rmdir($dir);
}

$action = $_REQUEST['action'] ?? '';
$is_admin = (($_REQUEST['token'] ?? '') === $ADMIN_TOKEN);

// Khách tạo đơn (từ form đặt hàng trên web)
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_ok()) { echo json_encode(['ok' => false, 'error' => 'slow']); exit; }
    $code = clean_code($_POST['code'] ?? '');
    $phone = digits_only($_POST['phone'] ?? '');
    $name = clean_text($_POST['name'] ?? '', 60);
    $pkg = clean_text($_POST['pkg'] ?? '', 80);
    $shop = clean_text($_POST['shop'] ?? '', 200);
    $product = clean_text($_POST['product'] ?? '', 500);
    $note = clean_text($_POST['note'] ?? '', 1000);
    $total = intval($_POST['total'] ?? 0);
    $deposit = intval($_POST['deposit'] ?? 0);
    $voice_mode = in_array($_POST['voice'] ?? 'ai', ['ai', 'own'], true) ? $_POST['voice'] : 'ai';
    $voice_ai = $_POST['voice_ai'] ?? 'nu-tre';
    if (!isset($VOICE_AI[$voice_ai])) $voice_ai = 'nu-tre';
    $email = clean_email($_POST['email'] ?? '');
    $telegram = clean_telegram($_POST['telegram'] ?? '');
    $delivery = in_array($_POST['delivery'] ?? 'zalo', $DELIVERY_CHANNELS, true) ? $_POST['delivery'] : 'zalo';
    if ($email === false || $telegram === false) {
        echo json_encode(['ok' => false, 'error' => 'bad_contact']); exit;
    }
    if ($delivery === 'telegram' && $telegram === '') {
        echo json_encode(['ok' => false, 'error' => 'missing_contact']); exit;
    }
    if ($delivery === 'email' && $email === '') {
        echo json_encode(['ok' => false, 'error' => 'missing_contact']); exit;
    }
    if (!valid_code($code) || strlen($phone) < 9 || $name === '' || $pkg === '') {
        echo json_encode(['ok' => false, 'error' => 'missing']); exit;
    }
    $all = orders_load();
    if (isset($all[$code])) { echo json_encode(['ok' => false, 'error' => 'duplicate']); exit; }
    $all[$code] = [
        'code' => $code, 'pkg' => $pkg, 'name' => $name, 'phone' => $phone,
        'shop' => $shop, 'product' => $product, 'note' => $note,
        'email' => $email, 'telegram' => $telegram, 'delivery' => $delivery,
        'total' => $total, 'deposit' => $deposit,
        'status' => 'Mới nhận', 'created_at' => date('c'),
        'voice' => ['mode' => $voice_mode, 'ai_voice' => $voice_ai, 'file' => null],
        'revisions' => [], 'uploads' => [], 'delivered' => [], 'history' => [],
    ];
    // File giọng mẫu đính kèm lúc đặt hàng (không bắt buộc).
    // File lỗi thì đơn vẫn tạo thành công — khách tải lại sau ở mục tra cứu.
    $voice_file_ok = null;
    if (isset($_FILES['voice_file']) && $_FILES['voice_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $voice_file_ok = false;
        $vf = $_FILES['voice_file'];
        if ($vf['error'] === UPLOAD_ERR_OK && $vf['size'] <= $MAX_VOICE_SIZE) {
            $vext = strtolower(pathinfo($vf['name'], PATHINFO_EXTENSION));
            if (isset($VOICE_AUDIO[$vext])) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $vmime = $finfo->file($vf['tmp_name']);
                if (in_array($vmime, $VOICE_AUDIO[$vext], true)) {
                    $vdir = $UPLOAD_DIR . '/' . $code;
                    if (!is_dir($vdir)) @mkdir($vdir, 0700, true);
                    $vstored = uniqid('voice-', true) . '.' . $vext;
                    if (@move_uploaded_file($vf['tmp_name'], $vdir . '/' . $vstored)) {
                        $all[$code]['voice']['file'] = [
                            'name' => clean_text($vf['name'], 120),
                            'stored' => $vstored,
                            'ts' => time(),
                        ];
                        $voice_file_ok = true;
                    }
                }
            }
        }
    }
    // Ảnh tham chiếu gửi kèm lúc đặt hàng (không bắt buộc, tối đa 3 ảnh).
    // File lỗi thì bỏ qua file đó — đơn vẫn tạo thành công, khách tải lại sau ở mục tra cứu.
    $ref_ok = 0;
    if (!empty($_FILES['ref_images']) && is_array($_FILES['ref_images']['name'])) {
        $rcount = count($_FILES['ref_images']['name']);
        for ($i = 0; $i < $rcount && $i < $MAX_REF_IMAGES; $i++) {
            if ($_FILES['ref_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ($_FILES['ref_images']['size'][$i] > $MAX_FILE_SIZE) continue;
            $rext = strtolower(pathinfo($_FILES['ref_images']['name'][$i], PATHINFO_EXTENSION));
            $rallowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            if (!isset($rallowed[$rext])) continue;
            $rinfo = @getimagesize($_FILES['ref_images']['tmp_name'][$i]);
            if ($rinfo === false || ($rinfo['mime'] ?? '') !== $rallowed[$rext]) continue;
            $rdir = $UPLOAD_DIR . '/' . $code;
            if (!is_dir($rdir)) @mkdir($rdir, 0700, true);
            $rstored = uniqid('img-', true) . '.' . $rext;
            if (@move_uploaded_file($_FILES['ref_images']['tmp_name'][$i], $rdir . '/' . $rstored)) {
                $all[$code]['uploads'][] = [
                    'name' => clean_text($_FILES['ref_images']['name'][$i], 120),
                    'stored' => $rstored,
                    'ts' => time(),
                ];
                $ref_ok++;
            }
        }
    }
    orders_save($all);
    // Gửi email xác nhận cho khách (nếu khách có nhập email).
    // Chỉ thử gửi — lỗi gửi mail không làm hỏng việc tạo đơn.
    $email_sent = null;
    if ($email !== '') {
        $email_sent = false;
        $subject = 'Xác nhận đơn hàng VideoShop AI ' . $code;
        $body = "Chào " . $name . ",\n\n"
            . "VideoShop AI đã nhận đơn hàng của bạn.\n"
            . "Mã đơn: " . $code . "\n"
            . "Gói: " . $pkg . "\n"
            . "Tổng tiền: " . number_format($total, 0, ',', '.') . "đ\n"
            . "Cần chuyển đợt 1: " . number_format($deposit, 0, ',', '.') . "đ\n"
            . "Nội dung chuyển khoản: " . $code . " " . $name . "\n"
            . "Ngân hàng: BIDV — Số tài khoản: 8812103302 — Chủ tài khoản: Trương Quốc Thùy\n\n"
            . "Trạng thái đơn: Đang xác minh thanh toán. Sau khi chúng tôi xác nhận đã nhận tiền, chúng tôi sẽ bắt đầu làm video và báo lại cho bạn thời gian hoàn thành dự kiến.\n\n"
            . "Tra cứu tiến độ đơn tại https://videoshopai.vn/ (mục Tra cứu đơn hàng) bằng mã đơn và số điện thoại của bạn.\n\n"
            . "VideoShop AI — hotro@videoshopai.vn — Zalo 0327 659 686\n";
        $headers = "From: VideoShop AI <donhang@videoshopai.vn>\r\n"
            . "Reply-To: hotro@videoshopai.vn\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        try { $email_sent = smtp_send_mail($email, $subject, $body); } catch (Throwable $_e) { $email_sent = false; }
        if (!$email_sent) { try { $email_sent = @mail($email, $subject, $body, $headers); } catch (Throwable $_e) { $email_sent = false; } }
        $all[$code]['confirm_email_sent'] = $email_sent ? true : false;
        @orders_save($all);
    }
    echo json_encode(['ok' => true, 'code' => $code, 'voice_file_ok' => $voice_file_ok, 'ref_ok' => $ref_ok, 'email_sent' => $email_sent]);
    exit;
}

// Khách tra cứu đơn bằng mã đơn + SĐT
if ($action === 'lookup') {
    if (!rate_ok()) { echo json_encode(['ok' => false, 'error' => 'slow']); exit; }
    $code = clean_code($_REQUEST['code'] ?? '');
    $phone = digits_only($_REQUEST['phone'] ?? '');
    $o = find_order($code, $phone);
    if (!$o) { echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    echo json_encode(['ok' => true, 'order' => public_order($o)]);
    exit;
}

// Khách gửi yêu cầu sửa video
if ($action === 'revise' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_ok()) { echo json_encode(['ok' => false, 'error' => 'slow']); exit; }
    $code = clean_code($_POST['code'] ?? '');
    $phone = digits_only($_POST['phone'] ?? '');
    $msg = clean_text($_POST['message'] ?? '', 1000);
    if ($msg === '') { echo json_encode(['ok' => false, 'error' => 'missing']); exit; }
    $all = orders_load();
    $o = find_order($code, $phone);
    if (!$o) { echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    $all[$code]['revisions'][] = ['ts' => time(), 'message' => $msg, 'status' => 'new'];
    orders_save($all);
    echo json_encode(['ok' => true]);
    exit;
}

// Khách tải ảnh tham chiếu lên đơn
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    global $MAX_UPLOADS, $MAX_FILE_SIZE, $UPLOAD_DIR;
    if (!rate_ok()) { echo json_encode(['ok' => false, 'error' => 'slow']); exit; }
    $code = clean_code($_POST['code'] ?? '');
    $phone = digits_only($_POST['phone'] ?? '');
    $all = orders_load();
    $o = find_order($code, $phone);
    if (!$o) { echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    if (count($o['uploads'] ?? []) >= $MAX_UPLOADS) { echo json_encode(['ok' => false, 'error' => 'limit']); exit; }
    if (isset($_FILES['file']) && ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE)) {
        echo json_encode(['ok' => false, 'error' => 'too_big']); exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'missing']); exit;
    }
    $f = $_FILES['file'];
    if ($f['size'] > $MAX_FILE_SIZE) { echo json_encode(['ok' => false, 'error' => 'too_big']); exit; }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed[$ext])) { echo json_encode(['ok' => false, 'error' => 'type']); exit; }
    $info = @getimagesize($f['tmp_name']);
    if ($info === false || ($info['mime'] ?? '') !== $allowed[$ext]) {
        echo json_encode(['ok' => false, 'error' => 'type']); exit;
    }
    $dir = $UPLOAD_DIR . '/' . $code;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $stored = uniqid('img-', true) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        echo json_encode(['ok' => false, 'error' => 'save']); exit;
    }
    $all[$code]['uploads'][] = [
        'name' => clean_text($f['name'], 120),
        'stored' => $stored,
        'ts' => time(),
    ];
    orders_save($all);
    echo json_encode(['ok' => true, 'name' => clean_text($f['name'], 120)]);
    exit;
}

// Khách tải/thay file giọng mẫu sau khi đã đặt hàng (1 file/đơn, file mới thay file cũ)
if ($action === 'upload_voice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    global $MAX_VOICE_SIZE, $VOICE_AUDIO, $UPLOAD_DIR;
    if (!rate_ok()) { echo json_encode(['ok' => false, 'error' => 'slow']); exit; }
    $code = clean_code($_POST['code'] ?? '');
    $phone = digits_only($_POST['phone'] ?? '');
    $all = orders_load();
    $o = find_order($code, $phone);
    if (!$o) { echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    if (!isset($_FILES['voice_file'])) { echo json_encode(['ok' => false, 'error' => 'missing']); exit; }
    $f = $_FILES['voice_file'];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        echo json_encode(['ok' => false, 'error' => 'too_big']); exit;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'error' => 'missing']); exit; }
    if ($f['size'] > $MAX_VOICE_SIZE) { echo json_encode(['ok' => false, 'error' => 'too_big']); exit; }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset($VOICE_AUDIO[$ext])) { echo json_encode(['ok' => false, 'error' => 'type']); exit; }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    if (!in_array($mime, $VOICE_AUDIO[$ext], true)) { echo json_encode(['ok' => false, 'error' => 'type']); exit; }
    $dir = $UPLOAD_DIR . '/' . $code;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (!isset($all[$code]['voice']) || !is_array($all[$code]['voice'])) {
        $all[$code]['voice'] = ['mode' => 'own', 'ai_voice' => 'nu-tre', 'file' => null];
    }
    $old = $all[$code]['voice']['file']['stored'] ?? null;
    if ($old) @unlink($dir . '/' . basename($old));
    $stored = uniqid('voice-', true) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        echo json_encode(['ok' => false, 'error' => 'save']); exit;
    }
    $all[$code]['voice']['mode'] = 'own';
    $all[$code]['voice']['file'] = [
        'name' => clean_text($f['name'], 120),
        'stored' => $stored,
        'ts' => time(),
    ];
    orders_save($all);
    echo json_encode(['ok' => true, 'name' => clean_text($f['name'], 120)]);
    exit;
}

// Khách xem lại ảnh tham chiếu đã tải lên
if ($action === 'download') {
    global $UPLOAD_DIR;
    $code = clean_code($_REQUEST['code'] ?? '');
    $phone = digits_only($_REQUEST['phone'] ?? '');
    $file = $_REQUEST['file'] ?? '';
    $file = basename((string)$file);
    if (strpos($file, '..') !== false || $file === '' || $file === '.' || $file === '..') {
        http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit;
    }
    $o = find_order($code, $phone);
    if (!$o) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    $path = $UPLOAD_DIR . '/' . $code . '/' . $file;
    if (!is_file($path)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
              'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'webm' => 'audio/webm'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Pip xem toàn bộ đơn (mới nhất trước), kèm số yêu cầu sửa mới và số ảnh đã tải
if ($action === 'admin_list' && $is_admin) {
    $all = orders_load();
    $list = array_values($all);
    usort($list, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    foreach ($list as &$o) {
        $new = 0;
        foreach (($o['revisions'] ?? []) as $r) { if (($r['status'] ?? '') === 'new') $new++; }
        $o['new_revisions'] = $new;
        $o['uploads_count'] = count($o['uploads'] ?? []);
        $o['voice_mode'] = $o['voice']['mode'] ?? 'ai';
        $o['has_voice_file'] = !empty($o['voice']['file']);
        $o['phone_digits'] = digits_only($o['phone'] ?? '');
    }
    echo json_encode(['ok' => true, 'orders' => $list, 'total' => count($list)]);
    exit;
}

// Pip đổi trạng thái đơn
if ($action === 'admin_set_status' && $_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    global $STATUSES;
    $code = clean_code($_POST['code'] ?? '');
    $status = clean_text($_POST['status'] ?? '', 40);
    $note = clean_text($_POST['note'] ?? '', 500);
    if (!in_array($status, $STATUSES, true)) { echo json_encode(['ok' => false, 'error' => 'bad_status']); exit; }
    $all = orders_load();
    if (!isset($all[$code])) { echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    $all[$code]['status'] = $status;
    $all[$code]['history'][] = ['ts' => time(), 'status' => $status, 'note' => $note];
    orders_save($all);
    echo json_encode(['ok' => true]);
    exit;
}

// Pip gắn video đã giao vào đơn
if ($action === 'admin_attach' && $_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $code = clean_code($_POST['code'] ?? '');
    $label = clean_text($_POST['label'] ?? '', 120);
    $url = clean_text($_POST['url'] ?? '', 500);
    if ($label === '' || $url === '') { echo json_encode(['ok' => false, 'error' => 'missing']); exit; }
    $all = orders_load();
    if (!isset($all[$code])) { echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    $all[$code]['delivered'][] = ['label' => $label, 'url' => $url, 'ts' => time()];
    orders_save($all);
    echo json_encode(['ok' => true]);
    exit;
}

// Pip tạo đơn tay (khách đặt qua Zalo/chat)
if ($action === 'admin_create' && $_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    global $STATUSES;
    $code = clean_code($_POST['code'] ?? '');
    $phone = digits_only($_POST['phone'] ?? '');
    $name = clean_text($_POST['name'] ?? '', 60);
    $pkg = clean_text($_POST['pkg'] ?? '', 80);
    $shop = clean_text($_POST['shop'] ?? '', 200);
    $product = clean_text($_POST['product'] ?? '', 500);
    $note = clean_text($_POST['note'] ?? '', 1000);
    $total = intval($_POST['total'] ?? 0);
    $deposit = intval($_POST['deposit'] ?? 0);
    $voice_mode = in_array($_POST['voice'] ?? 'ai', ['ai', 'own'], true) ? $_POST['voice'] : 'ai';
    $voice_ai = $_POST['voice_ai'] ?? 'nu-tre';
    if (!isset($VOICE_AI[$voice_ai])) $voice_ai = 'nu-tre';
    $email = clean_email($_POST['email'] ?? '');
    $telegram = clean_telegram($_POST['telegram'] ?? '');
    $delivery = in_array($_POST['delivery'] ?? 'zalo', $DELIVERY_CHANNELS, true) ? $_POST['delivery'] : 'zalo';
    if ($email === false) $email = '';
    if ($telegram === false) $telegram = '';
    $status = clean_text($_POST['status'] ?? 'Mới nhận', 40);
    if (!in_array($status, $STATUSES, true)) $status = 'Mới nhận';
    if (!valid_code($code) || strlen($phone) < 9 || $name === '' || $pkg === '') {
        echo json_encode(['ok' => false, 'error' => 'missing']); exit;
    }
    $all = orders_load();
    if (isset($all[$code])) { echo json_encode(['ok' => false, 'error' => 'duplicate']); exit; }
    $all[$code] = [
        'code' => $code, 'pkg' => $pkg, 'name' => $name, 'phone' => $phone,
        'shop' => $shop, 'product' => $product, 'note' => $note,
        'email' => $email, 'telegram' => $telegram, 'delivery' => $delivery,
        'total' => $total, 'deposit' => $deposit,
        'status' => $status, 'created_at' => date('c'),
        'voice' => ['mode' => $voice_mode, 'ai_voice' => $voice_ai, 'file' => null],
        'revisions' => [], 'uploads' => [], 'delivered' => [], 'history' => [],
    ];
    orders_save($all);
    echo json_encode(['ok' => true, 'code' => $code]);
    exit;
}

// Pip xóa đơn (kèm thư mục ảnh của đơn nếu xóa được)
if ($action === 'admin_delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    global $UPLOAD_DIR;
    $code = clean_code($_POST['code'] ?? '');
    $all = orders_load();
    if (!isset($all[$code])) { echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
    unset($all[$code]);
    orders_save($all);
    delete_dir($UPLOAD_DIR . '/' . $code);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'bad']);
