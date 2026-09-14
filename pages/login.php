<?php
// Login page
$page_title = 'Login';
?>
<div class="login-page">
    <div class="login-container">
        <div class="login-logo">
            <div class="icon">📄</div>
            <h2>PO Management</h2>
            <p>Purchase Order System</p>
        </div>
        <?php if (isset($login_error)): ?>
            <div class="alert alert-danger"><?php echo $login_error; ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="form-control" value="admin@posystem.lk" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" value="password" required>
            </div>
            <button type="submit" class="btn btn-primary">Login</button>
        </form>
        <div class="demo-credentials">
            <strong>🔑 Demo Credentials:</strong><br>
            📧 admin@posystem.lk &nbsp;|&nbsp; 🔒 password
        </div>
    </div>
</div>