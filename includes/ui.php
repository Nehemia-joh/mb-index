<?php
/**
 * includes/ui.php — shared page chrome + helpers (navbar, flash messages, clean URLs)
 * Front-end styling follows the Maisha Bora palette (blue #0B5CAF / gold #FFBA19).
 */

if (!defined('GARS_APP_ROOT')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tracker.php';

/** Base URL of the project (works in subfolders and in document roots). */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base = rtrim($dir, '/');
    return $base;
}

/** Build a clean URL: gars_url('admin') -> /admin or /project/admin */
function gars_url(string $route = ''): string
{
    $base = base_url();
    return $route === '' ? ($base !== '' ? $base . '/' : '/') : $base . '/' . ltrim($route, '/');
}

function gars_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** One-line flash message store (works across redirects). */
function gars_flash(?string $message = null, string $type = 'success'): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if ($message !== null) {
        $_SESSION['gars_flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['gars_flash'] ?? null;
    unset($_SESSION['gars_flash']);
    return $flash;
}

function gars_nav_links(?array $user): array
{
    $links = ['index' => 'New Assessment'];
    if (!$user) {
        return ['login' => 'Login'];
    }
    $links['assessments'] = 'Records';
    if ($user['role'] === 'therapist') {
        $links['therapist'] = 'My Dashboard';
    } else {
        $links[$user['role'] === 'it_admin' ? 'itadmin' : 'admin'] =
            $user['role'] === 'it_admin' ? 'IT Admin' : 'Admin';
        $links['register'] = 'Create User';
    }
    return $links;
}

/** Page header. $active is the clean route name (without .php). */
function gars_header(string $title, string $active = '', bool $fullContainer = true): void
{
    $user = gars_current_user();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0B5CAF">
<title><?php echo gars_e($title); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --blue:#0B5CAF; --blue2:#094d94; --gold:#FFBA19; --gold-lt:#ffe49a;
  --cream:#f4f8ff; --warm:#eaf1fb; --sand:#c8daf2; --text:#2a2a2a; --muted:#4d6585;
  --serif:'Cormorant Garamond',Georgia,serif; --sans:'DM Sans',system-ui,sans-serif;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--sans); color: var(--text); background: var(--cream); min-height: 100vh; padding: 20px; line-height: 1.65; }
.topnav { max-width: 1160px; margin: 0 auto 16px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
          background: rgba(11,92,175,.97); border-radius: 3px; padding: 10px 14px; box-shadow: 0 2px 20px rgba(0,0,0,.18); }
.topnav .brand { font-family: var(--serif); font-size: 1.25rem; font-weight: 600; color: var(--gold); letter-spacing: .5px; margin-right: auto; }
.topnav a { font-size: .78rem; font-weight: 500; letter-spacing: .8px; text-transform: uppercase; color: rgba(255,255,255,.85);
            text-decoration: none; padding: 7px 12px; border-radius: 3px; transition: .3s; }
.topnav a:hover { color: var(--gold); background: rgba(255,186,25,.08); }
.topnav a.active { color: var(--gold); background: rgba(255,186,25,.15); }
.topnav .who { font-size: .8rem; color: rgba(255,255,255,.7); padding: 0 10px; }
.topnav .out { background: rgba(0,0,0,.18); }
.container { max-width: 1160px; margin: 0 auto; background: #ffffff; border-radius: 3px; border: 1px solid var(--sand);
             box-shadow: 0 4px 24px rgba(11,92,175,.12); overflow: hidden; }
