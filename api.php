<?php
/**
 * VuFeedback API: one handler for every route in.
 *
 *   Plain HTML site:  feedback.php?action=<op>
 *   WordPress:        wp-admin/admin-ajax.php?action=vufeedback&op=<op>
 *                     (feedback.php also works on WordPress: it loads WordPress
 *                     first, so both routes read the same storage)
 *
 * Ops (POST JSON, or GET for list / file):
 *   list                     -> all pins              (client key)
 *   add     {pin}            -> create pin            (client key)
 *   reply   {id,author,text} -> add a reply           (client key)
 *   status  {id,status}      -> open|progress|resolved (admin key)
 *   delete  {id}             -> remove pin            (admin key)
 *   upload  multipart: id, author, files[]            (client key)
 *   file    ?f=<fileid>      -> view or download an attachment (client key)
 *   unattach {id,file}       -> remove an attachment  (admin key)
 *
 * The key is sent as header X-Feedback-Key or ?key=.
 */

require_once __DIR__ . '/storage.php';

const VUFB_MAX_TEXT   = 4000;

const VUFB_MAX_FILE   = 25 * 1024 * 1024;   // per file; php.ini upload_max_filesize/post_max_size also apply
const VUFB_ALLOWED_EXT = ['jpg','jpeg','png','gif','webp','svg','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','md','rtf','csv','zip','mp4','mov','ai','eps','psd','indd','sketch','fig'];
const VUFB_INLINE_MIME = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf'];

/**
 * Attachments folder.
 *
 * @return string
 */
function vufb_files_dir() {
	return VUFB_DATA_DIR . '/files';
}

function vufb_clean($v, $max = VUFB_MAX_TEXT) {
    $v = is_string($v) ? trim($v) : '';
    return mb_substr($v, 0, $max);
}
function vufb_load() {
    return vufb_pins();
}
function vufb_save(callable $mutate) {
    $result = vufb_save_pins($mutate);
    if ($result instanceof RuntimeException) { http_response_code(500); echo json_encode(['error' => $result->getMessage()]); exit; }
    return $result;
}
function vufb_find_index(array $pins, $id) {
    foreach ($pins as $i => $p) if ($p['id'] === $id) return $i;
    return -1;
}

/**
 * Handle one API request and exit.
 *
 * @param string $action      The op.
 * @param string $html_review Plain-HTML fallback client key.
 * @param string $html_admin  Plain-HTML fallback admin key.
 * @return void
 */
