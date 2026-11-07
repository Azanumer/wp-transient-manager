# WP Transient Manager

See, search and clean up WordPress transients from **Tools → Transient Manager**. Transients are WordPress's built-in temporary cache — stuck or expired ones bloat `wp_options` and slow every page load. This plugin lists them biggest first, so you can see exactly what is eating space.

## Features

- **List all transients** — name, serialized size and expiry, biggest first, 50 per page.
- **Search** by transient name.
- **Delete expired** — one click removes every transient whose timeout has passed (plus its timeout row).
- **Delete all** — with a type-`DELETE` confirmation so you can't click it by accident.
- **Delete single** — per-row delete with a JavaScript confirm.
- **Site transients** — on multisite, a second tab lists `_site_transient_*` rows from `wp_sitemeta`.
- **Safe** — only `manage_options` users can open the page; every action is nonce-checked. Deleting a transient never breaks a site: WordPress simply regenerates it on the next request.

## Why it matters

A plugin that stores a 2 MB transient and never cleans it up adds 2 MB to every `wp_options` autoload query. Multiply that by a dozen sloppy plugins and your database is doing megabytes of useless work on every single request. This plugin makes that bloat visible — and removable in one click.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **Tools → Transient Manager**.

## Notes

- Transients with no timeout row show "Never" — those are permanent until deleted.
- Object-cache drop-ins (Redis/Memcached) store transients in memory instead of the database; this plugin lists the database rows, so it is most useful on sites without a persistent object cache.

## License

MIT — see [LICENSE](LICENSE).