.full { max-width: 1400px; }
.header { background: linear-gradient(135deg, var(--blue) 0%, var(--blue2) 100%); color: white; padding: 26px 30px; }
.header h1 { font-family: var(--serif); font-size: 1.9em; font-weight: 500; margin-bottom: 6px; }
.header p { color: rgba(255,186,25,.9); font-size: .8rem; letter-spacing: 2px; text-transform: uppercase; }
.section { padding: 24px 30px; border-bottom: 1px solid var(--sand); }
.section h2 { font-family: var(--serif); color: var(--blue); margin-bottom: 8px; display: inline-block; font-size: 1.55em; font-weight: 500; }
.section h2::after { content: ''; display: block; width: 48px; height: 2px; background: var(--gold); margin-top: 10px; }
.card { background: #ffffff; border: 1px solid var(--sand); border-radius: 3px; padding: 18px; }
.grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
table.data { width: 100%; border-collapse: collapse; margin-top: 12px; }
table.data th, table.data td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--sand); font-size: .92em; }
table.data th { background: var(--blue); color: rgba(255,255,255,.85); font-weight: 500; letter-spacing: .8px; text-transform: uppercase; font-size: .74em; }
table.data tr:hover td { background: var(--warm); }
.badge { display: inline-block; padding: 3px 10px; border-radius: 50px; font-size: .74em; font-weight: 500; letter-spacing: .5px; text-transform: uppercase; }
.badge.therapist { background: var(--warm);  color: var(--blue);  border: 1px solid var(--sand); }
.badge.admin     { background: #fff4d6; color: #92400e; border: 1px solid #fde68a; }
.badge.it_admin  { background: var(--blue); color: var(--gold-lt); border: 1px solid var(--blue2); }
.badge.on  { background: #d1fae5; color: #065f46; }
.badge.off { background: #fee2e2; color: #991b1b; }
.notice { padding: 12px 16px; border-radius: 3px; margin: 14px 0; border: 1px solid var(--sand); border-left: 3px solid var(--blue); background: var(--warm); color: var(--blue2); }
.notice-error   { border-color: #fca5a5; border-left-color: #dc2626; background: #fee2e2; color: #991b1b; }
.notice-success { border-color: #a7f3d0; border-left-color: #059669; background: #d1fae5; color: #065f46; }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 26px; font-family: var(--sans); font-size: .825rem;
       font-weight: 500; letter-spacing: .8px; text-transform: uppercase; cursor: pointer; text-decoration: none;
       color: var(--gold); background: var(--blue); border: 1.5px solid var(--blue); border-radius: 3px; transition: .3s cubic-bezier(.4,0,.2,1); }
.btn:hover { background: var(--blue2); border-color: var(--blue2); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(11,92,175,.25); }
.btn.green { background: var(--gold); color: var(--blue); border-color: var(--gold); }
.btn.green:hover { background: var(--gold-lt); border-color: var(--gold-lt); box-shadow: 0 6px 20px rgba(255,186,25,.35); }
.btn.red { background: #991b1b; color: #fff; border-color: #991b1b; }
.btn.red:hover { background: #7f1d1d; border-color: #7f1d1d; }
.a-link { color: var(--blue); text-decoration: none; font-weight: 500; }
.a-link:hover { color: var(--gold); }
.muted { color: var(--muted); font-size: .88em; }
.form-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 14px; }
.form-group { flex: 1; min-width: 200px; }
.form-group label { display: block; font-size: .72rem; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; font-weight: 500; }
.form-group label .req { color: #dc2626; }
.form-group input, .form-group select { width: 100%; padding: 11px 16px; background: var(--warm); border: 1px solid var(--sand);
       border-radius: 3px; font-family: var(--sans); font-size: .95em; color: var(--text); outline: none; transition: .3s; }
.form-group input:focus, .form-group select:focus { border-color: var(--blue); background: #fff; box-shadow: 0 0 0 3px rgba(11,92,175,.08); }
::selection { background: var(--gold); color: var(--blue); }
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--warm); }
::-webkit-scrollbar-thumb { background: var(--gold); border-radius: 3px; }
@media (max-width: 768px) { body { padding: 8px; } .section { padding: 16px; } .header { padding: 20px 16px; } }
</style>
</head>
<body>
<div class="topnav">
    <span class="brand"><?php echo gars_e(APP_NAME); ?></span>
    <?php foreach (gars_nav_links($user) as $route => $label): ?>
        <a href="<?php echo gars_url($route); ?>" class="<?php echo $route === $active ? 'active' : ''; ?>"><?php echo gars_e($label); ?></a>
    <?php endforeach; ?>
    <?php if ($user): ?>
        <span class="who"><?php echo gars_e($user['full_name']); ?> · <span class="badge <?php echo gars_e($user['role']); ?>"><?php echo gars_e(gars_role_label($user['role'])); ?></span></span>
        <a class="out" href="<?php echo gars_url('logout'); ?>">Logout</a>
    <?php endif; ?>
</div>
<div class="container <?php echo $fullContainer ? 'full' : ''; ?>">
<?php if ($flash = gars_flash()): ?>
    <div style="padding: 12px 16px 0;">
        <div class="notice notice-<?php echo gars_e($flash['type']); ?>"><?php echo gars_e($flash['message']); ?></div>
    </div>
<?php endif;
}

function gars_footer(): void
{
    ?></div>
</body>
</html><?php
}

/** Standalone lightweight navbar for pages that bring their own CSS. */
function gars_navbar(string $active = ''): void
{
    $user = gars_current_user();
    ?><div style="max-width:900px; margin:0 auto 14px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; background:rgba(11,92,175,.97); border-radius:3px; padding:10px 14px; box-shadow:0 2px 20px rgba(0,0,0,.18);">
        <span style="font-family:'Cormorant Garamond',Georgia,serif; color:#FFBA19; font-weight:600; font-size:1.2rem; margin-right:auto;"><?php echo gars_e(APP_NAME); ?></span>
        <?php foreach (gars_nav_links($user) as $route => $label): ?>
            <a href="<?php echo gars_url($route); ?>" style="color:rgba(255,255,255,.85);text-decoration:none;padding:7px 12px;border-radius:3px;font-size:.78rem;font-weight:500;letter-spacing:.8px;text-transform:uppercase;<?php echo $route === $active ? 'color:#FFBA19;background:rgba(255,186,25,.15);' : ''; ?>"><?php echo gars_e($label); ?></a>
        <?php endforeach; ?>
        <?php if ($user): ?>
            <span style="color:rgba(255,255,255,.7);font-size:.8rem;padding:0 10px;"><?php echo gars_e($user['full_name']); ?> · <?php echo gars_e(gars_role_label($user['role'])); ?></span>
            <a href="<?php echo gars_url('logout'); ?>" style="background:rgba(0,0,0,.18);color:rgba(255,255,255,.85);text-decoration:none;padding:7px 12px;border-radius:3px;font-size:.78rem;">Logout</a>
        <?php endif; ?>
    </div><?php
}
