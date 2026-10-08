# eBay Alerts

Watches eBay for new listings matching your keywords and posts them to one or
more Discord channels via webhook. Each keyword has its own set of eBay sites
(DE, CH, FR, US, CA, …). Plain PHP, no framework, no database.

A Discord post looks like this, linked to the listing and with the photo:

```
[CA][F][$299.99] Vintage Apple Macintosh IIvx Computer w/ Keyboard
Suchbegriff: apple keyboard
```

`F` = Buy It Now, `A` = auction.

## How it works

- `bot.php` runs every minute from cron. It uses the official
  [eBay Browse API](https://developer.ebay.com/api-docs/buy/browse/overview.html)
  (eBay blocks plain scraping), searches sorted by *newly listed* and posts
  every listing it hasn't seen before.
- The first search of a new keyword/site pair only records what's already
  there, so the channel isn't flooded with old listings.
- The free API quota is 5000 calls a day, so the bot does **3 searches per
  minute**, cycling through all keyword/site pairs. With 6 pairs, each one is
  checked every 2 minutes. Set `max_abfragen` in `data/config.php` if eBay grants
  you more.
- `index.php` is a small page to add/remove keywords and pick their sites.
  It has no login: anyone with the URL can edit the keywords.

## Setup

Requires PHP 8.1+ with curl.

1. Create an app at [developer.ebay.com](https://developer.ebay.com) and a
   **Production** keyset. For "Marketplace account deletion notifications"
   choose *exempted* — the bot stores no user data.
2. Create a webhook in Discord: channel settings → Integrations → Webhooks.
3. Copy `data/config.example.php` to `data/config.php` and fill in the App ID,
   Cert ID and webhook URL(s).
4. Make `data/` writable for the web server user and run the bot every minute
   as that user:

   ```
   * * * * * php /path/to/ebay/bot.php
   ```

5. Open `index.php` in the browser and add keywords.

`data/` must not be reachable from the web — `data/.htaccess` takes care of
that on Apache; on other servers block it yourself. `bot.php` refuses to run
outside the CLI.

## Files

| File | Purpose |
|---|---|
| `bot.php` | Cron job: search eBay, post new listings to Discord |
| `index.php`, `stil.css` | Keyword page |
| `data/config.php` | eBay keys + webhooks (not in the repo) |
| `data/einstellungen.json` | Keywords and their sites, written by the page |
| `data/gesehen.json` | Listing IDs already posted, per keyword/site |

## License

MIT, see `LICENSE`.
