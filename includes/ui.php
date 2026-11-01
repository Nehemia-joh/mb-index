<?php
/**
 * includes/ui.php — shared page chrome + helpers (navbar, flash messages, clean URLs)
 * Design language: "the protocol booklet" — cool paper ground, deep Nile-teal ink,
 * Besley display type, ruled surfaces. Semantic color is reserved for
 * clinical meaning (probability bands, mute, missing fields).
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

/** Favicon / touch-icon links (static/favicon.*), base-path aware. */
function gars_favicon_links(): void
{
    $b = base_url();
    echo '<link rel="icon" href="' . gars_e($b . '/static/favicon.ico') . '" sizes="32x32">' . "\n"
       . '<link rel="icon" href="' . gars_e($b . '/static/favicon.svg') . '" type="image/svg+xml">' . "\n"
       . '<link rel="apple-touch-icon" href="' . gars_e($b . '/static/apple-touch-icon.png') . '">' . "\n";
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
    $links = ['index' => 'New assessment'];
    if (!$user) {
        return ['login' => 'Sign in'];
    }
    $links['assessments'] = 'Records';
    if ($user['role'] === 'therapist') {
        $links['therapist'] = 'My dashboard';
    } else {
        $links[$user['role'] === 'it_admin' ? 'itadmin' : 'admin'] =
            $user['role'] === 'it_admin' ? 'IT admin' : 'Admin';
        $links['register'] = 'Create user';
    }
    return $links;
}

/** Color class for a probability of ASD: unlikely=on(green) probable=warn(amber) very likely=off(red). */
function gars_prob_class(?string $probability): string
{
    $p = strtolower(trim((string) $probability));
    if ($p === 'unlikely') return 'on';
    if ($p === 'probable') return 'warn';
    if ($p !== '') return 'off';
    return '';
}

/**
 * The design system: fonts, tokens and every shared component.
 * Printed once per document <head>.
 */
