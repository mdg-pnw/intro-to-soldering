<?php
/**
 * signal.php - WebRTC signaling via flat JSON files
 * 
 * Actions (POST or GET ?action=):
 *   publish_offer   - host writes SDP offer
 *   get_offer       - viewer reads SDP offer
 *   publish_answer  - viewer writes SDP answer
 *   get_answer      - host reads SDP answer
 *   add_ice         - either side appends an ICE candidate
 *   get_ice         - either side polls for new ICE candidates (pass ?since=N)
 *   reset           - clears all session state
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Store everything in a subdirectory to keep it tidy
$dir = __DIR__ . '/signal_data';
if (!is_dir($dir)) mkdir($dir, 0700, true);

$offer_file  = "$dir/offer.json";
$answer_file = "$dir/answer.json";
$ice_file    = "$dir/ice.json";

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// Also accept action in body
if (!$action && isset($body['action'])) $action = $body['action'];

function send($data) {
    echo json_encode($data);
    exit;
}

function read_json($file) {
    if (!file_exists($file)) return null;
    $raw = file_get_contents($file);
    return $raw ? json_decode($raw, true) : null;
}

function write_json($file, $data) {
    file_put_contents($file, json_encode($data), LOCK_EX);
}

switch ($action) {

    case 'publish_offer':
        if (empty($body['sdp'])) send(['ok' => false, 'error' => 'Missing sdp']);
        // Reset old session when host starts fresh
        @unlink($answer_file);
        write_json($ice_file, []);
        write_json($offer_file, [
            'sdp'  => $body['sdp'],
            'type' => $body['type'] ?? 'offer',
            'ts'   => time(),
        ]);
        send(['ok' => true]);

    case 'get_offer':
        $offer = read_json($offer_file);
        if (!$offer) send(['ok' => false, 'error' => 'No offer yet']);
        send(['ok' => true, 'offer' => $offer]);

    case 'publish_answer':
        if (empty($body['sdp'])) send(['ok' => false, 'error' => 'Missing sdp']);
        write_json($answer_file, [
            'sdp'  => $body['sdp'],
            'type' => $body['type'] ?? 'answer',
            'ts'   => time(),
        ]);
        send(['ok' => true]);

    case 'get_answer':
        $answer = read_json($answer_file);
        if (!$answer) send(['ok' => false, 'error' => 'No answer yet']);
        send(['ok' => true, 'answer' => $answer]);

    case 'add_ice':
        if (empty($body['candidate'])) send(['ok' => false, 'error' => 'Missing candidate']);
        $ice = read_json($ice_file) ?? [];
        $ice[] = [
            'candidate'     => $body['candidate'],
            'sdpMid'        => $body['sdpMid'] ?? null,
            'sdpMLineIndex' => $body['sdpMLineIndex'] ?? null,
            'from'          => $body['from'] ?? 'unknown', // 'host' or 'viewer'
            'id'            => count($ice),
        ];
        write_json($ice_file, $ice);
        send(['ok' => true]);

    case 'get_ice':
        $since = intval($_GET['since'] ?? $body['since'] ?? 0);
        $from  = $_GET['from']  ?? $body['from']  ?? '';   // filter by sender
        $ice   = read_json($ice_file) ?? [];
        // Return candidates after index $since, excluding ones from $from (don't echo back)
        $filtered = array_values(array_filter($ice, function($c) use ($since, $from) {
            return $c['id'] >= $since && ($from === '' || $c['from'] !== $from);
        }));
        send(['ok' => true, 'candidates' => $filtered, 'total' => count($ice)]);

    case 'reset':
        @unlink($offer_file);
        @unlink($answer_file);
        write_json($ice_file, []);
        send(['ok' => true]);

    case 'status':
        send([
            'ok'         => true,
            'has_offer'  => file_exists($offer_file),
            'has_answer' => file_exists($answer_file),
            'ice_count'  => count(read_json($ice_file) ?? []),
        ]);

    default:
        send(['ok' => false, 'error' => 'Unknown action: ' . $action]);
}
