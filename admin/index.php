<?php
declare(strict_types=1);
/**
 * Panel administracyjny PatrykLab: zapytania z formularza + zarządzanie realizacjami.
 * Pierwsze wejście → ustawienie hasła (zapisywane jako hash w api/_data/admin.json).
 */
require __DIR__ . '/../api/_bootstrap.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('plab_admin');
session_start();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function check_csrf(): void { if (!hash_equals(csrf(), $_POST['csrf'] ?? '')) { http_response_code(400); exit('Nieprawidłowy token.'); } }
function go(string $q = ''): void { header('Location: ./' . $q); exit; }

$cfg = store_read('admin', []);
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$action = $_POST['action'] ?? '';

// ---------- Konfiguracja hasła (pierwsze uruchomienie) ----------
if (empty($cfg['hash'])) {
    if ($action === 'setup') {
        check_csrf();
        $p = (string)($_POST['pass'] ?? '');
        if (mb_strlen($p) < 10 || $p !== ($_POST['pass2'] ?? '')) { $_SESSION['flash'] = 'Hasło musi mieć min. 10 znaków i być powtórzone identycznie.'; go(); }
        store_write('admin', ['hash' => password_hash($p, PASSWORD_DEFAULT)]);
        session_regenerate_id(true); $_SESSION['auth'] = true; go();
    }
    $view = 'setup';
}
// ---------- Logowanie ----------
elseif (empty($_SESSION['auth'])) {
    if ($action === 'login') {
        check_csrf();
        $fails = $_SESSION['fails'] ?? 0;
        if ($fails >= 5 && time() - ($_SESSION['fail_t'] ?? 0) < 900) { $_SESSION['flash'] = 'Zbyt wiele prób. Odczekaj 15 minut.'; go(); }
        if (password_verify((string)($_POST['pass'] ?? ''), $cfg['hash'])) {
            session_regenerate_id(true); $_SESSION = ['auth' => true]; go();
        }
        $_SESSION['fails'] = $fails + 1; $_SESSION['fail_t'] = time();
        usleep(600000); $_SESSION['flash'] = 'Nieprawidłowe hasło.'; go();
    }
    $view = 'login';
}
// ---------- Akcje zalogowanego ----------
else {
    if ($action) check_csrf();
    $leads = store_read('leads', []);
    $works = store_read('realizations', []);

    switch ($action) {
        case 'logout': session_destroy(); go();
        case 'lead_read':
            foreach ($leads as &$l) if ($l['id'] === $_POST['id']) $l['read'] = !$l['read'];
            unset($l); store_write('leads', $leads); go('?tab=leads');
        case 'lead_del':
            store_write('leads', array_values(array_filter($leads, fn($l) => $l['id'] !== $_POST['id']))); go('?tab=leads');
        case 'work_save':
            $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_POST['id'] ?: $_POST['title']))) ?: bin2hex(random_bytes(4));
            $item = [
                'id' => $id,
                'title' => clean($_POST['title'] ?? '', 120),
                'url' => filter_var($_POST['url'] ?? '', FILTER_VALIDATE_URL) ?: '#',
                'category' => clean($_POST['category'] ?? '', 60),
                'year' => clean($_POST['year'] ?? '', 10),
                'summary' => clean($_POST['summary'] ?? '', 600),
                'services' => clean($_POST['services'] ?? '', 120),
                'tags' => array_values(array_filter(array_map(fn($t) => clean($t, 40), explode(',', (string)($_POST['tags'] ?? ''))))),
                'image' => clean($_POST['image_current'] ?? '', 300),
                'hidden' => !empty($_POST['hidden']),
            ];
            if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']);
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'][$mime] ?? null;
                if ($ext && $_FILES['image']['size'] <= 8 * 1024 * 1024) {
                    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                    $fn = $id . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
                    move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . '/' . $fn);
                    $item['image'] = '/uploads/' . $fn;
                } else { $_SESSION['flash'] = 'Obraz musi być JPG/PNG/WEBP/AVIF do 8 MB.'; }
            }
            $found = false;
            foreach ($works as &$w) if ($w['id'] === ($_POST['id'] ?: null)) { $w = $item; $found = true; }
            unset($w);
            if (!$found) $works[] = $item;
            store_write('realizations', array_values($works));
            $_SESSION['flash'] = $_SESSION['flash'] ?? 'Zapisano realizację.';
            go('?tab=works');
        case 'work_del':
            store_write('realizations', array_values(array_filter($works, fn($w) => $w['id'] !== $_POST['id']))); go('?tab=works');
        case 'work_move':
            $i = array_search($_POST['id'], array_column($works, 'id'), true);
            $j = $i + ((int)$_POST['dir']);
            if ($i !== false && isset($works[$j])) { [$works[$i], $works[$j]] = [$works[$j], $works[$i]]; store_write('realizations', $works); }
            go('?tab=works');
    }
    $view = 'app';
    $tab = $_GET['tab'] ?? 'leads';
    $edit = null;
    if (isset($_GET['edit'])) foreach ($works as $w) if ($w['id'] === $_GET['edit']) $edit = $w;
    $unread = count(array_filter($leads, fn($l) => empty($l['read'])));
}
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Panel – PatrykLab</title>
<style>
:root{--bg:#070707;--b2:#111;--line:#232323;--t:#f3f0ea;--m:#999;--or:#9b30ff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--t);font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
a{color:var(--or)}.w{max-width:1100px;margin:0 auto;padding:24px 16px}
.logo{font-weight:800;font-size:22px;letter-spacing:-.03em}.logo span{background:var(--or);color:#fff;border-radius:6px;padding:0 6px;margin-left:3px}
header{display:flex;justify-content:space-between;align-items:center;gap:16px;border-bottom:1px solid var(--line);padding-bottom:18px;margin-bottom:24px;flex-wrap:wrap}
nav a{padding:8px 16px;border-radius:99px;text-decoration:none;color:var(--t);border:1px solid var(--line);margin-right:6px}nav a.on{background:var(--or);color:#fff;border-color:var(--or)}
.card{background:var(--b2);border:1px solid var(--line);border-radius:16px;padding:20px;margin-bottom:14px}
.card.new{border-color:var(--or)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.sp{flex:1}
input,textarea,select{width:100%;background:#0b0b0b;border:1px solid var(--line);border-radius:10px;color:var(--t);padding:11px 13px;font:inherit}
input:focus,textarea:focus{outline:none;border-color:var(--or)}
label{display:block;font-size:13px;color:var(--m);margin:12px 0 5px}
button,.btn{background:var(--or);color:#fff;border:0;border-radius:99px;padding:10px 18px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-size:14px}
.ghost{background:none;border:1px solid var(--line);color:var(--t)}.del{background:#3a1010;color:#ffb3b3}
.m{color:var(--m);font-size:13px}.flash{background:#1d0a33;border:1px solid var(--or);padding:12px 16px;border-radius:12px;margin-bottom:18px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}@media(max-width:700px){.grid{grid-template-columns:1fr}}
.thumb{width:84px;height:62px;object-fit:cover;border-radius:8px;background:#222}
form.inline{display:inline}.pill{font-size:12px;border:1px solid var(--line);padding:2px 10px;border-radius:99px;margin-right:4px}
.login{max-width:380px;margin:12vh auto}
</style></head><body><div class="w">
<?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>

<?php if ($view === 'setup' || $view === 'login'): ?>
  <div class="login card">
    <div class="logo">Patryk<span>Lab</span></div>
    <p class="m"><?= $view === 'setup' ? 'Pierwsze uruchomienie: ustaw hasło administratora (min. 10 znaków).' : 'Zaloguj się do panelu.' ?></p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="<?= $view ?>">
      <label>Hasło</label><input type="password" name="pass" required autofocus autocomplete="<?= $view === 'setup' ? 'new-password' : 'current-password' ?>">
      <?php if ($view === 'setup'): ?><label>Powtórz hasło</label><input type="password" name="pass2" required autocomplete="new-password"><?php endif; ?>
      <p><button><?= $view === 'setup' ? 'Zapisz hasło' : 'Zaloguj' ?></button></p>
    </form>
  </div>
<?php else: ?>
  <header>
    <div class="logo">Patryk<span>Lab</span> <span style="background:none;color:var(--m);font-weight:400;font-size:14px">panel</span></div>
    <nav>
      <a href="?tab=leads" class="<?= $tab === 'leads' ? 'on' : '' ?>">Zapytania<?= $unread ? " ($unread)" : '' ?></a>
      <a href="?tab=works" class="<?= $tab === 'works' ? 'on' : '' ?>">Realizacje</a>
      <a href="/" target="_blank">Strona ↗</a>
    </nav>
    <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button name="action" value="logout" class="ghost">Wyloguj</button></form>
  </header>

  <?php if ($tab === 'leads'): ?>
    <?php if (!$leads): ?><p class="m">Brak zapytań. Pojawią się tu wiadomości z formularza kontaktowego.</p><?php endif; ?>
    <?php foreach ($leads as $l): ?>
      <div class="card <?= empty($l['read']) ? 'new' : '' ?>">
        <div class="row">
          <strong><?= e($l['name']) ?></strong><?php if ($l['company']): ?><span class="m">· <?= e($l['company']) ?></span><?php endif; ?>
          <span class="sp"></span><span class="m"><?= e(date('d.m.Y H:i', strtotime($l['date']))) ?></span>
        </div>
        <div class="m" style="margin:6px 0"><a href="mailto:<?= e($l['email']) ?>"><?= e($l['email']) ?></a><?= $l['phone'] ? ' · <a href="tel:' . e($l['phone']) . '">' . e($l['phone']) . '</a>' : '' ?><?= $l['budget'] ? ' · budżet: ' . e($l['budget']) : '' ?></div>
        <div><?php foreach ($l['services'] as $s): ?><span class="pill"><?= e($s) ?></span><?php endforeach; ?></div>
        <p style="white-space:pre-wrap"><?= e($l['message']) ?></p>
        <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= e($l['id']) ?>">
          <button name="action" value="lead_read" class="ghost"><?= empty($l['read']) ? 'Oznacz jako przeczytane' : 'Oznacz jako nowe' ?></button>
          <button name="action" value="lead_del" class="del" onclick="return confirm('Usunąć zapytanie?')">Usuń</button>
        </form>
      </div>
    <?php endforeach; ?>

  <?php else: ?>
    <div class="card">
      <h3 style="margin-top:0"><?= $edit ? 'Edytuj: ' . e($edit['title']) : 'Dodaj realizację' ?></h3>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="work_save">
        <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>"><input type="hidden" name="image_current" value="<?= e($edit['image'] ?? '') ?>">
        <div class="grid">
          <div><label>Nazwa *</label><input name="title" required value="<?= e($edit['title'] ?? '') ?>"></div>
          <div><label>Adres strony (URL)</label><input name="url" type="url" value="<?= e($edit['url'] ?? 'https://') ?>"></div>
          <div><label>Kategoria (np. Strony WWW, Aplikacje, Sklepy, Dron, 360°)</label><input name="category" value="<?= e($edit['category'] ?? '') ?>"></div>
          <div><label>Rok</label><input name="year" value="<?= e($edit['year'] ?? date('Y')) ?>"></div>
        </div>
        <label>Zakres usług na karcie (np. Strona WWW / SEO / Automatyzacja)</label><input name="services" value="<?= e($edit['services'] ?? '') ?>">
        <label>Opis</label><textarea name="summary" rows="3"><?= e($edit['summary'] ?? '') ?></textarea>
        <label>Tagi (oddzielone przecinkami)</label><input name="tags" value="<?= e(implode(', ', $edit['tags'] ?? [])) ?>">
        <label>Zrzut ekranu strony (pionowy, zalecane 900×1440 – górna część strony)</label><input type="file" name="image" accept="image/*">
        <?php if (!empty($edit['image'])): ?><p><img class="thumb" src="<?= e($edit['image']) ?>" alt=""></p><?php endif; ?>
        <label><input type="checkbox" name="hidden" value="1" style="width:auto" <?= !empty($edit['hidden']) ? 'checked' : '' ?>> Ukryj na stronie</label>
        <p class="row"><button>Zapisz</button><?php if ($edit): ?><a class="btn ghost" href="?tab=works">Anuluj</a><?php endif; ?></p>
      </form>
    </div>
    <?php foreach ($works as $i => $w): ?>
      <div class="card row">
        <?php if ($w['image']): ?><img class="thumb" src="<?= e($w['image']) ?>" alt=""><?php else: ?><div class="thumb"></div><?php endif; ?>
        <div class="sp"><strong><?= e($w['title']) ?></strong> <?= !empty($w['hidden']) ? '<span class="pill">ukryta</span>' : '' ?><div class="m"><?= e($w['category']) ?> · <?= e($w['year']) ?> · <a href="<?= e($w['url']) ?>" target="_blank" rel="noopener"><?= e($w['url']) ?></a></div></div>
        <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= e($w['id']) ?>">
          <button name="action" value="work_move" class="ghost" onclick="this.form.dir.value=-1" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
          <button name="action" value="work_move" class="ghost" onclick="this.form.dir.value=1" <?= $i === count($works) - 1 ? 'disabled' : '' ?>>↓</button>
          <input type="hidden" name="dir" value="0">
          <a class="btn ghost" href="?tab=works&edit=<?= e(urlencode($w['id'])) ?>">Edytuj</a>
          <button name="action" value="work_del" class="del" onclick="return confirm('Usunąć realizację?')">Usuń</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
</div></body></html>
