<?php
/* Seyon UPVC project admin, managed by Ganalytix.
   Saves projects to ../data/projects.json and photos to ../images/projects/.
   Both locations are git-ignored, so GitHub deploys never overwrite them. */
declare(strict_types=1);

const INITIAL_HASH = '$2y$12$lDg9dnNbi8tJ6xpEu1KFueGtElOH9iMN9GWsyuo1QlI8eFOz9CISO';            // first password; change it after the first login
const MAX_PHOTOS = 6;
const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;  // 12 MB per original photo
const MAX_SIDE = 1600;                      // photos are resized to this longest side

$ROOT = dirname(__DIR__);
$DATA = $ROOT . '/data';
$PHOTO_DIR = $ROOT . '/images/projects';
$PROJECTS = $DATA . '/projects.json';
/* private files (password hash, login attempts) live OUTSIDE the public website folder when possible */
$PRIV = dirname($ROOT) . '/seyon-admin-private';
if (!is_dir($PRIV)) @mkdir($PRIV, 0700, true);
if (!is_dir($PRIV) || !is_writable($PRIV)) { $PRIV = $DATA . '/private-' . substr(hash('sha256', __DIR__ . INITIAL_HASH), 0, 24); if (!is_dir($PRIV)) @mkdir($PRIV, 0700, true); @file_put_contents($PRIV . '/.htaccess', "Require all denied\n"); @file_put_contents($PRIV . '/index.html', ''); }
$AUTH = $PRIV . '/admin.json';
$LOCKS = $PRIV . '/login-attempts.json';

$TYPES = ['Casement windows','Sliding windows','Tilt and turn windows','Fixed and combination','Sliding doors','French doors','Fold and slide doors','Casement doors','Ventilators','Balcony design','Office partitions','Mixed windows and doors'];
$COLOURS = ['Pristine White'=>'White','Walnut'=>'Walnut brown','Golden Oak'=>'Golden Oak','Agate Grey'=>'Grey','Black'=>'Black'];
$SITES = ['Apartment','Independent house','Villa','Office','Clinic or shop','Other'];
$SERIES = ['','60 mm casement','65 mm casement','80 mm casement door','60 mm tilt and turn','60 mm sliding','80 mm sliding','88 mm sliding'];
$GLASS = ['','Clear toughened','Frosted','Double-glazed','Tinted','Laminated'];

foreach ([$DATA, $PHOTO_DIR] as $d) { if (!is_dir($d)) @mkdir($d, 0755, true); }
if (!file_exists($DATA . '/.htaccess')) {
  @file_put_contents($DATA . '/.htaccess', "<FilesMatch \"^(admin|login-attempts)\\.json$\">\n  Require all denied\n</FilesMatch>\nOptions -Indexes\n");
}

ini_set('session.use_strict_mode', '1');
session_name('seyon_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'");

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function read_json(string $f, $def) { if (!is_file($f)) return $def; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : $def; }
function write_json(string $f, $v): bool {
  $tmp = $f . '.tmp' . bin2hex(random_bytes(4));
  if (file_put_contents($tmp, json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) return false;
  return rename($tmp, $f);
}
function current_hash(string $AUTH): string { $a = read_json($AUTH, []); return $a['hash'] ?? INITIAL_HASH; }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('Session expired. Go back and try again.'); } }
function flash(string $m, string $k = 'ok'): void { $_SESSION['flash'] = [$k, $m]; }
function go(string $q = ''): void { header('Location: ./' . $q); exit; }

$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$locks = read_json($LOCKS, []);
$lockedFor = 0;
if (isset($locks[$ip]) && $locks[$ip]['n'] >= 5) { $lockedFor = max(0, $locks[$ip]['t'] + 900 - time()); if ($lockedFor === 0) { unset($locks[$ip]); write_json($LOCKS, $locks); } }

$action = $_POST['action'] ?? '';
$authed = !empty($_SESSION['ok']) && ($_SESSION['exp'] ?? 0) > time();

if ($action === 'login') {
  check_csrf();
  if ($lockedFor > 0) { flash('Too many attempts. Try again in ' . ceil($lockedFor / 60) . ' minutes.', 'err'); go(); }
  if (password_verify((string)($_POST['password'] ?? ''), current_hash($AUTH))) {
    session_regenerate_id(true); $_SESSION['ok'] = true; $_SESSION['exp'] = time() + 8 * 3600;
    unset($locks[$ip]); write_json($LOCKS, $locks); go();
  }
  $locks[$ip] = ['n' => ($locks[$ip]['n'] ?? 0) + 1, 't' => time()]; write_json($LOCKS, $locks);
  usleep(600000); flash('Wrong password.', 'err'); go();
}
if ($action === 'logout') { check_csrf(); $_SESSION = []; session_destroy(); go(); }

