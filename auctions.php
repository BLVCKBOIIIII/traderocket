<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// API Endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'fetch_cards') {
        try {
            $stmt = $pdo->prepare("
                SELECT c.id AS card_id, c.name, c.image_url, MAX(i.sell_price) AS sell_price 
                FROM cards c
                JOIN inventory_stocks i ON c.id = i.card_id
                WHERE i.quantity > 0
                GROUP BY c.id, c.name, c.image_url
            ");
            $stmt->execute();
            echo json_encode(['success' => true, 'cards' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'push_order') {
        try {
            $cardId = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
            $winnerName = trim(filter_input(INPUT_POST, 'winner_name', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
            $winningBid = filter_input(INPUT_POST, 'winning_bid', FILTER_VALIDATE_FLOAT);

            if (!$cardId || empty($winnerName) || $winningBid === false || $winningBid <= 0) {
                throw new Exception('Invalid order payload provided.');
            }

            // Find stock inventory item matching the selected card
            $stockStmt = $pdo->prepare("SELECT id, quantity FROM inventory_stocks WHERE card_id = ? AND quantity > 0 LIMIT 1");
            $stockStmt->execute([$cardId]);
            $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$stock) {
                throw new Exception('Selected card is out of stock.');
            }

            $inventoryStockId = $stock['id'];

            $pdo->beginTransaction();

            // Insert into orders with schema matching orders_process.php
            $orderStmt = $pdo->prepare("
                INSERT INTO orders (customer_name, total_amount, payment_status, fulfillment_status, created_at) 
                VALUES (?, ?, 'Unpaid', 'Pending', NOW())
            ");
            $orderStmt->execute([$winnerName, $winningBid]);

            $orderId = $pdo->lastInsertId('orders_id_seq');

            // Insert item into order_items matching orders_process.php schema
            $itemStmt = $pdo->prepare("
                INSERT INTO order_items (order_id, card_id, quantity, price, created_at) 
                VALUES (?, ?, 1, ?, NOW())
            ");
            $itemStmt->execute([$orderId, $inventoryStockId, $winningBid]);

            // Deduct quantity by 1 from inventory
            $stockUpdate = $pdo->prepare("UPDATE inventory_stocks SET quantity = quantity - 1 WHERE id = ?");
            $stockUpdate->execute([$inventoryStockId]);

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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Card Auction Arena | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700;900&display=swap');

        body {
            font-family: 'Space Grotesk', sans-serif;
            background-color: #060508;
            color: #f4f4f5;
            transition: background-color 0.8s ease;
        }

        body.gloomy-mode {
            background-color: #020104 !important;
        }

        #setupPanel {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            max-height: 280px;
            opacity: 1;
            overflow: hidden;
        }

        #setupPanel.collapsed {
            max-height: 0px;
            opacity: 0;
            padding-top: 0;
            padding-bottom: 0;
            margin: 0;
            border: none;
        }

        .neon-timer-box {
            border: 4px solid #10b981;
            box-shadow: 0 0 30px rgba(16, 185, 129, 0.35), inset 0 0 20px rgba(16, 185, 129, 0.25);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .neon-timer-box.timer-line-closed {
            max-height: 10px;
            padding: 0 !important;
            border-radius: 9999px;
            border-color: #a855f7;
            box-shadow: 0 0 30px #a855f7, 0 0 60px #9333ea;
            background-color: #a855f7;
            margin-bottom: 0.25rem;
        }

        .neon-timer-box.timer-line-closed * {
            opacity: 0;
            display: none !important;
        }

        .neon-timer-text {
            color: #a7f3d0;
            text-shadow: 0 0 18px #34d399, 0 0 36px #10b981;
            transition: all 0.3s ease;
        }

        .neon-timer-box.timer-warning {
            border-color: #ef4444;
            box-shadow: 0 0 35px rgba(239, 68, 68, 0.7), inset 0 0 25px rgba(239, 68, 68, 0.3);
            animation: warningPulse 0.8s infinite alternate;
        }

        .neon-timer-text.timer-warning-text {
            color: #fca5a5;
            text-shadow: 0 0 18px #f87171, 0 0 36px #ef4444;
        }

        .neon-timer-box.timer-critical {
            border-color: #ff0055;
            box-shadow: 0 0 45px rgba(255, 0, 85, 0.95), inset 0 0 30px rgba(255, 0, 85, 0.6);
            animation: criticalPulse 0.3s infinite alternate;
        }

        .neon-timer-text.timer-critical-text {
            color: #ffffff;
            text-shadow: 0 0 22px #ff0055, 0 0 45px #ff0055;
        }

        @keyframes warningPulse {
            0% { transform: scale(1); }
            100% { transform: scale(1.02); }
        }

        @keyframes criticalPulse {
            0% { transform: scale(1); }
            100% { transform: scale(1.03); }
        }

        .card-perspective-container {
            perspective: 1400px;
        }

        .pokemon-card-frame {
            aspect-ratio: 2.5 / 3.5;
            border-radius: 4.75% / 3.5%;
            box-shadow: 0 25px 50px rgba(168, 85, 247, 0.5), 0 0 30px rgba(168, 85, 247, 0.3);
            animation: cardSpinAndBounce 10s ease-in-out infinite;
            transform-style: preserve-3d;
        }

        .pokemon-card-frame:hover {
            animation-play-state: paused;
        }

        @keyframes cardSpinAndBounce {
            0% { transform: translateY(0px) rotateY(-10deg); }
            25% { transform: translateY(-10px) rotateY(0deg); }
            50% { transform: translateY(0px) rotateY(10deg); }
            75% { transform: translateY(-10px) rotateY(0deg); }
            100% { transform: translateY(0px) rotateY(-10deg); }
        }

        .bidding-board-focus {
            transition: all 0.4s ease;
        }

        .bidding-board-focus.active-mode {
            border-color: #a855f7;
            box-shadow: 0 0 35px rgba(168, 85, 247, 0.35);
            background-color: #161027;
        }

        .config-box {
            background: linear-gradient(145deg, #161226, #0d0a17);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .config-box.highlighted {
            transform: scale(1.15);
            z-index: 20;
            box-shadow: 0 0 40px #a855f7, 0 0 25px #3b82f6 !important;
            border-color: #ffffff !important;
        }

        #hypeOverlay {
            backdrop-filter: blur(8px);
            transition: opacity 0.3s ease;
        }

        #hypeOverlayText {
            text-shadow: 0 0 40px #a855f7, 0 0 80px #ec4899;
            animation: textPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes textPop {
            0% { transform: scale(0.3); opacity: 0; }
            80% { transform: scale(1.15); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        input::-webkit-outer-spin-button,
        input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type=number] {
            -moz-appearance: textfield;
        }
    </style>
</head>
<body class="h-screen flex flex-col bg-[#060508] overflow-hidden">

    <!-- HEADER -->
    <?php include 'includes/header.php'; ?>

    <!-- FULLSCREEN HYPE OVERLAY -->
    <div id="hypeOverlay" class="fixed inset-0 bg-black/80 z-50 hidden flex items-center justify-center pointer-events-none">
        <span id="hypeOverlayText" class="text-8xl md:text-[12rem] font-black text-white uppercase tracking-widest italic">READY</span>
    </div>

    <!-- MAIN UI CONTAINER -->
    <main class="flex-1 w-full max-w-[90rem] mx-auto p-4 md:p-8 flex flex-col justify-center overflow-hidden">

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-center flex-1 min-h-0 justify-center">

            <!-- LEFT: CARD DISPLAY FRAME -->
            <div class="lg:col-span-5 flex flex-col items-center justify-center card-perspective-container w-full max-w-xl mx-auto">
                <div class="pokemon-card-frame w-full max-w-[320px] md:max-w-[380px] overflow-hidden border-3 border-purple-500/50 bg-black/80 flex items-center justify-center">
                    <img id="cardDisplayImg" src="https://images.placeholders.dev/?width=400&height=560&text=Select+Card&bgColor=120f1d&textColor=a855f7" class="w-full h-full object-cover" alt="Selected Card Image">
                </div>
                <h3 id="cardDisplayName" class="text-xl md:text-2xl font-black text-white mt-4 text-center tracking-wide drop-shadow-md truncate max-w-full px-2">No Card Selected</h3>
                
                <button id="randomBtn" title="Spawn Random Card" class="mt-2.5 bg-purple-900/60 border border-purple-500/40 hover:bg-purple-800 text-purple-200 text-base font-bold px-5 py-2 rounded-xl flex items-center gap-2 transition-all shadow-lg hover:scale-105 active:scale-95">
                    🎲 Random Card
                </button>
            </div>

            <!-- RIGHT: CONTROLS & TIMERS -->
            <div class="lg:col-span-7 flex flex-col gap-4 justify-center my-auto w-full max-w-4xl mx-auto">

                <!-- AUCTION CARD SELECTOR -->
                <div id="setupPanel" class="bg-[#120f1d] border-2 border-purple-900/60 p-4 rounded-2xl flex items-center justify-between gap-4 shadow-2xl flex-shrink-0">
                    <span class="text-base font-black uppercase text-purple-200 tracking-wider">Select Card</span>
                    <div class="flex items-center gap-3 flex-1 justify-end">
                        <input type="text" id="cardSearchInput" placeholder="Search..." class="bg-black/60 border border-purple-500/40 text-base text-white px-4 py-2.5 rounded-xl outline-none focus:border-purple-400 w-48">
                        <select id="cardSelect" class="bg-black/60 border border-purple-500/40 text-base text-white px-4 py-2.5 rounded-xl outline-none focus:border-purple-400 max-w-[280px]">
                            <option value="">Loading Cards...</option>
                        </select>
                    </div>
                </div>

                <!-- EDITABLE & EXPANDABLE TIMER BOX -->
                <div id="neonTimerBox" class="neon-timer-box bg-[#070d0a] rounded-2xl p-5 md:p-7 flex items-center justify-between gap-5 relative overflow-hidden flex-shrink-0">

                    <div class="flex flex-col items-center justify-center flex-1 w-full">
                        <span id="timerLabel" class="text-sm md:text-base font-extrabold uppercase tracking-widest text-emerald-400 mb-2">Auction Time Remaining</span>
                        
                        <div id="editableTimerContainer" class="flex items-center justify-center gap-2 text-5xl md:text-7xl font-black tracking-tight leading-none text-emerald-300">
                            <input type="number" id="hrsInput" min="0" placeholder="00" value="00" class="neon-timer-text bg-transparent text-center border-b-3 border-emerald-500/40 focus:border-emerald-400 outline-none w-20 md:w-28 py-0 font-black">
                            <span class="neon-timer-text pb-1">:</span>
                            <input type="number" id="minsInput" min="0" max="59" placeholder="01" value="01" class="neon-timer-text bg-transparent text-center border-b-3 border-emerald-500/40 focus:border-emerald-400 outline-none w-20 md:w-28 py-0 font-black">
                            <span class="neon-timer-text pb-1">:</span>
                            <input type="number" id="secsInput" min="0" max="59" placeholder="00" value="00" class="neon-timer-text bg-transparent text-center border-b-3 border-emerald-500/40 focus:border-emerald-400 outline-none w-20 md:w-28 py-0 font-black">
                        </div>

                        <div id="neonTimerDisplay" class="hidden neon-timer-text text-7xl md:text-9xl font-black tracking-tighter leading-none py-1">
                            00:01:00
                        </div>
                    </div>

                    <button id="startAuctionBtn" class="purple-btn text-white text-base md:text-lg font-black px-8 py-6 rounded-2xl uppercase tracking-wider hover:scale-105 transition-transform shadow-xl flex-shrink-0">
                        Start
                    </button>
                </div>

                <!-- CONFIGURATION INPUTS -->
                <div id="configInputsRow" class="grid grid-cols-3 gap-3 md:gap-5 relative">
                    <div id="sbBox" class="config-box border-2 border-purple-500/60 focus-within:border-purple-400 rounded-2xl p-3.5 md:p-4 flex flex-col items-center shadow-xl shadow-purple-950/40">
                        <span class="text-xs md:text-base text-purple-300 font-black uppercase tracking-widest mb-2 flex items-center gap-1.5">
                            🏷️ <span class="bg-purple-900/80 px-2.5 py-0.5 rounded-md border border-purple-500/40">SB</span> Start Bid
                        </span>
                        <div class="relative w-full flex items-center justify-center">
                            <span class="absolute left-3 text-purple-400 font-black text-lg md:text-xl">₱</span>
                            <input type="number" id="startingBidInput" step="0.01" value="0" class="w-full text-center text-2xl md:text-4xl bg-black/80 border-2 border-purple-500/50 focus:border-purple-300 rounded-xl text-purple-100 font-black py-2 pl-7 pr-2 outline-none shadow-inner">
                        </div>
                    </div>

                    <div id="incBox" class="config-box border-2 border-indigo-500/60 focus-within:border-indigo-400 rounded-2xl p-3.5 md:p-4 flex flex-col items-center shadow-xl shadow-indigo-950/40">
                        <span class="text-xs md:text-base text-indigo-300 font-black uppercase tracking-widest mb-2 flex items-center gap-1.5">
                            ⚡ <span class="bg-indigo-900/80 px-2.5 py-0.5 rounded-md border border-indigo-500/40">INC</span> Step
                        </span>
                        <div class="relative w-full flex items-center justify-center">
                            <span class="absolute left-3 text-indigo-400 font-black text-lg md:text-xl">₱</span>
                            <input type="number" id="bidIncrementInput" step="0.01" value="0" class="w-full text-center text-2xl md:text-4xl bg-black/80 border-2 border-indigo-500/50 focus:border-indigo-300 rounded-xl text-indigo-100 font-black py-2 pl-7 pr-2 outline-none shadow-inner">
                        </div>
                    </div>

                    <div id="boBox" class="config-box border-2 border-emerald-500/60 focus-within:border-emerald-400 rounded-2xl p-3.5 md:p-4 flex flex-col items-center shadow-xl shadow-emerald-950/40">
                        <span class="text-xs md:text-base text-emerald-300 font-black uppercase tracking-widest mb-2 flex items-center gap-1.5">
                            🚀 <span class="bg-emerald-900/80 px-2.5 py-0.5 rounded-md border border-emerald-500/40">BO</span> Buyout
                        </span>
                        <div class="relative w-full flex items-center justify-center">
                            <span class="absolute left-3 text-emerald-400 font-black text-lg md:text-xl">₱</span>
                            <input type="number" id="buyoutPriceInput" step="0.01" value="0" class="w-full text-center text-2xl md:text-4xl bg-black/80 border-2 border-emerald-500/50 focus:border-emerald-300 rounded-xl text-emerald-300 font-black py-2 pl-7 pr-2 outline-none shadow-inner">
                        </div>
                    </div>
                </div>

                <!-- LIVE BID CONTROL BOARD -->
                <div id="biddingBoard" class="bidding-board-focus bg-[#120f1d] border-2 border-purple-900/60 rounded-2xl p-5 flex flex-col gap-4 shadow-2xl">
                    <div class="flex items-center justify-between bg-black/60 border border-purple-900/60 p-4 rounded-2xl">
                        <div class="flex flex-col">
                            <span class="text-xs md:text-sm text-purple-300/70 font-bold uppercase tracking-wider">Current Highest Bid</span>
                            <span class="text-sm md:text-base text-emerald-400 font-bold uppercase tracking-wider">Buyout: ₱<span id="displayBuyoutPrice">0.00</span></span>
                        </div>
                        <span id="currentHighestBid" class="text-4xl md:text-6xl font-black text-emerald-400">₱0.00</span>
                    </div>

                    <div class="grid grid-cols-12 gap-3">
                        <button id="quickIncrementBtn" disabled class="col-span-6 bg-purple-600 hover:bg-purple-500 text-white text-lg font-black py-3.5 px-4 rounded-2xl uppercase tracking-wider opacity-50 cursor-not-allowed transition-all shadow-xl active:scale-95 flex items-center justify-center gap-2">
                            ⚡ +₱<span id="btnIncrementVal">0.00</span>
                        </button>

                        <button id="buyoutBtn" disabled class="col-span-6 bg-amber-600 hover:bg-amber-500 text-white text-lg font-black py-3.5 px-4 rounded-2xl uppercase tracking-wider opacity-50 cursor-not-allowed transition-all shadow-xl active:scale-95 flex items-center justify-center gap-2">
                            🚀 Instant Buyout
                        </button>
                    </div>

                    <div class="grid grid-cols-12 gap-3 items-center">
                        <div class="col-span-8 flex items-center gap-2.5">
                            <input type="number" id="bidAmountInput" step="0.01" placeholder="₱ Custom Bid" class="w-full bg-black/60 border border-purple-500/40 text-white text-base p-3 rounded-xl outline-none focus:border-purple-400 font-bold">
                            <button id="placeBidBtn" disabled class="bg-purple-900/80 hover:bg-purple-800 text-purple-200 text-sm font-black px-5 py-3 rounded-xl uppercase tracking-wider opacity-50 cursor-not-allowed transition-all whitespace-nowrap">
                                Set Custom
                            </button>
                        </div>
                        <button id="cancelAuctionBtn" disabled class="col-span-4 bg-red-900/80 hover:bg-red-700 text-white text-sm font-black py-3 px-2 rounded-xl uppercase tracking-wider opacity-50 cursor-not-allowed transition-all shadow-md active:scale-95">
                            ✖ Cancel
                        </button>
                    </div>

                    <!-- WINNER NAME INPUT & INSTAPAY PAYMENT DISPLAY -->
                    <div id="winnerSection" class="hidden flex-col gap-4 border-t border-purple-900/40 pt-4">
                        <div class="bg-purple-950/40 border border-purple-800/50 p-4 rounded-xl flex flex-col md:flex-row gap-4 items-center justify-between">
                            <div class="flex flex-col gap-2 flex-1 w-full">
                                <label for="bidderNameInput" class="text-sm text-emerald-400 font-bold uppercase tracking-wider">Winning Bidder Name <span class="text-red-400">*</span></label>
                                <input type="text" id="bidderNameInput" placeholder="Type winner name..." class="bg-black/80 border border-emerald-500/60 text-white text-base p-3 rounded-xl outline-none focus:border-emerald-400 w-full">
                            </div>

                            <!-- INSTAPAY QR PAYMENT CODE -->
                            <div class="flex flex-col items-center justify-center bg-white/5 p-3 rounded-xl border border-purple-500/30 flex-shrink-0">
                                <span class="text-xs text-purple-300 font-bold uppercase mb-1">Scan to Pay via InstaPay</span>
                                <img src="qr gcash ko.jpg" alt="InstaPay Payment QR Code" class="w-28 h-28 object-contain rounded-lg border border-white/20">
                            </div>
                        </div>

                        <button id="pushOrderBtn" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white text-base font-black py-3.5 rounded-xl uppercase tracking-wider transition-all shadow-xl active:scale-95">
                            Send Winning Bid to Pending Orders
                        </button>
                    </div>
                </div>

            </div>
        </div>

    </main>

    <script>
        let availableCards = [];
        let selectedCard = null;
        let countdownInterval = null;
        let totalSecondsLeft = 0;
        let highestBid = 0;
        let buyoutPrice = 0;

        let audioCtx = null;

        const marioCoinAudio = new Audio('https://www.myinstants.com/media/sounds/mario-coin.mp3');
        const buyoutMoneyAudio = new Audio('https://www.myinstants.com/media/sounds/cash-register-sound-effect.mp3');
        const animeWowAudio = new Audio('https://www.myinstants.com/media/sounds/anime-wow.mp3');

        function playBidCoinSound() {
            marioCoinAudio.currentTime = 0;
            marioCoinAudio.play().catch(e => {});
        }

        function playBuyoutSound() {
            buyoutMoneyAudio.currentTime = 0;
            buyoutMoneyAudio.play().catch(e => {});
        }

        function getAudioContext() {
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            return audioCtx;
        }

        function playHypeStab(freq = 440) {
            try {
                const ctx = getAudioContext();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(freq, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(freq * 2, ctx.currentTime + 0.3);
                gain.gain.setValueAtTime(0.4, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.35);
            } catch (e) {}
        }

        function playHighlightSound() {
            try {
                const ctx = getAudioContext();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, ctx.currentTime);
                gain.gain.setValueAtTime(0.3, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.15);
            } catch (e) {}
        }

        function playTickSound(mode) {
            try {
                const ctx = getAudioContext();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                const now = ctx.currentTime;

                if (mode === 'normal') {
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(800, now);
                    gain.gain.setValueAtTime(0.15, now);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.05);
                    osc.start(now);
                    osc.stop(now + 0.05);
                } else if (mode === 'intense') {
                    osc.type = 'triangle';
                    osc.frequency.setValueAtTime(1200, now);
                    gain.gain.setValueAtTime(0.35, now);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
                    osc.start(now);
                    osc.stop(now + 0.08);
                } else if (mode === 'critical') {
                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(1800, now);
                    gain.gain.setValueAtTime(0.5, now);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.12);
                    osc.start(now);
                    osc.stop(now + 0.12);
                }
            } catch (e) {}
        }

        async function fetchAvailableCards() {
            try {
                const formData = new FormData();
                formData.append('action', 'fetch_cards');
                const res = await fetch('auctions.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success && data.cards.length > 0) {
                    availableCards = data.cards;
                    populateDropdown(availableCards);
                    spawnRandomCard();
                } else {
                    document.getElementById('cardSelect').innerHTML = '<option value="">No cards available</option>';
                }
            } catch (e) {}
        }

        function populateDropdown(cards) {
            const select = document.getElementById('cardSelect');
            select.innerHTML = '<option value="">Select Card...</option>';

            cards.forEach(card => {
                const option = document.createElement('option');
                option.value = card.card_id;
                option.textContent = `${card.name} - ₱${parseFloat(card.sell_price || 0).toFixed(2)}`;
                select.appendChild(option);
            });
        }

        function filterCards() {
            const query = document.getElementById('cardSearchInput').value.toLowerCase().trim();
            const filtered = availableCards.filter(c => c.name.toLowerCase().includes(query));
            populateDropdown(filtered);

            if (filtered.length === 1) {
                document.getElementById('cardSelect').value = filtered[0].card_id;
                selectCardById(filtered[0].card_id);
            }
        }

        function spawnRandomCard() {
            if (availableCards.length === 0) return;
            const randomIndex = Math.floor(Math.random() * availableCards.length);
            const randomCard = availableCards[randomIndex];

            document.getElementById('cardSearchInput').value = '';
            populateDropdown(availableCards);
            document.getElementById('cardSelect').value = randomCard.card_id;

            selectCardById(randomCard.card_id);
        }

        function selectCardById(cardId) {
            selectedCard = availableCards.find(c => c.card_id == cardId);

            if (selectedCard) {
                document.getElementById('cardDisplayImg').src = selectedCard.image_url;
                document.getElementById('cardDisplayName').innerText = selectedCard.name;
                updatePricingFromInputs();
            }
        }

        function updatePricingFromInputs() {
            const startVal = parseFloat(document.getElementById('startingBidInput').value) || 0;
            const incVal = parseFloat(document.getElementById('bidIncrementInput').value) || 0;
            buyoutPrice = parseFloat(document.getElementById('buyoutPriceInput').value) || 0;

            highestBid = startVal;
            document.getElementById('currentHighestBid').innerText = `₱${highestBid.toFixed(2)}`;
            document.getElementById('displayBuyoutPrice').innerText = buyoutPrice.toFixed(2);
            document.getElementById('bidAmountInput').value = (highestBid + incVal).toFixed(2);
            document.getElementById('btnIncrementVal').innerText = incVal.toFixed(2);
        }

        function triggerQuickIncrement() {
            const incVal = parseFloat(document.getElementById('bidIncrementInput').value) || 0;
            
            if (buyoutPrice > 0 && highestBid + incVal >= buyoutPrice) {
                triggerBuyout();
                return;
            }

            highestBid += incVal;
            document.getElementById('currentHighestBid').innerText = `₱${highestBid.toFixed(2)}`;
            document.getElementById('bidAmountInput').value = (highestBid + incVal).toFixed(2);
            playBidCoinSound();
        }

        function triggerBuyout() {
            highestBid = buyoutPrice;
            document.getElementById('currentHighestBid').innerText = `₱${highestBid.toFixed(2)}`;
            playBuyoutSound();
            
            if (countdownInterval) clearInterval(countdownInterval);
            finishAuction();
        }

        function placeCustomBid() {
            const customBid = parseFloat(document.getElementById('bidAmountInput').value);

            if (isNaN(customBid) || customBid <= highestBid) {
                alert(`Bid must be higher than current bid of ₱${highestBid.toFixed(2)}`);
                return;
            }

            if (buyoutPrice > 0 && customBid >= buyoutPrice) {
                triggerBuyout();
                return;
            }

            highestBid = customBid;
            const incVal = parseFloat(document.getElementById('bidIncrementInput').value) || 0;

            document.getElementById('currentHighestBid').innerText = `₱${highestBid.toFixed(2)}`;
            document.getElementById('bidAmountInput').value = (highestBid + incVal).toFixed(2);
            playBidCoinSound();
        }

        async function startAuctionSequence() {
            if (!selectedCard) {
                alert("Please select or spawn a card first!");
                return;
            }

            const hrs = parseInt(document.getElementById('hrsInput').value) || 0;
            const mins = parseInt(document.getElementById('minsInput').value) || 0;
            const secs = parseInt(document.getElementById('secsInput').value) || 0;

            totalSecondsLeft = (hrs * 3600) + (mins * 60) + secs;

            if (totalSecondsLeft <= 0) {
                alert("Please set a timer greater than 0!");
                return;
            }

            const overlay = document.getElementById('hypeOverlay');
            const overlayText = document.getElementById('hypeOverlayText');
            const sbBox = document.getElementById('sbBox');
            const incBox = document.getElementById('incBox');
            const boBox = document.getElementById('boBox');

            if (buyoutPrice <= 0) {
                document.body.classList.add('gloomy-mode');
            } else {
                document.body.classList.remove('gloomy-mode');
            }

            overlay.classList.remove('hidden');

            overlayText.innerText = 'READY';
            playHypeStab(300);
            await new Promise(r => setTimeout(r, 700));

            overlayText.innerText = 'SET';
            playHypeStab(450);
            await new Promise(r => setTimeout(r, 700));

            overlayText.innerText = 'AUCTION!';
            playHypeStab(700);
            await new Promise(r => setTimeout(r, 800));

            overlay.classList.add('hidden');

            await new Promise(r => setTimeout(r, 300));

            sbBox.classList.add('highlighted');
            playHighlightSound();
            await new Promise(r => setTimeout(r, 700));
            sbBox.classList.remove('highlighted');

            await new Promise(r => setTimeout(r, 300));

            incBox.classList.add('highlighted');
            playHighlightSound();
            await new Promise(r => setTimeout(r, 700));
            incBox.classList.remove('highlighted');

            await new Promise(r => setTimeout(r, 300));

            boBox.classList.add('highlighted');
            playHighlightSound();
            await new Promise(r => setTimeout(r, 700));
            boBox.classList.remove('highlighted');

            await new Promise(r => setTimeout(r, 300));

            beginAuctionTimer();
        }

        function beginAuctionTimer() {
            document.getElementById('setupPanel').classList.add('collapsed');
            document.getElementById('randomBtn').classList.add('hidden');
            document.getElementById('startAuctionBtn').classList.add('hidden');
            document.getElementById('editableTimerContainer').classList.add('hidden');
            document.getElementById('neonTimerDisplay').classList.remove('hidden');
            document.getElementById('biddingBoard').classList.add('active-mode');

            document.getElementById('startingBidInput').disabled = true;
            document.getElementById('bidIncrementInput').disabled = true;
            document.getElementById('buyoutPriceInput').disabled = true;

            const winnerSec = document.getElementById('winnerSection');
            winnerSec.classList.add('hidden');
            winnerSec.classList.remove('flex');

            resetTimerColor();
            toggleBiddingButtons(true);

            if (countdownInterval) clearInterval(countdownInterval);

            updateTimerDisplay();
            countdownInterval = setInterval(() => {
                totalSecondsLeft--;
                updateTimerDisplay();

                if (totalSecondsLeft <= 0) {
                    clearInterval(countdownInterval);
                    finishAuction();
                }
            }, 1000);
        }

        function cancelAuction() {
            if (countdownInterval) clearInterval(countdownInterval);

            totalSecondsLeft = 0;
            document.body.classList.remove('gloomy-mode');
            document.getElementById('neonTimerDisplay').innerText = "00:00:00";
            
            document.getElementById('setupPanel').classList.remove('collapsed');
            document.getElementById('randomBtn').classList.remove('hidden');
            document.getElementById('startAuctionBtn').classList.remove('hidden');
            document.getElementById('editableTimerContainer').classList.remove('hidden');
            document.getElementById('neonTimerDisplay').classList.add('hidden');
            document.getElementById('biddingBoard').classList.remove('active-mode');

            document.getElementById('startingBidInput').disabled = false;
            document.getElementById('bidIncrementInput').disabled = false;
            document.getElementById('buyoutPriceInput').disabled = false;

            const winnerSec = document.getElementById('winnerSection');
            winnerSec.classList.add('hidden');
            winnerSec.classList.remove('flex');

            resetTimerColor();
            toggleBiddingButtons(false);
            updatePricingFromInputs();
        }

        function updateTimerDisplay() {
            const h = Math.floor(totalSecondsLeft / 3600).toString().padStart(2, '0');
            const m = Math.floor((totalSecondsLeft % 3600) / 60).toString().padStart(2, '0');
            const s = (totalSecondsLeft % 60).toString().padStart(2, '0');
            
            document.getElementById('neonTimerDisplay').innerText = `${h}:${m}:${s}`;

            const box = document.getElementById('neonTimerBox');
            const text = document.getElementById('neonTimerDisplay');
            const label = document.getElementById('timerLabel');

            if (totalSecondsLeft <= 3 && totalSecondsLeft > 0) {
                box.classList.remove('timer-warning');
                text.classList.remove('timer-warning-text');

                box.classList.add('timer-critical');
                text.classList.add('timer-critical-text');
                label.classList.remove('text-emerald-400', 'text-red-400');
                label.classList.add('text-rose-200');

                playTickSound('critical');
            } else if (totalSecondsLeft <= 10 && totalSecondsLeft > 3) {
                box.classList.remove('timer-critical');
                text.classList.remove('timer-critical-text');

                box.classList.add('timer-warning');
                text.classList.add('timer-warning-text');
                label.classList.remove('text-emerald-400', 'text-rose-200');
                label.classList.add('text-red-400');

                playTickSound('intense');
            } else if (totalSecondsLeft > 10) {
                resetTimerColor();
                playTickSound('normal');
            }
        }

        function resetTimerColor() {
            const box = document.getElementById('neonTimerBox');
            const text = document.getElementById('neonTimerDisplay');
            const label = document.getElementById('timerLabel');

            box.classList.remove('timer-warning', 'timer-critical', 'timer-line-closed');
            text.classList.remove('timer-warning-text', 'timer-critical-text');
            label.classList.remove('text-red-400', 'text-rose-200');
            label.classList.add('text-emerald-400');
        }

        function toggleBiddingButtons(enabled) {
            const btns = [
                document.getElementById('quickIncrementBtn'),
                document.getElementById('buyoutBtn'),
                document.getElementById('placeBidBtn'),
                document.getElementById('cancelAuctionBtn')
            ];

            btns.forEach(btn => {
                btn.disabled = !enabled;
                if (enabled) {
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                } else {
                    btn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            });
        }

        function finishAuction() {
            toggleBiddingButtons(false);
            document.body.classList.remove('gloomy-mode');

            const timerBox = document.getElementById('neonTimerBox');
            timerBox.classList.add('timer-line-closed');

            animeWowAudio.currentTime = 0;
            animeWowAudio.play().catch(e => {});

            const winnerSec = document.getElementById('winnerSection');
            winnerSec.classList.remove('hidden');
            winnerSec.classList.add('flex');
            document.getElementById('bidderNameInput').focus();

            confetti({
                particleCount: 200,
                spread: 120,
                origin: { x: 0.5, y: 0.5 }
            });
        }

        async function pushToOrder() {
            const winnerNameInput = document.getElementById('bidderNameInput');
            const winnerName = winnerNameInput.value.trim();

            if (!selectedCard) {
                alert("No card selected.");
                return;
            }

            if (!winnerName) {
                alert("Please fill out the winning bidder's name before sending to pending orders!");
                winnerNameInput.focus();
                return;
            }

            if (highestBid <= 0) {
                alert("Invalid winning bid.");
                return;
            }

            const formData = new FormData();
            formData.append('action', 'push_order');
            formData.append('card_id', selectedCard.card_id);
            formData.append('winner_name', winnerName);
            formData.append('winning_bid', highestBid);

            try {
                const res = await fetch('auctions.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    alert(`Order #${data.order_id} for ${winnerName} sent to New/Pending Orders! Redirecting to processing page...`);
                    window.location.href = 'orders_process.php';
                } else {
                    alert('Failed to push order: ' + data.error);
                }
            } catch (e) {
                alert('Server error creating order.');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            fetchAvailableCards();
            
            document.getElementById('cardSearchInput').addEventListener('input', filterCards);
            document.getElementById('cardSelect').addEventListener('change', (e) => selectCardById(e.target.value));
            document.getElementById('randomBtn').addEventListener('click', spawnRandomCard);
            
            document.getElementById('startingBidInput').addEventListener('input', updatePricingFromInputs);
            document.getElementById('bidIncrementInput').addEventListener('input', updatePricingFromInputs);
            document.getElementById('buyoutPriceInput').addEventListener('input', updatePricingFromInputs);

            document.getElementById('quickIncrementBtn').addEventListener('click', triggerQuickIncrement);
            document.getElementById('buyoutBtn').addEventListener('click', triggerBuyout);
            document.getElementById('placeBidBtn').addEventListener('click', placeCustomBid);
            document.getElementById('cancelAuctionBtn').addEventListener('click', cancelAuction);
            
            document.getElementById('startAuctionBtn').addEventListener('click', startAuctionSequence);
            document.getElementById('pushOrderBtn').addEventListener('click', pushToOrder);
        });
    </script>
</body>
</html>