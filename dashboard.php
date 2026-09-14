<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');

require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

requireLogin();

$page_title = 'ASB Group · Dashboard';
$page = 'dashboard';

$isReceived = isReceivedUser();

// Only fetch stats if not received (to save queries)
$totalSuppliers = 0;
$totalPOs = 0;
$pendingPOs = 0;
$totalItems = 0;
$totalUsers = 0;
$totalLogins = 0;

if (!$isReceived) {
    $totalSuppliers = getTotalSuppliers();
    $totalPOs       = getTotalPOs();
    $pendingPOs     = getPendingPOs();
    $totalItems     = getTotalItems();

    // Total users – we need to query directly as no helper exists
    $conn = getConnection();
    try {
        $result = $conn->query("SELECT COUNT(*) FROM po_users");
        if ($result) {
            $totalUsers = (int) $result->fetch_row()[0];
            $result->free();
        }
    } catch (Exception $e) {
        // ignore
    }

    if (isAdmin()) {
        try {
            $totalLogins = countLoginLogs();
        } catch (Exception $e) {
            // ignore
        }
    }
}

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<style>
    /* ===== DASHBOARD-SPECIFIC STYLES ===== */
    .hero-slider {
        position: relative;
        width: 100%;
        height: 320px;
        border-radius: 20px;
        overflow: hidden;
        margin-bottom: 30px;
        box-shadow: 0 8px 30px rgba(185, 28, 28, 0.2);
    }
    .hero-slider .slides {
        display: flex;
        width: 100%;
        height: 100%;
        transition: transform 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }
    .hero-slider .slide {
        min-width: 100%;
        height: 100%;
        background-size: cover;
        background-position: center;
        position: relative;
        opacity: 0;
        transition: opacity 0.8s ease;
    }
    .hero-slider .slide.active {
        opacity: 1;
    }
    .hero-slider .slide .overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(185, 28, 28, 0.7) 0%, rgba(127, 29, 29, 0.8) 100%);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        color: white;
        text-align: center;
        padding: 30px;
    }
    .hero-slider .slide .overlay h2 {
        font-size: 2.4rem;
        font-weight: 900;
        letter-spacing: 1px;
        text-shadow: 0 2px 15px rgba(0,0,0,0.3);
        margin-bottom: 10px;
    }
    .hero-slider .slide .overlay p {
        font-size: 1.1rem;
        opacity: 0.95;
        max-width: 600px;
        font-weight: 500;
    }
    .hero-slider .slide .overlay .btn-white {
        margin-top: 15px;
        background: white;
        color: #b91c1c;
        padding: 10px 30px;
        border-radius: 50px;
        font-weight: 700;
        text-decoration: none;
        transition: 0.3s;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        display: inline-block;
    }
    .hero-slider .slide .overlay .btn-white:hover {
        transform: scale(1.05);
        background: #fef2f2;
    }
    .slider-dots {
        position: absolute;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 10px;
        z-index: 5;
    }
    .slider-dots .dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: rgba(255,255,255,0.4);
        cursor: pointer;
        transition: 0.3s;
        border: none;
    }
    .slider-dots .dot.active {
        background: white;
        transform: scale(1.2);
        box-shadow: 0 0 10px rgba(255,255,255,0.6);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background: white;
        padding: 20px 15px;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        text-align: center;
        border-bottom: 4px solid #b91c1c;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(185,28,28,0.12);
    }
    .stat-card .stat-number {
        font-size: 32px;
        font-weight: 900;
        color: #b91c1c;
        line-height: 1.2;
    }
    .stat-card .stat-label {
        font-size: 14px;
        color: #6b7280;
        font-weight: 600;
        margin-top: 4px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .stat-card .stat-icon {
        font-size: 28px;
        color: #b91c1c;
        opacity: 0.2;
        margin-bottom: 6px;
    }

    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 25px;
        margin-top: 20px;
    }
    .dashboard-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        padding: 28px 20px 22px;
        text-align: center;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        border-top: 5px solid #b91c1c;
        text-decoration: none;
        color: #1e293b;
        display: block;
        position: relative;
        overflow: hidden;
    }
    .dashboard-card::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, rgba(185,28,28,0.03), transparent);
        pointer-events: none;
    }
    .dashboard-card:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: 0 16px 40px rgba(185,28,28,0.13);
    }
    .dashboard-card .icon {
        font-size: 48px;
        color: #b91c1c;
        margin-bottom: 12px;
        transition: transform 0.4s ease;
    }
    .dashboard-card:hover .icon {
        transform: scale(1.15) rotate(-3deg);
    }
    .dashboard-card h3 {
        font-size: 17px;
        font-weight: 700;
        margin-bottom: 6px;
        color: #0f172a;
    }
    .dashboard-card p {
        font-size: 13px;
        color: #94a3b8;
        margin: 0;
        font-weight: 500;
    }

    /* GRN section */
    .grn-section {
        margin-top: 30px;
    }
    .grn-section h4 {
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .grn-section h4 i {
        color: #b91c1c;
    }
    .grn-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    /* For received user: make the GRN section the main content */
    .main-grn {
        margin-top: 0;
    }
    .main-grn h2 {
        text-align: center;
        margin-bottom: 30px;
        color: #0f172a;
    }
    .main-grn .grn-grid {
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        max-width: 700px;
        margin: 0 auto;
    }

    @media (max-width: 768px) {
        .hero-slider { height: 240px; }
        .hero-slider .slide .overlay h2 { font-size: 1.6rem; }
        .hero-slider .slide .overlay p { font-size: 0.9rem; }
        .stats-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 480px) {
        .hero-slider { height: 200px; }
        .stats-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="container-fluid" style="padding: 20px 25px;">

    <!-- ===== HERO SLIDESHOW ===== -->
    <div class="hero-slider" id="heroSlider">
        <div class="slides" id="slidesContainer">
            <?php if (!$isReceived): ?>
                <!-- Normal slides for admin/user -->
                <div class="slide active" style="background-image: url('https://picsum.photos/seed/fashion1/1200/500');">
                    <div class="overlay">
                        <h2>Welcome to ASB Group</h2>
                        <p>Streamline your purchase orders, allocations, and supplier management – all in one place.</p>
                        <a href="create_po.php" class="btn-white"><i class="fas fa-plus-circle"></i> Create New PO</a>
                    </div>
                </div>
                <div class="slide" style="background-image: url('https://picsum.photos/seed/fashion2/1200/500');">
                    <div class="overlay">
                        <h2>Supplier Control</h2>
                        <p>Manage all your suppliers efficiently – add, edit, and track every vendor.</p>
                        <a href="supplier_module.php" class="btn-white"><i class="fas fa-truck"></i> View Suppliers</a>
                    </div>
                </div>
                <div class="slide" style="background-image: url('https://picsum.photos/seed/fashion3/1200/500');">
                    <div class="overlay">
                        <h2>Smart Allocations</h2>
                        <p>Distribute PO items to branches with ease and generate printable reports.</p>
                        <a href="allocate_po.php" class="btn-white"><i class="fas fa-tasks"></i> Go to Allocations</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Slides for received user (GRN focused) -->
                <div class="slide active" style="background-image: url('https://picsum.photos/seed/grn1/1200/500');">
                    <div class="overlay">
                        <h2>Goods Receipt Notes</h2>
                        <p>Receive purchase orders and create GRN notes efficiently.</p>
                        <a href="receive_po_dashboard.php" class="btn-white"><i class="fas fa-arrow-down"></i> Receive PO</a>
                    </div>
                </div>
                <div class="slide" style="background-image: url('https://picsum.photos/seed/grn2/1200/500');">
                    <div class="overlay">
                        <h2>GRN Print & View</h2>
                        <p>View and print all Goods Receipt Notes for your records.</p>
                        <a href="view_pos.php" class="btn-white"><i class="fas fa-print"></i> Print GRN</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <!-- Dots -->
        <div class="slider-dots" id="sliderDots">
            <?php
            $totalSlides = $isReceived ? 2 : 3;
            for ($i = 0; $i < $totalSlides; $i++):
            ?>
                <button class="dot <?php echo $i === 0 ? 'active' : ''; ?>" data-index="<?php echo $i; ?>"></button>
            <?php endfor; ?>
        </div>
    </div>

    <?php if (!$isReceived): ?>
        <!-- ===== KEY STATS (only for admin/user) ===== -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                <div class="stat-number"><?php echo number_format($totalPOs); ?></div>
                <div class="stat-label">Total Purchase Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-number"><?php echo number_format($pendingPOs); ?></div>
                <div class="stat-label">Pending POs</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-truck"></i></div>
                <div class="stat-number"><?php echo number_format($totalSuppliers); ?></div>
                <div class="stat-label">Active Suppliers</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                <div class="stat-number"><?php echo number_format($totalItems); ?></div>
                <div class="stat-label">PO Items</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?php echo number_format($totalUsers); ?></div>
                <div class="stat-label">Registered Users</div>
            </div>
            <?php if (isAdmin()): ?>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-number"><?php echo number_format($totalLogins); ?></div>
                    <div class="stat-label">Total Logins</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== DASHBOARD ACTION CARDS (for admin/user) ===== -->
        <div class="dashboard-grid">
            <a href="create_po.php" class="dashboard-card">
                <div class="icon"><i class="fas fa-plus-circle"></i></div>
                <h3>Create Purchase Order</h3>
                <p>Start a new PO with items & suppliers</p>
            </a>

            <a href="edit_po.php" class="dashboard-card">
                <div class="icon"><i class="fas fa-edit"></i></div>
                <h3>Edit Purchase Order</h3>
                <p>Search and modify existing POs</p>
            </a>

            <a href="allocate_po.php" class="dashboard-card">
                <div class="icon"><i class="fas fa-tasks"></i></div>
                <h3>Quantity Allocations</h3>
                <p>Allocate PO items to branches</p>
            </a>

            <!-- ===== NEW CARD: Quick Qty Allocation ===== -->
            <a href="quick_allocation.php" class="dashboard-card" style="border-top-color: #e67e22;">
                <div class="icon" style="color:#e67e22;"><i class="fas fa-bolt"></i></div>
                <h3>Quick Qty Allocation</h3>
                <p>Fast allocate with preset company splits</p>
            </a>

            <a href="allocation_summary.php" class="dashboard-card">
                <div class="icon"><i class="fas fa-print"></i></div>
                <h3>Allocation Report</h3>
                <p>View and print allocation reports</p>
            </a>

            <a href="pos.php" class="dashboard-card" style="border-top-color: #2c3e50;">
                <div class="icon" style="color:#2c3e50;"><i class="fas fa-file-pdf"></i></div>
                <h3>Print Reports</h3>
                <p>Generate printable PO reports</p>
            </a>

            <!-- ===== NEW CARD: PO Summary Report ===== -->
            <a href="po_summary_report.php" class="dashboard-card" style="border-top-color: #8b5cf6;">
                <div class="icon" style="color:#8b5cf6;"><i class="fas fa-chart-pie"></i></div>
                <h3>PO Summary Report</h3>
                <p>Analytical overview with charts &amp; print</p>
            </a>

            <a href="supplier_module.php" class="dashboard-card" style="border-top-color: #1976d2;">
                <div class="icon" style="color:#1976d2;"><i class="fas fa-truck"></i></div>
                <h3>Supplier Management</h3>
                <p>Add, edit, or view supplier details</p>
            </a>

            <!-- Admin-only cards -->
            <?php if (isAdmin()): ?>
                <a href="user_management.php" class="dashboard-card" style="border-top-color: #6c5ce7;">
                    <div class="icon" style="color:#6c5ce7;"><i class="fas fa-users-cog"></i></div>
                    <h3>User Management</h3>
                    <p>Add, edit, or delete system users</p>
                </a>

                <a href="login_logs.php" class="dashboard-card" style="border-top-color: #e67e22;">
                    <div class="icon" style="color:#e67e22;"><i class="fas fa-history"></i></div>
                    <h3>User Log History</h3>
                    <p>View full system login history logs</p>
                </a>

                <a href="companies.php" class="dashboard-card" style="border-top-color: #17a2b8;">
                    <div class="icon" style="color:#17a2b8;"><i class="fas fa-building"></i></div>
                    <h3>Companies</h3>
                    <p>Manage companies</p>
                </a>

                <a href="locations.php" class="dashboard-card" style="border-top-color: #28a745;">
                    <div class="icon" style="color:#28a745;"><i class="fas fa-store"></i></div>
                    <h3>Store Locations</h3>
                    <p>Manage locations per company</p>
                </a>
            <?php endif; ?>
        </div>

        <!-- ===== GRN ACTIONS (for admin/user as well) ===== -->
        <div class="grn-section">
            <h4><i class="fas fa-clipboard-list"></i> Goods Receipt Notes (GRN)</h4>
            <div class="grn-grid">
                <a href="receive_po_dashboard.php" class="dashboard-card" style="border-top-color: #0d9488;">
                    <div class="icon" style="color:#0d9488;"><i class="fas fa-arrow-down"></i></div>
                    <h3>Receive PO</h3>
                    <p>Create GRN for incoming orders</p>
                </a>
                <a href="view_pos.php" class="dashboard-card" style="border-top-color: #2563eb;">
                    <div class="icon" style="color:#2563eb;"><i class="fas fa-print"></i></div>
                    <h3>GRN Note Print</h3>
                    <p>View and print GRN reports</p>
                </a>
            </div>
        </div>

    <?php else: ?>
        <!-- ===== MAIN GRN SECTION FOR RECEIVED USER ===== -->
        <div class="main-grn">
            <h2>Goods Receipt Notes</h2>
            <div class="grn-grid">
                <a href="receive_po_dashboard.php" class="dashboard-card" style="border-top-color: #0d9488;">
                    <div class="icon" style="color:#0d9488;"><i class="fas fa-arrow-down"></i></div>
                    <h3>Receive PO</h3>
                    <p>Create GRN for incoming orders</p>
                </a>
                <a href="view_pos.php" class="dashboard-card" style="border-top-color: #2563eb;">
                    <div class="icon" style="color:#2563eb;"><i class="fas fa-print"></i></div>
                    <h3>GRN Note Print</h3>
                    <p>View and print GRN reports</p>
                </a>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- ===== SLIDER SCRIPT ===== -->
<script>
    (function() {
        const slidesContainer = document.getElementById('slidesContainer');
        const slides = slidesContainer.querySelectorAll('.slide');
        const dots = document.querySelectorAll('.dot');
        let currentIndex = 0;
        const totalSlides = slides.length;
        let interval;

        function goToSlide(index) {
            if (index < 0) index = totalSlides - 1;
            if (index >= totalSlides) index = 0;
            currentIndex = index;

            slidesContainer.style.transform = `translateX(-${currentIndex * 100}%)`;

            slides.forEach((slide, i) => {
                slide.classList.toggle('active', i === currentIndex);
            });

            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === currentIndex);
            });
        }

        function nextSlide() {
            goToSlide(currentIndex + 1);
        }

        function startSlider() {
            interval = setInterval(nextSlide, 5000);
        }

        function stopSlider() {
            clearInterval(interval);
        }

        dots.forEach(dot => {
            dot.addEventListener('click', function() {
                const index = parseInt(this.dataset.index);
                goToSlide(index);
                stopSlider();
                startSlider();
            });
        });

        const slider = document.getElementById('heroSlider');
        slider.addEventListener('mouseenter', stopSlider);
        slider.addEventListener('mouseleave', startSlider);

        startSlider();
    })();
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>