<?php
// pos.php - Point of Sale Module (Supabase PostgreSQL Compatible)
session_start();
require_once __DIR__ . '/config/database.php';

$userId = $_SESSION['user_id'] ?? 1;
$posInventoryItems = [];
$debugError = '';

// Fetch inventory stocks ensuring deleted cards and items with less than 1 stock are excluded
try {
    $stmtPOS = $pdo->prepare("
        SELECT 
            i.id AS stock_id, 
            c.id AS card_id, 
            COALESCE(c.name, 'Unknown Card') AS name, 
            COALESCE(c.card_number, 'N/A') AS card_number, 
            COALESCE(s.name, 'Standard Set') AS set_name, 
            COALESCE(c.language, 'en') AS card_language,
            COALESCE(c.artist, 'Unknown') AS illustrator,
            c.image_url,
            COALESCE(i.quantity, 0) AS quantity,
            COALESCE(i.sell_price, i.price, c.price, 0.00) AS price,
            COALESCE(i.buy_price, i.purchase_price, 0.00) AS purchase_price,
            COALESCE(i.card_condition, 'Near Mint (NM)') AS condition,
            COALESCE(i.is_cosigned, 0) AS is_cosigned
        FROM inventory_stocks i
        JOIN cards c ON i.card_id = c.id
        LEFT JOIN sets s ON c.set_id = s.id
        WHERE i.quantity >= 1 AND (c.deleted_at IS NULL)
        ORDER BY i.id DESC
    ");
    $stmtPOS->execute();
    $posInventoryItems = $stmtPOS->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        // Fallback query if c.artist or deleted_at check encounters schema differences
        $stmtPOSFallback = $pdo->prepare("
            SELECT 
                i.id AS stock_id, 
                c.id AS card_id, 
                COALESCE(c.name, 'Unknown Card') AS name, 
                COALESCE(c.card_number, 'N/A') AS card_number, 
                COALESCE(s.name, 'Standard Set') AS set_name, 
                COALESCE(c.language, 'en') AS card_language,
                'Unknown' AS illustrator,
                c.image_url,
                COALESCE(i.quantity, 0) AS quantity,
                COALESCE(i.sell_price, i.price, c.price, 0.00) AS price,
                COALESCE(i.buy_price, i.purchase_price, 0.00) AS purchase_price,
                COALESCE(i.card_condition, 'Near Mint (NM)') AS condition,
                COALESCE(i.is_cosigned, 0) AS is_cosigned
            FROM inventory_stocks i
            JOIN cards c ON i.card_id = c.id
            LEFT JOIN sets s ON c.set_id = s.id
            WHERE i.quantity >= 1
            ORDER BY i.id DESC
        ");
        $stmtPOSFallback->execute();
        $posInventoryItems = $stmtPOSFallback->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex2) {
        $debugError = $ex2->getMessage();
        $posInventoryItems = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS System - Trade Rocket TCG</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background-color: #07050d;
            color: #f4f4f5;
        }

        .tr-sidebar {
            background: rgba(15, 11, 26, 0.85);
            backdrop-filter: blur(12px);
            border-radius: 0.75rem;
        }

        .tr-card {
            background: rgba(22, 16, 38, 0.6);
            backdrop-filter: blur(8px);
            border-radius: 0.75rem;
            border: 1px solid rgba(147, 51, 234, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tr-card:hover {
            border-color: rgba(168, 85, 247, 0.6);
            background: rgba(28, 20, 48, 0.8);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(147, 51, 234, 0.2);
        }

        .purple-btn {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            transition: all 0.2s ease;
        }

        .purple-btn:hover {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
        }

        /* Glowing yellow indicator effect with thin purple outline */
        .cosign-glow-badge {
            background-color: #facc15;
            border: 1px solid rgba(147, 51, 234, 0.8);
            box-shadow: 0 0 8px rgba(250, 204, 21, 0.7), 0 0 12px rgba(250, 204, 21, 0.4);
        }

        /* Dark Mode Custom Scrollbar Styles */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #0d0918;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: #2a1b4e;
            border-radius: 4px;
            border: 1px solid rgba(147, 51, 234, 0.3);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #5b21b6;
        }

        /* Firefox Scrollbar */
        * {
            scrollbar-width: thin;
            scrollbar-color: #2a1b4e #0d0918;
        }

        /* Hide number input spinners for clean look */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        input[type=number] {
            -moz-appearance: textfield;
        }
    </style>
</head>

<body class="min-h-screen flex flex-col font-sans relative">

    <!-- Top Navigation Bar -->
    <?php include 'includes/header.php'; ?>

    <!-- Main Outer Container -->
    <div class="flex-1 flex flex-col lg:flex-row p-4 gap-4 w-full items-start">
        
        <!-- Products Catalog Section -->
        <div class="flex-1 flex flex-col space-y-3 min-w-0">
            
            <?php if (!empty($debugError)): ?>
                <div class="p-4 rounded-xl border bg-rose-950/40 border-rose-500/30 text-rose-300 text-xs">
                    <strong>Database Error:</strong> <?php echo htmlspecialchars($debugError); ?>
                </div>
            <?php endif; ?>

            <!-- Sticky Filter Bar & Header -->
            <div class="tr-sidebar p-3 border border-purple-900/30 flex flex-col md:flex-row justify-between items-start md:items-center gap-3 shrink-0 sticky top-0 z-10 shadow-lg">
                <h2 class="text-xs font-bold text-purple-200 uppercase tracking-wider whitespace-nowrap">
                    CARD CATALOG (<span id="totalFoundCount"><?php echo count($posInventoryItems); ?></span>)
                </h2>
                
                <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                    <!-- Sort Dropdown -->
                    <select id="posSortSelect" onchange="filterPosProducts()" class="bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-1.5 text-[11px] sm:text-xs text-white focus:outline-none cursor-pointer">
                        <option value="name_asc">Name (A-Z)</option>
                        <option value="name_desc">Name (Z-A)</option>
                        <option value="stock_desc">Stock (High to Low)</option>
                        <option value="stock_asc">Stock (Low to High)</option>
                    </select>

                    <!-- Cosigned Filter Dropdown -->
                    <select id="posCosignedFilter" onchange="filterPosProducts()" class="bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-1.5 text-[11px] sm:text-xs text-white focus:outline-none cursor-pointer">
                        <option value="all">All Items</option>
                        <option value="1">Cosigned Only</option>
                        <option value="0">Non-Cosigned</option>
                    </select>

                    <!-- Text Search -->
                    <input type="text" id="posSearchInput" placeholder="Search card name..." oninput="filterPosProducts()" class="bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-1.5 text-xs text-white focus:outline-none w-full sm:w-48 flex-1 md:flex-none">
                </div>
            </div>

            <!-- Product Grid -->
            <div id="posProductGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-7 gap-3">
                <?php if (count($posInventoryItems) > 0): ?>
                    <?php foreach ($posInventoryItems as $p): ?>
                        <?php 
                            $stockId = $p['stock_id']; 
                            $quantity = intval($p['quantity']);
                            $cardName = $p['name'];
                            $cardIllustrator = $p['illustrator'] ?? 'Unknown';
                            $cleanName = preg_replace('/\s*-\s*[^\(]+/', '', $cardName);
                            $cleanName = trim(preg_replace('/\s*\([^\)]+\)/', '', $cleanName));
                            
                            $cardLangCode = strtolower($p['card_language'] ?? 'en');
                            if ($cardLangCode === 'jp' || $cardLangCode === 'japanese') {
                                $cardLangCode = 'ja';
                            }
                            $displayPrice = floatval($p['price']);
                            $isCosigned = intval($p['is_cosigned']);
                        ?>
                        
                        <div id="product-card-<?php echo $stockId; ?>" 
                             class="tr-card p-2 flex flex-col justify-between pos-product-item" 
                             data-name="<?php echo htmlspecialchars(strtolower($cleanName)); ?>"
                             data-stock="<?php echo $quantity; ?>"
                             data-cosigned="<?php echo $isCosigned; ?>">
                            <div>
                                <!-- Card Image Display -->
                                <div class="w-full aspect-[3/4] bg-[#0a0810] rounded-md mb-2 flex items-center justify-center border border-purple-900/40 overflow-hidden relative shadow-inner">
                                    <img src="<?php echo !empty($p['image_url']) ? htmlspecialchars($p['image_url']) : 'https://images.placeholders.dev/?width=300&height=420&text=Trade+Rocket+TCG&bgColor=120f1d&textColor=a855f7'; ?>"
                                        alt="<?php echo htmlspecialchars($cardName); ?>"
                                        class="w-full h-full object-contain"
                                        loading="lazy">
                                </div>

                                <div class="flex justify-between items-start mb-0.5">
                                    <h3 class="font-bold text-white text-[11px] truncate flex-1" title="<?php echo htmlspecialchars($cardName); ?>"><?php echo htmlspecialchars($cardName); ?></h3>
                                    <span class="ml-1 text-[8px] bg-purple-950/50 text-purple-300 border border-purple-800/30 px-1 py-0.5 rounded uppercase font-bold"><?php echo htmlspecialchars(strtoupper($cardLangCode)); ?></span>
                                </div>
                                <p class="text-[9px] text-purple-300/60 truncate"><?php echo htmlspecialchars($p['set_name']); ?></p>
                                <p class="text-[9px] text-purple-300/80 font-semibold mt-0.5 truncate">No. <?php echo htmlspecialchars($p['card_number']); ?> <span class="text-purple-400/50 font-normal">| Art: <?php echo htmlspecialchars($cardIllustrator); ?></span></p>
                                
                                <div class="mt-1 text-[9px] text-purple-300/70 truncate">
                                    Condition: <span class="text-purple-200"><?php echo htmlspecialchars($p['condition']); ?></span> |
                                    Stock: <strong id="stock-count-<?php echo $stockId; ?>" class="text-white"><?php echo $quantity; ?></strong>
                                </div>
                                
                                <!-- Pricing Row -->
                                <div class="flex justify-between items-center mt-1">
                                    <p class="text-[11px] text-emerald-400 font-bold">₱<?php echo number_format($displayPrice, 2); ?></p>
                                    
                                    <?php if ($isCosigned === 1): ?>
                                        <div class="w-3 h-3 rounded-full cosign-glow-badge" title="Cosigned Item"></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <button type="button" id="add-btn-<?php echo $stockId; ?>" onclick="addToCart(<?php echo $stockId; ?>, '<?php echo htmlspecialchars(addslashes($cardName . ' (' . $p['condition'] . ')')); ?>', <?php echo $displayPrice; ?>, <?php echo max($quantity, 1); ?>)" class="mt-2 purple-btn text-white text-[10px] font-bold py-1.5 rounded-lg uppercase w-full tracking-wider">
                                ADD TO CART
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-span-full py-12 text-center text-purple-300/60 text-xs tr-sidebar">
                        No available in-stock items found.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Vertically Centered & Sticky Order Cart Sidebar -->
        <div class="w-full lg:w-80 tr-sidebar p-4 border border-purple-900/30 flex flex-col justify-between max-h-[85vh] sticky top-1/2 -translate-y-1/2 shrink-0 overflow-hidden shadow-2xl z-20">
            <div class="flex flex-col h-full overflow-hidden">
                <h3 class="text-xs font-bold text-purple-200 uppercase tracking-wider mb-3">CURRENT ORDER CART</h3>

                <div class="mb-3 shrink-0">
                    <label class="block text-[10px] text-purple-300/70 uppercase mb-1 font-semibold">CUSTOMER NAME</label>
                    <input type="text" id="cartCustomerName" placeholder="Enter customer name..." class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none">
                </div>

                <!-- Scrollable Order Cart Items Container -->
                <div id="cartItemsContainer" class="space-y-2 overflow-y-auto flex-1 pr-1 my-2 max-h-[350px]">
                    <div class="text-center py-12 text-purple-300/40 text-xs">Cart is currently empty.</div>
                </div>

                <!-- Fixed Bottom Section inside Cart -->
                <div class="border-t border-purple-900/40 pt-3 space-y-3 shrink-0 mt-auto">
                    <div class="flex justify-between items-center text-xs font-bold">
                        <span class="text-purple-200 uppercase">Total Amount:</span>
                        <span id="cartTotalDisplay" class="text-emerald-400 text-sm">₱0.00</span>
                    </div>
                    <button type="button" onclick="processCheckout()" class="w-full purple-btn text-white font-bold py-2.5 rounded-xl uppercase tracking-wider text-xs shadow-lg">
                        PROCESS CHECKOUT
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Back to Top Button -->
    <button type="button" id="backToTopBtn" onclick="scrollToTop()" class="fixed bottom-6 right-6 z-50 purple-btn text-white p-3 rounded-full shadow-2xl border border-purple-400/30 flex items-center justify-center opacity-0 pointer-events-none transition-all duration-300 hover:scale-110 active:scale-95" title="Back to Top">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
        </svg>
    </button>

    <!-- Custom Glassmorphic Centered Popup Modal -->
    <div id="customPosModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black/75 backdrop-blur-md p-4 transition-all duration-300">
        <div class="tr-card max-w-sm w-full p-6 text-center border border-purple-500/40 shadow-[0_0_35px_rgba(147,51,234,0.35)] bg-[#0f0b1a]/95 rounded-2xl transform transition-all duration-300">
            <!-- Modal Icon Container -->
            <div id="posModalIcon" class="w-14 h-14 bg-emerald-500/10 border border-emerald-500/30 rounded-full flex items-center justify-center mx-auto mb-4 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.2)]">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            
            <h3 id="posModalTitle" class="text-lg font-extrabold text-white uppercase tracking-wider mb-1">Order Placed</h3>
            <p id="posModalSubtitle" class="text-xs text-purple-300/80 font-medium mb-6">check 'Orders' to see Queued Orders</p>
            
            <button type="button" onclick="closePosModal()" class="w-full purple-btn text-white font-bold py-2.5 rounded-xl uppercase tracking-wider text-xs shadow-lg">
                CONTINUE
            </button>
        </div>
    </div>

    <script>
        let cart = [];

        // Synthesize a bell ringing chime using Web Audio API
        function playBellSound() {
            try {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx) return;
                const ctx = new AudioCtx();

                const now = ctx.currentTime;

                // First chime tone (high pitch bell frequency)
                const osc1 = ctx.createOscillator();
                const gain1 = ctx.createGain();
                osc1.type = 'sine';
                osc1.frequency.setValueAtTime(830, now); // A5 note harmonic
                gain1.gain.setValueAtTime(0.6, now);
                gain1.gain.exponentialRampToValueAtTime(0.0001, now + 1.2);
                osc1.connect(gain1);
                gain1.connect(ctx.destination);
                osc1.start(now);
                osc1.stop(now + 1.2);

                // Second layered bell chime tone slightly delayed for a ring effect
                const osc2 = ctx.createOscillator();
                const gain2 = ctx.createGain();
                osc2.type = 'sine';
                osc2.frequency.setValueAtTime(1240, now + 0.08); // Eb6 harmonic
                gain2.gain.setValueAtTime(0.4, now + 0.08);
                gain2.gain.exponentialRampToValueAtTime(0.0001, now + 1.5);
                osc2.connect(gain2);
                gain2.connect(ctx.destination);
                osc2.start(now + 0.08);
                osc2.stop(now + 1.5);
            } catch (e) {
                console.log('Audio playback error:', e);
            }
        }

        // Custom styled replacement for standard alert popups
        function showPosModal(title, subtitle, type = 'success') {
            const modal = document.getElementById('customPosModal');
            const titleEl = document.getElementById('posModalTitle');
            const subTitleEl = document.getElementById('posModalSubtitle');
            const iconEl = document.getElementById('posModalIcon');

            titleEl.innerText = title;
            subTitleEl.innerText = subtitle;

            if (type === 'error') {
                iconEl.className = "w-14 h-14 bg-rose-500/10 border border-rose-500/30 rounded-full flex items-center justify-center mx-auto mb-4 text-rose-400 shadow-[0_0_15px_rgba(244,63,94,0.2)]";
                iconEl.innerHTML = `
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                `;
            } else {
                iconEl.className = "w-14 h-14 bg-emerald-500/10 border border-emerald-500/30 rounded-full flex items-center justify-center mx-auto mb-4 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.2)]";
                iconEl.innerHTML = `
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                    </svg>
                `;
            }

            modal.classList.remove('hidden');
        }

        function closePosModal() {
            const modal = document.getElementById('customPosModal');
            modal.classList.add('hidden');
        }

        document.addEventListener("DOMContentLoaded", () => {
            filterPosProducts();

            // Toggle Back to Top button visibility on scroll
            const backToTopBtn = document.getElementById('backToTopBtn');
            window.addEventListener('scroll', () => {
                if (window.scrollY > 300) {
                    backToTopBtn.classList.remove('opacity-0', 'pointer-events-none');
                    backToTopBtn.classList.add('opacity-100', 'pointer-events-auto');
                } else {
                    backToTopBtn.classList.remove('opacity-100', 'pointer-events-auto');
                    backToTopBtn.classList.add('opacity-0', 'pointer-events-none');
                }
            });
        });

        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        function addToCart(id, name, price, maxStock) {
            const existing = cart.find(item => item.id === id);
            const currentCartQty = existing ? existing.quantity : 0;

            if (currentCartQty < maxStock) {
                if (existing) {
                    existing.quantity++;
                } else {
                    cart.push({
                        id,
                        name,
                        price,
                        quantity: 1,
                        maxStock
                    });
                }
                renderCart();
            } else {
                showPosModal('Stock Limit Reached', 'Maximum available stock reached for this item.', 'error');
            }
        }

        function updateQuantity(id, delta) {
            const item = cart.find(i => i.id === id);
            if (item) {
                let newQty = item.quantity + delta;
                if (newQty <= 0) {
                    cart = cart.filter(i => i.id !== id);
                } else if (newQty > item.maxStock) {
                    item.quantity = item.maxStock;
                    showPosModal('Stock Limit Reached', 'Cannot exceed available stock.', 'error');
                } else {
                    item.quantity = newQty;
                }
            }
            renderCart();
        }

        function setQuantity(id, val) {
            const item = cart.find(i => i.id === id);
            if (!item) return;

            let parsed = parseInt(val);
            if (isNaN(parsed) || parsed <= 0) {
                cart = cart.filter(i => i.id !== id);
            } else if (parsed > item.maxStock) {
                item.quantity = item.maxStock;
                showPosModal('Stock Limit Reached', 'Cannot exceed available stock.', 'error');
            } else {
                item.quantity = parsed;
            }
            renderCart();
        }

        function handleWheelQuantity(event, id) {
            event.preventDefault();
            const delta = event.deltaY < 0 ? 1 : -1;
            updateQuantity(id, delta);
        }

        function renderCart() {
            const container = document.getElementById('cartItemsContainer');
            const totalDisplay = document.getElementById('cartTotalDisplay');

            if (cart.length === 0) {
                container.innerHTML = '<div class="text-center py-12 text-purple-300/40 text-xs">Cart is currently empty.</div>';
                totalDisplay.innerText = '₱0.00';
            } else {
                container.innerHTML = '';
                let total = 0;

                cart.forEach(item => {
                    const subtotal = item.price * item.quantity;
                    total += subtotal;

                    const div = document.createElement('div');
                    div.className = 'bg-[#0a0810] p-2.5 rounded-xl border border-purple-900/30 text-xs flex justify-between items-center';
                    div.innerHTML = `
                        <div class="flex-1 pr-2 min-w-0">
                            <h4 class="font-bold text-white truncate text-[11px]">${item.name}</h4>
                            <p class="text-[10px] text-purple-300/70">₱${item.price.toFixed(2)} x ${item.quantity}</p>
                        </div>
                        <div class="flex items-center space-x-1 shrink-0">
                            <button type="button" onclick="updateQuantity(${item.id}, -1)" class="w-5 h-5 rounded bg-purple-950 text-purple-200 font-bold flex items-center justify-center text-xs">-</button>
                            <input type="number" min="1" max="${item.maxStock}" value="${item.quantity}" 
                                onchange="setQuantity(${item.id}, this.value)" 
                                onwheel="handleWheelQuantity(event, ${item.id})"
                                class="w-8 bg-purple-950/60 border border-purple-900/50 rounded text-center text-white font-bold text-[11px] py-0.5 focus:outline-none cursor-ns-resize" 
                                title="Type quantity or scroll mouse wheel to adjust">
                            <button type="button" onclick="updateQuantity(${item.id}, 1)" class="w-5 h-5 rounded bg-purple-950 text-purple-200 font-bold flex items-center justify-center text-xs">+</button>
                        </div>
                    `;
                    container.appendChild(div);
                });

                totalDisplay.innerText = '₱' + total.toFixed(2);
            }

            document.querySelectorAll('.pos-product-item').forEach(card => {
                const sId = card.id.replace('product-card-', '');
                const maxStock = parseInt(card.dataset.stock) || 1;
                const cartItem = cart.find(i => i.id == sId);
                const assignedQty = cartItem ? cartItem.quantity : 0;
                const remaining = maxStock - assignedQty;

                const countEl = document.getElementById('stock-count-' + sId);
                if (countEl) countEl.innerText = remaining;
            });

            filterPosProducts();
        }

        function filterPosProducts() {
            const query = document.getElementById('posSearchInput').value.toLowerCase();
            const sortMode = document.getElementById('posSortSelect').value;
            const cosignedMode = document.getElementById('posCosignedFilter').value;
            
            const grid = document.getElementById('posProductGrid');
            let items = Array.from(grid.querySelectorAll('.pos-product-item'));

            items.sort((a, b) => {
                if (sortMode === 'name_asc') {
                    return a.dataset.name.localeCompare(b.dataset.name);
                } else if (sortMode === 'name_desc') {
                    return b.dataset.name.localeCompare(a.dataset.name);
                } else if (sortMode === 'stock_desc') {
                    return parseInt(b.dataset.stock) - parseInt(a.dataset.stock);
                } else if (sortMode === 'stock_asc') {
                    return parseInt(a.dataset.stock) - parseInt(b.dataset.stock);
                }
                return 0;
            });

            let visibleCount = 0;

            items.forEach(item => {
                grid.appendChild(item);

                const name = item.dataset.name;
                const isCosigned = item.dataset.cosigned;
                
                const sId = item.id.replace('product-card-', '');
                const maxStock = parseInt(item.dataset.stock) || 1;
                const cartItem = cart.find(i => i.id == sId);
                const assignedQty = cartItem ? cartItem.quantity : 0;
                const remaining = maxStock - assignedQty;

                const matchesSearch = name.includes(query);
                const matchesCosigned = (cosignedMode === 'all') || (cosignedMode === isCosigned);
                const hasStock = remaining > 0;

                if (matchesSearch && matchesCosigned && hasStock) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            const countSpan = document.getElementById('totalFoundCount');
            if (countSpan) countSpan.innerText = visibleCount;
        }

        async function processCheckout() {
            const customerName = document.getElementById('cartCustomerName').value.trim();
            if (!customerName) {
                showPosModal('Missing Information', 'Please enter a customer name.', 'error');
                return;
            }
            if (cart.length === 0) {
                showPosModal('Empty Cart', 'Cart is currently empty.', 'error');
                return;
            }

            try {
                const response = await fetch('pos_checkout_ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        customer_name: customerName,
                        items: cart
                    })
                });

                const result = await response.json();
                if (result.success) {
                    playBellSound();
                    cart = [];
                    document.getElementById('cartCustomerName').value = '';
                    renderCart();
                    showPosModal('Order Placed', "check 'Orders' to see Queued Orders", 'success');
                } else {
                    showPosModal('Checkout Failed', result.error || 'An error occurred during checkout.', 'error');
                }
            } catch (err) {
                showPosModal('Connection Error', 'Network or server error during checkout.', 'error');
            }
        }
    </script>
</body>

</html>