<?php
// Chat widget backend cho videoshopai.vn — khách chat trực tiếp với Pip (trợ lý AI của VideoShop AI).
// Tin nhắn lưu ở ngoài public_html nên không ai xem được qua web.
header('Content-Type: application/json; charset=utf-8');
$DATA_DIR = '/home/videosho6af5/chat-data';
$DATA_FILE = $DATA_DIR . '/messages.json';
$ADMIN_TOKEN = 'b5493d8efc83c73aaa528ea44863a1e4';

if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0700, true);
if (!file_exists($DATA_FILE)) @file_put_contents($DATA_FILE, '[]');

function chat_load() {
    global $DATA_FILE;
    $d = json_decode(@file_get_contents($DATA_FILE), true);
    return is_array($d) ? $d : [];
}
function chat_save($d) {
    global $DATA_FILE;
    @file_put_contents($DATA_FILE, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function clean_session($s) {
    return preg_replace('/[^a-zA-Z0-9-]/', '', (string)$s);
}

$action = $_REQUEST['action'] ?? '';

// Khách gửi tin nhắn
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $session = clean_session($_POST['session'] ?? '');
    $name = mb_substr(trim(strip_tags($_POST['name'] ?? '')), 0, 60);
    $contact = mb_substr(trim(strip_tags($_POST['contact'] ?? '')), 0, 60);
    $msg = mb_substr(trim(strip_tags($_POST['message'] ?? '')), 0, 1000);
    $type = ($_POST['type'] ?? '') === 'complaint' ? 'complaint' : 'normal';
    if (strlen($session) < 8 || $msg === '') { echo json_encode(['ok' => false, 'error' => 'missing']); exit; }
    $all = chat_load();
    $recent = 0;
    foreach ($all as $m) { if ($m['session'] === $session && $m['ts'] > time() - 600) $recent++; }
    if ($recent >= 10) { echo json_encode(['ok' => false, 'error' => 'slow']); exit; }
    $all[] = ['id' => uniqid(), 'session' => $session, 'from' => 'customer', 'name' => $name, 'contact' => $contact, 'message' => $msg, 'ts' => time(), 'answered' => false, 'type' => $type];
    chat_save($all);
    echo json_encode(['ok' => true]);
    exit;
}

// Khách xem lại tin nhắn của mình (kèm câu trả lời của Pip)
if ($action === 'poll') {
    $session = clean_session($_GET['session'] ?? '');
    $all = chat_load();
    $mine = array_values(array_filter($all, function ($m) use ($session) { return $m['session'] === $session; }));
    echo json_encode(['ok' => true, 'messages' => $mine]);
    exit;
}

// Pip xem tin chưa trả lời — khiếu nại lên trước
if ($action === 'admin_list' && ($_GET['token'] ?? '') === $ADMIN_TOKEN) {
    $all = chat_load();
    $un = array_values(array_filter($all, function ($m) { return $m['from'] === 'customer' && empty($m['answered']); }));
    usort($un, function ($a, $b) {
        $pa = ($a['type'] ?? 'normal') === 'complaint' ? 0 : 1;
        $pb = ($b['type'] ?? 'normal') === 'complaint' ? 0 : 1;
        if ($pa !== $pb) return $pa - $pb;
        return ($a['ts'] ?? 0) - ($b['ts'] ?? 0);
    });
    echo json_encode(['ok' => true, 'unanswered' => $un, 'total' => count($all)]);
    exit;
}

// Pip trả lời khách
if ($action === 'admin_reply' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['token'] ?? '') === $ADMIN_TOKEN) {
    $session = clean_session($_POST['session'] ?? '');
    $msg = mb_substr(trim(strip_tags($_POST['message'] ?? '')), 0, 2000);
    if (strlen($session) < 8 || $msg === '') { echo json_encode(['ok' => false]); exit; }
    $all = chat_load();
    $all[] = ['id' => uniqid(), 'session' => $session, 'from' => 'pip', 'name' => 'Pip', 'contact' => '', 'message' => $msg, 'ts' => time(), 'answered' => false];
    foreach ($all as &$m) { if ($m['session'] === $session && $m['from'] === 'customer') $m['answered'] = true; }
    chat_save($all);
    echo json_encode(['ok' => true]);
    exit;
}

// Pip xóa tin nhắn (dọn tin test)
if ($action === 'admin_delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['token'] ?? '') === $ADMIN_TOKEN) {
    $id = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['id'] ?? '');
    $all = chat_load();
    $all = array_values(array_filter($all, function ($m) use ($id) { return $m['id'] !== $id; }));
    chat_save($all);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'bad']);
