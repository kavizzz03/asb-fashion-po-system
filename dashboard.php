<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');

require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

requireLogin();

$page_title = 'ASB Group · Modern Dashboard';
$page = 'dashboard';

// Role Definitions
$userRole   = getUserRole();
$isAdmin    = isAdmin();
$isReceived = isReceivedUser();

// Set Sri Lanka Timezone explicitly
date_default_timezone_set('Asia/Colombo');
$currentDateLK = date('l, F j, Y');

// Fetch dynamic tabs mapped to user's role from database
$mainTabs = getTabsByRole($userRole, 'main');
$grnTabs  = getTabsByRole($userRole, 'grn');

// Stats Counters
$totalSuppliers = 0;
$totalPOs       = 0;
$pendingPOs     = 0;
$totalItems     = 0;
$totalUsers     = 0;
$totalLogins    = 0;

if (!$isReceived) {
    $totalSuppliers = getTotalSuppliers();
    $totalPOs       = getTotalPOs();
    $pendingPOs     = getPendingPOs();
    $totalItems     = getTotalItems();

    $conn = getConnection();
    try {
        $result = $conn->query("SELECT COUNT(*) FROM po_users");
        if ($result) {
            $totalUsers = (int) $result->fetch_row()[0];
            $result->free();
        }
    } catch (Exception $e) {}

    if ($isAdmin) {
        try {
            $totalLogins = countLoginLogs();
        } catch (Exception $e) {}
    }
}

// Hero slides (same links and wording as before; photos come from /login_img)
if (!$isReceived) {
    $slides = [
        ['Purchase Orders', 'Welcome to ASB Group', 'Streamline purchase orders, allocations, and supplier operations in one centralized portal.', 'create_po.php', 'fa-plus-circle', 'Create New PO', ['red-set', 'orange-front']],
        ['Suppliers', 'Supplier Control Center', 'Track vendor activities, modify account info, and manage inventory pipelines seamlessly.', 'supplier_module.php', 'fa-truck', 'View Suppliers', ['venom-teal', 'polo-blue']],
        ['Allocations', 'Smart Allocations', 'Distribute purchase order line items across branch networks with automated split presets.', 'allocate_po.php', 'fa-tasks', 'Go to Allocations', ['pink', 'tee-black']],
    ];
} else {
    $slides = [
        ['Goods Receipt', 'Goods Receipt Notes', 'Process incoming warehouse shipments and print automated GRN documentation.', 'receive_po_dashboard.php', 'fa-arrow-down', 'Receive PO', ['orange-back', 'tee-white']],
        ['Audit', 'GRN Print & Audit', 'Access historical receipt notes and generate official physical report copies.', 'view_pos.php', 'fa-print', 'Print GRN', ['crop-b', 'red-set']],
    ];
}

$stats = [];
if (!$isReceived) {
    $stats[] = ['fa-file-invoice', 'Total Purchase Orders', $totalPOs];
    $stats[] = ['fa-hourglass-half', 'Pending POs', $pendingPOs];
    $stats[] = ['fa-truck', 'Active Suppliers', $totalSuppliers];
    $stats[] = ['fa-boxes-stacked', 'PO Items', $totalItems];
    $stats[] = ['fa-users', 'Registered Users', $totalUsers];
    if ($isAdmin) $stats[] = ['fa-clock-rotate-left', 'Total Logins', $totalLogins];
}

$processedPOs = max(0, $totalPOs - $pendingPOs);
$pendingPct   = $totalPOs > 0 ? round(($pendingPOs / $totalPOs) * 100) : 0;

