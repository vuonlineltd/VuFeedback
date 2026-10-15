<?php
/**
 * VuFeedback endpoint. Stores pins on disk (see storage.php).
 *
 * Runs on its own, without WordPress, so the same folder works on a plain
 * HTML site. On WordPress the keys come from Settings > VuFeedback; on a plain
 * HTML site, set the two keys below.
 *
 * Actions (POST JSON, or GET for list):
 *   list                  -> all pins            (review key)
 *   add     {pin}         -> create pin          (review key)
 *   reply   {id,author,text} -> add a reply      (review key)
 *   status  {id,status}   -> open|progress|resolved  (admin key)
 *   delete  {id}          -> remove pin          (admin key)
 *   upload  multipart: id, author, files[]  -> attach files to a pin (review key)
 *   file    ?f=<fileid>   -> download/view an attachment (review key)
 *   unattach {id,file}    -> remove an attachment  (admin key)
 *
 * Key is sent as header X-Feedback-Key or ?key=
 */

require __DIR__ . '/storage.php';

// Plain HTML sites only: set fresh keys here (openssl rand -hex 8). WordPress ignores these.
const HTML_REVIEW_KEY = '';   // share this one with the client
const HTML_ADMIN_KEY  = '';   // keep this one for yourself

const MAX_TEXT   = 4000;
const FILES_DIR  = VUFB_DATA_DIR . '/files';
const MAX_FILE   = 25 * 1024 * 1024;   // per file; php.ini upload_max_filesize/post_max_size also apply
const ALLOWED_EXT = ['jpg','jpeg','png','gif','webp','svg','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','md','rtf','csv','zip','mp4','mov','ai','eps','psd','indd','sketch','fig'];
const INLINE_MIME = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf'];

$conf = vufb_config();
define('REVIEW_KEY', $conf['review_key'] !== '' ? $conf['review_key'] : HTML_REVIEW_KEY);
define('ADMIN_KEY',  $conf['admin_key']  !== '' ? $conf['admin_key']  : HTML_ADMIN_KEY);

$action = $_GET['action'] ?? '';
if ($action !== 'file') header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$key = $_SERVER['HTTP_X_FEEDBACK_KEY'] ?? ($_GET['key'] ?? '');
// No keys configured, or no key sent: nothing is allowed. (hash_equals('', '') is true.)
if (ADMIN_KEY === '' || REVIEW_KEY === '') { http_response_code(503); echo json_encode(['error' => 'not configured']); exit; }
$isAdmin  = $key !== '' && hash_equals(ADMIN_KEY, $key);
$isReview = $isAdmin || ($key !== '' && hash_equals(REVIEW_KEY, $key));

if (!$isReview) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];

function clean($v, $max = MAX_TEXT) {
    $v = is_string($v) ? trim($v) : '';
    return mb_substr($v, 0, $max);
}
function load() {
    return vufb_pins();
}
function save(callable $mutate) {
    $result = vufb_save_pins($mutate);
    if ($result instanceof RuntimeException) { http_response_code(500); echo json_encode(['error' => 'lock']); exit; }
    return $result;
}
function findIndex(array $pins, $id) {
    foreach ($pins as $i => $p) if ($p['id'] === $id) return $i;
    return -1;
}

switch ($action) {

case 'list':
    echo json_encode(['pins' => load(), 'admin' => $isAdmin]);
    break;

case 'add':
    $p = $body['pin'] ?? [];
    $pin = [
        'id'       => bin2hex(random_bytes(5)),
        'created'  => gmdate('c'),
        'status'   => 'open',
        'page'     => clean($p['page'] ?? '', 300),
        'title'    => clean($p['title'] ?? '', 200),
        'author'   => clean($p['author'] ?? 'Anonymous', 80),
        'type'     => in_array($p['type'] ?? '', ['bug','change','question']) ? $p['type'] : 'change',
        'priority' => in_array($p['priority'] ?? '', ['low','normal','high']) ? $p['priority'] : 'normal',
        'text'     => clean($p['text'] ?? ''),
        'selector' => clean($p['selector'] ?? '', 600),
        'rx'       => (float)($p['rx'] ?? 0.5),
        'ry'       => (float)($p['ry'] ?? 0.5),
        'docX'     => (int)($p['docX'] ?? 0),
        'docY'     => (int)($p['docY'] ?? 0),
        'snippet'  => clean($p['snippet'] ?? '', 160),
        'viewport' => clean($p['viewport'] ?? '', 40),
        'ua'       => clean($p['ua'] ?? '', 300),
        'replies'  => [],
        'attachments' => [],
    ];
    if ($pin['text'] === '') { http_response_code(422); echo json_encode(['error' => 'empty']); exit; }
    save(function (&$pins) use ($pin) { $pins[] = $pin; });
    echo json_encode(['pin' => $pin]);
    break;

case 'reply':
    $id = clean($body['id'] ?? '', 20);
    $reply = ['author' => clean($body['author'] ?? 'Anonymous', 80), 'text' => clean($body['text'] ?? ''), 'created' => gmdate('c')];
    if ($reply['text'] === '') { http_response_code(422); echo json_encode(['error' => 'empty']); exit; }
    $pin = save(function (&$pins) use ($id, $reply) {
        $i = findIndex($pins, $id); if ($i < 0) return null;
        $pins[$i]['replies'][] = $reply; return $pins[$i];
    });
    if (!$pin) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
    echo json_encode(['pin' => $pin]);
    break;

case 'status':
    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }
    $id = clean($body['id'] ?? '', 20);
    $status = in_array($body['status'] ?? '', ['open','progress','resolved']) ? $body['status'] : 'open';
    $pin = save(function (&$pins) use ($id, $status) {
        $i = findIndex($pins, $id); if ($i < 0) return null;
        $pins[$i]['status'] = $status; return $pins[$i];
    });
    if (!$pin) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
    echo json_encode(['pin' => $pin]);
    break;

