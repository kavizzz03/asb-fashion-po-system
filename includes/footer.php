<!-- End of page content -->
        </div>
        <!-- END .main-content -->

        <!-- ===== ONE-LINE FOOTER ===== -->
        <footer class="site-footer no-print">
            <style>
                .site-footer { margin: 0 30px; padding: 1rem .5rem calc(1.2rem + env(safe-area-inset-bottom, 0px)); border-top: 1px solid #f3d6d6; display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: .15rem .6rem; text-align: center; font-family: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: .78rem; font-weight: 500; color: #7a6a6d; }
                .site-footer b { color: #b91c1c; font-weight: 700; }
                .site-footer a { color: #1a1214; font-weight: 700; white-space: nowrap; transition: color .25s; }
                .site-footer a:hover { color: #b91c1c; text-decoration: underline; }
                .site-footer .sep { color: #e2c4c4; }
                @media (max-width: 768px) { .site-footer { margin: 0 12px; } }
            </style>
            <span>&copy; <?php echo date('Y'); ?> <b>ASB Group of Companies</b></span>
            <span class="sep">|</span>
            <span>Designed and developed by <a href="https://www.vexelit.xyz/" target="_blank" rel="noopener">Vexel IT by Kavizz</a></span>
            <span class="sep">|</span>
            <a href="mailto:Info.vexelit@gmail.com">Info.vexelit@gmail.com</a>
            <span class="sep">|</span>
            <a href="https://www.vexelit.xyz/" target="_blank" rel="noopener">www.vexelit.xyz</a>
        </footer>
    </div>
    <!-- END .app-container -->

    <script>
        // Sidebar toggle (kept for pages that still use a sidebar)
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.toggle('open');
        }
        document.addEventListener('click', function (e) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.mobile-menu-btn');
            if (window.innerWidth <= 768 && sidebar && sidebar.classList.contains('open')) {
                if (!sidebar.contains(e.target) && !(menuBtn && menuBtn.contains(e.target))) {
                    sidebar.classList.remove('open');
                }
            }
        });
    </script>
</body>
</html>