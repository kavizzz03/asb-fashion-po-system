<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');

require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

requireLogin();

$page_title = 'ASB Group · Dashboard';
$page = 'dashboard';

// Get database connections using MySQLi
$conn = getConnection();      // PO database (contains po_users, po_login_logs)
$qcConn = getQcConnection();  // QC database (suppliers)

// Initialize stats
$totalSuppliers = 0;
$totalPOs = 0;
$totalItems = 0;
$totalUsers = 0;
$totalLogins = 0;

// Count suppliers from return_qc
if ($qcConn) {
    $result = $qcConn->query("SELECT COUNT(*) FROM suppliers");
    if ($result) {
        $totalSuppliers = (int) $result->fetch_row()[0];
        $result->free();
    }
}

// Count POs and items from po_system
if ($conn) {
    $result = $conn->query("SELECT COUNT(*) FROM purchase_orders");
    if ($result) {
        $totalPOs = (int) $result->fetch_row()[0];
        $result->free();
    }
    $result = $conn->query("SELECT COUNT(*) FROM purchase_order_items");
    if ($result) {
        $totalItems = (int) $result->fetch_row()[0];
        $result->free();
    }

    // Count total users
    try {
        $result = $conn->query("SELECT COUNT(*) FROM po_users");
        if ($result) {
            $totalUsers = (int) $result->fetch_row()[0];
            $result->free();
        }
    } catch (Exception $e) {
        // ignore (table might not exist yet)
    }

    // Count total logins (only for admin)
    if (isAdmin()) {
        try {
            $totalLogins = countLoginLogs(); // uses the optimized function
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
            <!-- Slide 1 -->
            <div class="slide active" style="background-image: url('https://picsum.photos/seed/fashion1/1200/500');">
                <div class="overlay">
                    <h2>Welcome to ASB Group</h2>
                    <p>Streamline your purchase orders, allocations, and supplier management – all in one place.</p>
                    <a href="create_po.php" class="btn-white"><i class="fas fa-plus-circle"></i> Create New PO</a>
                </div>
            </div>
            <!-- Slide 2 -->
            <div class="slide" style="background-image: url('https://picsum.photos/seed/fashion2/1200/500');">
                <div class="overlay">
                    <h2>Supplier Control</h2>
                    <p>Manage all your suppliers efficiently – add, edit, and track every vendor.</p>
                    <a href="supplier_module.php" class="btn-white"><i class="fas fa-truck"></i> View Suppliers</a>
                </div>
            </div>
            <!-- Slide 3 -->
            <div class="slide" style="background-image: url('https://picsum.photos/seed/fashion3/1200/500');">
                <div class="overlay">
                    <h2>Smart Allocations</h2>
                    <p>Distribute PO items to branches with ease and generate printable reports.</p>
                    <a href="allocate_po.php" class="btn-white"><i class="fas fa-tasks"></i> Go to Allocations</a>
                </div>
            </div>
        </div>
        <!-- Dots -->
        <div class="slider-dots" id="sliderDots">
            <button class="dot active" data-index="0"></button>
            <button class="dot" data-index="1"></button>
            <button class="dot" data-index="2"></button>
        </div>
    </div>

    <!-- ===== KEY STATS ===== -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
            <div class="stat-number"><?php echo number_format($totalPOs); ?></div>
            <div class="stat-label">Total Purchase Orders</div>
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

        <!-- Total Logins (admin only) -->
        <?php if (isAdmin()): ?>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-history"></i></div>
                <div class="stat-number"><?php echo number_format($totalLogins); ?></div>
                <div class="stat-label">Total Logins</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ===== DASHBOARD ACTION CARDS ===== -->
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

            <!-- NEW: Companies & Store Locations -->
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

</div>

<!-- ===== SLIDER SCRIPT (Smooth Fade + Slide) ===== -->
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

            // Move the container horizontally (slide effect)
            slidesContainer.style.transform = `translateX(-${currentIndex * 100}%)`;

            // Toggle active class for opacity (fade effect)
            slides.forEach((slide, i) => {
                slide.classList.toggle('active', i === currentIndex);
            });

            // Update dots
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

        // Dot click
        dots.forEach(dot => {
            dot.addEventListener('click', function() {
                const index = parseInt(this.dataset.index);
                goToSlide(index);
                stopSlider();
                startSlider(); // reset timer
            });
        });

        // Pause on hover
        const slider = document.getElementById('heroSlider');
        slider.addEventListener('mouseenter', stopSlider);
        slider.addEventListener('mouseleave', startSlider);

        // Start auto-play
        startSlider();
    })();
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>