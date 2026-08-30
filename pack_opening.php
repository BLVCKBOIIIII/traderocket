<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Security check: Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'partner';

// 1. AJAX Endpoint: Fetch pack contents based on selected tier
if (isset($_GET['action']) && $_GET['action'] === 'fetch_pack') {
    header('Content-Type: application/json');
    try {
        $tier = $_GET['tier'] ?? 'tier1';

        $tierConfigs = [
            'tier1' => ['name' => 'Green Tier', 'count' => 10, 'cost' => 50.00],
            'tier2' => ['name' => 'Blue Tier', 'count' => 8, 'cost' => 100.00],
            'tier3' => ['name' => 'Red Tier', 'count' => 5, 'cost' => 200.00],
            'tier4' => ['name' => 'Purple Tier', 'count' => 5, 'cost' => 500.00],
        ];

        $selectedConfig = $tierConfigs[$tier] ?? $tierConfigs['tier1'];
        $cardCount = $selectedConfig['count'];

        $whereSql = "WHERE i.quantity > 0";
        $params = [];

        if ($userRole !== 'admin') {
            $whereSql .= " AND i.owner_id = ?";
            $params[] = $userId;
        }

        $stmt = $pdo->prepare("
            SELECT 
                i.id AS stock_id,
                i.quantity,
                i.sell_price,
                i.card_category,
                c.id AS card_id,
                c.name AS card_name,
                c.card_number,
                c.image_url,
                c.pokemon_type
            FROM inventory_stocks i
            JOIN cards c ON i.card_id = c.id
            $whereSql
            ORDER BY RANDOM()
            LIMIT $cardCount
        ");
        $stmt->execute($params);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // MOCK DATA GENERATOR (Fallback if inventory stock is insufficient)
        $mockPool = [
            'tier1' => ['name' => 'Green Leaf Energy', 'price' => 5.00, 'type' => 'grass', 'bg' => '22c55e', 'text' => 'ffffff', 'label' => 'COMMON'],
            'tier2' => ['name' => 'Blue Water Blastoise', 'price' => 25.00, 'type' => 'water', 'bg' => '3b82f6', 'text' => 'ffffff', 'label' => 'UNCOMMON'],
            'tier3' => ['name' => 'Red Fire Charizard', 'price' => 120.00, 'type' => 'fire', 'bg' => 'ef4444', 'text' => 'ffffff', 'label' => 'RARE EX'],
            'tier4' => ['name' => 'Purple Psychic Mewtwo', 'price' => 450.00, 'type' => 'psychic', 'bg' => 'a855f7', 'text' => 'ffffff', 'label' => 'ILLUSTRATOR']
        ];

        $mockItem = $mockPool[$tier] ?? $mockPool['tier1'];

        while (count($cards) < $cardCount) {
            $idx = count($cards);
            $randomPrice = $mockItem['price'] + rand(0, 50);
            $cards[] = [
                'stock_id' => null, // Mock items have no stock ID to deduct
                'quantity' => 1,
                'sell_price' => $randomPrice,
                'card_category' => $mockItem['label'],
                'card_id' => 8800 + $idx,
                'card_name' => $mockItem['name'] . " #" . ($idx + 1),
                'card_number' => "TEST-" . ($idx + 1),
                'image_url' => "https://images.placeholders.dev/?width=400&height=560&text=" . urlencode($mockItem['label']) . "&bgColor=" . $mockItem['bg'] . "&textColor=" . $mockItem['text'],
                'pokemon_type' => $mockItem['type']
            ];
        }

        foreach ($cards as &$c) {
            if (empty($c['image_url'])) {
                $c['image_url'] = 'https://images.placeholders.dev/?width=400&height=560&text=TCG+Card&bgColor=120f1d&textColor=a855f7';
            }
            $c['pokemon_type'] = strtolower($c['pokemon_type'] ?? 'normal');
        }

        echo json_encode([
            'success' => true,
            'cards' => $cards,
            'tier_cost' => $selectedConfig['cost'],
            'tier_name' => $selectedConfig['name']
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 2. AJAX Endpoint: Complete Order & Deduct Inventory Stock
if (isset($_GET['action']) && $_GET['action'] === 'save_order') {
    header('Content-Type: application/json');
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $buyerName = trim($input['buyer_name'] ?? '');
        $tierName  = $input['tier_name'] ?? 'Mystery Pack';
        $totalCost = floatval($input['tier_cost'] ?? 0);
        $cards     = $input['cards'] ?? [];

        if (empty($buyerName)) {
            throw new Exception("Buyer name is required.");
        }

        $pdo->beginTransaction();

        // 1. Insert into orders table
        $stmtOrder = $pdo->prepare("
            INSERT INTO orders (user_id, buyer_name, pack_tier, total_amount, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmtOrder->execute([$userId, $buyerName, $tierName, $totalCost]);
        $orderId = $pdo->lastInsertId();

        // Prepared statements for order items and stock deduction
        $stmtItem = $pdo->prepare("
            INSERT INTO order_items (order_id, card_id, card_name, sell_price) 
            VALUES (?, ?, ?, ?)
        ");

        $stmtDeductStock = $pdo->prepare("
            UPDATE inventory_stocks 
            SET quantity = GREATEST(0, quantity - 1) 
            WHERE id = ?
        ");

        foreach ($cards as $card) {
            // Save order item
            $stmtItem->execute([
                $orderId,
                $card['card_id'] ?? null,
                $card['card_name'] ?? 'Unknown Card',
                $card['sell_price'] ?? 0.00
            ]);

            // Deduct card stock if from actual inventory stock ID
            if (!empty($card['stock_id'])) {
                $stmtDeductStock->execute([$card['stock_id']]);
            }
        }

        $pdo->commit();

        echo json_encode(['success' => true, 'order_id' => $orderId]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TCG Pocket Pack Opening | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700;900&display=swap');

        body {
            font-family: 'Space Grotesk', sans-serif;
            background-color: #0f0f12;
            color: #f4f4f5;
            overflow-x: hidden;
            position: relative;
        }

        .stage-container {
            perspective: 1400px;
        }

        .pack-carousel-wrapper {
            position: relative;
            width: 100%;
            height: 620px;
            display: flex;
            align-items: center;
            overflow: hidden;
            cursor: grab;
            user-select: none;
            mask-image: linear-gradient(to right, transparent 0%, black 8%, black 92%, transparent 100%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, black 8%, black 92%, transparent 100%);
        }

        .pack-carousel-wrapper:active {
            cursor: grabbing;
        }

        .pack-carousel-track {
            display: flex;
            gap: 36px;
            width: max-content;
            will-change: transform;
            transition: opacity 0.3s ease;
        }

        .booster-pack {
            width: 330px;
            height: 540px;
            position: relative;
            flex-shrink: 0;
            cursor: pointer;
            user-select: none;
            border-radius: 32px;
            transition: transform 0.3s ease, filter 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease;
        }

        .booster-pack:hover {
            transform: translateY(-8px) scale(1.02);
            filter: brightness(1.05);
        }

        .booster-pack.ready-to-rip {
            transform: scale(1.08) !important;
            z-index: 100 !important;
            opacity: 1 !important;
            border-radius: 32px;
            box-shadow: 0 12px 36px rgba(168, 85, 247, 0.45);
        }

        .booster-pack.unselected-pack {
            opacity: 0.35 !important;
            transform: scale(0.9) !important;
            filter: grayscale(0.2) !important;
        }

        .booster-pack-inner {
            width: 100%;
            height: 100%;
            border-radius: 32px;
            position: relative;
            overflow: hidden;
            background: #18181b;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.6);
        }

        .tier1-bg { border: 4px solid #22c55e; }
        .tier2-bg { border: 4px solid #3b82f6; }
        .tier3-bg { border: 4px solid #ef4444; }
        .tier4-bg { border: 4px solid #a855f7; }

        .pack-art-img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            z-index: 1;
            transition: transform 0.3s ease;
        }

        .booster-pack:hover .pack-art-img {
            transform: scale(1.05);
        }

        .pack-content-layer {
            position: relative;
            z-index: 5;
            width: 100%; 
            height: 100%;
            display: flex; 
            flex-direction: column;
            justify-content: space-between;
            padding: 1.5rem;
            background: linear-gradient(180deg, rgba(0, 0, 0, 0.75) 0%, rgba(0, 0, 0, 0.25) 45%, rgba(0, 0, 0, 0.95) 100%);
        }

        .pack-top-seal {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 60px;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            border-bottom: 2px dashed rgba(255, 255, 255, 0.4);
            border-top-left-radius: 28px;
            border-top-right-radius: 28px;
            z-index: 10;
        }

        .pack-seal-text {
            position: absolute;
            bottom: 14px; width: 100%;
            text-align: center;
            font-size: 15px; 
            font-weight: 900;
            letter-spacing: 2px;
            color: #ffffff;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.9), 0 0 8px rgba(0, 0, 0, 0.8);
        }

        .tier-tab-btn {
            transition: all 0.2s ease;
            position: relative;
        }

        .tier-tab-btn.active-tab {
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4);
        }

        .tier-tab-btn.active-tab::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 15%;
            right: 15%;
            height: 3px;
            border-radius: 99px;
            background: currentColor;
        }

        .card-3d {
            width: 400px;
            height: 560px;
            perspective: 1400px;
            transform-style: preserve-3d;
            transition: transform 0.4s ease, opacity 0.3s ease, box-shadow 0.5s ease;
            border-radius: 22px;
        }

        .card-slide-up {
            animation: slideFromBottom 0.5s ease-out forwards;
        }

        @keyframes slideFromBottom {
            0% {
                transform: translateY(60px) scale(0.9);
                opacity: 0;
            }
            100% {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        .card-inner {
            position: relative;
            width: 100%;
            height: 100%;
            transform-style: preserve-3d;
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 22px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.6);
        }

        .card-3d.flipped .card-inner {
            transform: rotateY(180deg);
        }

        .card-face {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            border-radius: 22px;
            overflow: hidden;
        }

        .card-back {
            background: #18181b;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        .card-front {
            transform: rotateY(180deg);
            background: #18181b;
            z-index: 1;
        }

        .card-front img,
        .card-back img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 22px;
        }

        .card-3d.glow-charging {
            animation: pulseAura 0.7s infinite alternate ease-in-out;
        }

        @keyframes pulseAura {
            0% {
                box-shadow: 0 0 25px rgba(168, 85, 247, 0.7), 0 0 50px rgba(168, 85, 247, 0.4);
                transform: scale(1.01);
            }
            100% {
                box-shadow: 0 0 75px rgba(236, 72, 153, 1), 0 0 120px rgba(168, 85, 247, 0.9);
                transform: scale(1.05);
            }
        }

        #earnPopContainer {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 100;
            pointer-events: none;
            display: flex;
            flex-direction: column-reverse;
            align-items: flex-end;
            gap: 8px;
        }

        .game-earn-pop {
            color: #4ade80;
            font-weight: 800;
            font-size: 30px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.8);
            animation: floatUpAndVanish 1.5s ease-out forwards;
        }

        @keyframes floatUpAndVanish {
            0% { opacity: 0; transform: translateY(10px) scale(0.8); }
            20% { opacity: 1; transform: translateY(0px) scale(1); }
            100% { opacity: 0; transform: translateY(-80px); }
        }

        .card-fan-container {
            position: relative;
            width: 100%;
            height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
            perspective: 1400px;
            margin-top: 10px;
        }

        .fan-card-slot {
            position: absolute;
            width: 220px;
            height: 300px;
            transform-origin: center bottom;
            transition: transform 0.3s ease;
            cursor: pointer;
        }

        .fan-card-slot .fan-card-visual {
            width: 100%;
            height: 100%;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            transition: transform 0.3s ease;
        }

        .fan-card-slot.hover-active .fan-card-visual {
            transform: translateY(-30px) scale(1.15);
            box-shadow: 0 15px 35px rgba(168, 85, 247, 0.4);
        }

        .card-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }

        .card-modal-backdrop.active {
            opacity: 1;
            pointer-events: auto;
        }
    </style>
</head>

<body class="min-h-screen flex flex-col bg-[#0f0f12] text-zinc-100">

    <?php include 'includes/header.php'; ?>

    <main class="flex-1 w-full max-w-[1400px] mx-auto p-4 md:p-8 pt-8 flex flex-col items-center justify-start gap-6 z-10">

        <!-- HEADER TITLE SECTION WITH GENGAR GIF -->
        <div class="flex items-center justify-center gap-4 md:gap-8 w-full">
            <img src="https://i.pinimg.com/originals/4f/d0/c0/4fd0c049c173c9beb5a0101a84deb6f9.gif" alt="Gengar Left" class="w-20 h-20 md:w-32 md:h-32 object-contain drop-shadow-[0_0_15px_rgba(168,85,247,0.5)]">

            <div class="flex flex-col items-center text-center gap-2">
                <h1 class="text-3xl md:text-5xl font-black tracking-tight text-white">
                    TCG Mystery Pack Opening
                </h1>
                <p id="subInstruction" class="text-sm md:text-base text-purple-300/80 font-medium">
                    Enter buyer name, select a tier, and tap a pack to open!
                </p>
            </div>

            <img src="https://i.pinimg.com/originals/4f/d0/c0/4fd0c049c173c9beb5a0101a84deb6f9.gif" alt="Gengar Right" class="w-20 h-20 md:w-32 md:h-32 object-contain drop-shadow-[0_0_15px_rgba(168,85,247,0.5)] -scale-x-100">
        </div>

        <!-- BUYER NAME INPUT & TIER SELECTOR BAR -->
        <div id="setupControls" class="flex flex-col md:flex-row items-center justify-center gap-4 w-full max-w-4xl">
            <!-- BUYER NAME INPUT -->
            <div class="w-full md:w-72 bg-zinc-900/90 p-2.5 rounded-2xl border border-white/10 shadow-xl flex items-center gap-2">
                <span class="text-xs font-bold text-purple-400 uppercase tracking-wider pl-2">Buyer:</span>
                <input type="text" id="buyerNameInput" placeholder="Enter Buyer Name..." class="w-full bg-black/50 border border-white/10 text-white rounded-xl px-3 py-1.5 text-sm focus:outline-none focus:border-purple-500 font-semibold placeholder-zinc-500">
            </div>

            <!-- TIER SELECTOR BUTTONS -->
            <div id="tierSelectorBar" class="flex flex-wrap items-center justify-center gap-2.5 bg-zinc-900/90 p-2.5 rounded-2xl border border-white/10 shadow-xl flex-1">
                <button onclick="switchPackTier('tier1')" id="btn-tier1" class="tier-tab-btn active-tab px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 border border-emerald-500/40 bg-emerald-950/40 text-emerald-400">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    <span>GREEN TIER</span>
                    <span class="text-[10px] opacity-75">(10 Cards • ₱50)</span>
                </button>

                <button onclick="switchPackTier('tier2')" id="btn-tier2" class="tier-tab-btn px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 border border-blue-500/20 bg-blue-950/20 text-blue-300 opacity-60">
                    <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                    <span>BLUE TIER</span>
                    <span class="text-[10px] opacity-75">(8 Cards • ₱100)</span>
                </button>

                <button onclick="switchPackTier('tier3')" id="btn-tier3" class="tier-tab-btn px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 border border-red-500/20 bg-red-950/20 text-red-300 opacity-60">
                    <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
                    <span>RED TIER</span>
                    <span class="text-[10px] opacity-75">(5 Cards • ₱200)</span>
                </button>

                <button onclick="switchPackTier('tier4')" id="btn-tier4" class="tier-tab-btn px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 border border-purple-500/20 bg-purple-950/20 text-purple-300 opacity-60">
                    <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                    <span>PURPLE TIER</span>
                    <span class="text-[10px] opacity-75">(5 Cards • ₱500)</span>
                </button>
            </div>
        </div>

        <!-- MAIN STAGE CONTAINER -->
        <div class="stage-container w-full min-h-[580px] flex items-center justify-center relative mt-2">

            <!-- 1. DRAG-SPINNABLE CAROUSEL -->
            <div id="packCarouselContainer" class="pack-carousel-wrapper">
                <div id="packCarouselTrack" class="pack-carousel-track"></div>
            </div>

            <!-- 2. CARD DECK STAGE -->
            <div id="cardDeckStage" class="relative hidden flex-col items-center justify-center">
                <div id="activeCard" class="card-3d cursor-pointer" onclick="handleCardClick()">
                    <div class="card-inner">
                        <div class="card-face card-back">
                            <img src="assets/back.png" alt="Card Back">
                        </div>

                        <div class="card-face card-front">
                            <div id="earnPopContainer"></div>
                            <img id="cardImage" src="" alt="Card Front">
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex flex-col items-center gap-1">
                    <h3 id="cardTitle" class="text-xl font-bold text-white text-center truncate tracking-tight">???</h3>
                    <span id="deckCounter" class="text-sm font-semibold text-purple-300/80 tracking-wider">CARD 1 OF 10</span>
                </div>
            </div>

            <!-- 3. INTERACTIVE SUMMARY STAGE -->
            <div id="summaryStage" class="w-full max-w-5xl hidden flex-col items-center gap-5">
                <div id="profitSummaryBox" class="w-full p-6 rounded-3xl border bg-zinc-900/95 grid grid-cols-1 md:grid-cols-4 items-center gap-6 shadow-2xl relative overflow-hidden">
                    
                    <!-- Buyer Info -->
                    <div class="flex flex-col items-center md:items-start text-center md:text-left justify-center">
                        <span class="text-xs uppercase tracking-widest text-purple-300/70 font-semibold mb-1">Customer</span>
                        <span id="buyerDisplay" class="text-xl font-bold text-purple-300 truncate max-w-[180px]">-</span>
                    </div>

                    <!-- Pack Cost -->
                    <div class="flex flex-col items-center md:items-start text-center md:text-left justify-center">
                        <span class="text-xs uppercase tracking-widest text-purple-300/70 font-semibold mb-1">Pack Cost</span>
                        <span id="packCostDisplay" class="text-2xl font-bold text-zinc-300">₱50.00</span>
                    </div>

                    <!-- Total Pull Value -->
                    <div class="flex flex-col items-center text-center justify-center py-2 md:py-0 border-y md:border-y-0 md:border-x border-white/10 px-4">
                        <span class="text-xs font-black uppercase tracking-widest text-amber-400 mb-1 flex items-center gap-1">
                            ✨ TOTAL PULL VALUE ✨
                        </span>
                        <span id="totalPullValue" class="text-3xl md:text-4xl font-black text-amber-300 drop-shadow-[0_0_20px_rgba(245,158,11,0.4)] tracking-tight">
                            ₱0.00
                        </span>
                    </div>

                    <!-- Pack Profit / Loss -->
                    <div class="flex flex-col items-center md:items-end text-center md:text-right justify-center">
                        <span id="outcomeBadge" class="text-[10px] font-bold uppercase tracking-widest px-2.5 py-0.5 rounded-full inline-block mb-1">
                            PACK PROFIT
                        </span>
                        <h2 id="outcomeTitle" class="text-2xl md:text-3xl font-black tracking-tight">
                            +₱0.00
                        </h2>
                    </div>

                </div>

                <div id="fanContainer" class="card-fan-container"></div>
                <p class="text-sm text-purple-300/70 font-semibold -mt-2">Hover to inspect cards • Click to view detailed stats</p>

                <!-- ACTION BUTTONS: CHOOSE ANOTHER PACK / NEW PACK ORDER -->
                <div class="flex items-center gap-4 mt-2">
                    <button onclick="initPackOpening(false)" class="purple-btn text-white text-sm font-bold px-7 py-3 rounded-2xl uppercase tracking-wider shadow-xl hover:scale-105 transition-transform">
                        Choose Another Pack
                    </button>
                    <button onclick="initPackOpening(true)" class="bg-zinc-800 hover:bg-zinc-700 text-purple-300 border border-purple-500/40 text-sm font-bold px-7 py-3 rounded-2xl uppercase tracking-wider shadow-xl hover:scale-105 transition-transform">
                        New Pack Order
                    </button>
                </div>
            </div>

        </div>

    </main>

    <!-- CARD DETAIL MODAL -->
    <div id="cardModal" class="card-modal-backdrop" onclick="closeCardModal()">
        <div class="modal-card-box p-6 bg-[#18181b] border border-purple-500/40 rounded-3xl flex flex-col sm:flex-row items-center gap-6 max-w-lg shadow-2xl relative" onclick="event.stopPropagation()">
            <button onclick="closeCardModal()" class="absolute top-3 right-3 text-zinc-400 hover:text-white font-bold text-xl">✕</button>
            <img id="modalCardImg" src="" class="w-60 h-84 object-cover rounded-2xl shadow-lg">
            <div class="flex flex-col gap-3 text-left">
                <div>
                    <span class="text-xs font-bold uppercase tracking-widest text-purple-400">Card Preview</span>
                    <h2 id="modalCardName" class="text-2xl font-bold text-white leading-snug"></h2>
                </div>
                <div class="bg-black/40 p-4 rounded-xl border border-white/5 flex flex-col gap-1">
                    <span class="text-xs text-zinc-400">Inventory Sell Value</span>
                    <span id="modalCardPrice" class="text-3xl font-black text-emerald-400"></span>
                </div>
                <button onclick="closeCardModal()" class="purple-btn text-white text-xs font-bold px-5 py-2.5 rounded-xl uppercase tracking-wider mt-2">
                    Close Details
                </button>
            </div>
        </div>
    </div>

    <script>
        const TIER_SPECS = {
            'tier1': {
                name: 'GREEN TIER',
                bg: 'tier1-bg',
                desc: '10 CARDS • ₱50.00',
                colorClass: 'border-emerald-500/40 bg-emerald-950/40 text-emerald-400'
            },
            'tier2': {
                name: 'BLUE TIER',
                bg: 'tier2-bg',
                desc: '8 CARDS • ₱100.00',
                colorClass: 'border-blue-500/40 bg-blue-950/40 text-blue-300'
            },
            'tier3': {
                name: 'RED TIER',
                bg: 'tier3-bg',
                desc: '5 CARDS • ₱200.00',
                colorClass: 'border-red-500/40 bg-red-950/40 text-red-300'
            },
            'tier4': {
                name: 'PURPLE TIER',
                bg: 'tier4-bg',
                desc: '5 CARDS • ₱500.00',
                colorClass: 'border-purple-500/40 bg-purple-950/40 text-purple-300'
            }
        };

        const POKEMON_PACK_IMAGES = [
            "https://public.getcollectr.com/public-assets/products/product_232748.png?optimizer=image&format=webp&width=1200&quality=80&strip=metadata",
            "https://public.getcollectr.com/public-assets/products/product_100490.png?optimizer=image&format=webp&width=1200&quality=80&strip=metadata",
            "https://public.getcollectr.com/public-assets/products/product_198634.png?optimizer=image&format=webp&width=1200&quality=80&strip=metadata",
            "https://public.getcollectr.com/public-assets/products/product_229276.png?optimizer=image&format=webp&width=1200&quality=80&strip=metadata",
            "https://public.getcollectr.com/public-assets/products/product_579930.png?optimizer=image&format=webp&width=1200&quality=80&strip=metadata",
            "https://public.getcollectr.com/public-assets/products/product_98538.png?optimizer=image&format=webp&width=1200&quality=80&strip=metadata",
            "https://public.getcollectr.com/public-assets/products/product_98549.png?optimizer=image&format=webp&width=1200&quality=80&strip=metadata"
        ];

        let currentActiveTier = 'tier1';
        let packCards = [];
        let currentIndex = 0;
        let isOpening = false;
        let selectedPackEl = null;
        let autoTransitionTimer = null;
        let animationFrameId = null;

        let currentScrollX = 0;
        let autoScrollSpeed = 0.8;
        let currentVelocity = 0.8;

        let isDragging = false;
        let startX = 0;
        let dragDistance = 0;
        let lastPointerX = 0;
        let lastMoveTime = 0;
        let dragVelocity = 0;

        let activePackCost = 50.00;
        let activeTierName = 'Green Tier';
        let lastUsedPackImages = [];

        function buildCarouselItems(tierKey) {
            const track = document.getElementById('packCarouselTrack');
            if (!track) return;
            const spec = TIER_SPECS[tierKey];

            function shuffleArray(array) {
                const arr = [...array];
                for (let i = arr.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [arr[i], arr[j]] = [arr[j], arr[i]];
                }
                return arr;
            }

            const packImages = [];
            let pool = shuffleArray(POKEMON_PACK_IMAGES);

            for (let i = 0; i < 12; i++) {
                const previousImage = packImages[i - 1] || lastUsedPackImages[lastUsedPackImages.length - 1];
                let selectedIndex = pool.findIndex(img => img !== previousImage);

                if (selectedIndex === -1) {
                    pool = shuffleArray(POKEMON_PACK_IMAGES);
                    selectedIndex = pool.findIndex(img => img !== previousImage);
                }

                const selectedImg = pool.splice(selectedIndex, 1)[0];
                packImages.push(selectedImg);

                if (pool.length === 0) {
                    pool = shuffleArray(POKEMON_PACK_IMAGES);
                }
            }

            lastUsedPackImages = packImages;

            let html = '';
            for (let i = 0; i < 12; i++) {
                const randomImg = packImages[i];
                html += `
                <div class="booster-pack" data-tier="${tierKey}" data-pack-id="${i+1}" onclick="handlePackClick(event, this)">
                    <div class="booster-pack-inner ${spec.bg}">
                        <img src="${randomImg}" alt="Pokemon Pack Art" class="pack-art-img">
                        <div class="pack-content-layer">
                            <div class="pack-top-seal">
                                <span class="pack-seal-text">TAP TO SELECT</span>
                            </div>

                            <div class="mt-16 text-center px-1">
                                <span class="text-sm font-black tracking-widest text-yellow-300 uppercase bg-black/85 backdrop-blur-md px-3.5 py-1.5 rounded-xl border border-yellow-500/40 shadow-2xl block text-center truncate" style="text-shadow: 0 2px 4px rgba(0,0,0,0.9);">
                                    ${spec.name} • PACK #${i + 1}
                                </span>
                                <h2 class="text-3xl font-black tracking-wider text-white uppercase mt-2.5" style="text-shadow: 0 3px 8px rgba(0,0,0,0.95), 0 0 12px rgba(0,0,0,0.9);">
                                    BOOSTER PACK
                                </h2>
                            </div>

                            <div class="text-center mb-2">
                                <span class="text-base font-black tracking-wide text-white bg-black/85 backdrop-blur-md px-5 py-2.5 rounded-xl border border-white/30 shadow-2xl inline-block" style="text-shadow: 0 2px 5px rgba(0,0,0,0.9);">
                                    ${spec.desc}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>`;
            }
            track.innerHTML = html;
        }

        function switchPackTier(tierKey) {
            if (isOpening || selectedPackEl) return;

            if (currentActiveTier !== tierKey) {
                playSound('selectTier');
            }

            currentActiveTier = tierKey;

            Object.keys(TIER_SPECS).forEach(key => {
                const btn = document.getElementById(`btn-${key}`);
                if (btn) {
                    if (key === tierKey) {
                        btn.className = `tier-tab-btn active-tab px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 border ${TIER_SPECS[key].colorClass}`;
                    } else {
                        btn.className = `tier-tab-btn px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 border border-white/10 bg-black/20 text-zinc-400 opacity-60`;
                    }
                }
            });

            const track = document.getElementById('packCarouselTrack');
            track.style.opacity = '0';
            setTimeout(() => {
                buildCarouselItems(tierKey);
                track.style.opacity = '1';
            }, 150);
        }

        function animateCarousel() {
            if (isOpening || selectedPackEl) return;
            const track = document.getElementById('packCarouselTrack');
            if (!track) return;

            const halfWidth = track.scrollWidth / 2;

            if (!isDragging) {
                currentVelocity += (autoScrollSpeed - currentVelocity) * 0.05;
                currentScrollX += currentVelocity;
            }

            if (currentScrollX >= halfWidth) {
                currentScrollX %= halfWidth;
            } else if (currentScrollX < 0) {
                currentScrollX = halfWidth + (currentScrollX % halfWidth);
            }

            track.style.transform = `translateX(-${currentScrollX}px)`;
            animationFrameId = requestAnimationFrame(animateCarousel);
        }

        function startAutoRotation() {
            stopAutoRotation();
            animationFrameId = requestAnimationFrame(animateCarousel);
        }

        function stopAutoRotation() {
            if (animationFrameId) {
                cancelAnimationFrame(animationFrameId);
                animationFrameId = null;
            }
        }

        function initDragControls() {
            const wrapper = document.getElementById('packCarouselContainer');

            function onPointerDown(e) {
                if (isOpening || selectedPackEl) return;
                isDragging = true;
                dragDistance = 0;
                startX = e.pageX || e.touches[0].pageX;
                lastPointerX = startX;
                lastMoveTime = performance.now();
                dragVelocity = 0;
            }

            function onPointerMove(e) {
                if (!isDragging || isOpening || selectedPackEl) return;
                const currentX = e.pageX || e.touches[0].pageX;
                const deltaX = currentX - lastPointerX;
                const now = performance.now();
                const dt = now - lastMoveTime;

                dragDistance += Math.abs(deltaX);
                currentScrollX -= deltaX;

                if (dt > 0) {
                    dragVelocity = -deltaX / Math.max(dt, 8) * 16;
                }

                lastPointerX = currentX;
                lastMoveTime = now;
            }

            function onPointerUp() {
                if (!isDragging) return;
                isDragging = false;
                currentVelocity = Math.abs(dragVelocity) > 0.5 ? dragVelocity : 0;
            }

            wrapper.addEventListener('mousedown', onPointerDown);
            window.addEventListener('mousemove', onPointerMove);
            window.addEventListener('mouseup', onPointerUp);

            wrapper.addEventListener('touchstart', onPointerDown, { passive: true });
            window.addEventListener('touchmove', onPointerMove, { passive: true });
            window.addEventListener('touchend', onPointerUp);
        }

        const audioCtx = new(window.AudioContext || window.webkitAudioContext)();

        function playSound(type, param = 0) {
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const now = audioCtx.currentTime;

            if (type === 'selectTier') {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(600, now);
                osc.frequency.exponentialRampToValueAtTime(1200, now + 0.08);

                gain.gain.setValueAtTime(0.2, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);

                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(now);
                osc.stop(now + 0.08);
            } else if (type === 'slash') {
                const bufferSize = audioCtx.sampleRate * 0.25;
                const buffer = audioCtx.createBuffer(1, bufferSize, audioCtx.sampleRate);
                const data = buffer.getChannelData(0);
                for (let i = 0; i < bufferSize; i++) {
                    data[i] = Math.random() * 2 - 1;
                }

                const noise = audioCtx.createBufferSource();
                noise.buffer = buffer;

                const filter = audioCtx.createBiquadFilter();
                filter.type = 'bandpass';
                filter.frequency.setValueAtTime(1400, now);
                filter.frequency.exponentialRampToValueAtTime(6000, now + 0.2);
                filter.Q.setValueAtTime(4, now);

                const gain = audioCtx.createGain();
                gain.gain.setValueAtTime(0.65, now);
                gain.gain.exponentialRampToValueAtTime(0.01, now + 0.22);

                noise.connect(filter);
                filter.connect(gain);
                gain.connect(audioCtx.destination);

                noise.start(now);
                noise.stop(now + 0.25);
            } else if (type === 'coin') {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(1174.66, now);
                gain.gain.setValueAtTime(0.35, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.18);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(now);
                osc.stop(now + 0.18);
            } else if (type === 'inspect') {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(420, now);
                osc.frequency.exponentialRampToValueAtTime(880, now + 0.08);
                gain.gain.setValueAtTime(0.12, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.09);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(now);
                osc.stop(now + 0.09);
            } else if (type === 'reveal') {
                const osc1 = audioCtx.createOscillator();
                const gain1 = audioCtx.createGain();
                osc1.type = 'sine';

                const startFreq = 1318.51 + (param * 40);
                const endFreq = 1975.53 + (param * 60);

                osc1.frequency.setValueAtTime(startFreq, now);
                osc1.frequency.setValueAtTime(startFreq, now + 0.05);
                osc1.frequency.exponentialRampToValueAtTime(endFreq, now + 0.12);
                osc1.frequency.exponentialRampToValueAtTime(endFreq * 1.05, now + 0.28);

                gain1.gain.setValueAtTime(0.45, now);
                gain1.gain.setValueAtTime(0.45, now + 0.15);
                gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.32);

                osc1.connect(gain1);
                gain1.connect(audioCtx.destination);
                osc1.start(now);
                osc1.stop(now + 0.32);
            } else if (type === 'charge') {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(180, now);
                osc.frequency.exponentialRampToValueAtTime(1200, now + 1.3);

                const filter = audioCtx.createBiquadFilter();
                filter.type = 'lowpass';
                filter.frequency.setValueAtTime(300, now);
                filter.frequency.exponentialRampToValueAtTime(4500, now + 1.3);

                gain.gain.setValueAtTime(0.05, now);
                gain.gain.linearRampToValueAtTime(0.45, now + 1.1);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 1.35);

                osc.connect(filter);
                filter.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(now);
                osc.stop(now + 1.35);
            } else if (type === 'complete') {
                const notes = [523.25, 659.25, 783.99, 1046.50, 1318.51];
                notes.forEach((freq, idx) => {
                    const osc = audioCtx.createOscillator();
                    const gain = audioCtx.createGain();
                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(freq, now + (idx * 0.03));

                    const filter = audioCtx.createBiquadFilter();
                    filter.type = 'lowpass';
                    filter.frequency.setValueAtTime(3000, now);

                    gain.gain.setValueAtTime(0.25, now + (idx * 0.03));
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.6);

                    osc.connect(filter);
                    filter.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.start(now + (idx * 0.03));
                    osc.stop(now + 0.6);
                });

                const bass = audioCtx.createOscillator();
                const bassGain = audioCtx.createGain();
                bass.type = 'triangle';
                bass.frequency.setValueAtTime(160, now);
                bass.frequency.exponentialRampToValueAtTime(40, now + 0.45);
                bassGain.gain.setValueAtTime(0.6, now);
                bassGain.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
                bass.connect(bassGain);
                bassGain.connect(audioCtx.destination);
                bass.start(now);
                bass.stop(now + 0.5);
            }
        }

        function triggerFloatingProfit(formattedPrice) {
            setTimeout(() => {
                const container = document.getElementById('earnPopContainer');
                if (!container) return;

                const popEl = document.createElement('div');
                popEl.className = 'game-earn-pop';
                popEl.innerText = `+${formattedPrice}`;

                container.appendChild(popEl);
                playSound('coin');

                setTimeout(() => {
                    popEl.remove();
                }, 1500);
            }, 50);
        }

        function deselectActivePack() {
            if (!selectedPackEl || isOpening) return;

            const allPacks = document.querySelectorAll('.booster-pack');
            allPacks.forEach(p => {
                p.classList.remove('ready-to-rip', 'unselected-pack');
                p.querySelector('.pack-seal-text').innerText = 'TAP TO SELECT';
            });

            selectedPackEl = null;
            document.getElementById('setupControls').classList.remove('opacity-40', 'pointer-events-none');
            document.getElementById('subInstruction').innerText = 'Enter buyer name, select a tier, and tap a pack to open!';

            startAutoRotation();
        }

        async function handlePackClick(event, packEl) {
            event.stopPropagation();
            if (isOpening || dragDistance > 8) return;

            const buyerInput = document.getElementById('buyerNameInput');
            const buyerName = buyerInput.value.trim();

            if (!buyerName) {
                alert("Please enter the Buyer's Name before selecting a pack!");
                buyerInput.focus();
                return;
            }

            const selectedTier = packEl.getAttribute('data-tier');

            if (!selectedPackEl) {
                playSound('inspect');
                selectedPackEl = packEl;
                stopAutoRotation();

                document.getElementById('setupControls').classList.add('opacity-40', 'pointer-events-none');

                const allPacks = document.querySelectorAll('.booster-pack');
                allPacks.forEach(p => {
                    if (p === packEl) {
                        p.classList.add('ready-to-rip');
                        p.querySelector('.pack-seal-text').innerText = 'TAP AGAIN TO OPEN';
                    } else {
                        p.classList.add('unselected-pack');
                    }
                });

                try {
                    const res = await fetch(`pack_opening.php?action=fetch_pack&tier=${selectedTier}`);
                    const data = await res.json();
                    if (data.success) {
                        packCards = data.cards.sort((a, b) => parseFloat(a.sell_price || 0) - parseFloat(b.sell_price || 0));
                        activePackCost = data.tier_cost;
                        activeTierName = data.tier_name;
                    } else {
                        alert(data.error);
                    }
                } catch (e) {
                    alert('Error loading pack details.');
                }

                document.getElementById('subInstruction').innerText = 'Tap the pack again to open it, or click outside to re-select!';
                return;
            }

            if (selectedPackEl === packEl) {
                ripSelectedPack(packEl);
            }
        }

        function ripSelectedPack(packEl) {
            if (isOpening || packCards.length === 0) return;
            isOpening = true;

            playSound('slash');
            confetti({ particleCount: 50, spread: 60, origin: { x: 0.5, y: 0.4 } });

            setTimeout(() => {
                document.getElementById('packCarouselContainer').classList.add('hidden');
                document.getElementById('setupControls').classList.add('hidden');
                document.getElementById('cardDeckStage').classList.remove('hidden');
                document.getElementById('cardDeckStage').classList.add('flex');

                loadCardIndex(0);
            }, 300);
        }

        function initPackOpening(clearBuyer = false) {
            const carousel = document.getElementById('packCarouselContainer');
            const setupControls = document.getElementById('setupControls');
            const deckStage = document.getElementById('cardDeckStage');
            const summaryStage = document.getElementById('summaryStage');
            const cardEl = document.getElementById('activeCard');

            if (clearBuyer) {
                document.getElementById('buyerNameInput').value = '';
            }

            if (autoTransitionTimer) clearTimeout(autoTransitionTimer);

            if (cardEl) {
                cardEl.classList.remove('glow-charging', 'flipped');
            }

            selectedPackEl = null;
            packCards = [];
            currentIndex = 0;
            isOpening = false;

            document.getElementById('subInstruction').innerText = 'Enter buyer name, select a tier, and tap a pack to open!';
            document.getElementById('subInstruction').className = "text-sm md:text-base text-purple-300/80 font-medium";

            buildCarouselItems(currentActiveTier);

            carousel.classList.remove('hidden');
            setupControls.classList.remove('hidden', 'opacity-40', 'pointer-events-none');
            
            deckStage.classList.add('hidden');
            deckStage.classList.remove('flex');
            
            summaryStage.classList.add('hidden');
            summaryStage.classList.remove('flex');

            startAutoRotation();
        }

        function loadCardIndex(index) {
            const card = packCards[index];
            const cardEl = document.getElementById('activeCard');
            const cardImgEl = document.getElementById('cardImage');
            const subText = document.getElementById('subInstruction');
            const total = packCards.length;

            cardEl.classList.remove('card-slide-up', 'glow-charging');
            document.getElementById('deckCounter').innerText = `CARD ${index + 1} OF ${total}`;

            if (index === 0) {
                cardImgEl.src = card.image_url;
                cardEl.classList.remove('flipped');
                document.getElementById('cardTitle').innerText = '???';

                void cardEl.offsetWidth;
                cardEl.classList.add('card-slide-up');

                subText.innerText = "Tap the card to flip!";
                subText.className = "text-sm text-purple-300/80 font-semibold";
                return;
            }

            if (index === total - 1) {
                cardImgEl.src = '';
                cardEl.classList.remove('flipped');
                cardEl.classList.add('glow-charging');
                document.getElementById('cardTitle').innerText = '???';

                subText.innerText = "⚡ RARE CARD AURA DETECTED! CHARGING...";
                subText.className = "text-sm font-bold text-amber-300 animate-pulse tracking-wide";

                playSound('charge');

                autoTransitionTimer = setTimeout(() => {
                    cardImgEl.src = card.image_url;

                    cardEl.classList.remove('glow-charging');
                    cardEl.classList.add('flipped');
                    document.getElementById('cardTitle').innerText = card.card_name;

                    const formattedPrice = `₱${parseFloat(card.sell_price || 0).toFixed(2)}`;
                    playSound('complete');
                    triggerFloatingProfit(formattedPrice);

                    confetti({ particleCount: 140, spread: 110, origin: { x: 0.5, y: 0.5 } });

                    autoTransitionTimer = setTimeout(() => {
                        advanceToNextCard();
                    }, 2400);
                }, 1400);
                return;
            }

            cardImgEl.src = card.image_url;
            cardEl.classList.add('flipped');
            document.getElementById('cardTitle').innerText = card.card_name;

            const formattedPrice = `₱${parseFloat(card.sell_price || 0).toFixed(2)}`;
            const momentumStep = index >= 7 ? (index - 6) : 0;
            
            playSound('reveal', momentumStep);
            triggerFloatingProfit(formattedPrice);

            subText.innerText = "Revealing cards...";
            subText.className = "text-sm text-purple-300/80";

            autoTransitionTimer = setTimeout(() => {
                advanceToNextCard();
            }, 1200);
        }

        function handleCardClick() {
            const cardEl = document.getElementById('activeCard');

            if (currentIndex === 0 && !cardEl.classList.contains('flipped')) {
                cardEl.classList.add('flipped');
                document.getElementById('cardTitle').innerText = packCards[0].card_name;

                const formattedPrice = `₱${parseFloat(packCards[0].sell_price || 0).toFixed(2)}`;
                playSound('reveal', 0);
                triggerFloatingProfit(formattedPrice);

                autoTransitionTimer = setTimeout(() => {
                    advanceToNextCard();
                }, 1300);
            }
        }

        function advanceToNextCard() {
            if (currentIndex < packCards.length - 1) {
                currentIndex++;
                loadCardIndex(currentIndex);
            } else {
                completeAndRenderSummary();
            }
        }

        async function completeAndRenderSummary() {
            const buyerName = document.getElementById('buyerNameInput').value.trim();

            // 1. Process order creation & inventory deduction via AJAX
            try {
                const res = await fetch('pack_opening.php?action=save_order', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        buyer_name: buyerName,
                        tier_name: activeTierName,
                        tier_cost: activePackCost,
                        cards: packCards
                    })
                });
                const result = await res.json();
                if (!result.success) {
                    console.error("Order processing error:", result.error);
                }
            } catch (err) {
                console.error("Failed to complete order transaction:", err);
            }

            // 2. Render summary UI
            document.getElementById('cardDeckStage').classList.add('hidden');
            const summaryStage = document.getElementById('summaryStage');
            const fanContainer = document.getElementById('fanContainer');

            const totalValue = packCards.reduce((sum, card) => sum + parseFloat(card.sell_price || 0), 0);
            const netDifference = totalValue - activePackCost;
            const isProfitable = netDifference >= 0;

            const profitBox = document.getElementById('profitSummaryBox');
            const outcomeBadge = document.getElementById('outcomeBadge');
            const outcomeTitle = document.getElementById('outcomeTitle');

            document.getElementById('subInstruction').innerText = 'Pack opening complete & sales record updated!';
            document.getElementById('subInstruction').className = "text-sm text-purple-300/80";

            document.getElementById('buyerDisplay').innerText = buyerName || 'Guest';
            document.getElementById('totalPullValue').innerText = `₱${totalValue.toFixed(2)}`;
            document.getElementById('packCostDisplay').innerText = `₱${activePackCost.toFixed(2)}`;

            if (isProfitable) {
                profitBox.className = 'w-full p-6 rounded-3xl border border-amber-500/30 bg-zinc-900/95 grid grid-cols-1 md:grid-cols-4 items-center gap-6 shadow-2xl relative overflow-hidden';
                outcomeBadge.className = 'text-[10px] font-bold uppercase tracking-widest px-2.5 py-0.5 rounded-full inline-block mb-1 bg-emerald-500/20 text-emerald-300 border border-emerald-500/30';
                outcomeBadge.innerText = 'PACK PROFIT';
                outcomeTitle.className = 'text-2xl md:text-3xl font-black text-emerald-400 tracking-tight';
                outcomeTitle.innerText = `+₱${netDifference.toFixed(2)}`;
            } else {
                profitBox.className = 'w-full p-6 rounded-3xl border border-zinc-700/50 bg-zinc-900/95 grid grid-cols-1 md:grid-cols-4 items-center gap-6 shadow-2xl relative overflow-hidden';
                outcomeBadge.className = 'text-[10px] font-bold uppercase tracking-widest px-2.5 py-0.5 rounded-full inline-block mb-1 bg-red-500/20 text-red-300 border border-red-500/30';
                outcomeBadge.innerText = 'PACK LOSS';
                outcomeTitle.className = 'text-2xl md:text-3xl font-black text-red-400 tracking-tight';
                outcomeTitle.innerText = `-₱${Math.abs(netDifference).toFixed(2)}`;
            }

            fanContainer.innerHTML = '';
            const total = packCards.length;
            const angleStep = 5;
            const xStep = 55;

            packCards.forEach((c, idx) => {
                const slot = document.createElement('div');
                slot.className = 'fan-card-slot';

                const offset = idx - (total - 1) / 2;
                const rot = offset * angleStep;
                const translateX = offset * xStep;
                const translateY = Math.abs(offset) * 6;

                slot.style.transform = `translate3d(${translateX}px, ${translateY}px, 0px) rotate(${rot}deg)`;
                slot.style.zIndex = idx + 1;

                slot.innerHTML = `<div class="fan-card-visual"><img src="${c.image_url}" alt="${c.card_name}"></div>`;

                slot.addEventListener('mouseenter', () => {
                    slot.classList.add('hover-active');
                    playSound('inspect');
                });
                slot.addEventListener('mouseleave', () => slot.classList.remove('hover-active'));
                slot.onclick = () => {
                    playSound('inspect');
                    openCardModal(c);
                };
                fanContainer.appendChild(slot);
            });

            summaryStage.classList.remove('hidden');
            summaryStage.classList.add('flex');
        }

        function openCardModal(card) {
            document.getElementById('modalCardImg').src = card.image_url;
            document.getElementById('modalCardName').innerText = card.card_name;
            document.getElementById('modalCardPrice').innerText = `₱${parseFloat(card.sell_price || 0).toFixed(2)}`;
            document.getElementById('cardModal').classList.add('active');
        }

        function closeCardModal() {
            document.getElementById('cardModal').classList.remove('active');
        }

        document.addEventListener('DOMContentLoaded', () => {
            initDragControls();
            initPackOpening(false);

            window.addEventListener('click', (e) => {
                if (selectedPackEl && !selectedPackEl.contains(e.target)) {
                    deselectActivePack();
                }
            });
        });
    </script>
</body>

</html>