$userName = $_SESSION['username'] ?? 'User';
$initial  = strtoupper(substr($userName, 0, 1));

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    /* ===== ASB DASHBOARD · RED & WHITE (scoped to .dx) ===== */
    .dx {
        --red: #b71c1c;
        --red-deep: #8b0000;
        --red-night: #3d0000;
        --blush: #fff3f3;
        --ink: #1a1214;
        --muted: #7a6a6d;
        --line: #eddedf;
        --card: #ffffff;
        --glow: rgba(183, 28, 28, .16);
        --ease: cubic-bezier(.16, 1, .3, 1);
        --display: 'Sora', system-ui, sans-serif;
        --body: 'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-family: var(--body);
        color: var(--ink);
        padding: 22px 28px 36px;
        -webkit-font-smoothing: antialiased;
    }
    .dx *, .dx *::before, .dx *::after { box-sizing: border-box; }
    .dx a { text-decoration: none; color: inherit; }
    .dx a.dx-btn, .dx a.dx-btn:hover { color: var(--red); }
    .dx a.dx-btn i { color: var(--red); }

    /* reveal on scroll (only when JS is on) */
    .dx.dx-js .dx-rv { opacity: 0; transform: translateY(28px); transition: opacity .8s var(--ease), transform .8s var(--ease); transition-delay: var(--d, 0s); }
    .dx.dx-js .dx-rv.in { opacity: 1; transform: none; }

    /* ───── Greeting bar ───── */
    .dx-top {
        display: flex; align-items: center; justify-content: space-between; gap: 1.2rem;
        padding: 1.1rem 1.4rem; margin-bottom: 1.4rem;
        background: var(--card); border: 1px solid var(--line); border-radius: 26px;
        box-shadow: 0 14px 34px rgba(139, 0, 0, .06);
    }
    .dx-who { display: flex; align-items: center; gap: 1rem; min-width: 0; }
    .dx-avatar {
        position: relative; flex: none; width: 62px; height: 62px; display: grid; place-items: center;
        border-radius: 50%;
        background: linear-gradient(145deg, var(--red-deep), var(--red));
        color: #fff; font: 800 1.4rem var(--display);
        box-shadow: 0 0 0 5px var(--blush), 0 12px 24px var(--glow);
    }
    .dx-avatar::after { content: ''; position: absolute; right: 1px; bottom: 1px; width: 13px; height: 13px; border-radius: 50%; background: #22c55e; border: 3px solid #fff; }
    .dx-who small { display: block; font-size: .78rem; font-weight: 700; color: var(--muted); letter-spacing: .3px; }
    .dx-who h2 { font: 800 1.55rem/1.15 var(--display); letter-spacing: -.02em; overflow-wrap: anywhere; }
    .dx-role { display: inline-block; margin-top: .35rem; padding: .25rem .8rem; border-radius: 999px; background: var(--blush); color: var(--red); font-size: .72rem; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; }

    .dx-clock {
        flex: none; display: flex; align-items: center; gap: 1rem; padding: .85rem 1.3rem;
        border-radius: 20px; color: #fff; position: relative; overflow: hidden;
        background: linear-gradient(135deg, var(--red-deep), var(--red-night));
        box-shadow: 0 14px 30px rgba(139, 0, 0, .28);
    }
    .dx-clock::before { content: ''; position: absolute; right: -50px; top: -60px; width: 170px; height: 170px; border-radius: 50%; background: repeating-radial-gradient(circle, rgba(255, 255, 255, .1) 0 2px, transparent 2px 16px); }
    .dx-clock i.ic { position: relative; font-size: 1.5rem; color: #ffcdd2; animation: dx-pulse 2.4s ease-in-out infinite; }
    @keyframes dx-pulse { 50% { transform: scale(1.14); opacity: .85; } }
    .dx-clock .t { position: relative; text-align: right; }
    .dx-clock .time { font: 800 1.35rem 'Consolas', 'Courier New', monospace; letter-spacing: 1.5px; }
    .dx-clock .date { font-size: .74rem; font-weight: 700; color: #ffcdd2; margin-top: 2px; }
    .dx-clock .tz { font-size: .66rem; font-weight: 600; color: #ffb3b3; letter-spacing: .6px; text-transform: uppercase; }

    /* ───── Hero slider ───── */
    .dx-hero { position: relative; height: 330px; border-radius: 30px; overflow: hidden; margin-bottom: 1.6rem; color: #fff; background: var(--red-night); box-shadow: 0 24px 50px rgba(139, 0, 0, .24); isolation: isolate; }
    .dx-slide { position: absolute; inset: 0; display: flex; align-items: center; padding: 0 3rem; opacity: 0; visibility: hidden; transition: opacity .8s ease, visibility .8s; }
    .dx-slide.is-active { opacity: 1; visibility: visible; }
    .dx-slide-bg {
        position: absolute; inset: 0; z-index: -1;
        background:
            radial-gradient(circle at 85% 20%, rgba(255, 90, 90, .35), transparent 45%),
            linear-gradient(130deg, #1c0407 0%, var(--red-night) 35%, var(--red-deep) 72%, #b71c1c 100%);
    }
    .dx-slide-bg::before { content: ''; position: absolute; inset: 0; background-image: linear-gradient(rgba(255, 255, 255, .06) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, .06) 1px, transparent 1px); background-size: 44px 44px; -webkit-mask-image: radial-gradient(ellipse 70% 90% at 70% 40%, #000, transparent 75%); mask-image: radial-gradient(ellipse 70% 90% at 70% 40%, #000, transparent 75%); }
    .dx-slide-text { max-width: 540px; position: relative; z-index: 2; }
    .dx-slide-text > * { opacity: 0; transform: translateY(24px); }
    .dx-slide.is-active .dx-slide-text > * { animation: dx-up .9s var(--ease) forwards; }
    .dx-slide.is-active .dx-slide-text > :nth-child(2) { animation-delay: .12s; }
    .dx-slide.is-active .dx-slide-text > :nth-child(3) { animation-delay: .24s; }
    .dx-slide.is-active .dx-slide-text > :nth-child(4) { animation-delay: .36s; }
    @keyframes dx-up { to { opacity: 1; transform: none; } }
    .dx-eyebrow { display: inline-flex; align-items: center; gap: 8px; font-size: .7rem; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: #ffc9c9; }
    .dx-eyebrow::before { content: ''; width: 26px; height: 2px; background: #ff8a8a; }
    .dx-slide h2 { margin: .7rem 0 .6rem; font: 800 clamp(1.7rem, 3vw, 2.6rem)/1.08 var(--display); letter-spacing: -.03em; }
    .dx-slide p { font-size: .98rem; line-height: 1.65; color: #ffe0e0; max-width: 480px; }
    .dx-btn { display: inline-flex; align-items: center; gap: 10px; margin-top: 1.3rem; padding: .85rem 1.6rem; border-radius: 999px; background: #fff; color: var(--red); font-weight: 800; font-size: .88rem; box-shadow: 0 14px 28px rgba(0, 0, 0, .28); transition: transform .3s var(--ease), background .25s; }
    .dx-btn:hover { transform: translateY(-3px) scale(1.03); background: var(--blush); }
    .dx-btn i.ar { transition: transform .3s var(--ease); }
    .dx-btn:hover i.ar { transform: translateX(4px); }

    .dx-art { position: absolute; right: 4%; bottom: -30px; display: flex; align-items: flex-end; gap: 14px; z-index: 1; }
    .dx-art img { width: 150px; object-fit: cover; object-position: center 15%; border-radius: 999px 999px 20px 20px; border: 5px solid rgba(255, 255, 255, .92); box-shadow: 0 22px 40px rgba(0, 0, 0, .35); opacity: 0; transform: translateY(80px); }
    .dx-art img:nth-child(1) { height: 250px; }
    .dx-art img:nth-child(2) { height: 310px; }
    .dx-slide.is-active .dx-art img { animation: dx-art 1s var(--ease) forwards, dx-bob 7s ease-in-out 1s infinite; }
    .dx-slide.is-active .dx-art img:nth-child(2) { animation-delay: .15s, 1.2s; }
    @keyframes dx-art { to { opacity: 1; transform: none; } }
    @keyframes dx-bob { 50% { transform: translateY(-10px); } }

    .dx-dots { position: absolute; left: 3rem; bottom: 1.3rem; z-index: 5; display: flex; align-items: center; gap: 10px; }
    .dx-dot { width: 34px; height: 5px; padding: 0; border: 0; border-radius: 5px; background: rgba(255, 255, 255, .3); cursor: pointer; overflow: hidden; position: relative; }
    .dx-dot span { position: absolute; inset: 0; background: #fff; transform: scaleX(0); transform-origin: left; }
    .dx-dot.is-active span { animation: dx-fill 5s linear forwards; }
    .dx-hero.paused .dx-dot.is-active span { animation-play-state: paused; }
    @keyframes dx-fill { to { transform: scaleX(1); } }

    /* ───── Stats ───── */
    .dx-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1.1rem; margin-bottom: 2rem; }
    .dx-stat { position: relative; overflow: hidden; display: flex; align-items: center; gap: 1rem; padding: 1.2rem 1.2rem; background: var(--card); border: 1px solid var(--line); border-radius: 22px; box-shadow: 0 8px 22px rgba(139, 0, 0, .04); transition: transform .35s var(--ease), box-shadow .35s; }
    .dx-stat::after { content: ''; position: absolute; right: -34px; top: -34px; width: 110px; height: 110px; border-radius: 50%; background: var(--blush); transition: transform .5s var(--ease); }
    .dx-stat:hover { transform: translateY(-6px); box-shadow: 0 20px 38px rgba(139, 0, 0, .13); }
    .dx-stat:hover::after { transform: scale(1.4); }
    .dx-stat .ico { position: relative; z-index: 1; flex: none; width: 52px; height: 52px; display: grid; place-items: center; border-radius: 16px; background: linear-gradient(145deg, var(--red-deep), var(--red)); color: #fff; font-size: 1.05rem; box-shadow: 0 10px 20px var(--glow); }
    .dx-stat .tx { position: relative; z-index: 1; min-width: 0; }
    .dx-stat .num { font: 800 1.9rem/1.05 var(--display); letter-spacing: -.02em; color: var(--ink); font-variant-numeric: tabular-nums; }
    .dx-stat .lbl { margin-top: .3rem; font-size: .7rem; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; color: var(--muted); }


    /* ───── Overview: stats + pipeline ring ───── */
    .dx-overview { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 1.2rem; margin-bottom: 2rem; align-items: stretch; }
    .dx-overview .dx-stats { margin-bottom: 0; align-content: start; }
    .dx-ring-card { position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; padding: 1.4rem; border-radius: 26px; color: #fff; background: linear-gradient(150deg, #1c0407 0%, var(--red-night) 40%, var(--red-deep) 100%); box-shadow: 0 22px 44px rgba(139, 0, 0, .25); }
    .dx-ring-card::before { content: ''; position: absolute; right: -80px; top: -90px; width: 260px; height: 260px; border-radius: 50%; background: repeating-radial-gradient(circle, rgba(255, 255, 255, .09) 0 2px, transparent 2px 18px); animation: dx-spin 120s linear infinite; }
    @keyframes dx-spin { to { transform: rotate(360deg); } }
    .dx-ring-card h4 { position: relative; display: flex; align-items: center; gap: 10px; font: 800 1rem var(--display); }
    .dx-ring-card h4 i { color: #ff9a9a; }
    .dx-ring { position: relative; width: 170px; height: 170px; margin: 1rem auto; }
    .dx-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .dx-ring circle { fill: none; stroke-width: 13; }
    .dx-ring .bg { stroke: rgba(255, 255, 255, .16); }
    .dx-ring .fg { stroke: #fff; stroke-linecap: round; stroke-dasharray: 326.7; stroke-dashoffset: 326.7; transition: stroke-dashoffset 1.6s var(--ease) .3s; }
    .dx-ring .mid { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; }
    .dx-ring .mid strong { font: 800 2.2rem/1 var(--display); letter-spacing: -.02em; }
    .dx-ring .mid span { margin-top: 4px; font-size: .68rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #ffcdd2; }
    .dx-legend { position: relative; display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
    .dx-legend div { padding: .65rem .8rem; border-radius: 14px; background: rgba(255, 255, 255, .1); border: 1px solid rgba(255, 255, 255, .16); }
    .dx-legend b { display: block; font: 800 1.15rem var(--display); }
    .dx-legend small { display: flex; align-items: center; gap: 6px; margin-top: 2px; font-size: .66rem; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: #ffcdd2; }
    .dx-legend small i { width: 8px; height: 8px; border-radius: 50%; background: #fff; display: inline-block; }
    .dx-legend div:last-child small i { background: rgba(255, 255, 255, .35); }

    /* ───── Module search ───── */
    .dx-find { display: flex; align-items: center; gap: .8rem; padding: .35rem .5rem .35rem 1.1rem; margin-bottom: 1.2rem; background: var(--card); border: 1.5px solid var(--line); border-radius: 18px; box-shadow: 0 8px 22px rgba(139, 0, 0, .04); transition: border-color .3s, box-shadow .3s; }
    .dx-find:focus-within { border-color: var(--red); box-shadow: 0 0 0 4px var(--glow); }
    .dx-find i { color: var(--red); }
    .dx-find input { flex: 1; min-width: 0; height: 46px; border: 0; outline: none; background: transparent; font: 600 .95rem var(--body); color: var(--ink); }
    .dx-find .hint { padding: .35rem .7rem; border-radius: 10px; background: var(--blush); color: var(--muted); font-size: .7rem; font-weight: 800; white-space: nowrap; }
    .dx-empty { display: none; padding: 2rem; text-align: center; color: var(--muted); font-weight: 600; }
    .dx-empty i { display: block; margin-bottom: .6rem; font-size: 1.6rem; color: #e4c5c5; }
    .dx-card[hidden] { display: none; }

    /* card spotlight */
    .dx-card { background: radial-gradient(280px circle at var(--mx, -200px) var(--my, -200px), rgba(183, 28, 28, .08), transparent 62%), var(--card); }

    /* scan line on slides */
    .dx-slide-bg::after { content: ''; position: absolute; left: 0; right: 0; height: 120px; background: linear-gradient(180deg, transparent, rgba(255, 140, 140, .14), transparent); animation: dx-scan 6s linear infinite; }
    @keyframes dx-scan { from { top: -120px; } to { top: 100%; } }

    /* ───── Section titles ───── */
    .dx-sec { display: flex; align-items: center; gap: .8rem; margin: 0 0 1.1rem; font: 800 1.2rem var(--display); letter-spacing: -.02em; }
    .dx-sec i { width: 36px; height: 36px; display: grid; place-items: center; border-radius: 11px; background: var(--blush); color: var(--red); font-size: .9rem; }
    .dx-sec::after { content: ''; flex: 1; height: 1px; background: var(--line); }

    /* ───── Action cards ───── */
    .dx-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.2rem; }
    .dx-card {
        --c: var(--red);
        position: relative; overflow: hidden; display: flex; flex-direction: column; gap: .85rem;
        padding: 1.5rem 1.4rem 1.3rem; background: var(--card); border: 1px solid var(--line); border-radius: 24px;
        color: var(--ink); box-shadow: 0 8px 22px rgba(0, 0, 0, .03);
        transition: transform .4s var(--ease), box-shadow .4s, border-color .3s;
    }
    .dx-card::before { content: ''; position: absolute; left: 0; right: 0; top: 0; height: 5px; background: var(--c); }
    .dx-card::after { content: ''; position: absolute; right: -50px; bottom: -60px; width: 170px; height: 170px; border-radius: 50%; background: var(--c); opacity: .06; transition: transform .6s var(--ease), opacity .4s; }
    .dx-card:hover { transform: translateY(-8px); box-shadow: 0 26px 46px rgba(139, 0, 0, .14); border-color: rgba(183, 28, 28, .25); }
    .dx-card:hover::after { transform: scale(1.6); opacity: .1; }
    .dx-card .ico { position: relative; z-index: 1; width: 58px; height: 58px; display: grid; place-items: center; border-radius: 18px; font-size: 1.5rem; color: var(--c); background: var(--blush); background: color-mix(in srgb, var(--c) 12%, #fff); transition: transform .5s var(--ease); }
    .dx-card:hover .ico { transform: scale(1.12) rotate(-6deg); }
    .dx-card h3 { position: relative; z-index: 1; font: 800 1.05rem/1.25 var(--display); letter-spacing: -.01em; }
    .dx-card p { position: relative; z-index: 1; font-size: .82rem; line-height: 1.55; color: var(--muted); font-weight: 500; }
    .dx-card .go { position: relative; z-index: 1; margin-top: auto; display: inline-flex; align-items: center; gap: 8px; font-size: .78rem; font-weight: 800; color: var(--c); }
    .dx-card .go i { transition: transform .35s var(--ease); }
    .dx-card:hover .go i { transform: translateX(5px); }

    .dx-block { margin-top: 2.2rem; }
    .dx-main-grn { max-width: 1000px; margin: 0 auto; }
    .dx-main-grn .dx-sec { justify-content: center; font-size: 1.5rem; }
    .dx-main-grn .dx-sec::after { display: none; }

    /* ───── Responsive ───── */
    @media (max-width: 1100px) {
        .dx-overview { grid-template-columns: 1fr; }
        .dx-ring-card { flex-direction: row; align-items: center; gap: 1.2rem; flex-wrap: wrap; justify-content: center; }
        .dx-ring-card h4 { width: 100%; }
        .dx-ring { margin: 0; }
        .dx-legend { min-width: 200px; flex: 1; }
    }
    @media (max-width: 900px) {
        .dx-art img { width: 110px; }
        .dx-art img:nth-child(1) { height: 190px; }
        .dx-art img:nth-child(2) { height: 240px; }
        .dx-slide-text { max-width: 60%; }
    }
    @media (max-width: 768px) {
        .dx { padding: 16px 14px 28px; }
        .dx-top { flex-direction: column; align-items: stretch; padding: 1rem; border-radius: 22px; }
        .dx-who h2 { font-size: 1.3rem; }
        .dx-avatar { width: 52px; height: 52px; font-size: 1.2rem; }
        .dx-clock { justify-content: space-between; }
        .dx-hero { height: 300px; border-radius: 24px; }
        .dx-slide { padding: 0 1.4rem 1.6rem; align-items: flex-start; padding-top: 1.8rem; }
        .dx-slide p { display: none; }
        .dx-slide h2 { font-size: 1.6rem; }
        .dx-slide-text { max-width: 62%; }
        .dx-art { right: 2%; gap: 8px; }
        .dx-art img { width: 84px; border-width: 3px; }
        .dx-art img:nth-child(1) { height: 150px; }
        .dx-art img:nth-child(2) { height: 190px; }
        .dx-dots { left: 1.4rem; }
        .dx-stats { grid-template-columns: 1fr 1fr; gap: .8rem; }
        .dx-stat { flex-direction: column; align-items: flex-start; gap: .7rem; padding: 1rem; }
        .dx-stat .num { font-size: 1.55rem; }
        .dx-grid { grid-template-columns: 1fr 1fr; gap: .8rem; }
        .dx-card { padding: 1.2rem 1rem 1rem; border-radius: 20px; }
        .dx-card p { display: none; }
        .dx-find .hint { display: none; }
    }
    @media (max-width: 420px) {
        .dx-stats { grid-template-columns: 1fr; }
        .dx-grid { grid-template-columns: 1fr; }
        .dx-card p { display: block; }
        .dx-slide-text { max-width: 100%; }
        .dx-art { display: none; }
    }
    @media (prefers-reduced-motion: reduce) {
        .dx *, .dx *::before, .dx *::after { animation: none !important; transition: none !important; }
        .dx.dx-js .dx-rv, .dx-slide-text > *, .dx-art img { opacity: 1 !important; transform: none !important; }
        .dx-dot.is-active span { transform: scaleX(1); }
    }
</style>

<div class="dx" id="dx">
    <script>document.getElementById('dx').classList.add('dx-js');</script>

    <!-- ===== GREETING + LIVE SRI LANKA TIME ===== -->
    <div class="dx-top dx-rv">
        <div class="dx-who">
            <div class="dx-avatar"><?php echo htmlspecialchars($initial); ?></div>
            <div>
                <small id="dxGreet">Welcome back</small>
                <h2><?php echo htmlspecialchars($userName); ?></h2>
                <span class="dx-role"><?php echo htmlspecialchars($userRole); ?></span>
            </div>
        </div>
        <div class="dx-clock">
            <i class="fas fa-clock ic"></i>
            <div class="t">
                <div class="time" id="colomboClock">--:--:-- --</div>
                <div class="date"><?php echo htmlspecialchars($currentDateLK); ?></div>
                <div class="tz"><i class="fas fa-location-dot"></i> Colombo, Sri Lanka</div>
            </div>
        </div>
    </div>

    <!-- ===== HERO SLIDESHOW ===== -->
    <div class="dx-hero dx-rv" id="heroSlider" style="--d:.08s">
        <?php foreach ($slides as $i => $sl): ?>
            <div class="dx-slide<?php echo $i === 0 ? ' is-active' : ''; ?>">
                <div class="dx-slide-bg"></div>
                <div class="dx-slide-text">
                    <span class="dx-eyebrow"><?php echo htmlspecialchars($sl[0]); ?></span>
                    <h2><?php echo htmlspecialchars($sl[1]); ?></h2>
                    <p><?php echo htmlspecialchars($sl[2]); ?></p>
                    <a href="<?php echo htmlspecialchars($sl[3]); ?>" class="dx-btn"><i class="fas <?php echo htmlspecialchars($sl[4]); ?>"></i> <?php echo htmlspecialchars($sl[5]); ?> <i class="fas fa-arrow-right ar"></i></a>
                </div>
                <div class="dx-art" aria-hidden="true">
                    <?php foreach ($sl[6] as $img): ?>
                        <img src="login_img/<?php echo htmlspecialchars($img); ?>.jpg" alt="" decoding="async">
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="dx-dots" id="sliderDots">
            <?php foreach ($slides as $i => $sl): ?>
                <button class="dx-dot<?php echo $i === 0 ? ' is-active' : ''; ?>" data-index="<?php echo $i; ?>" aria-label="Slide <?php echo $i + 1; ?>"><span></span></button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$isReceived): ?>
        <!-- ===== KEY METRICS + PO PIPELINE ===== -->
        <div class="dx-overview">
            <div class="dx-stats">
                <?php foreach ($stats as $k => $st): ?>
                    <div class="dx-stat dx-rv" style="--d:<?php echo number_format($k * 0.07, 2); ?>s">
                        <div class="ico"><i class="fas <?php echo $st[0]; ?>"></i></div>
                        <div class="tx">
                            <div class="num" data-count="<?php echo (int) $st[2]; ?>"><?php echo number_format($st[2]); ?></div>
                            <div class="lbl"><?php echo htmlspecialchars($st[1]); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="dx-ring-card dx-rv" style="--d:.2s">
                <h4><i class="fas fa-diagram-project"></i> PO pipeline</h4>
                <div class="dx-ring" data-pct="<?php echo (int) $pendingPct; ?>">
                    <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="bg" cx="60" cy="60" r="52"></circle><circle class="fg" cx="60" cy="60" r="52"></circle></svg>
                    <div class="mid"><strong><span id="dxPct"><?php echo (int) $pendingPct; ?></span>%</strong><span>awaiting</span></div>
                </div>
                <div class="dx-legend">
                    <div><b><?php echo number_format($pendingPOs); ?></b><small><i></i> Pending</small></div>
                    <div><b><?php echo number_format($processedPOs); ?></b><small><i></i> Processed</small></div>
                </div>
            </div>
        </div>

        <!-- ===== MODULE SEARCH ===== -->
        <div class="dx-find dx-rv">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" id="dxFind" placeholder="Search modules and shortcuts…" autocomplete="off" aria-label="Search modules">
            <span class="hint">Press / to search</span>
        </div>

        <!-- ===== DYNAMIC MAIN TABS (FROM DATABASE) ===== -->
        <div class="dx-grid">
            <?php foreach ($mainTabs as $k => $tab): ?>
                <a href="<?php echo htmlspecialchars($tab['url']); ?>" class="dx-card dx-rv" data-search="<?php echo htmlspecialchars(strtolower($tab['title'] . ' ' . $tab['description'])); ?>" style="--c: <?php echo htmlspecialchars($tab['border_color']); ?>; --d:<?php echo number_format(($k % 4) * 0.08, 2); ?>s">
                    <div class="ico"><i class="<?php echo htmlspecialchars($tab['icon']); ?>"></i></div>
                    <h3><?php echo htmlspecialchars($tab['title']); ?></h3>
                    <p><?php echo htmlspecialchars($tab['description']); ?></p>
                    <span class="go">Open <i class="fas fa-arrow-right"></i></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ===== DYNAMIC GRN TABS (FROM DATABASE) ===== -->
        <?php if (!empty($grnTabs)): ?>
            <div class="dx-block">
                <h4 class="dx-sec dx-rv"><i class="fas fa-clipboard-list"></i> Goods Receipt Notes (GRN)</h4>
                <div class="dx-grid">
                    <?php foreach ($grnTabs as $k => $tab): ?>
                        <a href="<?php echo htmlspecialchars($tab['url']); ?>" class="dx-card dx-rv" data-search="<?php echo htmlspecialchars(strtolower($tab['title'] . ' ' . $tab['description'])); ?>" style="--c: <?php echo htmlspecialchars($tab['border_color']); ?>; --d:<?php echo number_format(($k % 4) * 0.08, 2); ?>s">
                            <div class="ico"><i class="<?php echo htmlspecialchars($tab['icon']); ?>"></i></div>
                            <h3><?php echo htmlspecialchars($tab['title']); ?></h3>
                            <p><?php echo htmlspecialchars($tab['description']); ?></p>
                            <span class="go">Open <i class="fas fa-arrow-right"></i></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ===== ONLY GRN SECTION FOR RECEIVED ROLE ===== -->
        <div class="dx-main-grn">
            <h2 class="dx-sec dx-rv"><i class="fas fa-clipboard-list"></i> Goods Receipt Notes</h2>
            <div class="dx-grid">
                <?php foreach ($grnTabs as $k => $tab): ?>
                    <a href="<?php echo htmlspecialchars($tab['url']); ?>" class="dx-card dx-rv" data-search="<?php echo htmlspecialchars(strtolower($tab['title'] . ' ' . $tab['description'])); ?>" style="--c: <?php echo htmlspecialchars($tab['border_color']); ?>; --d:<?php echo number_format(($k % 4) * 0.08, 2); ?>s">
                        <div class="ico"><i class="<?php echo htmlspecialchars($tab['icon']); ?>"></i></div>
                        <h3><?php echo htmlspecialchars($tab['title']); ?></h3>
                        <p><?php echo htmlspecialchars($tab['description']); ?></p>
                        <span class="go">Open <i class="fas fa-arrow-right"></i></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="dx-empty" id="dxEmpty"><i class="fas fa-face-frown-open"></i> No modules match your search.</div>
</div>

<!-- ===== REAL-TIME SRI LANKA CLOCK, SLIDER, REVEAL & COUNT-UP ===== -->
<script>
    // Live Real-Time Sri Lanka (Asia/Colombo) Clock Engine
    function updateColomboClock() {
        const options = { timeZone: 'Asia/Colombo', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
        const formatter = new Intl.DateTimeFormat('en-US', options);
        document.getElementById('colomboClock').textContent = formatter.format(new Date());
    }
    setInterval(updateColomboClock, 1000);
    updateColomboClock();

    // Hero Banner Slideshow (fade + progress bars)
    (function () {
        const hero = document.getElementById('heroSlider');
        if (!hero) return;
        const slides = hero.querySelectorAll('.dx-slide');
        const dots = hero.querySelectorAll('.dx-dot');
        const total = slides.length;
        let current = 0, timer = null;

        function go(index) {
            current = (index + total) % total;
            slides.forEach((s, i) => s.classList.toggle('is-active', i === current));
            dots.forEach((d, i) => {
                d.classList.remove('is-active');
                if (i === current) { void d.offsetWidth; d.classList.add('is-active'); }
            });
        }
        function start() { stop(); timer = setInterval(() => go(current + 1), 5000); hero.classList.remove('paused'); }
        function stop() { clearInterval(timer); }

        dots.forEach(d => d.addEventListener('click', function () { go(parseInt(this.dataset.index, 10)); start(); }));
        hero.addEventListener('mouseenter', () => { stop(); hero.classList.add('paused'); });
        hero.addEventListener('mouseleave', () => { go(current); start(); });
        if (total > 1) start();
    })();

    // Time-based greeting (Colombo time)
    (function () {
        const g = document.getElementById('dxGreet');
        if (!g) return;
        const h = parseInt(new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Colombo', hour: '2-digit', hour12: false }).format(new Date()), 10) % 24;
        g.textContent = h < 12 ? 'Good morning' : (h < 17 ? 'Good afternoon' : 'Good evening');
    })();

    // Pipeline ring
    (function () {
        const ring = document.querySelector('.dx-ring');
        if (!ring) return;
        const pct = parseInt(ring.dataset.pct, 10) || 0;
        const fg = ring.querySelector('.fg');
        setTimeout(() => { fg.style.strokeDashoffset = 326.7 * (1 - pct / 100); }, 250);
    })();

    // Module search (press / to focus) + card spotlight
    (function () {
        const input = document.getElementById('dxFind');
        const cards = document.querySelectorAll('.dx-card[data-search]');
        const empty = document.getElementById('dxEmpty');
        if (input) {
            input.addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                let shown = 0;
                cards.forEach(c => { const m = !q || c.dataset.search.indexOf(q) !== -1; c.hidden = !m; if (m) shown++; });
                if (empty) empty.style.display = shown ? 'none' : 'block';
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); input.focus(); }
                if (e.key === 'Escape' && document.activeElement === input) { input.value = ''; input.dispatchEvent(new Event('input')); input.blur(); }
            });
        }
        cards.forEach(c => c.addEventListener('pointermove', e => {
            const r = c.getBoundingClientRect();
            c.style.setProperty('--mx', (e.clientX - r.left) + 'px');
            c.style.setProperty('--my', (e.clientY - r.top) + 'px');
        }));
    })();

    // Scroll reveal + number count-up
    (function () {
        const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const items = document.querySelectorAll('.dx-rv');

        function countUp(el) {
            const to = parseInt(el.dataset.count, 10) || 0;
            if (calm || to === 0) return;
            const t0 = performance.now(), dur = 1200;
            (function step(t) {
                const p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 4);
                el.textContent = Math.round(to * e).toLocaleString('en-US');
                if (p < 1) requestAnimationFrame(step);
            })(t0);
        }

        if (!('IntersectionObserver' in window)) { items.forEach(i => i.classList.add('in')); return; }
        const io = new IntersectionObserver((entries) => {
            entries.forEach(en => {
                if (!en.isIntersecting) return;
                en.target.classList.add('in');
                const n = en.target.querySelector('[data-count]');
                if (n) countUp(n);
                io.unobserve(en.target);
            });
        }, { threshold: .12 });
        items.forEach(i => io.observe(i));
    })();
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>