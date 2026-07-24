<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

startSession();

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
        $conn = getConnection();
        $stmt = $conn->prepare("SELECT id, username, password, role FROM po_users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && $password === $user['password']) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            $updateStmt = $conn->prepare("UPDATE po_users SET last_login = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $user['id']);
            $updateStmt->execute();
            $updateStmt->close();

            logLogin($user['id']);

            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASB Group – Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* === Full styles (glassmorphism, red theme) === */
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: url('https://images.unsplash.com/photo-1441984904996-e0b6ba687e04?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80') no-repeat center center/cover;
            position: relative;
        }
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(139,0,0,0.65) 0%, rgba(0,0,0,0.75) 100%);
            backdrop-filter: blur(8px);
            z-index: 0;
        }
        .login-container {
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(12px);
            padding: 48px 38px 38px;
            border-radius: 28px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.5);
            width: 100%;
            max-width: 420px;
            border: 1px solid rgba(255,255,255,0.25);
            animation: fadeInUp 0.8s ease;
        }
        @keyframes fadeInUp {
            from { opacity:0; transform: translateY(40px) scale(0.96); }
            to { opacity:1; transform: translateY(0) scale(1); }
        }
        .login-container .logo { text-align: center; margin-bottom: 32px; }
        .login-container .logo .brand-icon {
            display: inline-block;
            background: linear-gradient(135deg, #8B0000, #b71c1c);
            color: #fff;
            width: 70px;
            height: 70px;
            line-height: 70px;
            border-radius: 18px;
            font-size: 32px;
            font-weight: 900;
            box-shadow: 0 8px 24px rgba(139,0,0,0.25);
            margin-bottom: 12px;
        }
        .login-container .logo h1 { color: #1a1a1a; font-size: 26px; font-weight: 800; }
        .login-container .logo h1 span { color: #b71c1c; }
        .login-container .logo .subtitle {
            color: #777;
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            margin-top: 4px;
        }
        .form-group { margin-bottom: 22px; }
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: #444;
            margin-bottom: 6px;
        }
        .form-group label i { color: #b71c1c; margin-right: 8px; }
        .input-wrapper { position: relative; }
        .input-wrapper input {
            width: 100%;
            padding: 13px 48px 13px 16px;
            border: 2px solid #e8e8e8;
            border-radius: 14px;
            font-size: 15px;
            background: #fafafa;
            transition: all 0.3s;
        }
        .input-wrapper input:focus {
            border-color: #b71c1c;
            outline: none;
            background: #fff;
            box-shadow: 0 0 0 5px rgba(183,28,28,0.1);
        }
        .input-wrapper .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #aaa;
            font-size: 18px;
            cursor: pointer;
            padding: 6px;
        }
        .input-wrapper .toggle-password:hover { color: #b71c1c; }
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #8B0000, #b71c1c);
            color: #fff;
            border: none;
            border-radius: 14px;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: 0.8px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 8px 24px rgba(139,0,0,0.25);
            margin-top: 6px;
        }
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 34px rgba(139,0,0,0.35);
            background: linear-gradient(135deg, #6d0000, #8B0000);
        }
        .btn-login i { margin-right: 10px; }
        .error-msg {
            background: #ffebee;
            color: #b71c1c;
            padding: 11px 16px;
            border-radius: 12px;
            font-size: 13px;
            margin-bottom: 22px;
            border-left: 5px solid #b71c1c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .footer {
            text-align: center;
            margin-top: 28px;
            font-size: 13px;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        .footer .company { font-weight: 700; color: #b71c1c; }
        .footer .developer { margin-top: 4px; }
        .footer .developer a {
            color: #b71c1c;
            text-decoration: none;
            font-weight: 700;
        }
        .footer .developer a:hover { color: #6d0000; text-decoration: underline; }
        @media (max-width:480px) {
            .login-container { padding: 32px 20px 28px; margin: 0 16px; border-radius: 20px; }
            .login-container .logo h1 { font-size: 22px; }
            .login-container .logo .brand-icon { width: 60px; height: 60px; line-height: 60px; font-size: 26px; }
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="logo">
        <div class="brand-icon">ASB</div>
        <h1>ASB Group <span>of Companies</span></h1>
        <div class="subtitle">Inventory Ledger Matrix System</div>
    </div>

    <?php if ($error): ?>
        <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label><i class="fas fa-user"></i> Username</label>
            <div class="input-wrapper">
                <input type="text" name="username" placeholder="Enter username" required>
            </div>
        </div>
        <div class="form-group">
            <label><i class="fas fa-lock"></i> Password</label>
            <div class="input-wrapper">
                <input type="password" name="password" id="password" placeholder="Enter password" required>
                <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt"></i> Sign In</button>
    </form>

    <div class="footer">
        <div class="company">&copy; <?php echo date('Y'); ?> ASB Group of Companies – All rights reserved.</div>
        <div class="developer">Developed by <a href="https://www.vexelit.xyz/" target="_blank">Vexel IT by Kavizz</a></div>
    </div>
</div>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    togglePassword.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
    });
</script>
</body>
</html>