<?php
/* Client proofing receiver
   Takes the client's selected filenames from index.html, saves them as plain
   name lists, and optionally pushes a notification.

   Configure the three constants below, then drop this file next to index.html. */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$BASE = __DIR__;
$LOGDIR = $BASE . '/_submissions';

/* ---------------- configure ----------------
   RECIPIENT    : email the picks go to ('' = skip email entirely)
   FROM_ADDRESS : From: header for that email
   NOTIFY_URL   : optional webhook POSTed on every submission
                  (ntfy.sh/<topic>, Slack, Discord, Zapier, ...). Kept in this
                  file on purpose: PHP is executed, never served, so it is not
                  publicly readable.
   ------------------------------------------- */
const RECIPIENT    = '';
const FROM_ADDRESS = 'proofing@example.com';
const NOTIFY_URL   = '';

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'POST only');
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) === 0) {
    fail(400, 'Empty body');
}
if (strlen($raw) > 200000) {
    fail(413, 'Payload too large');
}

$in = json_decode($raw, true);
if (!is_array($in)) {
    /* fall back to form-encoded */
    $in = $_POST;
}
if (!isset($in['photos']) || !is_array($in['photos'])) {
    fail(400, 'No photos supplied');
}

$client  = trim((string)($in['client']  ?? 'unknown'));
$session = trim((string)($in['session'] ?? ''));
$photos  = [];
foreach ($in['photos'] as $p) {
    $p = trim((string)$p);
    if ($p !== '' && strlen($p) < 200) { $photos[] = $p; }
}
$photos = array_values(array_unique($photos));

if (count($photos) === 0) {
    fail(400, 'No valid filenames supplied');
}
if (count($photos) > 5000) {
    fail(413, 'Too many filenames');
}

if (!is_dir($LOGDIR)) {
    @mkdir($LOGDIR, 0775, true);
}
if (!is_dir($LOGDIR) || !is_writable($LOGDIR)) {
    fail(500, 'Storage directory not writable');
}

$stamp   = date('Y-m-d_His');
$safeCl  = preg_replace('/[^A-Za-z0-9._-]+/', '-', $client) ?: 'client';
$safeSe  = preg_replace('/[^A-Za-z0-9._-]+/', '-', $session) ?: 'session';
$basename = $safeCl . '__' . $safeSe . '__' . $stamp . '__' . count($photos) . 'picks';

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);

$record = [
    'client'   => $client,
    'session'  => $session,
    'count'    => count($photos),
    'photos'   => $photos,
    'received' => date('c'),
    'ip'       => $ip,
    'ua'       => $ua,
];

/* 1. machine-readable: one JSON object per line, append-only */
$jsonl = $LOGDIR . '/submissions.jsonl';
@file_put_contents($jsonl, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);

/* 2. human-readable: a plain text file you can open instantly */
$txt  = "CLIENT FAVORITES — PROOFING LIST\n";
$txt .= str_repeat('=', 46) . "\n\n";
$txt .= "Client : $client\n";
$txt .= "Session: $session\n";
$txt .= "Picked : " . count($photos) . " photos\n";
$txt .= "When   : " . date('D, M j, Y g:i A T') . "\n";
$txt .= "From   : $ip\n\n";
$txt .= str_repeat('-', 46) . "\n";
foreach ($photos as $i => $f) {
    $txt .= sprintf("%3d. %s\n", $i + 1, $f);
}
$txt .= str_repeat('-', 46) . "\n";
@file_put_contents($LOGDIR . '/' . $basename . '.txt', $txt, LOCK_EX);

/* 2b. bare filename list — drops straight into Lightroom Statistics Photo List Importer */
@file_put_contents($LOGDIR . '/' . $basename . '.names.txt', implode("\n", $photos) . "\n", LOCK_EX);
$csv = "Filename\n";
foreach ($photos as $f) { $csv .= '"' . str_replace('"', '""', $f) . "\"\n"; }
@file_put_contents($LOGDIR . '/' . $basename . '.names.csv', $csv, LOCK_EX);

/* 3. best-effort email — works only if the host can send mail */
$subject = "[Proofing] $client — " . count($photos) . " favorites";
if (RECIPIENT !== '') {
    @mail(RECIPIENT, $subject, $txt,
          "From: " . FROM_ADDRESS . "\r\nContent-Type: text/plain; charset=utf-8\r\n");
}

/* 4. optional push hook — see the config block at the top of this file */
if (NOTIFY_URL !== '' && preg_match('#^https://#i', NOTIFY_URL)) {
    $body = $subject . "\n" . implode(", ", array_slice($photos, 0, 30));
    $ch = curl_init(NOTIFY_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Title: ' . $subject, 'Tags: camera'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

echo json_encode([
    'ok'      => true,
    'count'   => count($photos),
    'saved'   => $basename,
    'message' => 'Favorites received',
]);