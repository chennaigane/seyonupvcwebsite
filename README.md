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

## Adding a finished project (admin panel)
Projects are managed by Ganalytix through a private, password-protected admin page. Its address and file are kept out of this public repository and uploaded to Hostinger by hand.
The panel saves details to `data/projects.json` and resized photos to `images/projects/`
on the live server. Both are git-ignored, so GitHub deploys never overwrite them.

`projects.json` in this repo is only a fallback used when the admin has no projects yet.

Do not edit website pages in Hostinger's File Manager: the next deploy overwrites them.