case 'delete':
    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }
    $id = clean($body['id'] ?? '', 20);
    save(function (&$pins) use ($id) {
        foreach ($pins as $p) if ($p['id'] === $id) foreach ($p['attachments'] ?? [] as $a) @unlink(FILES_DIR . '/' . $a['id']);
        $pins = array_values(array_filter($pins, fn($p) => $p['id'] !== $id));
    });
    echo json_encode(['ok' => true]);
    break;

case 'upload':
    $id = clean($_POST['id'] ?? '', 20);
    $author = clean($_POST['author'] ?? 'Anonymous', 80);
    if (empty($_FILES['files'])) { http_response_code(422); echo json_encode(['error' => 'no files (check post_max_size)']); exit; }
    if (!is_dir(FILES_DIR)) { mkdir(FILES_DIR, 0755, true); file_put_contents(FILES_DIR . '/index.php', "<?php\n// Silence is golden.\n"); }
    $new = []; $rejected = [];
    $f = $_FILES['files'];
    $names = is_array($f['name']) ? $f['name'] : [$f['name']];
    foreach ($names as $i => $name) {
        $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
        $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
        $size = is_array($f['size']) ? $f['size'][$i] : $f['size'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($err !== UPLOAD_ERR_OK) { $rejected[] = $name . ' (upload error ' . $err . ')'; continue; }
        if (!in_array($ext, ALLOWED_EXT)) { $rejected[] = $name . ' (type not allowed)'; continue; }
        if ($size > MAX_FILE) { $rejected[] = $name . ' (over ' . (MAX_FILE / 1048576) . ' MB)'; continue; }
        $fid = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($tmp, FILES_DIR . '/' . $fid)) { $rejected[] = $name . ' (could not save)'; continue; }
        $new[] = ['id' => $fid, 'name' => mb_substr(basename($name), 0, 150), 'size' => (int)$size, 'author' => $author, 'created' => gmdate('c')];
    }
    $pin = save(function (&$pins) use ($id, $new) {
        $i = findIndex($pins, $id); if ($i < 0) return null;
        $pins[$i]['attachments'] = array_merge($pins[$i]['attachments'] ?? [], $new); return $pins[$i];
    });
    if (!$pin) { foreach ($new as $a) @unlink(FILES_DIR . '/' . $a['id']); http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
    echo json_encode(['pin' => $pin, 'rejected' => $rejected]);
    break;

case 'file':
    $fid = preg_replace('/[^a-z0-9.]/', '', $_GET['f'] ?? '');
    $path = FILES_DIR . '/' . $fid;
    $meta = null;
    foreach (load() as $p) foreach ($p['attachments'] ?? [] as $a) if ($a['id'] === $fid) $meta = $a;
    if (!$meta || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain'); echo 'Not found'; exit; }
    $ext = strtolower(pathinfo($fid, PATHINFO_EXTENSION));
    $inline = isset(INLINE_MIME[$ext]) && empty($_GET['dl']);
    header('Content-Type: ' . ($inline ? INLINE_MIME[$ext] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $meta['name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;

case 'unattach':
    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }
    $id = clean($body['id'] ?? '', 20); $fid = preg_replace('/[^a-z0-9.]/', '', $body['file'] ?? '');
    $pin = save(function (&$pins) use ($id, $fid) {
        $i = findIndex($pins, $id); if ($i < 0) return null;
        $pins[$i]['attachments'] = array_values(array_filter($pins[$i]['attachments'] ?? [], fn($a) => $a['id'] !== $fid));
        @unlink(FILES_DIR . '/' . $fid); return $pins[$i];
    });
    if (!$pin) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
    echo json_encode(['pin' => $pin]);
    break;

default:
    http_response_code(400); echo json_encode(['error' => 'unknown action']);
}
