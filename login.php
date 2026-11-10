<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

startSession();

// Redirect to dashboard if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        // Authenticate via functions.php (handles Bcrypt verification & plain text migration)
        $user = authenticate($username, $password);

        if ($user) {
            // Set up active user session
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            // Update last_login timestamp in DB
            $conn = getConnection();
            $updateStmt = $conn->prepare("UPDATE po_users SET last_login = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $user['id']);
            $updateStmt->execute();
            $updateStmt->close();

            // Log entry into audit table
            logLogin($user['id']);

            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

// Decorative ledger grid (fixed seed so the pattern is the same on every load)
mt_srand(2026);
$cells = [];
for ($i = 0; $i < 140; $i++) {
    $cells[] = [
        'lit'   => mt_rand(1, 100) <= 15,
        'delay' => number_format(mt_rand(0, 90) / 10, 1),
        'dur'   => number_format(3 + mt_rand(0, 40) / 10, 1),
    ];
}
// Barcode bars for the scanner card
mt_srand(77);
$bars = [];
for ($i = 0; $i < 38; $i++) { $bars[] = mt_rand(1, 4); }

// Photo columns (files live in /login_img)
$lookbook = [
    ['pink', 'orange-front', 'tee-white'],
    ['red-set', 'tee-black', 'venom-teal'],
    ['orange-back', 'polo-blue', 'crop-b'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ASB Group – Purchase Order System | Login</title>
    <meta name="description" content="Secure sign-in for the ASB Group of Companies Purchase Order System.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#3d0000">
    <link rel="icon" type="image/png" href="logo.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <style>
        :root {
            --red: #b71c1c;
            --red-deep: #8b0000;
            --red-night: #3d0000;
            --ink: #1a1214;
            --muted: #7a6a6d;
            --line: #eddedf;
            --blush: #fff3f3;
            --paper: #ffffff;
            --glow: rgba(183, 28, 28, .18);
            --ease: cubic-bezier(.16, 1, .3, 1);
            --display: 'Sora', system-ui, sans-serif;
            --body: 'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { font-family: var(--body); color: var(--ink); background: var(--paper); min-height: 100vh; min-height: 100svh; overflow-x: hidden; -webkit-font-smoothing: antialiased; }
        a { color: inherit; text-decoration: none; }
        :focus-visible { outline: 3px solid var(--red); outline-offset: 3px; }

        .shell { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(430px, .85fr); min-height: 100vh; min-height: 100svh; }

        /* ═════════ Left stage ═════════ */
        .stage {
            position: sticky; top: 0; height: 100vh; height: 100svh; overflow: hidden; isolation: isolate; color: #fff;
            display: flex; flex-direction: column; justify-content: space-between; padding: 2.5rem 3.25rem;
            background: linear-gradient(145deg, #1c0407 0%, var(--red-night) 40%, var(--red-deep) 80%, #b71c1c 100%);
        }

        /* photo lookbook */
        .lookbook { position: absolute; inset: -22% -12%; z-index: -4; display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; transform: rotate(-7deg); opacity: .5; }
        .lb-col { overflow: hidden; opacity: 0; animation: lbin 1.4s var(--ease) forwards; }
        .lb-col:nth-child(1) { animation-delay: .1s; }
        .lb-col:nth-child(2) { animation-delay: .3s; margin-top: 90px; }
        .lb-col:nth-child(3) { animation-delay: .5s; }
        @keyframes lbin { from { opacity: 0; transform: translateY(70px); } to { opacity: 1; transform: none; } }
        .lb-track { display: flex; flex-direction: column; will-change: transform; }
        .lb-col:nth-child(1) .lb-track { animation: lbUp 80s linear infinite; }
        .lb-col:nth-child(2) .lb-track { animation: lbDown 96s linear infinite; }
        .lb-col:nth-child(3) .lb-track { animation: lbUp 70s linear infinite; }
        @keyframes lbUp { to { transform: translateY(-50%); } }
        @keyframes lbDown { from { transform: translateY(-50%); } to { transform: none; } }
        .lb-tile { display: block; width: 100%; aspect-ratio: 3 / 4; object-fit: cover; object-position: center 18%; margin-bottom: 16px; border-radius: 20px; background: var(--red-deep); }
        .scrim { position: absolute; inset: 0; z-index: -3; pointer-events: none;
            background:
                linear-gradient(90deg, rgba(30, 0, 2, .95) 0%, rgba(75, 0, 4, .74) 46%, rgba(110, 0, 8, .32) 100%),
                linear-gradient(0deg, rgba(24, 0, 2, .95) 0%, rgba(24, 0, 2, .6) 38%, rgba(24, 0, 2, 0) 62%),
                linear-gradient(180deg, rgba(30, 0, 2, .55) 0%, rgba(30, 0, 2, 0) 22%); }
        .spot { position: absolute; inset: 0; z-index: -2; pointer-events: none; background: radial-gradient(520px circle at var(--sx, 70%) var(--sy, 35%), rgba(255, 120, 120, .2), transparent 62%); }

        /* ledger matrix + scan line */
        .matrix { position: absolute; inset: 0; z-index: -1; display: grid; grid-template-columns: repeat(14, 1fr); grid-auto-rows: 1fr; gap: 10px; padding: 12px;
            -webkit-mask-image: radial-gradient(ellipse 80% 75% at 62% 40%, #000 20%, transparent 78%); mask-image: radial-gradient(ellipse 80% 75% at 62% 40%, #000 20%, transparent 78%); }
        .cell { border-radius: 8px; border: 1px solid rgba(255, 255, 255, .07); background: rgba(255, 255, 255, .012); }
        .cell.lit { animation: litcell 5s ease-in-out infinite; }
        @keyframes litcell { 0%, 100% { background: rgba(255, 255, 255, .012); border-color: rgba(255, 255, 255, .07); } 50% { background: rgba(255, 90, 90, .28); border-color: rgba(255, 140, 140, .5); box-shadow: 0 0 18px rgba(255, 70, 70, .35); } }
        .scan { position: absolute; left: 0; right: 0; height: 160px; z-index: -1; pointer-events: none; background: linear-gradient(180deg, transparent, rgba(255, 120, 120, .15), transparent); animation: scan 7s linear infinite; }
        @keyframes scan { from { top: -160px; } to { top: 100%; } }

        @keyframes rise { from { opacity: 0; transform: translateY(26px); } to { opacity: 1; transform: none; } }

        /* brand pill with logo */
        .brand { position: relative; display: inline-flex; align-items: center; gap: 14px; align-self: flex-start; padding: 8px 22px 8px 8px; border-radius: 999px; background: rgba(255, 255, 255, .1); border: 1px solid rgba(255, 255, 255, .2); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); animation: rise .8s var(--ease) both; }
        .badge { flex: none; display: flex; align-items: center; justify-content: center; height: 46px; min-width: 46px; max-width: 150px; padding: 6px 12px; overflow: hidden; border-radius: 999px; background: #fff; color: var(--red-deep); font: 800 .95rem var(--display); box-shadow: 0 8px 20px rgba(0, 0, 0, .25); }
        .badge img { display: block; height: 30px; width: auto; max-width: 120px; object-fit: contain; }
        .brand strong { display: block; font: 700 .95rem var(--display); white-space: nowrap; }
        .brand small { display: block; margin-top: 1px; font-size: .68rem; font-weight: 800; letter-spacing: 1.4px; text-transform: uppercase; color: #ffd9d9; white-space: nowrap; }

        /* floating glass cards */
        .float { position: absolute; z-index: 1; border-radius: 22px; background: rgba(255, 255, 255, .12); border: 1px solid rgba(255, 255, 255, .24); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); box-shadow: 0 30px 60px rgba(0, 0, 0, .32); }
        .float > .in { animation: bob 7s ease-in-out infinite; }
        @keyframes bob { 50% { transform: translateY(-12px); } }
        .f-po { right: 8%; top: 12%; width: 320px; padding: 1.1rem 1.2rem; animation: rise 1s .25s var(--ease) both; }
        .f-chart { left: 9%; top: 30%; width: 290px; padding: 1.1rem 1.2rem; animation: rise 1s .45s var(--ease) both; }
        .f-chart > .in { animation-delay: -3s; }
        .f-scan { right: 5%; top: 37%; width: 262px; padding: 1rem 1.15rem 1.05rem; animation: rise 1s .55s var(--ease) both; }
        .f-scan > .in { animation-delay: -2s; }
        .f-chip { right: 14%; top: 59%; padding: .7rem 1.1rem; border-radius: 999px; font-size: .8rem; font-weight: 700; animation: rise 1s .65s var(--ease) both; }
        .f-chip > .in { display: flex; align-items: center; gap: 10px; animation-delay: -1.5s; }
        .f-chip .dot { width: 9px; height: 9px; border-radius: 50%; background: #4ade80; animation: ping 2s infinite; }
        .f-photo { right: 7%; bottom: 7%; width: 150px; padding: 8px; border-radius: 24px; animation: rise 1s .75s var(--ease) both; }
        .f-photo > .in { animation-delay: -4s; }
        .f-photo img { display: block; width: 100%; aspect-ratio: 3 / 4; object-fit: cover; object-position: center 15%; border-radius: 17px; }
        .f-photo .cap { display: flex; align-items: center; justify-content: center; gap: 7px; margin: 8px 0 2px; font-size: .66rem; font-weight: 800; letter-spacing: 1.1px; text-transform: uppercase; color: #ffd6d6; }
        .f-photo .cap i { color: #ff8a8a; }
        @keyframes ping { 0% { box-shadow: 0 0 0 0 rgba(74, 222, 128, .7); } 70%, 100% { box-shadow: 0 0 0 10px rgba(74, 222, 128, 0); } }

        .fh { display: flex; align-items: center; gap: 10px; margin-bottom: .9rem; font-size: .85rem; font-weight: 800; }
        .fh i.ico { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 11px; background: rgba(255, 255, 255, .18); font-size: .85rem; }
        .fh .tag { margin-left: auto; padding: 3px 10px; border-radius: 999px; background: rgba(74, 222, 128, .2); color: #bbf7d0; font-size: .68rem; font-weight: 800; }

        .row { display: flex; align-items: center; gap: 10px; padding: .55rem 0; border-top: 1px solid rgba(255, 255, 255, .12); }
        .row .bars { flex: 1; }
        .row .bars span { display: block; height: 7px; border-radius: 6px; background: rgba(255, 255, 255, .3); }
        .row .bars span + span { width: 55%; margin-top: 6px; background: rgba(255, 255, 255, .16); }
        .row .qty { width: 34px; height: 22px; border-radius: 7px; background: rgba(255, 255, 255, .16); }
        .row .ok { width: 22px; height: 22px; display: grid; place-items: center; border-radius: 50%; background: #22c55e; color: #fff; font-size: .6rem; transform: scale(0); animation: tick 8s infinite; }
        .row:nth-of-type(2) .ok { animation-delay: .8s; }
        .row:nth-of-type(3) .ok { animation-delay: 1.6s; }
        @keyframes tick { 0% { transform: scale(0); } 6%, 80% { transform: scale(1); } 90%, 100% { transform: scale(0); } }

        .bar-chart { display: flex; align-items: flex-end; gap: 9px; height: 96px; padding-top: .3rem; }
        .bar-chart span { flex: 1; height: 100%; border-radius: 7px 7px 3px 3px; background: linear-gradient(180deg, #fff, rgba(255, 255, 255, .35)); transform-origin: bottom; animation: grow 5s ease-in-out infinite; }
        .bar-chart span:nth-child(1) { --h: .45; animation-delay: 0s; }
        .bar-chart span:nth-child(2) { --h: .7; animation-delay: .25s; }
        .bar-chart span:nth-child(3) { --h: .55; animation-delay: .5s; }
        .bar-chart span:nth-child(4) { --h: .9; animation-delay: .75s; }
        .bar-chart span:nth-child(5) { --h: .65; animation-delay: 1s; }
        .bar-chart span:nth-child(6) { --h: .8; animation-delay: 1.25s; }
        .bar-chart span:nth-child(7) { --h: .5; animation-delay: 1.5s; }
        @keyframes grow { 0%, 100% { transform: scaleY(calc(var(--h) * .55)); } 50% { transform: scaleY(var(--h)); } }
        .axis { margin-top: .5rem; height: 2px; border-radius: 2px; background: rgba(255, 255, 255, .25); }

        .barcode { position: relative; display: flex; align-items: stretch; gap: 3px; height: 54px; padding: 6px 8px; border-radius: 10px; background: rgba(255, 255, 255, .92); overflow: hidden; }
        .barcode i { display: block; background: #1a1214; border-radius: 1px; }
        .barcode i.w1 { width: 2px; } .barcode i.w2 { width: 3px; } .barcode i.w3 { width: 5px; } .barcode i.w4 { width: 7px; }
        .laser { position: absolute; left: 0; right: 0; top: 4px; height: 3px; background: #ff3b3b; box-shadow: 0 0 14px 4px rgba(255, 59, 59, .65); animation: laser 2.2s ease-in-out infinite alternate; }
        @keyframes laser { to { top: calc(100% - 7px); } }
        .scan-status { position: relative; height: 20px; margin-top: .65rem; font-size: .74rem; font-weight: 800; letter-spacing: .4px; }
        .scan-status span { position: absolute; left: 0; display: flex; align-items: center; gap: 8px; }
        .scan-status .s1 { color: #ffd6d6; animation: st1 4.4s infinite; }
        .scan-status .s2 { color: #bbf7d0; opacity: 0; animation: st2 4.4s infinite; }
        @keyframes st1 { 0%, 48% { opacity: 1; } 52%, 96% { opacity: 0; } 100% { opacity: 1; } }
        @keyframes st2 { 0%, 48% { opacity: 0; } 52%, 96% { opacity: 1; } 100% { opacity: 0; } }

        .copy { position: relative; z-index: 3; max-width: 580px; animation: rise 1s .15s var(--ease) both; }
        .copy::before { /* dark halo so the text always reads clearly over photos */
            content: ''; position: absolute; z-index: -1; pointer-events: none; inset: -3rem -4rem -2.5rem -4rem;
            background: radial-gradient(ellipse 75% 80% at 28% 58%, rgba(18, 0, 2, .9) 0%, rgba(18, 0, 2, .72) 45%, rgba(18, 0, 2, 0) 78%);
        }
        .copy .eyebrow { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 1rem; font-size: .78rem; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: #ffd9d9; text-shadow: 0 1px 12px rgba(0, 0, 0, .6); }
        .copy .eyebrow::before { content: ''; width: 28px; height: 2px; background: #ff8a8a; }
        .copy h1 { font-family: var(--display); font-weight: 800; font-size: clamp(2.3rem, 3.9vw, 3.7rem); line-height: 1.07; letter-spacing: -.03em; color: #fff; text-shadow: 0 3px 28px rgba(0, 0, 0, .65), 0 1px 3px rgba(0, 0, 0, .5); }
        .copy p { margin-top: 1.1rem; max-width: 500px; font-size: 1.08rem; line-height: 1.7; font-weight: 500; color: #fff; text-shadow: 0 2px 16px rgba(0, 0, 0, .65); }
        .feats { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1.5rem; list-style: none; }
        .feats li { display: inline-flex; align-items: center; gap: 8px; padding: .55rem 1rem; border-radius: 999px; background: rgba(20, 0, 2, .55); border: 1px solid rgba(255, 255, 255, .28); font-size: .82rem; font-weight: 700; color: #fff; }
        .feats i { color: #ff9a9a; font-size: .78rem; }

        /* ═════════ Right panel ═════════ */
        .panel { position: relative; display: flex; flex-direction: column; min-height: 100vh; min-height: 100svh; background: radial-gradient(520px 420px at 100% 0%, var(--blush) 0%, rgba(255, 243, 243, 0) 70%), var(--paper); }
        .panel-main { flex: 1; display: grid; place-items: center; padding: 3rem clamp(1.5rem, 5vw, 4.5rem) 1.5rem; }
        .card { width: 100%; max-width: 400px; opacity: 0; animation: settle .9s .4s var(--ease) forwards; }
        @keyframes settle { from { opacity: 0; transform: translateY(22px) scale(.985); } to { opacity: 1; transform: none; } }

        .id-logo { display: flex; align-items: center; height: 60px; margin-bottom: 1.3rem; font: 800 1.6rem var(--display); color: var(--red); }
        .id-logo img { display: block; height: 56px; width: auto; max-width: 220px; object-fit: contain; }
        .secure-pill { display: inline-flex; align-items: center; gap: 8px; margin-bottom: .9rem; padding: .3rem .85rem; border-radius: 999px; background: var(--blush); border: 1px solid #f6d3d3; color: var(--red-deep); font-size: .68rem; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; }
        .secure-pill i { color: #16a34a; }

        .card h2 { font-family: var(--display); font-weight: 800; font-size: 2rem; line-height: 1.1; letter-spacing: -.03em; }
        .card h2::after { content: ''; display: block; width: 46px; height: 4px; margin-top: .8rem; border-radius: 4px; background: linear-gradient(90deg, var(--red), #ff6b6b, var(--red)); background-size: 200% 100%; animation: shimmer 3s linear infinite; }
        @keyframes shimmer { to { background-position: -200% 0; } }
        .card .lede { margin-top: .9rem; font-size: .93rem; line-height: 1.55; color: var(--muted); }

        .error-msg { display: flex; align-items: flex-start; gap: 10px; margin-top: 1.4rem; padding: .85rem 1.1rem; border-radius: 12px; background: #fff3f3; border: 1px solid #f8caca; border-left: 4px solid var(--red); color: #8a1414; font-size: .86rem; font-weight: 600; line-height: 1.45; animation: shake .5s var(--ease); }
        .error-msg i { margin-top: 2px; }
        @keyframes shake { 20% { transform: translateX(-7px); } 40% { transform: translateX(6px); } 60% { transform: translateX(-4px); } 80% { transform: translateX(2px); } }

        form { margin-top: 1.7rem; }
        .field { position: relative; margin-bottom: 1.1rem; }
        .field > i.lead { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #b3a3a6; font-size: .95rem; pointer-events: none; transition: color .3s; }
        .field input { width: 100%; height: 58px; padding: 1.35rem 3rem .4rem 3rem; font: 600 1rem var(--body); color: var(--ink); background: #fff; border: 1.5px solid var(--line); border-radius: 14px; outline: none; transition: border-color .3s, box-shadow .3s; }
        .field input:hover { border-color: #dcc3c3; }
        .field input:focus { border-color: var(--red); box-shadow: 0 0 0 4px var(--glow); }
        .field:focus-within > i.lead { color: var(--red); }
        .field label { position: absolute; left: 48px; top: 50%; transform: translateY(-50%); font-size: .95rem; font-weight: 500; color: #8f7d80; pointer-events: none; transform-origin: left center; transition: transform .25s var(--ease), color .25s; }
        .field input:focus + label, .field input:not(:placeholder-shown) + label { transform: translateY(-165%) scale(.78); color: var(--red); font-weight: 700; }
        .toggle-password { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 42px; height: 42px; border: 0; border-radius: 10px; background: transparent; color: #8f7d80; cursor: pointer; transition: color .25s, background .25s; }
        .toggle-password:hover { color: var(--red); background: var(--blush); }
        .caps { display: none; align-items: center; gap: 8px; margin: -.5rem 0 .9rem; font-size: .76rem; font-weight: 700; color: #b45309; }
        .caps.show { display: flex; }

        .btn-login { position: relative; overflow: hidden; width: 100%; height: 58px; margin-top: .5rem; display: flex; align-items: center; justify-content: center; gap: 12px; border: 0; border-radius: 14px; background: linear-gradient(135deg, var(--red-deep), var(--red)); color: #fff; font: 800 1rem var(--body); letter-spacing: .3px; cursor: pointer; box-shadow: 0 14px 28px rgba(139, 0, 0, .3); transition: transform .3s var(--ease), box-shadow .3s; }
        .btn-login::after { content: ''; position: absolute; inset: 0; background: linear-gradient(105deg, transparent 35%, rgba(255, 255, 255, .35) 50%, transparent 65%); transform: translateX(-120%); }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 18px 34px rgba(139, 0, 0, .4); }
        .btn-login:hover::after { transform: translateX(120%); transition: transform .9s var(--ease); }
        .btn-login i { transition: transform .35s var(--ease); }
        .btn-login:hover i { transform: translateX(4px); }
        .btn-login.is-loading { pointer-events: none; opacity: .9; }

        .secure { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 1.3rem; font-size: .78rem; font-weight: 600; color: var(--muted); }
        .secure i { color: #16a34a; }

        /* one-line footer */
        .foot { border-top: 1px solid var(--line); display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: .15rem .6rem; padding: 1rem 1rem calc(1.1rem + env(safe-area-inset-bottom, 0px)); text-align: center; font-size: .76rem; font-weight: 500; color: var(--muted); }
        .foot b { color: var(--red); font-weight: 700; }
        .foot a { color: var(--ink); font-weight: 700; white-space: nowrap; transition: color .25s; }
        .foot a:hover { color: var(--red); text-decoration: underline; }
        .foot .sep { color: #e2c4c4; }

        /* ═════════ Mobile / tablet ═════════ */
        @media (max-width: 960px) {
            .shell { display: block; }
            .stage { position: relative; height: auto; min-height: 340px; padding: 1.2rem 1.3rem 4.4rem; gap: 1.6rem; }
            .lookbook { inset: -10% -30% 0 -30%; opacity: .55; }
            .lb-col:nth-child(3) { display: none; }
            .matrix { grid-template-columns: repeat(10, 1fr); }
            .cell:nth-child(n+81) { display: none; }
            .float { display: none; }
            .brand { padding: 6px 16px 6px 6px; gap: 10px; }
            .badge { height: 38px; min-width: 38px; padding: 5px 9px; }
            .badge img { height: 26px; max-width: 90px; }
            .brand strong { font-size: .85rem; }
            .brand small { font-size: .58rem; }
            .copy h1 { font-size: 2rem; }
            .copy p { font-size: .98rem; margin-top: .7rem; }
            .copy::before { inset: -2rem -1.5rem -1.5rem -1.5rem; }
            .copy .eyebrow { margin-bottom: .6rem; }
            .feats { margin-top: 1rem; gap: .45rem; }
            .feats li { padding: .4rem .75rem; font-size: .72rem; }
            .panel { margin-top: -30px; z-index: 2; border-radius: 30px 30px 0 0; min-height: calc(100svh - 310px); box-shadow: 0 -18px 40px rgba(60, 0, 0, .25); }
            .panel-main { place-items: start center; padding: 1.8rem 1.4rem 1.2rem; }
            .id-logo { height: 50px; margin-bottom: 1rem; }
            .id-logo img { height: 46px; }
            .card h2 { font-size: 1.7rem; }
        }
        @media (max-width: 480px) {
            .foot .sep { display: none; }
            .foot a, .foot span { display: block; width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
            .card { opacity: 1; }
            .row .ok { transform: scale(1); }
            .lb-col { opacity: 1; }
        }
    </style>
</head>
<body>

<div class="shell">

    <!-- ═════ Stage: photos + animated ledger ═════ -->
    <aside class="stage" aria-label="ASB Group Purchase Order System">
        <div class="lookbook" aria-hidden="true">
            <?php foreach ($lookbook as $col): ?>
                <div class="lb-col"><div class="lb-track">
                    <?php for ($pass = 0; $pass < 2; $pass++): foreach ($col as $img): ?>
                        <img class="lb-tile" src="login_img/<?php echo $img; ?>.jpg" alt="" width="480" height="640" <?php echo $pass ? 'loading="lazy"' : ''; ?> decoding="async">
                    <?php endforeach; endfor; ?>
                </div></div>
            <?php endforeach; ?>
        </div>
        <div class="scrim" aria-hidden="true"></div>
        <div class="spot" aria-hidden="true"></div>
        <div class="matrix" aria-hidden="true">
            <?php foreach ($cells as $c): ?>
                <span class="cell<?php echo $c['lit'] ? ' lit' : ''; ?>"<?php if ($c['lit']): ?> style="animation-delay:<?php echo $c['delay']; ?>s;animation-duration:<?php echo $c['dur']; ?>s"<?php endif; ?>></span>
            <?php endforeach; ?>
        </div>
        <div class="scan" aria-hidden="true"></div>

        <div class="brand">
            <div class="badge"><img src="logo.png" alt="ASB logo" onerror="this.replaceWith(document.createTextNode('ASB'))"></div>
            <div>
                <strong>ASB Group of Companies</strong>
                <small>Purchase Order System</small>
            </div>
        </div>

        <!-- floating product visuals (desktop) -->
        <div class="float f-po" aria-hidden="true"><div class="in">
            <div class="fh"><i class="fas fa-file-invoice ico"></i> Purchase Order <span class="tag">Approved</span></div>
            <div class="row"><div class="bars"><span></span><span></span></div><div class="qty"></div><div class="ok"><i class="fas fa-check"></i></div></div>
            <div class="row"><div class="bars"><span></span><span></span></div><div class="qty"></div><div class="ok"><i class="fas fa-check"></i></div></div>
            <div class="row"><div class="bars"><span></span><span></span></div><div class="qty"></div><div class="ok"><i class="fas fa-check"></i></div></div>
        </div></div>

        <div class="float f-chart" aria-hidden="true"><div class="in">
            <div class="fh"><i class="fas fa-boxes-stacked ico"></i> Stock Ledger</div>
            <div class="bar-chart"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
            <div class="axis"></div>
        </div></div>

        <div class="float f-scan" aria-hidden="true"><div class="in">
            <div class="fh"><i class="fas fa-barcode ico"></i> Scan &amp; Receive</div>
            <div class="barcode">
                <?php foreach ($bars as $w): ?><i class="w<?php echo $w; ?>"></i><?php endforeach; ?>
                <span class="laser"></span>
            </div>
            <div class="scan-status"><span class="s1"><i class="fas fa-satellite-dish"></i> Scanning item…</span><span class="s2"><i class="fas fa-circle-check"></i> Recorded in ledger</span></div>
        </div></div>

        <div class="float f-photo" aria-hidden="true"><div class="in">
            <img src="login_img/red-set.jpg" alt="" decoding="async">
            <div class="cap"><i class="fas fa-tag"></i> New stock</div>
        </div></div>

        <div class="float f-chip" aria-hidden="true"><div class="in"><span class="dot"></span> Live stock movement</div></div>

        <div class="copy">
            <div class="eyebrow">Purchase Order System</div>
            <h1>Every order. Every item. One ledger.</h1>
            <p>Create purchase orders and keep a clear, accurate record of stock across the ASB Group of Companies.</p>
            <ul class="feats">
                <li><i class="fas fa-file-invoice"></i> Purchase orders</li>
                <li><i class="fas fa-boxes-stacked"></i> Stock ledger</li>
                <li><i class="fas fa-user-shield"></i> Role-based access</li>
            </ul>
        </div>
    </aside>

    <!-- ═════ Sign-in ═════ -->
    <main class="panel">
        <div class="panel-main">
            <div class="card">
                <div class="id-logo"><img src="logo.png" alt="ASB Group logo" onerror="this.replaceWith(document.createTextNode('ASB'))"></div>
                <div class="secure-pill"><i class="fas fa-lock"></i> Secure sign-in</div>
                <h2>Welcome back</h2>
                <p class="lede">Sign in to the Purchase Order System with your username and password.</p>

                <?php if ($error): ?>
                    <div class="error-msg" role="alert"><i class="fas fa-exclamation-circle"></i> <span><?php echo htmlspecialchars($error); ?></span></div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <div class="field">
                        <i class="fas fa-user lead" aria-hidden="true"></i>
                        <input type="text" name="username" id="username" placeholder=" " value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required autocomplete="username" autofocus>
                        <label for="username">Username</label>
                    </div>
                    <div class="field">
                        <i class="fas fa-lock lead" aria-hidden="true"></i>
                        <input type="password" name="password" id="password" placeholder=" " required autocomplete="current-password">
                        <label for="password">Password</label>
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="caps" id="capsWarn" role="status"><i class="fas fa-triangle-exclamation"></i> Caps Lock is on</div>
                    <button type="submit" class="btn-login"><span>Sign In</span> <i class="fas fa-arrow-right-to-bracket"></i></button>
                </form>

                <div class="secure"><i class="fas fa-shield-halved"></i> Sign-ins are recorded for security</div>
            </div>
        </div>

        <footer class="foot">
            <span>&copy; <?php echo date('Y'); ?> <b>ASB Group of Companies</b></span>
            <span class="sep">|</span>
            <span>Designed and developed by <a href="https://www.vexelit.xyz/" target="_blank" rel="noopener">Vexel IT by Kavizz</a></span>
            <span class="sep">|</span>
            <a href="mailto:Info.vexelit@gmail.com">Info.vexelit@gmail.com</a>
            <span class="sep">|</span>
            <a href="https://www.vexelit.xyz/" target="_blank" rel="noopener">www.vexelit.xyz</a>
        </footer>
    </main>
</div>

<script>
    // Show / hide password + Caps Lock hint
    (function () {
        var btn = document.getElementById('togglePassword');
        var input = document.getElementById('password');
        btn.addEventListener('click', function () {
            var show = input.getAttribute('type') === 'password';
            input.setAttribute('type', show ? 'text' : 'password');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            var i = btn.querySelector('i');
            i.classList.toggle('fa-eye', !show);
            i.classList.toggle('fa-eye-slash', show);
            input.focus();
        });
        var warn = document.getElementById('capsWarn');
        function caps(e) { if (e.getModifierState) warn.classList.toggle('show', e.getModifierState('CapsLock')); }
        input.addEventListener('keydown', caps);
        input.addEventListener('keyup', caps);
        input.addEventListener('blur', function () { warn.classList.remove('show'); });
    })();

    // Soft spotlight follows the pointer over the red panel (desktop only)
    (function () {
        var stage = document.querySelector('.stage');
        var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!stage || calm || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
        stage.addEventListener('pointermove', function (e) {
            var r = stage.getBoundingClientRect();
            stage.style.setProperty('--sx', (e.clientX - r.left) + 'px');
            stage.style.setProperty('--sy', (e.clientY - r.top) + 'px');
        });
    })();

    // Loading state on submit (button stays enabled so the form posts normally)
    (function () {
        var form = document.getElementById('loginForm');
        var btn = form.querySelector('.btn-login');
        var original = btn.innerHTML;
        form.addEventListener('submit', function () {
            btn.classList.add('is-loading');
            btn.innerHTML = '<span>Signing in…</span> <i class="fas fa-circle-notch fa-spin"></i>';
        });
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) { btn.classList.remove('is-loading'); btn.innerHTML = original; }
        });
    })();
</script>
</body>
</html>