# `hnzio/share-embed`

Kirby-Plugin fuer lokale Link-Embeds. Es holt Metadaten fuer Bluesky-, Mastodon-, YouTube-, Vimeo- und normale Web-Links, cached die Ergebnisse lokal und rendert daraus robuste Vorschaukarten ueber ein KirbyTag.

## Features

- KirbyTag `(share: https://example.org/post)`
- lokale JSON- und Bild-Caches
- Freeze-Modus fuer stabile Embeds nach dem ersten Abruf
- Profil-/Avatar-Anreicherung fuer Bluesky und Mastodon
- optionaler Panel-Button zum manuellen Refresh
- plugin-eigene Icons, keine Abhaengigkeit von Site-Snippets

## Installation

Plugin in `site/plugins/share-embed` ablegen oder per Composer installieren:

```bash
composer require hnzio/kirby-share-embed
```

## Konfiguration

Das Plugin nutzt dieselbe Struktur wie `ai-text`:

- `config.sample.php`: dokumentierte Standardwerte
- `config.php`: lokale Overrides, nicht versioniert

Start:

```bash
cp site/plugins/share-embed/config.sample.php site/plugins/share-embed/config.php
```

Wichtige Optionen:

- `freeze`
- `ttl`
- `incompleteRetryTtl`
- `storage.path`
- `storage.root`
- `storage.url`
- `youtube.apiKey`
- `web-image-safe-mode`

Die Werte werden intern unter `hnzio.share-embed.*` gelesen. Alte `hnz.share-embed.*`-Keys funktionieren weiter als Fallback.

## Nutzung

Einfaches Embed:

```text
(share: https://bsky.app/profile/hnz.io/post/3lxyz)
```

Mit Overrides:

```text
(share: https://example.org/post
  title: Eigener Titel
  desc: Eigene Beschreibung
  author: Beispielautor
  profile: https://example.org/@autor
  network: Web
  refresh: true
)
```

## Hinweise

- Der Standardpfad fuer gecachte Assets ist in der Sample-Datei `assets/share-embed`.
- In dieser lokalen Installation bleibt der bestehende Pfad `assets/embeded` per ignorierter `config.php` erhalten.
- Fuer YouTube-Metadaten mit API-Unterstuetzung kann `youtube.apiKey` gesetzt werden.

## Lizenz

MIT