if (!$authed) { page_login($lockedFor); exit; }
$_SESSION['exp'] = time() + 8 * 3600;

$projects = read_json($PROJECTS, []);
$seed = $ROOT . '/projects.json';
if (!$projects && !is_file($PROJECTS) && is_file($seed)) $projects = read_json($seed, []);

function save_photo(array $f, string $dir): ?string {
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
  if ($f['size'] > MAX_UPLOAD_BYTES) throw new RuntimeException('A photo is larger than 12 MB.');
  $info = @getimagesize($f['tmp_name']);
  if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new RuntimeException('Photos must be JPG, PNG or WebP.');
  $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']), IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']), IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name']) };
  if (!$src) throw new RuntimeException('Could not read one of the photos.');
  if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
    $ex = @exif_read_data($f['tmp_name']); $o = $ex['Orientation'] ?? 1;
    if ($o == 3) $src = imagerotate($src, 180, 0); elseif ($o == 6) $src = imagerotate($src, -90, 0); elseif ($o == 8) $src = imagerotate($src, 90, 0);
  }
  $w = imagesx($src); $hh = imagesy($src); $k = min(1, MAX_SIDE / max($w, $hh));
  $nw = (int)round($w * $k); $nh = (int)round($hh * $k);
  $dst = imagecreatetruecolor($nw, $nh); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
  imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hh);
  $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.jpg';
  if (!imagejpeg($dst, $dir . '/' . $name, 82)) throw new RuntimeException('Could not save a photo.');
  imagedestroy($src); imagedestroy($dst);
  return 'images/projects/' . $name;
}
function clean(string $k, int $max = 600): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? ''))), 0, $max); }
function pick(string $k, array $allowed): string { $v = (string)($_POST[$k] ?? ''); return in_array($v, $allowed, true) ? $v : ''; }
function delete_photo(string $p, string $ROOT): void { if (preg_match('#^images/projects/[0-9]{8}-[0-9a-f]{12}\.jpg$#', $p)) @unlink($ROOT . '/' . $p); }

