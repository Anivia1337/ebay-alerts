<?php
// Kopieren nach data/config.php und ausfüllen. config.php ist nicht im Repository.
return [
    // developer.ebay.com → Application Keys → Production: App ID (Client ID) + Cert ID (Client Secret)
    'ebay_client_id' => '',
    'ebay_client_secret' => '',
    // Discord: Kanal → Einstellungen → Integrationen → Webhooks → URL kopieren
    // Mehrere möglich, jeder bekommt alle neuen Angebote
    'discord_webhooks' => [
        'https://discord.com/api/webhooks/…',
    ],
    // Suchen pro Minute (eBay-Limit ≈ 3 pro Schlüssel, bei mehreren Instanzen aufteilen)
    'max_abfragen' => 3,
    // Beschreibungen pro Tag (1 Abruf je Angebot, darüber ohne Beschreibung)
    'max_beschreibungen' => 600,
    // Suchbegriffe und Marktplätze: über die Seite (data/einstellungen.json)
];
