# Building Nexora Media from source

The admin interface is a React + TypeScript application. The human-readable
source lives in `frontend/`, and the production bundle that the plugin loads is
built to `assets/dist/nexora-media.js`.

This document exists so anyone (including WordPress.org reviewers) can verify
and reproduce the compiled asset from source. No code is obfuscated; the bundle
is a standard Vite production build of the `frontend/` sources.

## Requirements

- Node.js 18 or newer
- npm 9 or newer

## Build steps

From the plugin root (`nexora-media/`):

```bash
npm install      # installs dev dependencies (Vite, React, TypeScript, Tailwind)
npm run build    # compiles frontend/ → assets/dist/nexora-media.js
```

That is the exact command used to produce the shipped `assets/dist/` bundle.

## Toolchain

- **Bundler:** Vite 5 (`vite.config.ts`) — output dir `../assets/dist`, single IIFE bundle
- **Language:** TypeScript (`tsconfig.json`)
- **CSS:** Tailwind CSS (`tailwind.config.js`, `postcss.config.js`)
- **Entry point:** `frontend/main.tsx`

## Source layout

```
frontend/
  main.tsx            App entry (mounts the React SPA)
  App.tsx             Router
  components/         UI components
  pages/              Admin pages (Dashboard, Library, Delivery, Diagnostic, …)
  lib/                API client, store, helpers
  index.css           Tailwind entry + design tokens
```

The plugin runs entirely from the built `assets/dist/` output at runtime;
`node_modules/` is **not** required at runtime and is not shipped.