if ($action !== '') {
  check_csrf();
  $id = (string)($_POST['id'] ?? '');
  $idx = null; foreach ($projects as $i => $p) if (($p['id'] ?? '') === $id) $idx = $i;
  try {
    if ($action === 'save') {
      $p = $idx !== null ? $projects[$idx] : ['id' => bin2hex(random_bytes(6)), 'createdAt' => date('c'), 'imgs' => []];
      $title = clean('title', 120); if ($title === '') throw new RuntimeException('Give the project a name.');
      $p['title'] = $title; $p['area'] = clean('area', 120);
      $p['site'] = pick('site', $SITES); $p['type'] = pick('type', $TYPES); $p['series'] = pick('series', $SERIES);
      $p['colour'] = pick('colour', array_keys($COLOURS)); $p['glass'] = pick('glass', $GLASS);
      $c = (int)($_POST['count'] ?? 0); $p['count'] = $c > 0 && $c < 10000 ? $c : null;
      $m = (string)($_POST['completed'] ?? ''); $p['completed'] = preg_match('/^\d{4}-\d{2}$/', $m) ? $m : '';
      foreach (['update', 'brief', 'did', 'quote'] as $k) $p[$k] = clean($k, 900);
      $p['who'] = clean('who', 120);
      $keep = array_values(array_filter($p['imgs'] ?? [], fn($x) => !in_array($x, (array)($_POST['remove'] ?? []), true)));
      foreach (array_diff($p['imgs'] ?? [], $keep) as $gone) delete_photo($gone, $ROOT);
      $p['imgs'] = $keep;
      if (!empty($_FILES['photos']['name'][0])) {
        $n = count($_FILES['photos']['name']);
        for ($i = 0; $i < $n; $i++) {
          if (count($p['imgs']) >= MAX_PHOTOS) break;
          $f = ['name' => $_FILES['photos']['name'][$i], 'tmp_name' => $_FILES['photos']['tmp_name'][$i], 'error' => $_FILES['photos']['error'][$i], 'size' => $_FILES['photos']['size'][$i]];
          $saved = save_photo($f, $PHOTO_DIR); if ($saved) $p['imgs'][] = $saved;
        }
      }
      $cover = (string)($_POST['cover'] ?? '');
      if ($cover !== '' && in_array($cover, $p['imgs'], true)) { $p['imgs'] = array_values(array_merge([$cover], array_diff($p['imgs'], [$cover]))); }
      if (!$p['imgs']) throw new RuntimeException('Add at least one photo of the finished project.');
      $p['updatedAt'] = date('c');
      if ($idx !== null) $projects[$idx] = $p; else array_unshift($projects, $p);
      if (!write_json($PROJECTS, $projects)) throw new RuntimeException('Could not save. Check folder permissions for public_html/data.');
      flash($idx !== null ? 'Project updated. It is live on the Projects page.' : 'Project published. It is live on the Projects page.'); go();
    }
    if ($action === 'delete' && $idx !== null) {
      foreach ($projects[$idx]['imgs'] ?? [] as $x) delete_photo($x, $ROOT);
      array_splice($projects, $idx, 1); write_json($PROJECTS, $projects); flash('Project removed.'); go();
    }
    if (($action === 'up' || $action === 'down') && $idx !== null) {
      $j = $action === 'up' ? $idx - 1 : $idx + 1;
      if ($j >= 0 && $j < count($projects)) { [$projects[$idx], $projects[$j]] = [$projects[$j], $projects[$idx]]; write_json($PROJECTS, $projects); }
      go();
    }
    if ($action === 'password') {
      if (!password_verify((string)($_POST['old'] ?? ''), current_hash($AUTH))) throw new RuntimeException('Current password is wrong.');
      $new = (string)($_POST['new'] ?? '');
      if (strlen($new) < 12) throw new RuntimeException('New password must be at least 12 characters.');
      if ($new !== (string)($_POST['new2'] ?? '')) throw new RuntimeException('The two new passwords do not match.');
      write_json($AUTH, ['hash' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), 'changed' => date('c')]);
      flash('Password changed.'); go();
    }
  } catch (RuntimeException $e) { flash($e->getMessage(), 'err'); go($action === 'save' && $idx !== null ? '?edit=' . urlencode($id) : ($action === 'save' ? '?new=1' : '')); }
}

$editId = (string)($_GET['edit'] ?? ''); $edit = null; foreach ($projects as $p) if (($p['id'] ?? '') === $editId) $edit = $p;
$showForm = $edit !== null || isset($_GET['new']);
page_admin($projects, $edit, $showForm, $TYPES, $COLOURS, $SITES, $SERIES, $GLASS);

