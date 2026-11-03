# Seyon UPVC website (seyonupvc.com)

Static website for Seyon UPVC, Avadi, Chennai. Everything in this repository is
deployed as-is into Hostinger's `public_html` for seyonupvc.com.

## Pages
- `index.html` Home + live 3D showroom
- `products.html`, `why-seyon.html`, `architects-builders.html`, `projects.html`, `contact.html`
- Service pages: `upvc-windows-chennai.html`, `upvc-sliding-doors-chennai.html`,
  `office-partitions-chennai.html`, `balcony-design-chennai.html`, `upvc-windows-avadi.html`
- Area pages: `upvc-windows-thiruvallur.html`, `upvc-windows-kancheepuram.html`,
  `upvc-windows-chengalpattu.html`, `upvc-windows-chennai-city.html`

## Adding a finished project
1. Upload photos to `images/projects/` (under ~500 KB each).
2. Edit `projects.json` and add an entry (see HOW-TO-ADD-PROJECTS.txt for the format).
3. Commit to `main`. Hostinger auto-deploys within a minute or two.

Do not edit files in Hostinger's File Manager: the next deploy overwrites them.
