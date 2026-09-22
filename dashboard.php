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

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<style>
    /* ===== PREMIUM RED & WHITE MODERN THEME ===== */
    :root {
        --primary-red: #8B0000;
        --accent-red: #B71C1C;
        --bright-red: #D32F2F;
        --light-red-bg: #FFEBEE;
        --dark-bg: #0D1117;
        --card-white: #FFFFFF;
        --text-dark: #1E293B;
        --text-muted: #64748B;
        --border-light: #F1F5F9;
    }

    /* Header Bar */
    .dashboard-header-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: var(--card-white);
        padding: 22px 30px;
        border-radius: 24px;
        box-shadow: 0 10px 30px rgba(139, 0, 0, 0.05);
        margin-bottom: 28px;
        border: 1px solid rgba(139, 0, 0, 0.08);
        border-left: 6px solid var(--accent-red);
    }
    .welcome-text h2 {
        font-size: 24px;
        font-weight: 800;
        color: var(--text-dark);
        margin: 0;
        letter-spacing: -0.5px;
    }
    .welcome-text p {
        margin: 3px 0 0 0;
        font-size: 13.5px;
        color: var(--text-muted);
        font-weight: 500;
    }
    .welcome-text .role-badge {
        background: var(--light-red-bg);
        color: var(--accent-red);
        padding: 3px 12px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 12px;
        text-transform: uppercase;
    }

    /* Sri Lanka Clock Widget */
    .colombo-clock-widget {
        display: flex;
        align-items: center;
        gap: 16px;
        background: linear-gradient(135deg, var(--primary-red) 0%, #4A0000 100%);
        color: #FFFFFF;
        padding: 12px 24px;
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(139, 0, 0, 0.3);
    }
    .colombo-clock-widget .time-icon {
        font-size: 26px;
        color: #FFCDD2;
        animation: pulseHeart 2s infinite ease-in-out;
    }
    @keyframes pulseHeart {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.15); opacity: 0.85; }
    }
    .colombo-clock-widget .time-details { text-align: right; }
    .colombo-clock-widget .clock-display {
        font-size: 20px;
        font-weight: 900;
        letter-spacing: 1.5px;
        font-family: 'Consolas', 'Courier New', monospace;
        color: #FFFFFF;
    }
    .colombo-clock-widget .tz-badge {
        font-size: 11px;
        color: #FFCDD2;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Hero Banner Slider */
    .hero-slider {
        position: relative;
        width: 100%;
        height: 310px;
        border-radius: 28px;
        overflow: hidden;
        margin-bottom: 35px;
        box-shadow: 0 16px 40px rgba(139, 0, 0, 0.22);
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
    .hero-slider .slide.active { opacity: 1; }
    .hero-slider .slide .overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(139, 0, 0, 0.85) 0%, rgba(13, 17, 23, 0.88) 100%);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        color: white;
        text-align: center;
        padding: 35px;
        backdrop-filter: blur(3px);
    }
    .hero-slider .slide .overlay h2 {
        font-size: 2.6rem;
        font-weight: 900;
        letter-spacing: -0.5px;
        text-shadow: 0 4px 20px rgba(0,0,0,0.5);
        margin-bottom: 10px;
    }
    .hero-slider .slide .overlay p {
        font-size: 1.1rem;
        opacity: 0.92;
        max-width: 650px;
        font-weight: 400;
        line-height: 1.6;
    }
    .hero-slider .slide .overlay .btn-white {
        margin-top: 22px;
        background: var(--card-white);
        color: var(--accent-red);
        padding: 13px 36px;
        border-radius: 50px;
        font-weight: 800;
        font-size: 14.5px;
        text-decoration: none;
        transition: all 0.3s ease;
        box-shadow: 0 10px 25px rgba(0,0,0,0.25);
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }
    .hero-slider .slide .overlay .btn-white:hover {
        transform: translateY(-4px) scale(1.04);
        background: var(--light-red-bg);
        box-shadow: 0 14px 32px rgba(0,0,0,0.35);
    }
    .slider-dots {
        position: absolute;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 12px;
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
        background: var(--card-white);
        transform: scale(1.35);
        box-shadow: 0 0 14px rgba(255,255,255,0.9);
    }

    /* Key Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 22px;
        margin-bottom: 38px;
    }
    .stat-card {
        background: var(--card-white);
        padding: 24px 20px;
        border-radius: 22px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        text-align: center;
        border: 1px solid var(--border-light);
        border-bottom: 5px solid var(--accent-red);
        transition: transform 0.35s ease, box-shadow 0.35s ease;
        position: relative;
        overflow: hidden;
    }
    .stat-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 14px 35px rgba(139,0,0,0.14);
    }
    .stat-card .stat-number {
        font-size: 32px;
        font-weight: 900;
        color: var(--accent-red);
        line-height: 1.1;
    }
    .stat-card .stat-label {
        font-size: 12.5px;
        color: var(--text-muted);
        font-weight: 700;
        margin-top: 8px;
        text-transform: uppercase;
        letter-spacing: 0.7px;
    }
    .stat-card .stat-icon {
        font-size: 28px;
        color: var(--accent-red);
        opacity: 0.2;
        margin-bottom: 10px;
    }

    /* Action Cards Grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 24px;
        margin-top: 20px;
    }
    .dashboard-card {
        background: var(--card-white);
        border-radius: 22px;
        box-shadow: 0 4px 18px rgba(0,0,0,0.03);
        padding: 30px 24px 26px;
        text-align: center;
        transition: all 0.35s cubic-bezier(0.25, 0.8, 0.25, 1);
        border: 1px solid var(--border-light);
        border-top: 5px solid var(--accent-red);
        text-decoration: none;
        color: var(--text-dark);
        display: block;
        position: relative;
        overflow: hidden;
    }
    .dashboard-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        background: linear-gradient(135deg, rgba(139, 0, 0, 0.03), transparent);
        pointer-events: none;
    }
    .dashboard-card:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: 0 18px 40px rgba(139, 0, 0, 0.12);
        border-color: rgba(139, 0, 0, 0.2);
    }
    .dashboard-card .icon {
        font-size: 46px;
        color: var(--accent-red);
        margin-bottom: 16px;
        transition: transform 0.4s ease;
    }
    .dashboard-card:hover .icon { transform: scale(1.15) rotate(-4deg); }
    .dashboard-card h3 {
        font-size: 17px;
        font-weight: 800;
        margin-bottom: 8px;
        color: var(--text-dark);
    }
    .dashboard-card p {
        font-size: 12.5px;
        color: var(--text-muted);
        margin: 0;
        font-weight: 500;
        line-height: 1.45;
    }

    /* GRN Section */
    .grn-section { margin-top: 40px; }
    .grn-section h4 {
        font-weight: 900;
        color: var(--text-dark);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 19px;
    }
    .grn-section h4 i { color: var(--accent-red); }
    .grn-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 24px;
    }
    .main-grn { margin-top: 0; }
    .main-grn h2 {
        text-align: center;
        margin-bottom: 30px;
        color: var(--text-dark);
        font-weight: 900;
    }
    .main-grn .grn-grid {
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        max-width: 720px;
        margin: 0 auto;
    }

    @media (max-width: 768px) {
        .dashboard-header-bar { flex-direction: column; align-items: flex-start; gap: 16px; }
        .colombo-clock-widget { width: 100%; justify-content: space-between; }
        .hero-slider { height: 250px; }
        .hero-slider .slide .overlay h2 { font-size: 1.8rem; }
        .hero-slider .slide .overlay p { font-size: 0.95rem; }
        .stats-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 480px) {
        .hero-slider { height: 220px; }
        .stats-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="container-fluid" style="padding: 22px 28px;">

    <!-- ===== LIVE SRI LANKA TIME & HEADER ===== -->
    <div class="dashboard-header-bar">
        <div class="welcome-text">
            <h2>System Dashboard</h2>
            <p>Welcome back, <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></strong> (<span class="role-badge"><?php echo htmlspecialchars($userRole); ?></span>)</p>
        </div>
        <div class="colombo-clock-widget">
            <div class="time-icon"><i class="fas fa-clock"></i></div>
            <div class="time-details">
                <div class="clock-display" id="colomboClock">--:--:-- --</div>
                <div class="tz-badge"><i class="fas fa-map-marker-alt" style="color:#FFCDD2; margin-right:4px;"></i> Colombo, Sri Lanka (Asia/Colombo)</div>
            </div>
        </div>
    </div>

    <!-- ===== HERO SLIDESHOW ===== -->
    <div class="hero-slider" id="heroSlider">
        <div class="slides" id="slidesContainer">
            <?php if (!$isReceived): ?>
                <div class="slide active" style="background-image: url('https://picsum.photos/seed/fashion1/1200/500');">
                    <div class="overlay">
                        <h2>Welcome to ASB Group</h2>
                        <p>Streamline purchase orders, allocations, and supplier operations in one centralized portal.</p>
                        <a href="create_po.php" class="btn-white"><i class="fas fa-plus-circle"></i> Create New PO</a>
                    </div>
                </div>
                <div class="slide" style="background-image: url('https://picsum.photos/seed/fashion2/1200/500');">
                    <div class="overlay">
                        <h2>Supplier Control Center</h2>
                        <p>Track vendor activities, modify account info, and manage inventory pipelines seamlessly.</p>
                        <a href="supplier_module.php" class="btn-white"><i class="fas fa-truck"></i> View Suppliers</a>
                    </div>
                </div>
                <div class="slide" style="background-image: url('https://picsum.photos/seed/fashion3/1200/500');">
                    <div class="overlay">
                        <h2>Smart Allocations</h2>
                        <p>Distribute purchase order line items across branch networks with automated split presets.</p>
                        <a href="allocate_po.php" class="btn-white"><i class="fas fa-tasks"></i> Go to Allocations</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="slide active" style="background-image: url('https://picsum.photos/seed/grn1/1200/500');">
                    <div class="overlay">
                        <h2>Goods Receipt Notes</h2>
                        <p>Process incoming warehouse shipments and print automated GRN documentation.</p>
                        <a href="receive_po_dashboard.php" class="btn-white"><i class="fas fa-arrow-down"></i> Receive PO</a>
                    </div>
                </div>
                <div class="slide" style="background-image: url('https://picsum.photos/seed/grn2/1200/500');">
                    <div class="overlay">
                        <h2>GRN Print & Audit</h2>
                        <p>Access historical receipt notes and generate official physical report copies.</p>
                        <a href="view_pos.php" class="btn-white"><i class="fas fa-print"></i> Print GRN</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
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
        <!-- ===== KEY METRICS ===== -->
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
            <?php if ($isAdmin): ?>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-history"></i></div>
                    <div class="stat-number"><?php echo number_format($totalLogins); ?></div>
                    <div class="stat-label">Total Logins</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== DYNAMIC MAIN TABS (FROM DATABASE) ===== -->
        <div class="dashboard-grid">
            <?php foreach ($mainTabs as $tab): ?>
                <a href="<?php echo htmlspecialchars($tab['url']); ?>" class="dashboard-card" style="border-top-color: <?php echo htmlspecialchars($tab['border_color']); ?>;">
                    <div class="icon" style="color: <?php echo htmlspecialchars($tab['border_color']); ?>;"><i class="<?php echo htmlspecialchars($tab['icon']); ?>"></i></div>
                    <h3><?php echo htmlspecialchars($tab['title']); ?></h3>
                    <p><?php echo htmlspecialchars($tab['description']); ?></p>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ===== DYNAMIC GRN TABS (FROM DATABASE) ===== -->
        <?php if (!empty($grnTabs)): ?>
            <div class="grn-section">
                <h4><i class="fas fa-clipboard-list"></i> Goods Receipt Notes (GRN)</h4>
                <div class="grn-grid">
                    <?php foreach ($grnTabs as $tab): ?>
                        <a href="<?php echo htmlspecialchars($tab['url']); ?>" class="dashboard-card" style="border-top-color: <?php echo htmlspecialchars($tab['border_color']); ?>;">
                            <div class="icon" style="color: <?php echo htmlspecialchars($tab['border_color']); ?>;"><i class="<?php echo htmlspecialchars($tab['icon']); ?>"></i></div>
                            <h3><?php echo htmlspecialchars($tab['title']); ?></h3>
                            <p><?php echo htmlspecialchars($tab['description']); ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ===== ONLY GRN SECTION FOR RECEIVED ROLE ===== -->
        <div class="main-grn">
            <h2>Goods Receipt Notes</h2>
            <div class="grn-grid">
                <?php foreach ($grnTabs as $tab): ?>
                    <a href="<?php echo htmlspecialchars($tab['url']); ?>" class="dashboard-card" style="border-top-color: <?php echo htmlspecialchars($tab['border_color']); ?>;">
                        <div class="icon" style="color: <?php echo htmlspecialchars($tab['border_color']); ?>;"><i class="<?php echo htmlspecialchars($tab['icon']); ?>"></i></div>
                        <h3><?php echo htmlspecialchars($tab['title']); ?></h3>
                        <p><?php echo htmlspecialchars($tab['description']); ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- ===== REAL-TIME SRI LANKA CLOCK & SLIDER SCRIPTS ===== -->
<script>
    // Live Real-Time Sri Lanka (Asia/Colombo) Clock Engine
    function updateColomboClock() {
        const options = {
            timeZone: 'Asia/Colombo',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        };
        const formatter = new Intl.DateTimeFormat('en-US', options);
        const now = new Date();
        document.getElementById('colomboClock').textContent = formatter.format(now);
    }
    setInterval(updateColomboClock, 1000);
    updateColomboClock();

    // Hero Banner Slideshow
    (function() {
        const slidesContainer = document.getElementById('slidesContainer');
        if (!slidesContainer) return;

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
        if (slider) {
            slider.addEventListener('mouseenter', stopSlider);
            slider.addEventListener('mouseleave', startSlider);
        }

        startSlider();
    })();
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>