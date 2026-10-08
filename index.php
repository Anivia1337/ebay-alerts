<?php
// Mini-Oberfläche für den eBay-Bot: Suchbegriffe mit je eigenen Marktplätzen pflegen.
// Speichert nach data/einstellungen.json, das bot.php jede Minute liest.
const EINSTELLUNGEN = __DIR__ . '/data/einstellungen.json';
const MAERKTE = [
    'EBAY_DE' => 'Germany', 'EBAY_CH' => 'Switzerland', 'EBAY_AT' => 'Austria', 'EBAY_FR' => 'France',
    'EBAY_IT' => 'Italy', 'EBAY_ES' => 'Spain', 'EBAY_NL' => 'Netherlands', 'EBAY_PL' => 'Poland',
    'EBAY_GB' => 'United Kingdom', 'EBAY_US' => 'United States', 'EBAY_CA' => 'Canada', 'EBAY_AU' => 'Australia',
];
const MAX_BEGRIFFE = 30;
date_default_timezone_set('Europe/Zurich'); // Server läuft auf UTC

// Format: {"suchbegriffe": {"begriff": ["EBAY_DE", ...]}} – jeder Begriff mit eigenen Märkten
$e = json_decode((string) @file_get_contents(EINSTELLUNGEN), true) ?: [];
$e['suchbegriffe'] ??= [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $begriff = trim(preg_replace('/\s+/', ' ', (string) ($_POST['neu'] ?? '')));
    $maerkte = array_values(array_intersect(array_keys(MAERKTE), (array) ($_POST['maerkte'] ?? [])));
    if ($begriff !== '' && mb_strlen($begriff) <= 80 && $maerkte) {
        // Gleicher Begriff nochmal = Märkte ändern (Gross/Klein egal)
        foreach (array_keys($e['suchbegriffe']) as $b) if (mb_strtolower($b) === mb_strtolower($begriff)) $begriff = $b;
        if (isset($e['suchbegriffe'][$begriff]) || count($e['suchbegriffe']) < MAX_BEGRIFFE) $e['suchbegriffe'][$begriff] = $maerkte;
    }
    if (isset($_POST['weg'])) unset($e['suchbegriffe'][(string) $_POST['weg']]);
    file_put_contents(EINSTELLUNGEN . '.tmp', json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename(EINSTELLUNGEN . '.tmp', EINSTELLUNGEN);
    header('Location: /ebay', true, 303);
    exit;
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$cfg = require __DIR__ . '/data/config.php';
$bereit = $cfg['ebay_client_id'] !== '' && $cfg['ebay_client_secret'] !== '' && array_filter($cfg['discord_webhooks']);
$paare = array_sum(array_map('count', $e['suchbegriffe']));
$takt = max(1, (int) ceil($paare / 3)); // bot.php: MAX_ABFRAGEN = 3 pro Minute
$letzter = @filemtime(__DIR__ . '/data/zeiger.json');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>eBay Alerts</title>
<link rel="stylesheet" href="/ebay/stil.css?v=<?= @filemtime(__DIR__ . '/stil.css') ?>">
</head>
<body>
<main class="seite">
  <h1>eBay Alerts</h1>
  <p class="unter">New listings for these keywords are posted to Discord.</p>

  <p class="status <?= $bereit && $letzter ? 'ok' : 'warten' ?>">
    <?php if (!$bereit): ?>Waiting for eBay API keys — nothing is being checked yet.
    <?php elseif (!$letzter): ?>Ready, waiting for the first run.
    <?php else: ?>Last check <?= $h(date('H:i', $letzter)) ?> · each keyword/site pair every <?= $takt ?> min
    <?php endif ?>
  </p>

  <section class="karte">
    <h2>Add keyword</h2>
    <form method="post">
      <input name="neu" maxlength="80" placeholder="e.g. apple design keyboard" required aria-label="Keyword" class="breit">
      <fieldset class="maerkte">
        <legend>Search on</legend>
        <?php foreach (MAERKTE as $id => $name): ?>
        <label><input type="checkbox" name="maerkte[]" value="<?= $id ?>"> <?= $h($name) ?></label>
        <?php endforeach ?>
      </fieldset>
      <button class="knopf">Add</button>
    </form>
    <p class="hinweis">Adding a keyword that already exists replaces its sites. eBay allows about 3 searches per minute, so every extra keyword × site makes each check less frequent.</p>
  </section>

  <section class="karte">
    <h2>Keywords</h2>
    <?php if (!$e['suchbegriffe']): ?><p class="leer">No keywords yet.</p><?php endif ?>
    <ul class="liste">
      <?php foreach ($e['suchbegriffe'] as $b => $m): ?>
      <li>
        <span><?= $h($b) ?> <small><?= $h(implode(' · ', array_map(fn($x) => substr($x, 5), $m))) ?></small></span>
        <form method="post"><button class="weg" name="weg" value="<?= $h($b) ?>" aria-label="Remove <?= $h($b) ?>">Remove</button></form>
      </li>
      <?php endforeach ?>
    </ul>
  </section>
</main>
</body>
</html>
