<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'PO Management System'; ?></title>
    <style>
        /* ===== COMPLETE CSS (no sidebar) ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #fef2f2; color: #333; line-height: 1.6; }
        a { text-decoration: none; color: #3498db; }
        a:hover { color: #2980b9; }
        
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
        .form-control { width: 100%; padding: 12px 15px; border: 2px solid #e8ecf1; border-radius: 10px; font-size: 14px; transition: border-color 0.3s; outline: none; background: white; }
        .form-control:focus { border-color: #b91c1c; box-shadow: 0 0 0 3px rgba(185,28,28,0.1); }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 15px center; padding-right: 40px; }
        
        .btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-align: center; }
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
        .main-content { padding: 30px; width: 100%; min-height: 100vh; }
        
        /* Top navbar – Red theme */
        .top-nav { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%); padding: 12px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #991b1b; box-shadow: 0 4px 12px rgba(185,28,28,0.2); margin-bottom: 25px; border-radius: 12px; color: white; }
        .top-nav .nav-left { display: flex; align-items: center; gap: 15px; }
        .top-nav .nav-left .brand-icon { background: white; color: #b91c1c; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 20px; }
        .top-nav .nav-left .brand-text { display: flex; flex-direction: column; }
        .top-nav .nav-left .brand-text .title { font-weight: 800; font-size: 18px; letter-spacing: 0.5px; }
        .top-nav .nav-left .brand-text .subtitle { font-size: 11px; opacity: 0.85; font-weight: 500; }
        .top-nav .nav-right { display: flex; align-items: center; gap: 15px; }
        .top-nav .nav-right .user-badge { background: rgba(255,255,255,0.2); color: white; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; backdrop-filter: blur(4px); }
        .top-nav .nav-right .btn-outline-light { background: transparent; border: 2px solid rgba(255,255,255,0.5); color: white; padding: 6px 15px; border-radius: 20px; font-weight: 600; font-size: 13px; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .top-nav .nav-right .btn-outline-light:hover { background: white; color: #b91c1c; border-color: white; transform: translateY(-1px); }
        .top-nav .nav-right .btn-danger-sm { background: rgba(255,255,255,0.15); border: 2px solid transparent; color: white; padding: 6px 15px; border-radius: 20px; font-weight: 600; font-size: 13px; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .top-nav .nav-right .btn-danger-sm:hover { background: #e74c3c; border-color: white; transform: translateY(-1px); }
        
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
            .stats-row { grid-template-columns: 1fr 1fr; }
            .item-entry .item-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: stretch; }
            .filter-bar .filter-row { grid-template-columns: 1fr; }
            .top-nav { flex-direction: column; gap: 10px; align-items: stretch; text-align: center; }
            .top-nav .nav-left { justify-content: center; }
            .top-nav .nav-right { justify-content: center; flex-wrap: wrap; }
        }
        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr; }
            .login-container { padding: 30px 20px; }
        }
        
        @media print {
            .no-print, .top-nav { display: none !important; }
            .main-content { padding: 20px !important; }
            .card { box-shadow: none !important; border: 1px solid #ddd !important; }
        }
    </style>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="app-container">
        <!-- MAIN CONTENT (no sidebar) -->
        <div class="main-content">
            <!-- Top Navigation Bar – Red & White theme -->
            <div class="top-nav no-print">
                <div class="nav-left">
                    <div class="brand-icon">ASB</div>
                    <div class="brand-text">
                        <span class="title">ASB Group Of Companies</span>
                        <span class="subtitle"><i class="fas fa-shopping-cart"></i> Purchase Order System</span>
                    </div>
                </div>
                <div class="nav-right">
                    <!-- Dashboard Button -->
                    <a href="dashboard.php" class="btn-outline-light">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <!-- User Badge -->
                    <span class="user-badge"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['username'] ?? 'Guest'); ?></span>
                    <!-- Logout -->
                    <a href="logout.php" class="btn-danger-sm">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
            <!-- END top-nav -->

            <!-- Page content starts here -->
            <!-- The individual page content will be inserted after this -->