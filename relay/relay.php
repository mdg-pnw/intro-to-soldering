<?php
/**
 * relay.php — JPEG frame relay for camera streaming
 *
 * POST ?action=push   — host uploads a JPEG frame (binary body)
 * GET  ?action=pull   — viewer fetches the latest frame as JPEG
 * GET  ?action=status — returns JSON metadata about the current stream
 * POST ?action=stop   — host signals end of stream
 *
 * Frames are written to a temp file and swapped atomically so the viewer
 * never reads a half-written frame.
 */

$dir = __DIR__ . '/relay_data';
if (!is_dir($dir)) mkdir($dir, 0700, true);

$frame_file  = "$dir/frame.jpg";
$meta_file   = "$dir/meta.json";

$action = $_GET['action'] ?? 'pull';

// ── CORS ──────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Frame-W, X-Frame-H');
header('Access-Control-Expose-Headers: X-Frame-Seq, X-Frame-Ts, X-Frame-W, X-Frame-H');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Helpers ───────────────────────────────────────────────────────────────
function read_meta($file) {
    if (!file_exists($file)) return ['seq' => 0, 'ts' => 0, 'live' => false, 'w' => 0, 'h' => 0];
    return json_decode(file_get_contents($file), true) ?? ['seq' => 0, 'ts' => 0, 'live' => false];
}
function write_meta($file, $data) {
    file_put_contents($file, json_encode($data), LOCK_EX);
}

// ── Actions ───────────────────────────────────────────────────────────────
switch ($action) {

    case 'push':
        // Read raw JPEG from request body (cap at 1 MB)
        $data = file_get_contents('php://input', false, null, 0, 1048576);
        if (!$data || strlen($data) < 3) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Empty frame']);
            exit;
        }
        // Verify JPEG magic bytes
        if (ord($data[0]) !== 0xFF || ord($data[1]) !== 0xD8) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Not a JPEG']);
            exit;
        }
        // Atomic write: write to temp then rename
        $tmp = $frame_file . '.tmp';
        file_put_contents($tmp, $data, LOCK_EX);
        rename($tmp, $frame_file);

        // Update metadata
        $meta = read_meta($meta_file);
        $meta['seq']  = ($meta['seq'] ?? 0) + 1;
        $meta['ts']   = microtime(true);
        $meta['live'] = true;
        $meta['size'] = strlen($data);
        // Parse optional width/height headers from host
        if (isset($_SERVER['HTTP_X_FRAME_W'])) $meta['w'] = intval($_SERVER['HTTP_X_FRAME_W']);
        if (isset($_SERVER['HTTP_X_FRAME_H'])) $meta['h'] = intval($_SERVER['HTTP_X_FRAME_H']);
        write_meta($meta_file, $meta);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'seq' => $meta['seq']]);
        break;

    case 'pull':
        $meta = read_meta($meta_file);

        // If caller passes ?since=N, hold the response until a newer frame
        // exists or 8 seconds pass (poor-man's long-poll)
        $since    = intval($_GET['since'] ?? 0);
        $waited   = 0;
        $max_wait = 8; // seconds
        $interval = 50000; // 50ms between checks

        while ($meta['seq'] <= $since && $waited < $max_wait * 1000000) {
            usleep($interval);
            $waited += $interval;
            clearstatcache(true, $meta_file);
            $meta = read_meta($meta_file);
            // If stream went offline, bail early
            if (!$meta['live']) break;
        }

        if (!file_exists($frame_file) || !$meta['live']) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'No stream', 'live' => false]);
            exit;
        }

        $frame = file_get_contents($frame_file);
        if (!$frame) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Frame read failed']);
            exit;
        }

        header('Content-Type: image/jpeg');
        header('X-Frame-Seq: ' . $meta['seq']);
        header('X-Frame-Ts: '  . $meta['ts']);
        header('X-Frame-W: '   . ($meta['w'] ?? 0));
        header('X-Frame-H: '   . ($meta['h'] ?? 0));
        header('Cache-Control: no-store');
        echo $frame;
        break;

    case 'status':
        header('Content-Type: application/json');
        $meta = read_meta($meta_file);
        // Mark stream as dead if no frame in 5 seconds
        if ($meta['live'] && (microtime(true) - ($meta['ts'] ?? 0)) > 5) {
            $meta['live'] = false;
            $meta['w']    = 0;
            $meta['h']    = 0;
            $meta['size'] = 0;
            write_meta($meta_file, $meta);
            @unlink($frame_file);
        }
        echo json_encode(['ok' => true] + $meta);
        break;

    case 'stop':
        $meta = read_meta($meta_file);
        $meta['live'] = false;
        $meta['w']    = 0;
        $meta['h']    = 0;
        $meta['size'] = 0;
        write_meta($meta_file, $meta);
        @unlink($frame_file);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
}
