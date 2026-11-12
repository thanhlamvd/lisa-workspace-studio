<?php
// Tra cứu đơn hàng cho videoshopai.vn — khách tra cứu bằng mã đơn + SĐT.
// Đơn hàng lưu ở ngoài public_html nên không ai xem được qua web.
header('Content-Type: application/json; charset=utf-8');
$DATA_DIR = '/home/videosho6af5/orders-data';
$DATA_FILE = $DATA_DIR . '/orders.json';
$RATE_FILE = $DATA_DIR . '/ratelimit.json';
$UPLOAD_DIR = $DATA_DIR . '/uploads';
$ADMIN_TOKEN = 'b5493d8efc83c73aaa528ea44863a1e4';
$STATUSES = ['Mới nhận', 'Đã nhận cọc', 'Đang làm', 'Đang sửa theo yêu cầu', 'Đã giao', 'Đã hủy'];
$MAX_UPLOADS = 5;
$MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

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
    return preg_replace('/[^0-9]/', '', (string)$s);
}
function clean_text($s, $max) {
    return mb_substr(trim(strip_tags((string)$s)), 0, $max);
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
    return [
        'code' => $code,
        'pkg' => $o['pkg'],
        'name' => $o['name'],
        'status' => $o['status'],
        'created_at' => $o['created_at'],
        'total' => (int)$o['total'],
        'deposit' => (int)$o['deposit'],
        'delivered' => $delivered,
        'revisions' => $revisions,
        'uploads' => $uploads,
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
    if (!valid_code($code) || strlen($phone) < 9 || $name === '' || $pkg === '') {
        echo json_encode(['ok' => false, 'error' => 'missing']); exit;
    }
    $all = orders_load();
    if (isset($all[$code])) { echo json_encode(['ok' => false, 'error' => 'duplicate']); exit; }
    $all[$code] = [
        'code' => $code, 'pkg' => $pkg, 'name' => $name, 'phone' => $phone,
        'shop' => $shop, 'product' => $product, 'note' => $note,
        'total' => $total, 'deposit' => $deposit,
        'status' => 'Mới nhận', 'created_at' => date('c'),
        'revisions' => [], 'uploads' => [], 'delivered' => [], 'history' => [],
    ];
    orders_save($all);
    echo json_encode(['ok' => true, 'code' => $code]);
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
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
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
        'total' => $total, 'deposit' => $deposit,
        'status' => $status, 'created_at' => date('c'),
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
