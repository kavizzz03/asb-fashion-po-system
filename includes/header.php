<?php
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . '/');
}
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

// Guarantee user must be logged in to view ANY page using header.php
requireLogin();

// Populate the correct logged-in user information
$navUser = $_SESSION['username'] ?? '';
$navRole = getUserRole();
if (empty($navUser) && !empty($_SESSION['user_id'])) {
    $navConn = getConnection();
    $uStmt = $navConn->prepare("SELECT username, role FROM po_users WHERE id = ?");
    $uStmt->bind_param("i", $_SESSION['user_id']);
    $uStmt->execute();
    $uRow = $uStmt->get_result()->fetch_assoc();
    $uStmt->close();
    if ($uRow) {
        $_SESSION['username'] = $uRow['username'];
        $_SESSION['role']     = $uRow['role'];
        $navUser = $uRow['username'];
        $navRole = $uRow['role'];
    }
}
if (empty($navUser)) {
    $navUser = 'User';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($page_title ?? 'PO Management System'); ?></title>
    <meta name="theme-color" content="#4a0000">
    <link rel="icon" type="image/png" href="logo.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <style>
        /* ===== COMPLETE CSS (no sidebar) ===== */
        :root {
            --asb-red: #b91c1c;
            --asb-red-deep: #7f1d1d;
            --asb-night: #3d0000;
            --asb-blush: #fef2f2;
            --asb-ink: #1a1214;
            --asb-line: #fecaca;
            --asb-display: 'Sora', system-ui, sans-serif;
            --asb-body: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            --asb-ease: cubic-bezier(.16, 1, .3, 1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
        body {
            font-family: var(--asb-body);
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            background:
                radial-gradient(900px 500px at 100% -10%, rgba(185, 28, 28, .08), transparent 60%),
                radial-gradient(700px 420px at -10% 110%, rgba(127, 29, 29, .07), transparent 60%),
                #fef6f6;
        }
        a { text-decoration: none; color: #3498db; }
        a:hover { color: #2980b9; }

        /* page-load progress line */
        body::before {
            content: ''; position: fixed; left: 0; top: 0; height: 3px; width: 100%; z-index: 9999;
            background: linear-gradient(90deg, #ff6b6b, #b91c1c, #7f1d1d);
            transform-origin: left; animation: asbLoad 1.1s var(--asb-ease) forwards;
            pointer-events: none;
        }
        @keyframes asbLoad { 0% { transform: scaleX(0); opacity: 1; } 70% { transform: scaleX(1); opacity: 1; } 100% { transform: scaleX(1); opacity: 0; } }

        /* Login Page */
        .login-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%); padding: 20px; }
        .login-container { background: white; border-radius: 20px; padding: 50px 40px; width: 100%; max-width: 420px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.6s ease; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .login-logo { text-align: center; margin-bottom: 30px; }
        .login-logo .icon { font-size: 50px; background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%); width: 80px; height: 80px; line-height: 80px; border-radius: 50%; display: inline-block; color: white; }
        .login-logo h2 { margin-top: 15px; color: #2c3e50; font-weight: 700; }
        .login-logo p { color: #7f8c8d; font-size: 14px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: 14px; }
        .form-control { width: 100%; padding: 12px 15px; border: 2px solid #e8ecf1; border-radius: 10px; font-size: 14px; transition: border-color 0.3s; outline: none; background: white; font-family: inherit; }
        .form-control:focus { border-color: #b91c1c; box-shadow: 0 0 0 3px rgba(185,28,28,0.1); }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 15px center; padding-right: 40px; }

        .btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-align: center; font-family: inherit; }
        .btn-primary { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%); color: white; width: 100%; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(185,28,28,0.4); }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #d68910; }
        .btn-info { background: #3498db; color: white; }
        .btn-info:hover { background: #2e86c1; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-lg { padding: 15px 30px; font-size: 16px; }
        .btn-outline-light { background: transparent; border: 2px solid rgba(255,255,255,0.5); color: white; }
        .btn-outline-light:hover { background: white; color: #b91c1c; border-color: white; }

        .demo-credentials { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-top: 20px; font-size: 13px; border: 1px solid #e8ecf1; }
        .demo-credentials strong { color: #2c3e50; }

        .alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; border: 1px solid transparent; }
        .alert-success { background: #d4edda; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-info { background: #d1ecf1; border-color: #bee5eb; color: #0c5460; }
        .alert-warning { background: #fff3cd; border-color: #ffeaa7; color: #856404; }

        /* Layout – no sidebar */
        .app-container { display: block; padding: 0; }
        .main-content { padding: 18px 30px 30px; width: 100%; min-height: calc(100vh - 20px); }

        /* ===== TOP NAVIGATION – matches the dashboard (white card + deep red) ===== */
        .top-nav {
            position: sticky; top: 10px; z-index: 900;
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            margin-bottom: 22px; padding: 10px 12px 10px 14px; overflow: hidden;
            border-radius: 24px; color: var(--asb-ink);
            background: #fff; border: 1px solid #eddedf;
            box-shadow: 0 14px 34px rgba(139, 0, 0, .08);
            animation: asbDrop .7s var(--asb-ease) both;
        }
        @keyframes asbDrop { from { opacity: 0; transform: translateY(-16px); } to { opacity: 1; transform: none; } }
        .top-nav::before { /* deep red accent on the left edge */
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 6px;
            background: linear-gradient(180deg, #3d0000, #8b0000 55%, #b71c1c);
        }

        .top-nav .nav-left { display: flex; align-items: center; gap: 12px; min-width: 0; padding-left: 6px; }
        .top-nav .nav-left .brand-icon {
            flex: none; display: flex; align-items: center; justify-content: center;
            height: 46px; width: auto; max-width: 150px; padding: 6px 12px; overflow: hidden;
            border-radius: 14px; background: #fff3f3; border: 1px solid #f6d3d3;
            color: var(--asb-red); font: 800 1rem var(--asb-display);
        }
        .top-nav .nav-left .brand-icon img { display: block; height: 32px; width: auto; max-width: 120px; object-fit: contain; }
        .top-nav .nav-left .brand-text { display: flex; flex-direction: column; min-width: 0; }
        .top-nav .nav-left .brand-text .title { font: 800 1rem/1.15 var(--asb-display); letter-spacing: -.01em; color: #1a1214; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .top-nav .nav-left .brand-text .subtitle { margin-top: 3px; font-size: .64rem; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: var(--asb-red); white-space: nowrap; }
        .top-nav .nav-left .brand-text .subtitle i { margin-right: 5px; }

        .top-nav .nav-right { display: flex; align-items: center; gap: 8px; flex: none; }
        .top-nav .nav-right .btn-outline-light {
            display: inline-flex; align-items: center; gap: 8px; padding: .6rem 1.1rem; border-radius: 999px;
            background: #fff; border: 1.5px solid #f0d0d0; color: #1a1214; white-space: nowrap;
            font-weight: 700; font-size: 13px; transition: background .25s, color .25s, border-color .25s, transform .3s var(--asb-ease);
        }
        .top-nav .nav-right .btn-outline-light i { color: var(--asb-red); }
        .top-nav .nav-right .btn-outline-light:hover { background: #fff3f3; border-color: var(--asb-red); color: var(--asb-red); transform: translateY(-2px); }
        .top-nav .nav-right .btn-outline-light.is-active { background: linear-gradient(135deg, #8b0000, #3d0000); border-color: transparent; color: #fff; box-shadow: 0 10px 22px rgba(139, 0, 0, .3); }
        .top-nav .nav-right .btn-outline-light.is-active i { color: #ffcdd2; }
        .top-nav .nav-right .user-badge {
            display: inline-flex; align-items: center; gap: 10px; padding: 4px 16px 4px 4px; border-radius: 999px; white-space: nowrap;
            background: #fff3f3; border: 1px solid #f6d3d3; color: #7f1d1d; font-size: 13px; font-weight: 700; backdrop-filter: none;
        }
        .top-nav .user-badge .av { position: relative; flex: none; width: 34px; height: 34px; display: grid; place-items: center; border-radius: 50%; background: linear-gradient(145deg, #8b0000, #b71c1c); color: #fff; font: 800 .85rem var(--asb-display); }
        .top-nav .user-badge .av::after { content: ''; position: absolute; right: -2px; bottom: -2px; width: 11px; height: 11px; border-radius: 50%; background: #22c55e; border: 2px solid #fff3f3; }
        .top-nav .user-badge .nm { max-width: 130px; overflow: hidden; text-overflow: ellipsis; }
        .top-nav .nav-right .btn-danger-sm {
            display: inline-flex; align-items: center; gap: 8px; padding: .6rem 1.1rem; border-radius: 999px; white-space: nowrap;
            background: linear-gradient(135deg, #8b0000, #b71c1c); border: 1px solid transparent; color: #fff;
            font-weight: 700; font-size: 13px; box-shadow: 0 8px 18px rgba(185, 28, 28, .25);
            transition: transform .3s var(--asb-ease), box-shadow .3s;
        }
        .top-nav .nav-right .btn-danger-sm:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(185, 28, 28, .38); color: #fff; }

        .card { background: white; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; overflow: hidden; }
        .card-header { padding: 20px 25px; background: white; border-bottom: 1px solid #e8ecf1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .card-header h5 { margin: 0; font-weight: 600; color: #2c3e50; }
        .card-body { padding: 25px; }

        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.3s; border-left: 4px solid #b91c1c; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .stat-label { color: #7f8c8d; font-size: 14px; font-weight: 500; }
        .stat-card .stat-number { font-size: 28px; font-weight: 700; margin: 5px 0; color: #b91c1c; }
        .stat-card .stat-icon { float: right; font-size: 35px; opacity: 0.2; color: #b91c1c; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        table thead { background: #fef2f2; }
        table th { padding: 12px 15px; text-align: left; font-weight: 600; color: #2c3e50; border-bottom: 2px solid #fecaca; white-space: nowrap; }
        table td { padding: 12px 15px; border-bottom: 1px solid #fecaca; vertical-align: middle; }
        table tbody tr:hover { background: #fef2f2; }

        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-pending { background: #f39c12; color: white; }
        .badge-received { background: #3498db; color: white; }
        .badge-completed { background: #27ae60; color: white; }
        .badge-cancelled { background: #e74c3c; color: white; }

        .item-entry { background: #f8f9fa; border: 2px solid #fecaca; border-radius: 12px; padding: 20px; margin-bottom: 15px; transition: border-color 0.3s; }
        .item-entry:hover { border-color: #b91c1c; }
        .item-entry .item-row { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 15px; align-items: end; }
        .item-entry .allocation-row { margin-top: 15px; padding-top: 15px; border-top: 1px solid #fecaca; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }

        .welcome-banner { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%); color: white; padding: 30px; border-radius: 15px; margin-bottom: 30px; }
        .welcome-banner h2 { font-weight: 700; margin-bottom: 5px; }
        .welcome-banner p { opacity: 0.9; margin: 0; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { color: #2c3e50; font-weight: 700; }

        .filter-bar { background: white; padding: 20px; border-radius: 15px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .filter-bar .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }

        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; flex-wrap: wrap; }
        .pagination a, .pagination span { display: inline-block; padding: 8px 14px; border: 1px solid #fecaca; border-radius: 8px; color: #333; transition: all 0.3s; }
        .pagination a:hover { background: #b91c1c; color: white; border-color: #b91c1c; }
        .pagination .active { background: #b91c1c; color: white; border-color: #b91c1c; }
        .pagination .disabled { opacity: 0.5; cursor: not-allowed; }

        .fade-in { animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 768px) {
            .main-content { padding: 12px 12px 24px; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .item-entry .item-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: stretch; }
            .filter-bar .filter-row { grid-template-columns: 1fr; }
            .top-nav { top: 8px; padding: 8px 10px; border-radius: 18px; margin-bottom: 18px; }
            .top-nav .nav-left { gap: 10px; }
            .top-nav .nav-left .brand-icon { height: 40px; min-width: 40px; padding: 5px 9px; border-radius: 12px; }
            .top-nav .nav-left .brand-icon img { height: 26px; max-width: 90px; }
            .top-nav .nav-left .brand-text .title { font-size: .86rem; max-width: 150px; }
            .top-nav .nav-left .brand-text .subtitle { font-size: .56rem; letter-spacing: 1px; }
            .top-nav .nav-right { gap: 6px; }
            .top-nav .nav-right .btn-outline-light span, .top-nav .nav-right .btn-danger-sm span, .top-nav .user-badge .nm { display: none; }
            .top-nav .nav-right .btn-outline-light, .top-nav .nav-right .btn-danger-sm { width: 40px; height: 40px; padding: 0; justify-content: center; }
            .top-nav .nav-right .user-badge { padding: 4px; }
        }
        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr; }
            .login-container { padding: 30px 20px; }
            .top-nav .nav-left .brand-text .subtitle { display: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
            html { scroll-behavior: auto; }
        }

        @media print {
            body::before { display: none; }
            .no-print, .top-nav { display: none !important; }
            .main-content { padding: 20px !important; }
            .card { box-shadow: none !important; border: 1px solid #ddd !important; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- MAIN CONTENT (no sidebar) -->
        <div class="main-content">
            <!-- Top Navigation Bar – Red & White theme -->
            <div class="top-nav no-print">
                <a href="dashboard.php" class="nav-left" style="text-decoration:none; color:inherit;" title="Return to Dashboard">
                    <div class="brand-icon"><img src="logo.png" alt="ASB logo" onerror="this.replaceWith(document.createTextNode('ASB'))"></div>
                    <div class="brand-text">
                        <span class="title">ASB Group Of Companies</span>
                        <span class="subtitle"><i class="fas fa-shopping-cart"></i> Purchase Order System</span>
                    </div>
                </a>
                <div class="nav-right">
                    <!-- Dashboard Button -->
                    <a href="dashboard.php" class="btn-outline-light<?php echo (isset($page) && $page === 'dashboard') ? ' is-active' : ''; ?>" title="Dashboard">
                        <i class="fas fa-gauge-high"></i> <span>Dashboard</span>
                    </a>
                    <!-- User Badge (Always Shows Correct Logged-in User) -->
                    <span class="user-badge" title="Signed in as <?php echo htmlspecialchars($navUser); ?> (<?php echo htmlspecialchars(ucfirst($navRole ?? 'user')); ?>)">
                        <span class="av"><?php echo htmlspecialchars(strtoupper(substr($navUser, 0, 1))); ?></span>
                        <span class="nm"><?php echo htmlspecialchars($navUser); ?></span>
                    </span>
                    <!-- Logout -->
                    <a href="logout.php" class="btn-danger-sm" title="Logout">
                        <i class="fas fa-right-from-bracket"></i> <span>Logout</span>
                    </a>
                </div>
            </div>
            <!-- END top-nav -->

            <!-- Page content starts here -->
            <!-- The individual page content will be inserted after this -->