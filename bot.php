<?php
// eBay-Suchbot: sucht die Begriffe aus data/einstellungen.json, schickt neue Angebote an Discord.
// Cron: * * * * * php /mnt/web/ebay/bot.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

const DATEN = __DIR__ . '/data';
$cfg = require DATEN . '/config.php'; // Schlüssel + Webhook
$cfg += json_decode((string) @file_get_contents(DATEN . '/einstellungen.json'), true) ?: ['suchbegriffe' => []]; // Begriff => Märkte, gepflegt über index.php

// Überlappende Läufe (Discord-Wartezeiten) überspringen
$sperre = fopen(DATEN . '/lauf.lock', 'c');
if (!flock($sperre, LOCK_EX | LOCK_NB)) exit;

function http(string $methode, string $url, array $kopf = [], ?string $body = null): array
{
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_HTTPHEADER => $kopf,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $body); // leerer Body bei GET → eBay antwortet 415
    $antwort = (string) curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    return [$code, json_decode($antwort, true)];
}

function json_lesen(string $datei): array
{
    return is_readable($datei) ? (json_decode((string) file_get_contents($datei), true) ?: []) : [];
}

function json_schreiben(string $datei, array $d): void
{
    file_put_contents("$datei.tmp", json_encode($d));
    rename("$datei.tmp", $datei);
}

// OAuth-Token (Client Credentials), gilt 2 h, wird zwischengespeichert
function token(array $cfg): string
{
    $t = json_lesen(DATEN . '/token.json');
    if (($t['bis'] ?? 0) > time() + 60) return $t['token'];
    [$code, $d] = http('POST', 'https://api.ebay.com/identity/v1/oauth2/token', [
        'Authorization: Basic ' . base64_encode("{$cfg['ebay_client_id']}:{$cfg['ebay_client_secret']}"),
        'Content-Type: application/x-www-form-urlencoded',
    ], 'grant_type=client_credentials&scope=' . urlencode('https://api.ebay.com/oauth/api_scope'));
    if ($code !== 200) { fwrite(STDERR, "eBay-Token: HTTP $code\n"); exit(1); }
    json_schreiben(DATEN . '/token.json', ['token' => $d['access_token'], 'bis' => time() + $d['expires_in']]);
    return $d['access_token'];
}

function suchen(array $cfg, string $markt, string $begriff): ?array
{
    [$code, $d] = http('GET', 'https://api.ebay.com/buy/browse/v1/item_summary/search?' . http_build_query([
        'q' => $begriff, 'sort' => 'newlyListed', 'limit' => 50,
    ]), ['Authorization: Bearer ' . token($cfg), "X-EBAY-C-MARKETPLACE-ID: $markt"]);
    if ($code !== 200) { fwrite(STDERR, "Suche '$begriff' auf $markt: HTTP $code\n"); return null; }
    return $d['itemSummaries'] ?? [];
}

function posten(string $webhook, array $embeds): void
{
    $nachricht = json_encode(['username' => 'eBay', 'embeds' => $embeds]);
    for ($versuch = 0; $versuch < 3; $versuch++) {
        [$code, $d] = http('POST', $webhook, ['Content-Type: application/json'], $nachricht);
        if ($code !== 429) return;
        sleep((int) ceil($d['retry_after'] ?? 2)); // Discord-Ratelimit
    }
}

// "[CA][F][$299.99] Titel" – Land, F = Sofortkauf / A = Auktion, Preis
function titel(string $markt, array $i): string
{
    $art = in_array('AUCTION', $i['buyingOptions'] ?? []) ? 'A' : 'F';
    $p = $i['price'] ?? $i['currentBidPrice'] ?? [];
    $preis = match ($p['currency'] ?? '') {
        'USD', 'CAD', 'AUD' => '$' . $p['value'],
        'EUR' => $p['value'] . '€',
        'GBP' => '£' . $p['value'],
        default => trim(($p['value'] ?? '?') . ' ' . ($p['currency'] ?? '')),
    };
    return mb_substr('[' . substr($markt, 5) . "][$art][$preis] " . $i['title'], 0, 256);
}

// Jede Minute nur MAX_ABFRAGEN Suchen (Gratis-Kontingent 5000/Tag), reihum über alle Begriff×Marktplatz-Paare.
// Bei 4 Begriffen × 3 Märkten kommt jedes Paar also alle 4 Minuten dran.
const MAX_ABFRAGEN = 3;
$paare = [];
foreach ($cfg['suchbegriffe'] as $b => $maerkte) foreach ($maerkte as $m) $paare[] = [$m, (string) $b];
$gesehen = json_lesen(DATEN . '/gesehen.json');
$zeiger = json_lesen(DATEN . '/zeiger.json')['n'] ?? 0;

for ($k = 0; $k < min(MAX_ABFRAGEN, count($paare)); $k++) {
    [$markt, $begriff] = $paare[($zeiger + $k) % count($paare)];
    $schluessel = "$markt|$begriff";
    $treffer = suchen($cfg, $markt, $begriff);
    if ($treffer === null) continue;
    $erster_lauf = !isset($gesehen[$schluessel]);
    // Über alle Paare: dasselbe Angebot erscheint oft auf mehreren eBay-Seiten
    $alt = array_flip(array_merge([], ...array_values($gesehen)));
    // eBay findet auch über Varianten/Merkmale (Farbe "Cherry" …) – nur Treffer mit allen Wörtern im Titel
    $woerter = preg_split('/\s+/', mb_strtolower($begriff));
    $treffer = array_filter($treffer, fn($i) => !array_filter($woerter, fn($w) => !str_contains(mb_strtolower($i['title']), $w)));
    $neu = array_values(array_filter($treffer, fn($i) => !isset($alt[$i['itemId']])));
    if (!$neu && !$erster_lauf) continue;

    // Beim ersten Lauf eines Paars nur merken, sonst kommen 50 alte Angebote auf einmal
    if (!$erster_lauf) {
        $embeds = array_map(fn($i) => [
            'title' => titel($markt, $i),
            'url' => strtok($i['itemWebUrl'], '?'), // ohne Tracking-Parameter
            'image' => ['url' => $i['image']['imageUrl'] ?? ''],
            'footer' => ['text' => "Suchbegriff: $begriff"],
            'color' => 0x0064D2,
        ], array_reverse($neu)); // älteste zuerst
        foreach (array_chunk($embeds, 10) as $paket) foreach ($cfg['discord_webhooks'] as $w) posten($w, $paket);
    }

    $gesehen[$schluessel] = array_slice(array_merge(array_column($neu, 'itemId'), $gesehen[$schluessel] ?? []), 0, 1000);
    json_schreiben(DATEN . '/gesehen.json', $gesehen);
}
json_schreiben(DATEN . '/zeiger.json', ['n' => ($zeiger + MAX_ABFRAGEN) % max(1, count($paare))]);
