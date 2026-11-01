<?php
// Maisha Bora Rating Form
// Route: /index — login is required; every visit of this page is tracked.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tracker.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/rating_form.php';
define('GARS_ROUTE', 'index');

$currentUser = gars_require_login();
gars_track_page('page_visit', 'Opened the assessment form');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0B5CAF">
    <?php gars_favicon_links(); ?>
    <title>Rating form</title>
    <?php gars_styles(); ?>
    <?php gars_rating_form_assets(); ?>
</head>
<body>
<?php gars_navbar('index'); ?>
<main class="wrap" id="main">
    <div class="page-head">
        <h1>Rating form</h1>
        <p class="sub">Fill in the identifying information, answer the mute question after the fourth subscale, then mark the items. Form data is stored as a record under your account.</p>
    </div>

<?php gars_rating_form_body(gars_url('score'), 'Score and save'); ?>
</body>
</html>
