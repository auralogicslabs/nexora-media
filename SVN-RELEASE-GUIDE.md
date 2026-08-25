# Nexora Media — WordPress.org SVN Release Guide

How to publish/update Nexora Media on the WordPress.org plugin directory.
First publish: see `svn log` on the repo below.

---

## ⚠️ THE WORKFLOW — always edit SOURCE first, never the SVN copy directly

```
1. EDIT in SOURCE:   D:\project\nexora\products\nexora-media\   (git-tracked, the canonical truth)
2. COMMIT + PUSH:    git add . && git commit -m "..." && git push origin main   (keeps public GitHub repo current)
3. BUILD/COPY to RELEASE:  D:\project\nexora\release\nexora-media\nexora-media\   (the shippable files)
4. COPY release → SVN working copy:  trunk\  (+ tags\<ver>\ for a new release, + assets\ for images)
5. svn ci   → publishes to wordpress.org
```

NEVER edit `nexora-media-svn\` directly and call it done — the source + GitHub would silently drift from
what's live. If you ever do edit the SVN copy by accident, copy that file BACK to source + release so all
copies stay byte-identical, then commit source to git.

Git identity for the public repo MUST be `Auralogics Labs <hello@auralogicslabs.com>` (never a personal name,
never an AI co-author trailer).

## Key facts (read once)

- **SVN is a RELEASE system, not Git.** You only push finished, ready-to-use versions.
- **Public page:** https://wordpress.org/plugins/nexora-media
- **SVN repo:** https://plugins.svn.wordpress.org/nexora-media
- **SVN username:** `auralogics` (case-sensitive — exactly this)
- **SVN password:** SEPARATE from the wp.org login password. Get/reset it at
  https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password
- **Client:** SlikSVN (command-line `svn`), installed and on PATH.
- **Working copy on this PC:** `D:\project\nexora\release\nexora-media-svn\`
  (Has `.svn/` — this is the live link to wp.org. Do NOT delete it.)

## Repo layout (what each folder is for)

```
nexora-media-svn/
├── trunk/         ← the CURRENT plugin code (the development/latest version)
├── tags/
│   └── 1.0.0/     ← a FROZEN snapshot of each released version (copy of trunk at release time)
└── assets/        ← wp.org LISTING images only (screenshots, banners, icons) — NOT shipped in the plugin
```

- `readme.txt` line **`Stable tag: X.Y.Z`** decides which `tags/X.Y.Z/` the public page serves.
  The public page reads `tags/<stable tag>/readme.txt`, so the stable tag MUST point to a real tag folder.
- `assets/` filenames are fixed by wp.org convention:
  `screenshot-1.png … screenshot-9.png` (order matches the readme `== Screenshots ==` captions),
  `banner-1544x500.png`, `banner-772x250.png`, `icon-128x128.png`, `icon-256x256.png`.

---

## A) First-time publish (already done — for reference)

```powershell
cd D:\project\nexora\release
svn co https://plugins.svn.wordpress.org/nexora-media nexora-media-svn
cd nexora-media-svn

# Copy the plugin file CONTENTS (not a wrapping folder, not the zip) into trunk:
Copy-Item "D:\project\nexora\release\nexora-media\nexora-media\*" -Destination "trunk\" -Recurse -Force
# Freeze the release tag (identical to trunk):
Copy-Item "trunk\*" -Destination "tags\1.0.0\" -Recurse -Force
# Listing images:
Copy-Item "D:\project\nexora\wporg-assets\nexora-media\*" -Destination "assets\" -Recurse -Force