/* ---------------- views ---------------- */
function head_html(string $title): void { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= h($title) ?></title><link rel="icon" href="/favicon.svg">
<style>
:root{--p:#440E7A;--g:#75BB25;--ink:#1B1530;--mut:#5E5873;--line:#E4E0EC;--bg:#FAF9FC;--soft:#F1EAF8;--err:#B42318}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
.top{background:#fff;border-bottom:1px solid var(--line);padding:12px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.top b{font-size:17px;color:var(--p)}.brand{display:flex;align-items:center;gap:10px;text-decoration:none}.brand img{height:40px;width:auto;display:block}
body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;background:url(/favicon.svg) no-repeat center 58%/min(60vw,520px) auto;opacity:.06}
.top{position:relative;z-index:1}.card{position:relative}.top span{color:var(--mut);font-size:13px}.top .sp{flex:1}
.wrap{max-width:1000px;margin:0 auto;padding:20px 16px 60px}
h1{font-size:24px;margin:0 0 4px}h2{font-size:19px;margin:28px 0 12px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:42px;padding:0 16px;border-radius:10px;border:1.5px solid var(--p);background:var(--p);color:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:var(--p)}.btn.sm{min-height:34px;padding:0 10px;font-size:13px}.btn.red{background:#fff;color:var(--err);border-color:#e6b8b3}
.msg{padding:12px 14px;border-radius:10px;margin:12px 0;font-weight:600}.msg.ok{background:#EAF6DD;color:#2E5A10}.msg.err{background:#FDECEA;color:var(--err)}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px}
.list{display:flex;flex-direction:column;gap:10px}
.row{display:grid;grid-template-columns:96px 1fr auto;gap:14px;align-items:center}
.row img{width:96px;height:72px;object-fit:cover;border-radius:8px;background:#eee}
.row .t b{display:block}.row .t small{color:var(--mut)}
.acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
form.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
label{display:flex;flex-direction:column;gap:6px;font-weight:600;font-size:14px}
input,select,textarea{font:inherit;font-weight:400;min-height:44px;padding:9px 11px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--ink);width:100%}
textarea{min-height:84px;resize:vertical}.full{grid-column:1/-1}.hint{font-weight:400;color:var(--mut);font-size:13px}
.photos{display:flex;flex-wrap:wrap;gap:10px}.ph{width:150px;border:1px solid var(--line);border-radius:10px;padding:6px;font-weight:400;font-size:13px}
.ph img{width:100%;height:100px;object-fit:cover;border-radius:6px;display:block;margin-bottom:6px}.ph label{flex-direction:row;align-items:center;gap:6px;font-weight:400}
.ph input{min-height:auto;width:auto}
.empty{text-align:center;color:var(--mut);padding:30px}
@media (max-width:640px){form.grid{grid-template-columns:1fr}.row{grid-template-columns:72px 1fr}.row img{width:72px;height:56px}.acts{grid-column:1/-1;justify-content:flex-start}}
.login{max-width:380px;margin:12vh auto;padding:0 16px}
</style></head><body>
<?php }

function flash_html(): void { if (!empty($_SESSION['flash'])) { [$k, $m] = $_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="msg ' . h($k) . '">' . h($m) . '</div>'; } }

function page_login(int $lockedFor): void { head_html('Seyon UPVC admin · Sign in'); ?>
<div class="login"><div class="card">
  <img src="/favicon.svg" alt="Seyon UPVC" width="64" height="64" style="display:block;margin:0 auto 10px">
  <h1 style="text-align:center">Seyon UPVC admin</h1><p style="color:var(--mut);margin:0 0 14px;text-align:center">Projects editor · managed by Ganalytix</p>
  <?php flash_html(); ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="login">
    <label>Password<input type="password" name="password" autocomplete="current-password" required autofocus <?= $lockedFor ? 'disabled' : '' ?>></label>
    <p></p><button class="btn" style="width:100%" <?= $lockedFor ? 'disabled' : '' ?>>Sign in</button>
  </form>
</div></div></body></html>
<?php }

function opt(array $list, string $cur, bool $assoc = false): string {
  $o = ''; foreach ($list as $k => $v) { $val = $assoc ? $k : $v; $lab = $v === '' ? 'Not specified' : $v; $o .= '<option value="' . h($val) . '"' . ($val === $cur ? ' selected' : '') . '>' . h($lab) . '</option>'; } return $o;
}

function page_admin(array $projects, ?array $e, bool $showForm, array $TYPES, array $COLOURS, array $SITES, array $SERIES, array $GLASS): void {
  head_html('Seyon UPVC admin · Projects'); $c = h(csrf()); ?>
<div class="top"><a class="brand" href="./"><img src="/favicon.svg" alt="" width="40" height="40"><b>Seyon UPVC</b></a><span>Projects admin · Ganalytix</span><div class="sp"></div>
  <a class="btn ghost sm" href="/projects.html" target="_blank" rel="noopener">View Projects page</a>
  <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= $c ?>"><button class="btn ghost sm" name="action" value="logout">Sign out</button></form></div>
<div class="wrap">
<?php flash_html(); ?>
<?php if ($showForm): $p = $e ?? []; ?>
  <h1><?= $e ? 'Edit project' : 'Add a finished project' ?></h1>
  <p class="hint">Photos are resized automatically. Up to <?= MAX_PHOTOS ?> photos; the first is the cover.</p>
  <form class="grid card" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= $c ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($p['id'] ?? '') ?>">
    <label class="full">Project name *<input name="title" required maxlength="120" value="<?= h($p['title'] ?? '') ?>" placeholder="Sea-facing 3BHK, balcony sliders"></label>
    <label>Area / town<input name="area" maxlength="120" value="<?= h($p['area'] ?? '') ?>" placeholder="Tambaram, Chengalpattu"><span class="hint">Town + district helps Google.</span></label>
    <label>Site type<select name="site"><?= opt($SITES, $p['site'] ?? 'Apartment') ?></select></label>
    <label>Main product<select name="type"><?= opt($TYPES, $p['type'] ?? 'Casement windows') ?></select></label>
    <label>Frame finish<select name="colour"><?= opt($COLOURS, $p['colour'] ?? 'Pristine White', true) ?></select></label>
    <label>Profile series<select name="series"><?= opt($SERIES, $p['series'] ?? '') ?></select></label>
    <label>Glass<select name="glass"><?= opt($GLASS, $p['glass'] ?? '') ?></select></label>
    <label>Openings fitted<input type="number" name="count" min="1" max="9999" value="<?= h($p['count'] ?? '') ?>"></label>
    <label>Completed (month)<input type="month" name="completed" value="<?= h($p['completed'] ?? '') ?>"></label>
    <label class="full">Seyon update<textarea name="update" maxlength="900" placeholder="We've finished another home in Tambaram with new casement windows."><?= h($p['update'] ?? '') ?></textarea></label>
    <label class="full">The brief<textarea name="brief" maxlength="900" placeholder="What the owner needed."><?= h($p['brief'] ?? '') ?></textarea></label>
    <label class="full">What we fitted<textarea name="did" maxlength="900" placeholder="Number of windows and doors, finish, mesh, glass."><?= h($p['did'] ?? '') ?></textarea></label>
    <label class="full">Customer's words <span class="hint">Only with the customer's permission.</span><textarea name="quote" maxlength="900"><?= h($p['quote'] ?? '') ?></textarea></label>
    <label>Customer name and area<input name="who" maxlength="120" value="<?= h($p['who'] ?? '') ?>" placeholder="Mr. Kumar, Tambaram"></label>
    <div class="full">
      <?php if (!empty($p['imgs'])): ?><b style="display:block;margin-bottom:8px">Current photos</b><div class="photos">
        <?php foreach ($p['imgs'] as $i => $img): ?><div class="ph"><img src="/<?= h($img) ?>" alt="">
          <label><input type="radio" name="cover" value="<?= h($img) ?>" <?= $i === 0 ? 'checked' : '' ?>> Cover</label>
          <label><input type="checkbox" name="remove[]" value="<?= h($img) ?>"> Remove</label></div><?php endforeach; ?>
      </div><?php endif; ?>
      <label style="margin-top:12px">Add photos<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple <?= empty($p['imgs']) ? 'required' : '' ?>><span class="hint">JPG, PNG or WebP, up to 12 MB each. Phone photos are fine.</span></label>
    </div>
    <div class="full" style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn"><?= $e ? 'Save changes' : 'Publish project' ?></button><a class="btn ghost" href="./">Cancel</a></div>
  </form>
<?php else: ?>
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div><h1>Projects</h1><p class="hint" style="margin:0"><?= count($projects) ?> published · the order here is the order on the website</p></div>
    <a class="btn" href="?new=1">+ Add project</a></div>
  <div class="list" style="margin-top:16px">
  <?php if (!$projects): ?><div class="card empty">No projects yet. Click <b>Add project</b> to publish the first one.</div><?php endif; ?>
  <?php foreach ($projects as $i => $p): ?>
    <div class="card row"><img src="/<?= h($p['imgs'][0] ?? '') ?>" alt="">
      <div class="t"><b><?= h($p['title'] ?? '') ?></b><small><?= h(implode(' · ', array_filter([$p['area'] ?? '', $p['type'] ?? '', $p['completed'] ?? '']))) ?> · <?= count($p['imgs'] ?? []) ?> photo(s)</small></div>
      <div class="acts">
        <form method="post" style="margin:0;display:flex;gap:6px"><input type="hidden" name="csrf" value="<?= $c ?>"><input type="hidden" name="id" value="<?= h($p['id'] ?? '') ?>">
          <button class="btn ghost sm" name="action" value="up" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Move up">↑</button>
          <button class="btn ghost sm" name="action" value="down" <?= $i === count($projects) - 1 ? 'disabled' : '' ?> aria-label="Move down">↓</button>
          <a class="btn ghost sm" href="?edit=<?= h(urlencode($p['id'] ?? '')) ?>">Edit</a>
          <button class="btn red sm" name="action" value="delete" onclick="return confirm('Remove this project and its photos from the website?')">Remove</button>
        </form>
      </div></div>
  <?php endforeach; ?>
  </div>
  <h2>Change admin password</h2>
  <form class="grid card" method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= $c ?>"><input type="hidden" name="action" value="password">
    <label class="full">Current password<input type="password" name="old" required autocomplete="current-password"></label>
    <label>New password (12+ characters)<input type="password" name="new" required minlength="12" autocomplete="new-password"></label>
    <label>New password again<input type="password" name="new2" required minlength="12" autocomplete="new-password"></label>
    <div class="full"><button class="btn ghost">Change password</button></div>
  </form>
<?php endif; ?>
</div></body></html>
<?php }
