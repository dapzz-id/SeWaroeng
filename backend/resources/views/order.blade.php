<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pemesanan Meja — SeWaroeng</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #059669;
            --primary-dark: #047857;
            --primary-light: #ecfdf5;
            --accent: #d97706;
            --bg-base: #f8fafc;
            --surface: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --danger: #ef4444;
            --radius-md: 12px;
            --radius-lg: 18px;
            --radius-full: 9999px;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -4px rgba(0,0,0,0.04);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.06);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            line-height: 1.5;
            padding-bottom: 120px;
        }

        /* Header & Hero */
        header {
            background: linear-gradient(135deg, #064e3b 0%, #047857 50%, #059669 100%);
            color: white;
            padding: 24px 20px 28px;
            border-bottom-left-radius: 24px;
            border-bottom-right-radius: 24px;
            box-shadow: var(--shadow-md);
            position: sticky;
            top: 0;
            z-index: 40;
        }

        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .brand-badge {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.5px;
            background: rgba(255,255,255,0.15);
            padding: 4px 10px;
            border-radius: var(--radius-full);
            backdrop-filter: blur(8px);
        }

        .outlet-title {
            font-size: 20px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.3px;
        }

        .table-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: white;
            color: #064e3b;
            font-size: 13px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: var(--radius-full);
            margin-top: 10px;
            box-shadow: var(--shadow-sm);
        }

        .table-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            display: inline-block;
        }

        /* Search & Categories */
        .controls-bar {
            padding: 16px 20px 8px;
            position: sticky;
            top: 110px;
            background: var(--bg-base);
            z-index: 30;
        }

        .category-scroll {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 6px;
            scrollbar-width: none;
        }

        .category-scroll::-webkit-scrollbar {
            display: none;
        }

        .cat-chip {
            padding: 8px 16px;
            border-radius: var(--radius-full);
            background: white;
            border: 1px solid var(--border);
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .cat-chip.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.3);
        }

        /* Products Grid */
        .container {
            max-width: 640px;
            margin: 0 auto;
            padding: 12px 20px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin: 16px 0 10px;
            color: var(--text-main);
        }

        .product-card {
            background: var(--surface);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            margin-bottom: 12px;
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-sm);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            cursor: pointer;
        }

        .product-card:active {
            transform: scale(0.99);
        }

        .product-info {
            flex: 1;
            padding-right: 14px;
        }

        .product-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 2px;
        }

        .product-desc {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.4;
            margin-bottom: 6px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-price {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
        }

        .btn-add {
            background: var(--primary-light);
            color: var(--primary);
            border: 1px solid rgba(5, 150, 105, 0.2);
            font-weight: 700;
            font-size: 13px;
            padding: 8px 16px;
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-add:hover {
            background: var(--primary);
            color: white;
        }

        /* Floating Cart Bar */
        .floating-cart-bar {
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
            max-width: 600px;
            margin: 0 auto;
            background: #0f172a;
            color: white;
            border-radius: 18px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-xl);
            z-index: 50;
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease;
            transform: translateY(120%);
            opacity: 0;
            pointer-events: none;
        }

        .floating-cart-bar.visible {
            transform: translateY(0);
            opacity: 1;
            pointer-events: auto;
        }

        .cart-summary {
            display: flex;
            flex-direction: column;
        }

        .cart-count {
            font-size: 12px;
            color: #94a3b8;
            font-weight: 600;
        }

        .cart-total {
            font-size: 16px;
            font-weight: 800;
            color: #34d399;
        }

        .btn-view-cart {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius-full);
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.4);
        }

        /* Bottom Sheet / Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 100;
            display: none;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .modal-overlay.open {
            display: block;
            opacity: 1;
        }

        .bottom-sheet {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            max-width: 640px;
            margin: 0 auto;
            background: white;
            border-top-left-radius: 24px;
            border-top-right-radius: 24px;
            padding: 24px 20px 32px;
            max-height: 85vh;
            overflow-y: auto;
            transform: translateY(100%);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 101;
        }

        .modal-overlay.open .bottom-sheet {
            transform: translateY(0);
        }

        .sheet-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .sheet-title {
            font-size: 18px;
            font-weight: 800;
        }

        .btn-close {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Cart Items List */
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .cart-item-title {
            font-size: 14px;
            font-weight: 700;
        }

        .cart-item-subtitle {
            font-size: 12px;
            color: var(--text-muted);
        }

        .cart-item-price {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
        }

        .qty-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 6px;
        }

        .qty-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: white;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .form-group {
            margin: 16px 0;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--text-main);
        }

        .form-input {
            width: 100%;
            padding: 12px 14px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-input:focus {
            border-color: var(--primary);
        }

        .btn-submit-order {
            width: 100%;
            background: var(--primary);
            color: white;
            border: none;
            padding: 16px;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 16px;
        }

        .btn-submit-order:disabled {
            background: #94a3b8;
            box-shadow: none;
            cursor: not-allowed;
        }

        /* Order Status Screen */
        .status-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 24px 20px;
            text-align: center;
            box-shadow: var(--shadow-md);
            margin-top: 20px;
            border: 1px solid var(--border);
        }

        .status-badge {
            display: inline-block;
            background: #ecfdf5;
            color: #059669;
            font-weight: 800;
            font-size: 14px;
            padding: 8px 18px;
            border-radius: var(--radius-full);
            margin-bottom: 12px;
        }

        .order-stepper {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin: 32px 0 20px;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            z-index: 2;
        }

        .step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e2e8f0;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .step-item.active .step-circle {
            background: var(--primary);
        }

        .step-item.active {
            color: var(--primary);
            font-weight: 700;
        }

        /* Feedback / Error states */
        .error-banner {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 16px 20px;
            border-radius: var(--radius-md);
            margin: 20px;
            text-align: center;
        }

        .skeleton {
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 6px;
        }

        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid white;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    @if ($initialError)
        <div class="error-banner">
            <h3 style="font-size: 16px; font-weight: 800; margin-bottom: 4px;">Pemberitahuan</h3>
            <p style="font-size: 14px;">{{ $initialError }}</p>
        </div>
    @else
        <!-- Header -->
        <header id="mainHeader">
            <div class="header-top">
                <span class="brand-badge">⚡ SeWaroeng Ordering</span>
                <span id="outletStatusBadge" style="font-size: 12px; opacity: 0.9;">Buka</span>
            </div>
            <h1 class="outlet-title" id="outletNameHeader">Memuat Warung...</h1>
            <div class="table-badge">
                <span class="table-dot"></span>
                <span id="tableNameHeader">Meja ...</span>
            </div>
        </header>

        <!-- Category Bar -->
        <div class="controls-bar" id="controlsBar">
            <div class="category-scroll" id="categoryTabs">
                <div class="cat-chip active" data-cat="all">Semua Menu</div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="container" id="menuContainer">
            <div id="loadingSkeletons">
                <div class="product-card">
                    <div style="flex: 1;">
                        <div class="skeleton" style="width: 60%; height: 18px; margin-bottom: 8px;"></div>
                        <div class="skeleton" style="width: 90%; height: 12px; margin-bottom: 6px;"></div>
                        <div class="skeleton" style="width: 30%; height: 14px;"></div>
                    </div>
                </div>
                <div class="product-card">
                    <div style="flex: 1;">
                        <div class="skeleton" style="width: 50%; height: 18px; margin-bottom: 8px;"></div>
                        <div class="skeleton" style="width: 80%; height: 12px; margin-bottom: 6px;"></div>
                        <div class="skeleton" style="width: 25%; height: 14px;"></div>
                    </div>
                </div>
            </div>

            <div id="productsList"></div>
            <div id="emptyMenuState" style="display: none; text-align: center; padding: 40px 20px; color: var(--text-muted);">
                <p style="font-weight: 600; font-size: 15px;">Belum ada menu yang tersedia saat ini.</p>
            </div>
        </div>

        <!-- Order Live Tracking Screen (Hidden by Default) -->
        <div class="container" id="orderStatusSection" style="display: none;">
            <div class="status-card">
                <div class="status-badge" id="orderStatusBadge">Pesanan Diterima</div>
                <h2 style="font-size: 18px; font-weight: 800; margin-bottom: 4px;" id="orderNumberTitle">ORD-...</h2>
                <p style="font-size: 13px; color: var(--text-muted);">Pesanan Anda sedang diproses oleh dapur.</p>

                <div class="order-stepper">
                    <div class="step-item active" id="step1">
                        <div class="step-circle">1</div>
                        <span>Diterima</span>
                    </div>
                    <div class="step-item" id="step2">
                        <div class="step-circle">2</div>
                        <span>Disiapkan</span>
                    </div>
                    <div class="step-item" id="step3">
                        <div class="step-circle">3</div>
                        <span>Siap</span>
                    </div>
                    <div class="step-item" id="step4">
                        <div class="step-circle">4</div>
                        <span>Selesai</span>
                    </div>
                </div>

                <div style="text-align: left; margin-top: 20px; border-top: 1px solid var(--border); padding-top: 14px;">
                    <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 6px;">
                        <span style="color: var(--text-muted);">Nama Pemesan</span>
                        <strong id="statusCustName">-</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 6px;">
                        <span style="color: var(--text-muted);">Jumlah Item</span>
                        <strong id="statusItemCount">0</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 15px; margin-top: 10px; border-top: 1px dashed var(--border); padding-top: 10px;">
                        <span style="font-weight: 700;">Total Tagihan</span>
                        <strong style="color: var(--primary); font-size: 17px;" id="statusTotalAmount">Rp 0</strong>
                    </div>
                </div>

                <button type="button" class="btn-submit-order" style="margin-top: 20px;" onclick="location.reload();">
                    Pesan Lagi
                </button>
            </div>
        </div>

        <!-- Floating Cart Bar -->
        <div class="floating-cart-bar" id="floatingCart">
            <div class="cart-summary">
                <span class="cart-count" id="cartItemCount">0 item</span>
                <span class="cart-total" id="cartTotalDisplay">Rp 0</span>
            </div>
            <button type="button" class="btn-view-cart" onclick="openCartModal()">Lihat Pesanan →</button>
        </div>

        <!-- Cart Bottom Sheet Modal -->
        <div class="modal-overlay" id="cartModal">
            <div class="bottom-sheet">
                <div class="sheet-header">
                    <h2 class="sheet-title">Keranjang Pesanan</h2>
                    <button type="button" class="btn-close" onclick="closeCartModal()">✕</button>
                </div>

                <div id="cartItemsList" style="max-height: 40vh; overflow-y: auto;"></div>

                <div class="form-group">
                    <label class="form-label">Nama Pemesan (Opsional)</label>
                    <input type="text" id="custNameInput" class="form-input" placeholder="Misal: Budi">
                </div>

                <div class="form-group">
                    <label class="form-label">Catatan Tambahan Meja</label>
                    <input type="text" id="custNotesInput" class="form-input" placeholder="Misal: Jangan terlalu pedas">
                </div>

                <div style="border-top: 1px solid var(--border); padding-top: 14px; margin-top: 14px;">
                    <div style="display: flex; justify-content: space-between; font-size: 16px; font-weight: 800;">
                        <span>Estimasi Total</span>
                        <span style="color: var(--primary);" id="sheetTotalDisplay">Rp 0</span>
                    </div>
                </div>

                <button type="button" class="btn-submit-order" id="btnSubmitOrder" onclick="submitOrder()">
                    <span id="btnSubmitText">Kirim Pesanan ke Dapur</span>
                    <div class="spinner" id="btnSubmitSpinner" style="display: none;"></div>
                </button>
            </div>
        </div>
    @endif

    <script>
        const QR_TOKEN = "{{ $token }}";
        let tableData = null;
        let menuProducts = [];
        let cart = [];
        let pollingTimer = null;
        let clientOrderIdempotencyKey = generateUUID();

        function generateUUID() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                const r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }

        function formatRupiah(num) {
            return 'Rp ' + Number(num).toLocaleString('id-ID');
        }

        // Initialize table context and load menu
        async function initPage() {
            try {
                const [tableRes, menuRes] = await Promise.all([
                    fetch(`/api/v1/public/tables/${QR_TOKEN}`).then(r => r.json()),
                    fetch(`/api/v1/public/tables/${QR_TOKEN}/menu`).then(r => r.json())
                ]);

                if (!tableRes.data) {
                    showError(tableRes.message || 'Meja tidak ditemukan.');
                    return;
                }

                tableData = tableRes.data;
                document.getElementById('outletNameHeader').textContent = tableData.outlet?.name || tableData.outlet_name || 'Outlet';
                document.getElementById('tableNameHeader').textContent = (tableData.table?.name || tableData.table_name || 'Meja') + ' (' + (tableData.table?.code || tableData.table_code || '') + ')';

                if (menuRes.data && menuRes.data.products) {
                    menuProducts = menuRes.data.products;
                    renderCategories(menuRes.data.categories || []);
                    renderProducts(menuProducts);
                } else {
                    document.getElementById('emptyMenuState').style.display = 'block';
                }

                document.getElementById('loadingSkeletons').style.display = 'none';

                // Restore active order status view if customer has an ongoing order in this browser session
                const savedOrder = sessionStorage.getItem('active_order_' + QR_TOKEN);
                if (savedOrder) {
                    try {
                        const parsed = JSON.parse(savedOrder);
                        if (parsed.order_number && parsed.tracking_token) {
                            showOrderStatus(parsed.order_number, parsed.tracking_token);
                        }
                    } catch (e) {}
                }
            } catch (err) {
                showError('Gagal memuat data meja. Periksa jaringan internet Anda.');
            }
        }

        function renderCategories(categories) {
            const tabs = document.getElementById('categoryTabs');
            categories.forEach(cat => {
                const chip = document.createElement('div');
                chip.className = 'cat-chip';
                chip.textContent = cat;
                chip.dataset.cat = cat;
                chip.onclick = () => filterCategory(cat, chip);
                tabs.appendChild(chip);
            });
        }

        function filterCategory(category, activeEl) {
            document.querySelectorAll('.cat-chip').forEach(el => el.classList.remove('active'));
            activeEl.classList.add('active');

            if (category === 'all') {
                renderProducts(menuProducts);
            } else {
                renderProducts(menuProducts.filter(p => p.category === category));
            }
        }

        function renderProducts(products) {
            const container = document.getElementById('productsList');
            container.innerHTML = '';

            if (products.length === 0) {
                document.getElementById('emptyMenuState').style.display = 'block';
                return;
            }
            document.getElementById('emptyMenuState').style.display = 'none';

            products.forEach(p => {
                const defaultVariant = p.variants?.find(v => v.is_default) || p.variants?.[0];
                const price = defaultVariant ? defaultVariant.price_minor : 0;

                const card = document.createElement('div');
                card.className = 'product-card';
                card.innerHTML = `
                    <div class="product-info">
                        <div class="product-name">${escapeHtml(p.name)}</div>
                        <div class="product-desc">${escapeHtml(p.description || '')}</div>
                        <div class="product-price">${formatRupiah(price)}</div>
                    </div>
                    <button class="btn-add" onclick="addToCart('${p.id}', '${defaultVariant?.id}')">+ Tambah</button>
                `;
                container.appendChild(card);
            });
        }

        function addToCart(productId, variantId) {
            const product = menuProducts.find(p => p.id === productId);
            if (!product) return;

            const variant = product.variants.find(v => v.id === variantId) || product.variants[0];
            const existing = cart.find(item => item.product_variant_id === variant.id);

            if (existing) {
                existing.quantity += 1;
            } else {
                cart.push({
                    product_id: product.id,
                    product_variant_id: variant.id,
                    product_name: product.name,
                    variant_name: variant.name,
                    price_minor: variant.price_minor,
                    quantity: 1
                });
            }

            updateCartUI();
        }

        function updateCartQty(variantId, delta) {
            const item = cart.find(i => i.product_variant_id === variantId);
            if (!item) return;

            item.quantity += delta;
            if (item.quantity <= 0) {
                cart = cart.filter(i => i.product_variant_id !== variantId);
            }

            updateCartUI();
            renderCartModalItems();
        }

        function updateCartUI() {
            const totalItems = cart.reduce((sum, i) => sum + i.quantity, 0);
            const totalAmount = cart.reduce((sum, i) => sum + (i.price_minor * i.quantity), 0);

            const cartBar = document.getElementById('floatingCart');
            if (totalItems > 0) {
                cartBar.classList.add('visible');
                document.getElementById('cartItemCount').textContent = `${totalItems} item dipilih`;
                document.getElementById('cartTotalDisplay').textContent = formatRupiah(totalAmount);
                document.getElementById('sheetTotalDisplay').textContent = formatRupiah(totalAmount);
            } else {
                cartBar.classList.remove('visible');
                closeCartModal();
            }
        }

        function openCartModal() {
            renderCartModalItems();
            document.getElementById('cartModal').classList.add('open');
        }

        function closeCartModal() {
            document.getElementById('cartModal').classList.remove('open');
        }

        function renderCartModalItems() {
            const list = document.getElementById('cartItemsList');
            list.innerHTML = '';

            cart.forEach(item => {
                const row = document.createElement('div');
                row.className = 'cart-item';
                row.innerHTML = `
                    <div>
                        <div class="cart-item-title">${escapeHtml(item.product_name)}</div>
                        <div class="cart-item-subtitle">${escapeHtml(item.variant_name)}</div>
                        <div class="qty-controls">
                            <button class="qty-btn" onclick="updateCartQty('${item.product_variant_id}', -1)">-</button>
                            <span style="font-weight: 700; font-size: 14px;">${item.quantity}</span>
                            <button class="qty-btn" onclick="updateCartQty('${item.product_variant_id}', 1)">+</button>
                        </div>
                    </div>
                    <div class="cart-item-price">${formatRupiah(item.price_minor * item.quantity)}</div>
                `;
                list.appendChild(row);
            });
        }

        async function submitOrder() {
            if (cart.length === 0) return;

            const btn = document.getElementById('btnSubmitOrder');
            const btnText = document.getElementById('btnSubmitText');
            const spinner = document.getElementById('btnSubmitSpinner');

            btn.disabled = true;
            btnText.textContent = 'Mengirim Pesanan...';
            spinner.style.display = 'block';

            const payload = {
                items: cart.map(i => ({
                    product_variant_id: i.product_variant_id,
                    quantity: i.quantity
                })),
                customer_name: document.getElementById('custNameInput').value.trim() || null,
                customer_notes: document.getElementById('custNotesInput').value.trim() || null,
                idempotency_key: clientOrderIdempotencyKey
            };

            try {
                const res = await fetch(`/api/v1/public/tables/${QR_TOKEN}/orders`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Idempotency-Key': clientOrderIdempotencyKey
                    },
                    body: JSON.stringify(payload)
                });

                const result = await res.json();

                if (res.status === 201 && result.data) {
                    closeCartModal();
                    if (result.data.order_number && result.data.tracking_token) {
                        try {
                            sessionStorage.setItem('active_order_' + QR_TOKEN, JSON.stringify({
                                order_number: result.data.order_number,
                                tracking_token: result.data.tracking_token
                            }));
                        } catch (e) {}
                    }
                    showOrderStatus(result.data.order_number, result.data.tracking_token);
                } else {
                    alert(result.message || 'Gagal mengirim pesanan. Silakan coba kembali.');
                    btn.disabled = false;
                    btnText.textContent = 'Kirim Pesanan ke Dapur';
                    spinner.style.display = 'none';
                }
            } catch (err) {
                alert('Terjadi gangguan koneksi. Pesanan belum terkirim.');
                btn.disabled = false;
                btnText.textContent = 'Kirim Pesanan ke Dapur';
                spinner.style.display = 'none';
            }
        }

        function showOrderStatus(orderNumber, trackingToken) {
            document.getElementById('mainHeader').style.display = 'block';
            document.getElementById('controlsBar').style.display = 'none';
            document.getElementById('menuContainer').style.display = 'none';
            document.getElementById('floatingCart').style.display = 'none';
            document.getElementById('orderStatusSection').style.display = 'block';
            document.getElementById('orderNumberTitle').textContent = orderNumber;

            pollOrderStatus(trackingToken);
        }

        async function pollOrderStatus(trackingToken) {
            if (!trackingToken) return;

            try {
                const res = await fetch(`/api/v1/public/tables/${QR_TOKEN}/orders/${trackingToken}`);
                const data = await res.json();

                if (data.data) {
                    const order = data.data;
                    document.getElementById('orderStatusBadge').textContent = order.status_label || order.status;
                    document.getElementById('statusCustName').textContent = order.customer_name || '-';
                    document.getElementById('statusItemCount').textContent = order.item_count + ' item';
                    document.getElementById('statusTotalAmount').textContent = formatRupiah(order.total_minor);

                    // Update Stepper with customer-facing status projection
                    // Step 1: received (submitted, accepted, or converted to open sale)
                    // Step 2: preparing
                    // Step 3: ready
                    // Step 4: served
                    const projected = order.public_status || (['submitted', 'accepted', 'converted'].includes(order.status) ? 'received' : order.status);
                    const steps = ['received', 'preparing', 'ready', 'served'];
                    const currentIdx = steps.indexOf(projected);
                    for (let i = 1; i <= 4; i++) {
                        const stepEl = document.getElementById('step' + i);
                        if (stepEl) {
                            if (currentIdx >= 0 && i <= currentIdx + 1) {
                                stepEl.classList.add('active');
                            } else {
                                stepEl.classList.remove('active');
                            }
                        }
                    }

                    // Stop polling and remove saved order ONLY on true customer-facing terminal states
                    const isTerminal = order.is_terminal ?? ['served', 'cancelled'].includes(projected);
                    if (isTerminal) {
                        try {
                            sessionStorage.removeItem('active_order_' + QR_TOKEN);
                        } catch (e) {}
                        return;
                    }
                }
            } catch (e) {
                // Ignore transient network errors during polling
            }

            pollingTimer = setTimeout(() => pollOrderStatus(trackingToken), 5000);
        }

        function showError(msg) {
            const container = document.getElementById('menuContainer');
            if (container) {
                container.innerHTML = `<div class="error-banner"><p style="font-weight:700;">${escapeHtml(msg)}</p></div>`;
            }
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        @if (!$initialError)
            document.addEventListener('DOMContentLoaded', initPage);
        @endif
    </script>
</body>
</html>
