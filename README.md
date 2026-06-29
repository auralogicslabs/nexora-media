# Nexora Media — Safe Image Optimization for WordPress

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

The official source repository for **Nexora Media**, a free image optimization
plugin for WordPress by [Auralogics Labs](https://auralogicslabs.com).

This repository contains the **full, human-readable source** of the plugin —
including the React/TypeScript admin interface in `frontend/`. It exists so that
anyone (including WordPress.org reviewers) can read and reproduce every line that
ships in the distributed plugin. Nothing is obfuscated.

> The plugin is distributed to users through the
> [WordPress.org plugin directory](https://wordpress.org/plugins/). This repo is
> the development source of truth.

---

## What it does

Nexora Media generates WebP variants of every image you upload, swaps them in
automatically for public visitors, and keeps your editors and page builders
untouched — safe by default.

- **WebP generation** — Imagick preferred, GD fallback
- **Background optimization queue** — safe batching, worker locking, per-image failure cooldown
- **Adaptive frontend delivery** — eligible image URLs swapped to WebP for logged-out visitors; originals always kept and served as a fallback
- **Builder-safe** — Elementor, Divi, Bricks, Oxygen, WPBakery, Beaver Builder, and the customizer always see the original while editing
- **Responsive variants**, lazy loading with hero-image guards, and EXIF stripping
- **Queue health system** — structured error log, stale-lock detection, one-click recovery
- **Nexora Engine bridge** — stands down on inline-CSS image rewriting when Engine handles it during static site generation
- **WP-CLI compatible** background processing

Nexora Media makes **no external HTTP requests** — it is fully self-contained.

---

## Repository layout

```
nexora-media/
├── nexora-media.php        # Plugin bootstrap + header
├── readme.txt              # WordPress.org listing (not this file)
├── uninstall.php           # Cleanup on delete
├── includes/               # PHP source (NXM_ prefix, spl_autoload)
│   └── engines/            # WebP / AVIF encoder engines (Imagick, GD)
├── admin/                  # wp-admin page + menu + AJAX
├── frontend/               # React + TypeScript admin UI (source)
├── assets/dist/            # Compiled UI bundle (committed, runnable)
├── languages/              # Translation directory (Domain Path)
├── build-zip.ps1           # Produces the WordPress.org distribution zip
└── BUILD.md                # How to reproduce assets/dist from source
```

## Building from source

The admin UI is compiled with Vite. See [BUILD.md](BUILD.md) for the exact
steps. In short:

```bash
npm install
npm run build       # frontend/ → assets/dist/nexora-media.js
```

## Branching & releases

- `main` — stable, released code. Each release is tagged (`v1.0.0`, `v1.0.1`, …).
- `dev` — ongoing development. Feature work merges here, then to `main` at release.

Release tags on `main` correspond 1:1 with the versions published to
WordPress.org.

## License

Nexora Media is free software, licensed under the **GPL v2 or later**.

© Auralogics Labs · [auralogicslabs.com](https://auralogicslabs.com)
