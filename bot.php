<?php
// eBay-Suchbot: sucht die Begriffe aus data/einstellungen.json, schickt neue Angebote an Discord.
// Cron: * * * * * php /mnt/web/ebay/bot.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Wörter, die im Titel gleichwertig zählen (Kleinschreibung, Wortteil genügt: "mechanisch" passt auf "mechanische").
// ponytail: feste Liste, bei Bedarf ergänzen – für Unvorhergesehenes "a|b" im Suchbegriff
const SYNONYME = [
    ['tastatur', 'keyboard', 'clavier', 'tastiera', 'teclado', 'toetsenbord', 'klawiatura'],
    ['mechanisch', 'mechanical', 'mécanique', 'mecanique', 'meccanica', 'mecánic', 'mecanic', 'mechaniczn',
        'hot-swap', 'hotswap', 'gasket', 'hall effect', 'cherry mx', 'gateron', 'kailh', 'reaper switch'], // typische Merkmale mechanischer Tastaturen
    ['wireless', 'kabellos', 'bluetooth', '2.4ghz', '2,4ghz', '2.4 ghz', '2,4 ghz', 'sans fil', 'senza fili', 'inalámbric', 'inalambric', 'draadloos', 'bezprzewodow', 'funktastatur', 'funkmaus'],
    ['maus', 'mouse', 'souris', 'mysz'],
    ['kopfhörer', 'kopfhoerer', 'headphone', 'headset', 'casque', 'cuffie', 'auriculares'],
    ['tastenkappen', 'keycaps', 'keycap'],
    ['schalter', 'switches', 'switch'],
];

// Passt der Titel? Jedes Wort des Suchbegriffs (oder eine Alternative/ein Synonym davon) muss vorkommen.
function passt(string $begriff, string $titel): bool
{
    $titel = mb_strtolower($titel);
    foreach (preg_split('/\s+/', mb_strtolower(trim($begriff))) as $wort) {
        $varianten = explode('|', $wort);
        foreach (SYNONYME as $gruppe) if (array_intersect($varianten, $gruppe)) $varianten = array_merge($varianten, $gruppe);
        if (!array_filter($varianten, fn($v) => $v !== '' && str_contains($titel, $v))) return false;
    }
    return true;
}

// php bot.php test – Selbsttest des Titelfilters
if (($argv[1] ?? '') === 'test') {
    assert(passt('tastatur mechanisch wireless', 'Logitech G915 Wireless mechanische Gaming-Tastatur'));
    assert(passt('tastatur mechanisch wireless', 'Cherry KW X ULP ultraflache mechanische Tastatur kabellos'));
    assert(passt('tastatur mechanisch wireless', 'Aula F75 Wireless Mechanical Keyboard, Cedar Green'));
    assert(!passt('cherry mx', 'Mens Sweatshirt Heavy Blend 100% Plain Jumper'));
    assert(passt('keychron|aula f75', 'AULA F75 Max Wireless Gaming'));
    assert(!passt('keychron|aula k8', 'AULA F75 Max Wireless Gaming'));
    assert(passt('tastatur mechanisch wireless', 'AULA F75 Wireless Gaming Tastatur Gasket Reaper Switches'));
    exit("ok\n");
}

defined('DATEN') || define('DATEN', __DIR__ . '/data'); // weitere Instanzen setzen ihren eigenen Ordner
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
        // "a|b" ist bei eBay "(a,b)"
        'q' => preg_replace_callback('/\S*\|\S*/', fn($m) => '(' . str_replace('|', ',', $m[0]) . ')', $begriff),
        'sort' => 'newlyListed', 'limit' => 50,
    ]), ['Authorization: Bearer ' . token($cfg), "X-EBAY-C-MARKETPLACE-ID: $markt"]);
    if ($code !== 200) { fwrite(STDERR, "Suche '$begriff' auf $markt: HTTP $code\n"); return null; }
    return $d['itemSummaries'] ?? [];
}

// Beschreibung eines Angebots (1 API-Aufruf). Gedeckelt pro Tag, damit das Kontingent für die Suchen reicht:
// 3 Suchen/Minute ≈ 4320 der 5000 Aufrufe → Rest für Beschreibungen, auf alle Instanzen mit denselben Schlüsseln verteilt.
function beschreibung(array $cfg, string $markt, string $id, string $titel): string
{
    $z = json_lesen(DATEN . '/zaehler.json');
    if (($z['tag'] ?? '') !== gmdate('Y-m-d')) $z = ['tag' => gmdate('Y-m-d'), 'n' => 0];
    if ($z['n'] >= ($cfg['max_beschreibungen'] ?? 300)) return '';
    $z['n']++;
    json_schreiben(DATEN . '/zaehler.json', $z);

    [$code, $d] = http('GET', 'https://api.ebay.com/buy/browse/v1/item/' . rawurlencode($id),
        ['Authorization: Bearer ' . token($cfg), "X-EBAY-C-MARKETPLACE-ID: $markt"]);
    if ($code !== 200) return '';
    $text = $d['shortDescription'] ?? '';
    if ($text === '') { // sonst aus der HTML-Beschreibung
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $d['description'] ?? '');
        $text = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</div>'], [' <br', ' </p>', ' </div>'], $html)), ENT_QUOTES | ENT_HTML5);
    }
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if (mb_strtolower($text) === mb_strtolower(trim($titel))) return ''; // manche Verkäufer wiederholen nur den Titel
    return mb_strlen($text) > 300 ? rtrim(mb_substr($text, 0, 300)) . ' …' : $text;
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

// Jede Minute nur max_abfragen Suchen (Gratis-Kontingent 5000/Tag ≈ 3/Minute pro eBay-App), reihum über alle Begriff×Marktplatz-Paare.
// Teilen sich mehrere Instanzen dieselben eBay-Schlüssel, muss die Summe ≤ 3 bleiben.
$max = $cfg['max_abfragen'] ?? 3;
$paare = [];
foreach ($cfg['suchbegriffe'] as $b => $maerkte) foreach ($maerkte as $m) $paare[] = [$m, (string) $b];
$gesehen = json_lesen(DATEN . '/gesehen.json');
$zeiger = json_lesen(DATEN . '/zeiger.json')['n'] ?? 0;

for ($k = 0; $k < min($max, count($paare)); $k++) {
    [$markt, $begriff] = $paare[($zeiger + $k) % count($paare)];
    $schluessel = "$markt|$begriff";
    $treffer = suchen($cfg, $markt, $begriff);
    if ($treffer === null) continue;
    $erster_lauf = !isset($gesehen[$schluessel]);
    // Über alle Paare: dasselbe Angebot erscheint oft auf mehreren eBay-Seiten
    $alt = array_flip(array_merge([], ...array_values($gesehen)));
    // eBay findet auch über Varianten/Merkmale (Farbe "Cherry" …) – nur Treffer, deren Titel passt
    $treffer = array_filter($treffer, fn($i) => passt($begriff, $i['title']));
    $neu = array_values(array_filter($treffer, fn($i) => !isset($alt[$i['itemId']])));
    if (!$neu && !$erster_lauf) continue;

    // Beim ersten Lauf eines Paars nur merken, sonst kommen 50 alte Angebote auf einmal
    if (!$erster_lauf) {
        $embeds = array_map(fn($i) => [
            'title' => titel($markt, $i),
            'description' => trim(($i['condition'] ?? '') . "\n" . beschreibung($cfg, $markt, $i['itemId'], $i['title'])),
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
json_schreiben(DATEN . '/zeiger.json', ['n' => ($zeiger + $max) % max(1, count($paare))]);