function gars_styles(): void
{
    ?><link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,500&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
/* ------------------------------- tokens ---------------------------------- */
:root{
  /* Maishabora Rehabilitation Centre brand (mmrc.co.tz) */
  --brand:#0B5CAF;         /* MMRC blue */
  --brand-deep:#094d94;    /* MMRC blue2 */
  --brand-soft:#eaf1fb;    /* MMRC warm */
  --gold:#FFBA19;          /* MMRC gold */
  --gold-soft:#ffe49a;     /* MMRC gold-lt */
  --paper:#f4f8ff;         /* MMRC cream */
  --surface:#ffffff;
  --ink:#2a2a2a;           /* MMRC text */
  --muted:#4d6585;         /* MMRC muted */
  --rule:#c8daf2;          /* MMRC sand */
  --rule-strong:#a8bfe3;
  --amber:#FFBA19;         /* probable / mute */
  --amber-soft:#fef3c7;
  --brick:#b3402e;         /* missing fields / very likely */
  --brick-soft:#f9eae6;
  --ok:#1f7a4d;            /* saved / unlikely */
  --ok-soft:#e8f3ec;
  --orange:#c05a28;        /* Level 2 */
  --red-deep:#a02c2c;      /* Level 3 */
  --display:'Cormorant Garamond',Georgia,serif;
  --text:'DM Sans',system-ui,sans-serif;
  --shadow:0 4px 24px rgba(11,92,175,.12);  /* MMRC shadow */
}
* { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body{
  font-family:var(--text); font-size:16px; line-height:1.6; color:var(--ink);
  background:var(--paper); min-height:100vh; -webkit-font-smoothing:antialiased;
}
:focus-visible{ outline:2px solid var(--brand); outline-offset:2px; border-radius:2px; }
::selection{ background:var(--gold); color:var(--brand); }
/* MMRC scrollbar */
::-webkit-scrollbar{ width:10px; height:10px; }
::-webkit-scrollbar-track{ background:var(--brand-soft); }
::-webkit-scrollbar-thumb{ background:var(--gold); border-radius:5px; }
@media (prefers-reduced-motion: reduce){
  *{ transition:none !important; animation:none !important; }
  html{ scroll-behavior:auto; }
}

/* ------------------------------ top bar ---------------------------------- */
.skip{ position:absolute; left:-9999px; top:0; background:var(--brand); color:#fff; padding:10px 16px; border-radius:0 0 3px 0; z-index:100; }
.skip:focus{ left:0; }
/* solid MMRC-blue bar with the gold wordmark, like mmrc.co.tz's navbar */
.topbar{ position:sticky; top:0; z-index:50; background:var(--brand); border-bottom:1px solid var(--brand-deep); box-shadow:var(--shadow); }
.topbar-in{ max-width:1180px; margin:0 auto; padding:10px 24px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.wordmark{ text-decoration:none; margin-right:auto; line-height:1.15; min-width:0; }
.wordmark b{ display:block; font-family:var(--display); font-weight:600; font-size:1.42rem; color:var(--gold); }
.wordmark span{ display:block; font-size:.72rem; color:rgba(255,255,255,.75); }
.topnav{ display:flex; align-items:center; gap:2px; flex-wrap:wrap; }
.topnav a{ font-size:.9rem; font-weight:500; color:rgba(255,255,255,.88); text-decoration:none; padding:8px 12px; border-radius:3px; }
.topnav a:hover{ background:rgba(255,186,25,.12); color:var(--gold); }
.topnav a.active{ background:rgba(255,186,25,.16); color:var(--gold); font-weight:600; }
.who{ display:flex; align-items:center; gap:10px; font-size:.9rem; color:rgba(255,255,255,.78); flex-wrap:wrap; }
.who .badge{ color:#fff; }
.who .out{ font-size:.88rem; font-weight:600; color:#fff; text-decoration:none; padding:7px 13px; border:1px solid rgba(255,255,255,.35); border-radius:3px; }
.who .out:hover{ border-color:var(--gold); color:var(--gold); }

/* ------------------------------ page frame -------------------------------- */
.wrap{ max-width:1180px; margin:0 auto; padding:30px 24px 80px; }
.wrap.narrow{ max-width:840px; }
.wrap.mid{ max-width:1000px; }
main:focus{ outline:none; }
.page-head{ padding:6px 0 24px; }
.page-head h1{ font-family:var(--display); font-weight:600; font-size:clamp(1.55rem, 1.15rem + 1.6vw, 2.15rem); line-height:1.12; letter-spacing:-.01em; color:var(--brand); }
.page-head .sub{ color:var(--muted); font-size:.98rem; margin-top:8px; max-width:44em; }
.section{ padding:26px 0; border-top:1px solid var(--rule); }
/* the short gold rule that underlines MMRC headings */
.section h2{ font-family:var(--display); font-weight:600; font-size:1.42rem; letter-spacing:-.01em; margin-bottom:16px; color:var(--brand); }
.section h2::after{ content:''; display:block; width:48px; height:2px; background:var(--gold); margin-top:8px; }
.sheet{ background:var(--surface); border:1px solid var(--rule); border-radius:4px; overflow:hidden; box-shadow:var(--shadow); }
.sheet > * + *{ border-top:1px solid var(--rule); }

/* ------------------------------ typography bits --------------------------- */
.muted{ color:var(--muted); }
.empty{ color:var(--muted); padding:26px 4px; }
.empty b{ color:var(--ink); }
a{ color:var(--brand); }
.a-link{ color:var(--brand); font-weight:500; text-decoration:none; }
.a-link:hover{ text-decoration:underline; }
.actions{ display:flex; gap:12px; margin:20px 0; flex-wrap:wrap; }

/* ------------------------------ figure strip ------------------------------ */
.stats{ display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1px; background:var(--rule); border:1px solid var(--rule); border-radius:4px; overflow:hidden; }
.stat{ background:var(--surface); padding:18px 20px; }
.stat .s-n{ display:block; font-family:var(--display); font-weight:700; font-size:2.05rem; line-height:1.1; font-variant-numeric:tabular-nums; color:var(--brand); }
.stat .s-l{ display:block; color:var(--muted); font-size:.86rem; margin-top:3px; }

/* ------------------------------ facts grid -------------------------------- */
.facts{ display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:0 28px; }
.fact{ padding:10px 0; border-bottom:1px solid var(--rule); }
.fact .f-k{ display:block; color:var(--muted); font-size:.82rem; }
.fact .f-v{ font-weight:600; font-size:.98rem; overflow-wrap:anywhere; }
.fact.off .f-v{ color:var(--muted); font-weight:500; }

/* ------------------------------ tables ------------------------------------ */
.tablewrap{ overflow-x:auto; border:1px solid var(--rule); border-radius:4px; background:var(--surface); }
table.data{ width:100%; border-collapse:collapse; font-size:.94rem; }
table.data th{ text-align:left; font-weight:600; font-size:.8rem; color:var(--muted); padding:10px 14px; border-bottom:1.5px solid var(--rule-strong); vertical-align:bottom; white-space:nowrap; }
table.data td{ padding:11px 14px; border-bottom:1px solid var(--rule); vertical-align:top; }
table.data tbody tr:last-child td{ border-bottom:none; }
table.data tbody tr:hover td{ background:var(--brand-soft); }
table.data th.n, table.data td.n{ text-align:right; font-variant-numeric:tabular-nums; }
table.data td.dim{ color:var(--muted); }
table.data td.dim small{ display:block; font-size:.8rem; }

/* ------------------------------ chips / badges ---------------------------- */
.badge, .chip{ display:inline-flex; align-items:center; gap:7px; font-size:.84rem; font-weight:500; line-height:1; white-space:nowrap; color:var(--ink); }
.badge::before{ content:''; width:8px; height:8px; border-radius:2px; background:var(--chipc,var(--muted)); flex:none; }
.badge.therapist{ --chipc:#0B5CAF; }
.badge.admin{ --chipc:#b7791f; }
.badge.it_admin{ --chipc:#41566e; }
.badge.warn{ --chipc:#b7791f; }
.badge.on{ --chipc:#1f7a4d; }
.badge.off{ --chipc:#b3402e; }

/* ------------------------------ buttons ----------------------------------- */
/* blue surface, gold text — MMRC's .btn-primary combination */
.btn{ display:inline-flex; align-items:center; justify-content:center; gap:8px; font-family:var(--text); font-size:.95rem; font-weight:600; line-height:1; padding:12px 20px; border-radius:3px; border:1px solid var(--brand); background:var(--brand); color:var(--gold); cursor:pointer; text-decoration:none; transition:background .15s, color .15s, border-color .15s; }
.btn:hover{ background:var(--brand-deep); border-color:var(--brand-deep); }
.btn:active{ transform:translateY(1px); }
.btn.ghost, .btn.green{ background:var(--surface); color:var(--brand); border-color:var(--brand); }
.btn.ghost:hover, .btn.green:hover{ background:var(--brand); color:var(--gold); }
.btn.red{ background:var(--brick); border-color:var(--brick); }
.btn.red:hover{ background:#8f2f22; border-color:#8f2f22; }
.btn.wide{ width:100%; }
.btn[disabled]{ opacity:.55; cursor:default; }

/* ------------------------------ notices ----------------------------------- */
.notice{ padding:13px 16px; border-radius:3px; border:1px solid var(--rule); border-left:3px solid var(--brand); background:var(--brand-soft); color:var(--ink); margin-bottom:20px; }
.notice a{ color:inherit; font-weight:600; }
.notice-error{ border-color:var(--brick-soft); border-left-color:var(--brick); background:var(--brick-soft); }
.notice-success{ border-color:var(--ok-soft); border-left-color:var(--ok); background:var(--ok-soft); }
.notice-amber{ border-color:var(--amber-soft); border-left-color:var(--amber); background:var(--amber-soft); }

/* ------------------------------ forms ------------------------------------- */
.form-row{ display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:14px; margin-bottom:14px; }
.form-row:last-child{ margin-bottom:0; }
.form-group{ min-width:0; }
.form-group label{ display:block; font-size:.88rem; font-weight:600; color:var(--ink); margin-bottom:6px; }
.form-group label .req{ color:var(--brick); font-weight:700; }
.form-group input, .form-group select{
  width:100%; padding:10px 13px; background:var(--brand-soft); border:1px solid var(--rule);
  border-radius:3px; font:inherit; font-size:.95rem; color:var(--ink);
  transition:border-color .15s, box-shadow .15s, background .15s;
}
.form-group input:focus, .form-group select:focus{ background:var(--surface); border-color:var(--brand); box-shadow:0 0 0 3px rgba(11,92,175,.13); outline:none; }
.form-group ::placeholder{ color:#90a4c4; }
.login-panel{ max-width:430px; margin:26px auto 0; }

/* ------------------------------ print -------------------------------------- */
@media print{
  .topbar,.skip,.formbar,.btn{ display:none !important; }
  body{ background:#fff; }
  .wrap{ padding:0; max-width:none; }
}
</style>
<?php
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
<?php gars_favicon_links(); ?>
<title><?php echo gars_e($title); ?></title>
<?php gars_styles(); ?>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
    <div class="topbar-in">
        <a class="wordmark" href="<?php echo gars_e(gars_url('index')); ?>">
            <b>Maisha Bora</b>
            <span>Autism Rating Scale, Third Edition</span>
        </a>
        <nav class="topnav" aria-label="Main">
            <?php foreach (gars_nav_links($user) as $route => $label): ?>
                <a href="<?php echo gars_url($route); ?>" class="<?php echo $route === $active ? 'active' : ''; ?>"><?php echo gars_e($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($user): ?>
            <div class="who">
                <span><?php echo gars_e($user['full_name']); ?></span>
                <span class="badge <?php echo gars_e($user['role']); ?>"><?php echo gars_e(gars_role_label($user['role'])); ?></span>
                <a class="out" href="<?php echo gars_url('logout'); ?>">Logout</a>
            </div>
        <?php endif; ?>
    </div>
</header>
<main id="main" class="wrap <?php echo $fullContainer ? 'mid' : 'narrow'; ?>" tabindex="-1">
<?php if ($flash = gars_flash()): ?>
    <div class="notice notice-<?php echo gars_e($flash['type']); ?>"><?php echo gars_e($flash['message']); ?></div>
<?php endif;
}

function gars_footer(): void
{
    ?></main>
</body>
</html><?php
}

/** Standalone topbar for pages that print their own <head>. */
function gars_navbar(string $active = ''): void
{
    $user = gars_current_user();
    ?><a class="skip" href="#main">Skip to content</a>
<header class="topbar">
    <div class="topbar-in">
        <a class="wordmark" href="<?php echo gars_e(gars_url('index')); ?>">
            <b>Maisha Bora</b>
            <span>Autism Rating Scale, Third Edition</span>
        </a>
        <nav class="topnav" aria-label="Main">
            <?php foreach (gars_nav_links($user) as $route => $label): ?>
                <a href="<?php echo gars_url($route); ?>" class="<?php echo $route === $active ? 'active' : ''; ?>"><?php echo gars_e($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($user): ?>
            <div class="who">
                <span><?php echo gars_e($user['full_name']); ?></span>
                <span class="badge <?php echo gars_e($user['role']); ?>"><?php echo gars_e(gars_role_label($user['role'])); ?></span>
                <a class="out" href="<?php echo gars_url('logout'); ?>">Logout</a>
            </div>
        <?php endif; ?>
    </div>
</header>
<?php
}