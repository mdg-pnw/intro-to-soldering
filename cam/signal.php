<?php
/**
 * signal.php - WebRTC signaling via flat JSON files
 *
 * Every time the host publishes a new offer, the session_id increments.
 * Viewers pass their known session_id with get_offer so the server can
 * tell them when a new session has started, even if they already have an offer.
 *
 * Actions:
 *   publish_offer   - host writes SDP offer (bumps session id, clears answer+ICE)
 *   get_offer       - viewer reads offer; pass known_session to detect host restarts
 *   publish_answer  - viewer writes SDP answer
 *   get_answer      - host reads SDP answer; pass known_session to detect stale answers
 *   add_ice         - either side appends an ICE candidate
 *   get_ice         - poll for new ICE candidates (pass since=N, from=host|viewer)
 *   reset           - clears all state
 *   status          - debugging info
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$dir = __DIR__ . '/signal_data';
if (!is_dir($dir)) mkdir($dir, 0700, true);

$offer_file   = "$dir/offer.json";
$answer_file  = "$dir/answer.json";
$ice_file     = "$dir/ice.json";
$session_file = "$dir/session.json";

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
if (!$action && isset($body['action'])) $action = $body['action'];

function send($data) { echo json_encode($data); exit; }

function read_json($file) {
    if (!file_exists($file)) return null;
    $raw = file_get_contents($file);
    return $raw ? json_decode($raw, true) : null;
}

function write_json($file, $data) {
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function get_session() {
    global $session_file;
    $s = read_json($session_file);
    return $s ? intval($s['id']) : 0;
}

function bump_session() {
    global $session_file;
    $id = get_session() + 1;
    write_json($session_file, ['id' => $id, 'ts' => time()]);
    return $id;
}

switch ($action) {

    case 'publish_offer':
        if (empty($body['sdp'])) send(['ok' => false, 'error' => 'Missing sdp']);
        $session_id = bump_session();
        @unlink($answer_file);
        write_json($ice_file, []);
        write_json($offer_file, [
            'sdp'        => $body['sdp'],
            'type'       => $body['type'] ?? 'offer',
            'session_id' => $session_id,
            'ts'         => time(),
        ]);
        send(['ok' => true, 'session_id' => $session_id]);

    case 'get_offer':
        $offer = read_json($offer_file);
        if (!$offer) send(['ok' => false, 'error' => 'No offer yet', 'session_id' => get_session()]);
        // known_session lets viewer detect a host restart mid-session
        $known = intval($body['known_session'] ?? $_GET['known_session'] ?? -1);
        $new_session = ($known >= 0 && $offer['session_id'] !== $known);
        send(['ok' => true, 'offer' => $offer, 'session_id' => $offer['session_id'], 'new_session' => $new_session]);

    case 'publish_answer':
        if (empty($body['sdp'])) send(['ok' => false, 'error' => 'Missing sdp']);
        $session_id = intval($body['session_id'] ?? get_session());
        write_json($answer_file, [
            'sdp'        => $body['sdp'],
            'type'       => $body['type'] ?? 'answer',
            'session_id' => $session_id,
            'ts'         => time(),
        ]);
        send(['ok' => true]);

    case 'get_answer':
        $answer = read_json($answer_file);
        if (!$answer) send(['ok' => false, 'error' => 'No answer yet']);
        // Let host detect a stale answer from a previous session
        $known = intval($body['known_session'] ?? $_GET['known_session'] ?? get_session());
        if ($answer['session_id'] !== $known) send(['ok' => false, 'error' => 'Stale answer']);
        send(['ok' => true, 'answer' => $answer]);

    case 'add_ice':
        if (empty($body['candidate'])) send(['ok' => false, 'error' => 'Missing candidate']);
        $ice = read_json($ice_file) ?? [];
        $ice[] = [
            'candidate'     => $body['candidate'],
            'sdpMid'        => $body['sdpMid'] ?? null,
            'sdpMLineIndex' => $body['sdpMLineIndex'] ?? null,
            'from'          => $body['from'] ?? 'unknown',
            'session_id'    => intval($body['session_id'] ?? get_session()),
            'id'            => count($ice),
        ];
        write_json($ice_file, $ice);
        send(['ok' => true]);

    case 'get_ice':
        $since      = intval($_GET['since'] ?? $body['since'] ?? 0);
        $from       = $_GET['from'] ?? $body['from'] ?? '';
        $session_id = intval($_GET['session_id'] ?? $body['session_id'] ?? get_session());
        $ice        = read_json($ice_file) ?? [];
        $filtered   = array_values(array_filter($ice, function($c) use ($since, $from, $session_id) {
            return $c['id'] >= $since
                && ($from === '' || $c['from'] !== $from)
                && ($c['session_id'] === $session_id);
        }));
        send(['ok' => true, 'candidates' => $filtered, 'total' => count($ice), 'session_id' => $session_id]);

    case 'reset':
        @unlink($offer_file);
        @unlink($answer_file);
        write_json($ice_file, []);
        // Don't reset session_id — let it keep incrementing so restarts are always detectable
        send(['ok' => true, 'session_id' => get_session()]);

    case 'status':
        $offer  = read_json($offer_file);
        $answer = read_json($answer_file);
        send([
            'ok'         => true,
            'session_id' => get_session(),
            'has_offer'  => (bool)$offer,
            'has_answer' => (bool)$answer,
            'offer_ts'   => $offer['ts'] ?? null,
            'answer_ts'  => $answer['ts'] ?? null,
            'ice_count'  => count(read_json($ice_file) ?? []),
        ]);

    default:
        send(['ok' => false, 'error' => 'Unknown action: ' . $action]);
}