svn add trunk tags assets --force
svn ci -m "Nexora Media 1.0.0" --username auralogics    # prompts for SVN password
```

GOTCHAS seen the first time (avoid these):
- When copying into `tags/1.0.0`, copy the PLUGIN ROOT (app/, nexora-media.php, readme.txt, …),
  NOT the inside of `app/`. tags/1.0.0 must look identical to trunk.
- Don't forget `assets/` — it's easy to leave empty. Verify it has all 13 images before commit.

---

## B) Releasing a NEW version (e.g. 1.0.1) — the normal flow

1. Build the new plugin zip as usual (your build-zip.ps1), so
   `D:\project\nexora\release\nexora-media\nexora-media\` holds the new files.

2. Bump the version in BOTH places (must match):
   - `nexora-media.php` header `Version: 1.0.1`
   - `readme.txt` `Stable tag: 1.0.1`
   - Add a `== Changelog ==` entry for 1.0.1.

3. Refresh trunk, then make the new tag:
   ```powershell
   cd D:\project\nexora\release\nexora-media-svn
   svn up                                   # sync first

   # Update trunk to the new files:
   Copy-Item "D:\project\nexora\release\nexora-media\nexora-media\*" -Destination "trunk\" -Recurse -Force

   # Stage trunk changes (adds new files, marks changed ones):
   svn add trunk --force

   # Create the version tag as a server-side copy of trunk (cleanest):
   svn cp trunk tags/1.0.1

   svn status                               # review what will be committed
   svn ci -m "Nexora Media 1.0.1" --username auralogics
   ```
   - If you DELETED files between versions, run `svn rm` on them in trunk before commit
     (or use `svn status` to spot missing `!` entries and `svn rm` them).

4. Updating ONLY listing images / screenshots (no code change):
   ```powershell
   Copy-Item "D:\project\nexora\wporg-assets\nexora-media\*" -Destination "assets\" -Recurse -Force
   svn add assets --force
   svn ci -m "Update listing assets" --username auralogics
   ```
   Asset changes go live almost immediately and do NOT require a version bump.

5. Updating ONLY the readme/description (no code change, no version bump):

   The public page renders `tags/<Stable tag>/readme.txt`, NOT trunk. Editing only
   trunk commits cleanly and changes nothing on the live page — it looks like it
   worked and does not. Update BOTH, in the same commit:

   ```powershell
   cd D:\project\nexora\release
   svn up nexora-media-svn                     # always sync first

   # 1) SOURCE is edited first (products\), then copied out - never edit SVN directly.
   Copy-Item "D:\project\nexora\products\nexora-media\readme.txt" `
             "D:\project\nexora\release\nexora-media\nexora-media\readme.txt" -Force

   # 2) release -> SVN: trunk AND the stable tag folder.
   Copy-Item "D:\project\nexora\release\nexora-media\nexora-media\readme.txt" `
             "nexora-media-svn\trunk\readme.txt" -Force
   Copy-Item "D:\project\nexora\release\nexora-media\nexora-media\readme.txt" `
             "nexora-media-svn\tags\1.0.0\readme.txt" -Force    # <- the Stable tag

   svn diff nexora-media-svn                   # expect exactly 2 changed files
   svn ci nexora-media-svn -m "Tested up to 7.1" --username auralogics
   ```

   - Keep `Stable tag:` pointing at the version already released. A readme-only
     change needs NO version bump: bumping would push a pointless update to every
     existing user.
   - The tag folder is a real directory in this repo, not a git-style ref, so it
     has to be edited like any other file.
   - Same rule applies to Nexora Pulse (`tags\1.0.1\`) and any future plugin:
     whatever `Stable tag:` names is the folder that must be updated.

   **"Tested up to" specifically:** confirm the plugin actually works on that
   WordPress version before claiming it. wp.org displays the value as the latest
   patch of that line (`7.1` shows as `7.1.x`), so the readme carries the major.

---

## C) Handy commands

```powershell
svn status            # what's changed/added/missing in the working copy
svn diff              # see the exact line changes before committing
svn up                # pull the latest from the server (do before editing)
svn info              # repo URL + current revision
svn revert -R .       # undo all local changes (careful)
```

## D) After any commit

- Code/version changes: the public page updates within minutes; search + profile can lag up to 72h.
- The page always reflects `tags/<Stable tag>/`. If a release looks wrong, first check the Stable tag
  value in `trunk/readme.txt` actually matches an existing `tags/` folder.

## E) Rules to stay listed

- Only push working, ready-to-use versions (no half-finished commits).
- Keep guideline compliance (Plugin Check / PHPCS+WPCS clean of errors).
- Commit identity for any PUBLIC git repo: "Auralogics Labs <hello@auralogicslabs.com>" — never personal
  names, never AI co-author trailers. (SVN commits just use the auralogics username + SVN password.)