function vufb_run_api( $action, $html_review = '', $html_admin = '' ) {
	$conf      = vufb_config();
	$review    = '' !== $conf['review_key'] ? $conf['review_key'] : $html_review;
	$admin_key = '' !== $conf['admin_key'] ? $conf['admin_key'] : $html_admin;

	if ($action !== 'file') header('Content-Type: application/json; charset=utf-8');
	// Never cached. WP Engine overrides this for plugin files, which is why the
	// widget also adds a unique value to every read and WordPress uses admin-ajax.
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	header('X-Robots-Tag: noindex');

	$key = $_SERVER['HTTP_X_FEEDBACK_KEY'] ?? ($_GET['key'] ?? '');
	$key = is_string($key) ? $key : '';
	// No keys configured, or no key sent: nothing is allowed. (hash_equals('', '') is true.)
	if ($admin_key === '' || $review === '') { http_response_code(503); echo json_encode(['error' => 'not configured']); exit; }
	$isAdmin  = $key !== '' && hash_equals($admin_key, $key);
	$isReview = $isAdmin || ($key !== '' && hash_equals($review, $key));

	if (!$isReview) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

	$body = json_decode(file_get_contents('php://input'), true) ?: [];
	// WordPress adds slashes to request data; undo that when it is loaded.
	$get  = function_exists('wp_unslash') ? wp_unslash($_GET) : $_GET;
	$post = function_exists('wp_unslash') ? wp_unslash($_POST) : $_POST;
	define('VUFB_FILES_DIR', vufb_files_dir());

	switch ($action) {

	case 'list':
	    echo json_encode(['pins' => vufb_load(), 'admin' => $isAdmin]);
	    break;

	case 'add':
	    $p = $body['pin'] ?? [];
	    $pin = [
	        'id'       => bin2hex(random_bytes(5)),
	        'created'  => gmdate('c'),
	        'status'   => 'open',
	        'page'     => vufb_clean($p['page'] ?? '', 300),
	        'title'    => vufb_clean($p['title'] ?? '', 200),
	        'author'   => vufb_clean($p['author'] ?? 'Anonymous', 80),
	        'type'     => in_array($p['type'] ?? '', ['bug','change','question']) ? $p['type'] : 'change',
	        'priority' => in_array($p['priority'] ?? '', ['low','normal','high']) ? $p['priority'] : 'normal',
	        'text'     => vufb_clean($p['text'] ?? ''),
	        'selector' => vufb_clean($p['selector'] ?? '', 600),
	        'rx'       => (float)($p['rx'] ?? 0.5),
	        'ry'       => (float)($p['ry'] ?? 0.5),
	        'docX'     => (int)($p['docX'] ?? 0),
	        'docY'     => (int)($p['docY'] ?? 0),
	        'snippet'  => vufb_clean($p['snippet'] ?? '', 160),
	        'viewport' => vufb_clean($p['viewport'] ?? '', 40),
	        'ua'       => vufb_clean($p['ua'] ?? '', 300),
	        'replies'  => [],
	        'attachments' => [],
	    ];
	    if ($pin['text'] === '') { http_response_code(422); echo json_encode(['error' => 'empty']); exit; }
	    vufb_save(function (&$pins) use ($pin) { $pins[] = $pin; });
	    echo json_encode(['pin' => $pin]);
	    break;

	case 'reply':
	    $id = vufb_clean($body['id'] ?? '', 20);
	    $reply = ['author' => vufb_clean($body['author'] ?? 'Anonymous', 80), 'text' => vufb_clean($body['text'] ?? ''), 'created' => gmdate('c')];
	    if ($reply['text'] === '') { http_response_code(422); echo json_encode(['error' => 'empty']); exit; }
	    $pin = vufb_save(function (&$pins) use ($id, $reply) {
	        $i = vufb_find_index($pins, $id); if ($i < 0) return null;
	        $pins[$i]['replies'][] = $reply; return $pins[$i];
	    });
	    if (!$pin) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
	    echo json_encode(['pin' => $pin]);
	    break;

	case 'status':
	    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }
	    $id = vufb_clean($body['id'] ?? '', 20);
	    $status = in_array($body['status'] ?? '', ['open','progress','resolved']) ? $body['status'] : 'open';
	    $pin = vufb_save(function (&$pins) use ($id, $status) {
	        $i = vufb_find_index($pins, $id); if ($i < 0) return null;
	        $pins[$i]['status'] = $status; return $pins[$i];
	    });
	    if (!$pin) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
	    echo json_encode(['pin' => $pin]);
	    break;

	case 'delete':
	    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }
	    $id = vufb_clean($body['id'] ?? '', 20);
	    vufb_save(function (&$pins) use ($id) {
	        foreach ($pins as $p) if ($p['id'] === $id) foreach ($p['attachments'] ?? [] as $a) @unlink(VUFB_FILES_DIR . '/' . $a['id']);
	        $pins = array_values(array_filter($pins, fn($p) => $p['id'] !== $id));
	    });
	    echo json_encode(['ok' => true]);
	    break;

	case 'upload':
	    $id = vufb_clean($post['id'] ?? '', 20);
	    $author = vufb_clean($post['author'] ?? 'Anonymous', 80);
	    if (empty($_FILES['files'])) { http_response_code(422); echo json_encode(['error' => 'no files (check post_max_size)']); exit; }
	    if (!is_dir(VUFB_FILES_DIR) && !@mkdir(VUFB_FILES_DIR, 0755, true)) { http_response_code(500); echo json_encode(['error' => 'attachments folder not writable']); exit; }
	    if (!is_file(VUFB_FILES_DIR . '/index.php')) { @file_put_contents(VUFB_FILES_DIR . '/index.php', "<?php\n// Silence is golden.\n"); }
	    $new = []; $rejected = [];
	    $f = $_FILES['files'];
	    $names = is_array($f['name']) ? $f['name'] : [$f['name']];
	    foreach ($names as $i => $name) {
	        $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
	        $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
	        $size = is_array($f['size']) ? $f['size'][$i] : $f['size'];
	        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	        if ($err !== UPLOAD_ERR_OK) { $rejected[] = $name . ' (upload error ' . $err . ')'; continue; }
	        if (!in_array($ext, VUFB_ALLOWED_EXT)) { $rejected[] = $name . ' (type not allowed)'; continue; }
	        if ($size > VUFB_MAX_FILE) { $rejected[] = $name . ' (over ' . (VUFB_MAX_FILE / 1048576) . ' MB)'; continue; }
	        $fid = bin2hex(random_bytes(8)) . '.' . $ext;
	        if (!move_uploaded_file($tmp, VUFB_FILES_DIR . '/' . $fid)) { $rejected[] = $name . ' (could not save)'; continue; }
	        $new[] = ['id' => $fid, 'name' => mb_substr(basename($name), 0, 150), 'size' => (int)$size, 'author' => $author, 'created' => gmdate('c')];
	    }
	    $pin = vufb_save(function (&$pins) use ($id, $new) {
	        $i = vufb_find_index($pins, $id); if ($i < 0) return null;
	        $pins[$i]['attachments'] = array_merge($pins[$i]['attachments'] ?? [], $new); return $pins[$i];
	    });
	    if (!$pin) { foreach ($new as $a) @unlink(VUFB_FILES_DIR . '/' . $a['id']); http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
	    echo json_encode(['pin' => $pin, 'rejected' => $rejected]);
	    break;

	case 'file':
	    $fid = preg_replace('/[^a-z0-9.]/', '', $get['f'] ?? '');
	    $path = VUFB_FILES_DIR . '/' . $fid;
	    $meta = null;
	    foreach (vufb_load() as $p) foreach ($p['attachments'] ?? [] as $a) if ($a['id'] === $fid) $meta = $a;
	    if (!$meta || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain'); echo 'Not found'; exit; }
	    $ext = strtolower(pathinfo($fid, PATHINFO_EXTENSION));
	    $inline = isset(VUFB_INLINE_MIME[$ext]) && empty($get['dl']);
	    header('Content-Type: ' . ($inline ? VUFB_INLINE_MIME[$ext] : 'application/octet-stream'));
	    header('Content-Length: ' . filesize($path));
	    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $meta['name']) . '"');
	    header('X-Content-Type-Options: nosniff');
	    readfile($path);
	    exit;

	case 'unattach':
	    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }
	    $id = vufb_clean($body['id'] ?? '', 20); $fid = preg_replace('/[^a-z0-9.]/', '', $body['file'] ?? '');
	    $pin = vufb_save(function (&$pins) use ($id, $fid) {
	        $i = vufb_find_index($pins, $id); if ($i < 0) return null;
	        $pins[$i]['attachments'] = array_values(array_filter($pins[$i]['attachments'] ?? [], fn($a) => $a['id'] !== $fid));
	        @unlink(VUFB_FILES_DIR . '/' . $fid); return $pins[$i];
	    });
	    if (!$pin) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
	    echo json_encode(['pin' => $pin]);
	    break;

	default:
	    http_response_code(400); echo json_encode(['error' => 'unknown action']);
	}
	exit;
}
