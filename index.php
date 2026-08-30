<?php
session_start();
require_once 'config/database.php';

// Security check: If not logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['user_role'] ?? 'user';

// Analytics Filter Parameters ('all', 'cosigned')
$chartFilter = $_GET['chart_filter'] ?? 'all';

try {
    if ($role === 'admin') {
        // --- ADMIN METRICS ---
        $stmtCards = $pdo->query("SELECT COUNT(*) FROM cards");
        $totalCards = $stmtCards->fetchColumn();

        $stmtStock = $pdo->query("SELECT SUM(quantity) FROM inventory_stocks");
        $totalStock = $stmtStock->fetchColumn() ?: 0;

        $stmtSets = $pdo->query("SELECT COUNT(*) FROM sets");
        $totalSets = $stmtSets->fetchColumn();

        // Admin Slideshow Query (All active items)
        $stmtSlide = $pdo->query("
            SELECT 
                i.*, 
                c.name AS card_name, 
                COALESCE(NULLIF(i.image_url, ''), NULLIF(c.image_url, ''), '') AS card_image,
                COALESCE(NULLIF(i.condition_status, ''), NULLIF(i.card_condition, ''), 'NM') AS card_condition_name
            FROM inventory_stocks i 
            LEFT JOIN cards c ON i.card_id = c.id 
            WHERE i.quantity > 0 
            ORDER BY i.id DESC
        ");
        $slideCards = $stmtSlide->fetchAll(PDO::FETCH_ASSOC);

        // Admin Analytics Breakdown (with filter support)
        $condSql = "SELECT COALESCE(NULLIF(i.condition_status, ''), NULLIF(i.card_condition, ''), 'NM') as card_condition, SUM(quantity) as total_qty, SUM(COALESCE(i.sell_price, i.price, c.price, 0) * quantity) as total_value FROM inventory_stocks i LEFT JOIN cards c ON i.card_id = c.id";
        if ($chartFilter === 'cosigned') {
            $condSql .= " WHERE (i.is_cosigned = TRUE OR i.is_cosigned = 1)";
        }
        $condSql .= " GROUP BY card_condition";

        $stmtCond = $pdo->query($condSql);
        $conditionStats = $stmtCond->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // --- PARTNER METRICS ---
        $stmtCards = $pdo->prepare("SELECT COUNT(DISTINCT card_id) FROM inventory_stocks WHERE owner_id = ?");
        $stmtCards->execute([$userId]);
        $totalCards = $stmtCards->fetchColumn();

        $stmtStock = $pdo->prepare("SELECT SUM(quantity) FROM inventory_stocks WHERE owner_id = ?");
        $stmtStock->execute([$userId]);
        $totalStock = $stmtStock->fetchColumn() ?: 0;

        $totalSets = '-';

        // Partner Slideshow Query (Only partner's own active items)
        $stmtSlide = $pdo->prepare("
            SELECT 
                i.*, 
                c.name AS card_name, 
                COALESCE(NULLIF(i.image_url, ''), NULLIF(c.image_url, ''), '') AS card_image,
                COALESCE(NULLIF(i.condition_status, ''), NULLIF(i.card_condition, ''), 'NM') AS card_condition_name
            FROM inventory_stocks i 
            LEFT JOIN cards c ON i.card_id = c.id 
            WHERE i.owner_id = ? AND i.quantity > 0 
            ORDER BY i.id DESC
        ");
        $stmtSlide->execute([$userId]);
        $slideCards = $stmtSlide->fetchAll(PDO::FETCH_ASSOC);

        // Partner Analytics Breakdown (with filter support)
        $condSql = "SELECT COALESCE(NULLIF(i.condition_status, ''), NULLIF(i.card_condition, ''), 'NM') as card_condition, SUM(quantity) as total_qty, SUM(COALESCE(i.sell_price, i.price, c.price, 0) * quantity) as total_value FROM inventory_stocks i LEFT JOIN cards c ON i.card_id = c.id WHERE i.owner_id = ?";
        $params = [$userId];

        if ($chartFilter === 'cosigned') {
            $condSql .= " AND (i.is_cosigned = TRUE OR i.is_cosigned = 1)";
        }
        $condSql .= " GROUP BY card_condition";

        $stmtCond = $pdo->prepare($condSql);
        $stmtCond->execute($params);
        $conditionStats = $stmtCond->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $totalCards = 0;
    $totalStock = 0;
    $totalSets = 0;
    $slideCards = [];
    $conditionStats = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center | Trade Rocket TCG</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Space Grotesk', sans-serif;
            background-color: #070709;
            color: #f3f4f6;
        }

        .glass-card {
            background: linear-gradient(135deg, rgba(24, 24, 27, 0.85) 0%, rgba(14, 14, 18, 0.95) 100%);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(147, 51, 234, 0.18);
            border-radius: 1.25rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.6);
        }

        .purple-glow {
            box-shadow: 0 0 25px rgba(147, 51, 234, 0.35);
        }

        .nav-card {
            background: rgba(18, 15, 29, 0.6);
            border: 1px solid rgba(147, 51, 234, 0.2);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .nav-card:hover {
            background: linear-gradient(135deg, rgba(147, 51, 234, 0.2) 0%, rgba(88, 28, 135, 0.3) 100%);
            border-color: rgba(168, 85, 247, 0.6);
            transform: translateY(-2px);
        }

        /* Vertical Seamless Card-Only Slideshow Styles */
        @keyframes verticalScroll {
            0% {
                transform: translateY(0);
            }

            100% {
                transform: translateY(-50%);
            }
        }

        .vertical-marquee-container {
            height: calc(100vh - 150px);
            overflow: hidden;
            position: relative;
        }

        .vertical-marquee-track {
            display: flex;
            flex-direction: column;
            animation: verticalScroll 40s linear infinite;
        }

        .vertical-marquee-track:hover {
            animation-play-state: paused;
        }

        /* Custom Scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #070709;
        }

        ::-webkit-scrollbar-thumb {
            background: #4c1d95;
            border-radius: 3px;
        }
    </style>
</head>

<body class="min-h-screen flex flex-col selection:bg-purple-500 selection:text-white overflow-x-hidden">

    <header class="sticky top-0 z-50 bg-[#070709]/95 backdrop-blur-md border-b border-purple-900/30 px-6 lg:px-8 py-3.5 flex justify-between items-center">
        <div class="flex items-center space-x-3">
            <div class="w-3.5 h-7 bg-gradient-to-b from-purple-400 to-purple-600 purple-glow rounded-full"></div>
            <span class="font-extrabold tracking-wider text-base lg:text-lg bg-gradient-to-r from-white via-purple-200 to-purple-400 bg-clip-text text-transparent">TRADE ROCKET TCG</span>
        </div>
        <div class="flex items-center space-x-4">
            <div class="hidden sm:flex items-center space-x-2 bg-purple-950/30 border border-purple-900/40 px-3.5 py-1.5 rounded-full text-xs">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <span class="text-zinc-300">Welcome, <strong class="text-white"><?php echo htmlspecialchars($username); ?></strong></span>
                <span class="text-purple-400 uppercase font-semibold text-[10px] bg-purple-900/50 px-2 py-0.5 rounded-md ml-1"><?php echo htmlspecialchars($role); ?></span>
            </div>
            <a href="logout.php" class="bg-zinc-900 hover:bg-red-950/40 border border-zinc-800 hover:border-red-600/50 text-xs font-semibold px-4 py-2 rounded-xl text-zinc-300 hover:text-red-300 transition-all flex items-center space-x-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                <span>Sign Out</span>
            </a>
        </div>
    </header>

    <div class="flex-1 w-full grid grid-cols-1 lg:grid-cols-12 gap-6 p-4 lg:p-6 items-start">

        <aside class="hidden lg:flex flex-col col-span-3 sticky top-20">
            <div class="flex items-center justify-between mb-2 px-1">
                <span class="text-[10px] font-bold uppercase tracking-widest text-purple-400/80">Card Atmosphere</span>
                <a href="slideshow.php" class="text-[10px] text-purple-400 hover:underline font-semibold">Fullscreen &rarr;</a>
            </div>

            <div class="vertical-marquee-container rounded-2xl bg-[#050507] border border-purple-950/40 p-2 overflow-hidden relative">
                <div class="absolute top-0 left-0 right-0 h-16 bg-gradient-to-b from-[#050507] to-transparent z-10 pointer-events-none"></div>
                <div class="absolute bottom-0 left-0 right-0 h-16 bg-gradient-to-t from-[#050507] to-transparent z-10 pointer-events-none"></div>

                <?php if (empty($slideCards)): ?>
                    <div class="h-full flex items-center justify-center text-center p-4">
                        <p class="text-xs text-zinc-600">No cards.</p>
                    </div>
                <?php else: ?>
                    <div class="vertical-marquee-track space-y-4">
                        <?php
                        // Duplicate array elements to form an endless vertical sequence seamlessly
                        $loopItems = array_merge($slideCards, $slideCards);
                        foreach ($loopItems as $card):
                            $cardImg = !empty($card['card_image']) ? $card['card_image'] : 'https://via.placeholder.com/250x350?text=No+Image';
                        ?>
                            <div class="group relative flex justify-center py-1">
                                <img src="<?php echo htmlspecialchars($cardImg); ?>"
                                    alt="<?php echo htmlspecialchars($card['card_name'] ?? 'Card Art'); ?>"
                                    class="h-56 w-auto object-contain rounded-xl shadow-xl transition-transform duration-300 group-hover:scale-105 opacity-90 group-hover:opacity-100"
                                    onerror="this.onerror=null; this.src='https://via.placeholder.com/250x350?text=No+Image';">
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <main class="flex flex-col col-span-1 lg:col-span-6 space-y-6">

            <div class="glass-card p-6 lg:p-8 relative overflow-hidden flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div class="absolute -right-10 -bottom-10 w-64 h-64 bg-purple-600/10 rounded-full blur-3xl pointer-events-none"></div>
                <div>
                    <span class="text-xs font-bold tracking-widest uppercase text-purple-400 bg-purple-950/60 px-3 py-1 rounded-full border border-purple-900/40 inline-block mb-3">Core Operations</span>
                    <h1 class="text-2xl lg:text-3xl font-extrabold tracking-tight text-white mb-2">Inventory Control Center</h1>
                    <p class="text-zinc-400 text-xs lg:text-sm">
                        <?php echo ($role === 'admin') ? 'Monitoring overall store assets and enterprise business metrics.' : 'Viewing your assigned bulk inventory assets and item performance.'; ?>
                    </p>
                </div>
                <a href="slideshow.php" class="bg-gradient-to-r from-purple-600 to-purple-800 hover:from-purple-500 hover:to-purple-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl shadow-lg shadow-purple-900/30 flex items-center space-x-2 shrink-0 transition-all">
                    <span>Fullscreen Slideshow</span> &rarr;
                </a>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="glass-card p-5">
                    <p class="text-[11px] uppercase tracking-wider text-purple-300 font-bold mb-1">Card Entries</p>
                    <h3 class="text-2xl lg:text-3xl font-extrabold text-white"><?php echo number_format($totalCards); ?></h3>
                </div>
                <div class="glass-card p-5">
                    <p class="text-[11px] uppercase tracking-wider text-purple-300 font-bold mb-1">Physical Stock</p>
                    <h3 class="text-2xl lg:text-3xl font-extrabold text-white"><?php echo number_format($totalStock); ?></h3>
                </div>
                <div class="glass-card p-5">
                    <p class="text-[11px] uppercase tracking-wider text-purple-300 font-bold mb-1"><?php echo ($role === 'admin') ? 'Sets Loaded' : 'Status'; ?></p>
                    <h3 class="text-2xl lg:text-3xl font-extrabold text-white"><?php echo is_numeric($totalSets) ? number_format($totalSets) : 'Active Partner'; ?></h3>
                </div>
            </div>

            <div class="glass-card p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-base font-bold text-white">Management Modules</h2>
                    <span class="text-[10px] font-semibold text-purple-300 bg-purple-950/50 border border-purple-900/40 px-2.5 py-1 rounded-lg">Core Tools</span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">

                    <a href="pos_module.php" class="nav-card p-4 rounded-xl flex flex-col justify-between group border-purple-700/40 bg-gradient-to-br from-purple-950/20 to-zinc-900">
                        <div class="flex items-center space-x-2.5 mb-2">
                            <div class="w-7 h-7 rounded-lg bg-purple-600/30 border border-purple-500/50 flex items-center justify-center text-purple-200 group-hover:scale-110 transition-transform">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </div>
                            <span class="font-bold text-xs text-purple-200 group-hover:text-white">POS Checkout</span>
                        </div>
                        <span class="text-[10px] text-zinc-400">Launch store register</span>
                    </a>

                    <a href="orders_process.php" class="nav-card p-4 rounded-xl flex flex-col justify-between group">
                        <div class="flex items-center space-x-2.5 mb-2">
                            <div class="w-7 h-7 rounded-lg bg-purple-900/40 border border-purple-700/40 flex items-center justify-center text-purple-300 group-hover:scale-110 transition-transform">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                </svg>
                            </div>
                            <span class="font-bold text-xs text-white group-hover:text-purple-300">Order Process</span>
                        </div>
                        <span class="text-[10px] text-zinc-400">Fulfillment tracking</span>
                    </a>

                    <a href="stocks.php" class="nav-card p-4 rounded-xl flex flex-col justify-between group">
                        <div class="flex items-center space-x-2.5 mb-2">
                            <div class="w-7 h-7 rounded-lg bg-purple-900/40 border border-purple-700/40 flex items-center justify-center text-purple-300 group-hover:scale-110 transition-transform">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                                </svg>
                            </div>
                            <span class="font-bold text-xs text-white group-hover:text-purple-300">Inventory</span>
                        </div>
                        <span class="text-[10px] text-zinc-400">Stock levels & prices</span>
                    </a>

                    <a href="sales.php" class="nav-card p-4 rounded-xl flex flex-col justify-between group">
                        <div class="flex items-center space-x-2.5 mb-2">
                            <div class="w-7 h-7 rounded-lg bg-purple-900/40 border border-purple-700/40 flex items-center justify-center text-purple-300 group-hover:scale-110 transition-transform">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <span class="font-bold text-xs text-white group-hover:text-purple-300">Sales Log</span>
                        </div>
                        <span class="text-[10px] text-zinc-400">Sold item records</span>
                    </a>

                    <a href="sales_costing.php" class="nav-card p-4 rounded-xl flex flex-col justify-between group">
                        <div class="flex items-center space-x-2.5 mb-2">
                            <div class="w-7 h-7 rounded-lg bg-purple-900/40 border border-purple-700/40 flex items-center justify-center text-purple-300 group-hover:scale-110 transition-transform">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                            </div>
                            <span class="font-bold text-xs text-white group-hover:text-purple-300">Costing</span>
                        </div>
                        <span class="text-[10px] text-zinc-400">Financial performance</span>
                    </a>

                    <a href="cards.php" class="nav-card p-4 rounded-xl flex flex-col justify-between group">
                        <div class="flex items-center space-x-2.5 mb-2">
                            <div class="w-7 h-7 rounded-lg bg-purple-900/40 border border-purple-700/40 flex items-center justify-center text-purple-300 group-hover:scale-110 transition-transform">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                            </div>
                            <span class="font-bold text-xs text-white group-hover:text-purple-300">Catalog</span>
                        </div>
                        <span class="text-[10px] text-zinc-400">Browse master database</span>
                    </a>

                </div>
            </div>

        </main>

        <aside class="glass-card p-5 flex flex-col col-span-3 sticky top-20 shadow-2xl">
            <div class="flex items-center justify-between mb-3 pb-2 border-b border-purple-900/40">
                <h3 class="text-xs font-bold uppercase tracking-wider text-purple-300 flex items-center space-x-1.5">
                    <svg class="w-3.5 h-3.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                    </svg>
                    <span>Analytics & Graphs</span>
                </h3>
            </div>

            <div class="flex items-center space-x-1 bg-[#121118] p-1 rounded-xl border border-purple-950 mb-4">
                <a href="?chart_filter=all" class="flex-1 text-center text-[10px] font-bold py-1.5 rounded-lg transition-all <?php echo $chartFilter === 'all' ? 'bg-purple-700 text-white shadow-md' : 'text-zinc-400 hover:text-white'; ?>">All Stock</a>
                <a href="?chart_filter=cosigned" class="flex-1 text-center text-[10px] font-bold py-1.5 rounded-lg transition-all <?php echo $chartFilter === 'cosigned' ? 'bg-purple-700 text-white shadow-md' : 'text-zinc-400 hover:text-white'; ?>">Co-signed</a>
            </div>

            <div class="space-y-4">
                <div class="bg-[#0e0d13] p-3.5 rounded-xl border border-purple-950">
                    <p class="text-[11px] font-bold text-white mb-2 flex justify-between items-center">
                        <span>Condition Distribution</span>
                        <span class="text-[9px] text-purple-400">Hover for Details</span>
                    </p>

                    <div class="space-y-3 mt-3">
                        <?php
                        $maxQty = 1;
                        foreach ($conditionStats as $stat) {
                            if ($stat['total_qty'] > $maxQty) $maxQty = $stat['total_qty'];
                        }

                        if (empty($conditionStats)):
                        ?>
                            <p class="text-[11px] text-zinc-500 text-center py-4">No condition metrics available.</p>
                        <?php else: ?>
                            <?php foreach ($conditionStats as $stat):
                                $percentage = round(($stat['total_qty'] / max(1, $totalStock)) * 100, 1);
                                $barWidth = max(8, round(($stat['total_qty'] / $maxQty) * 100));
                            ?>
                                <div class="group relative cursor-pointer">
                                    <div class="flex justify-between text-[11px] font-medium mb-1">
                                        <span class="text-purple-200 group-hover:text-purple-400 transition-colors"><?php echo htmlspecialchars($stat['card_condition']); ?></span>
                                        <span class="text-zinc-400"><?php echo number_format($stat['total_qty']); ?> units (<?php echo $percentage; ?>%)</span>
                                    </div>
                                    <div class="w-full bg-zinc-900 h-2.5 rounded-full overflow-hidden border border-purple-950">
                                        <div class="bg-gradient-to-r from-purple-600 to-fuchsia-400 h-full rounded-full transition-all duration-500 group-hover:brightness-125" style="width: <?php echo $barWidth; ?>%;"></div>
                                    </div>

                                    <div class="absolute left-0 bottom-full mb-2 hidden group-hover:flex flex-col bg-[#181622] border border-purple-500/50 p-2.5 rounded-xl shadow-2xl z-30 w-48 text-[11px] pointer-events-none">
                                        <p class="font-bold text-white mb-1"><?php echo htmlspecialchars($stat['card_condition']); ?></p>
                                        <p class="text-zinc-300">Total Stock: <strong class="text-purple-300"><?php echo number_format($stat['total_qty']); ?></strong></p>
                                        <p class="text-zinc-300">Asset Value: <strong class="text-emerald-400">₱<?php echo number_format($stat['total_value'], 2); ?></strong></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-[#0e0d13] p-3.5 rounded-xl border border-purple-950 flex flex-col justify-between">
                    <p class="text-[11px] font-bold text-white mb-2">Portfolio Valuation Ratio</p>
                    <div class="flex items-center space-x-3 my-2">
                        <div class="w-12 h-12 rounded-full border-4 border-purple-500 border-t-emerald-400 border-r-amber-400 flex items-center justify-center text-[10px] font-extrabold text-purple-200">
                            100%
                        </div>
                        <div class="flex-1 text-[11px] space-y-1">
                            <div class="flex justify-between text-zinc-400">
                                <span>Active Assets:</span>
                                <strong class="text-white"><?php echo number_format($totalStock); ?> pcs</strong>
                            </div>
                            <div class="flex justify-between text-zinc-400">
                                <span>Unique Entries:</span>
                                <strong class="text-purple-300"><?php echo number_format($totalCards); ?></strong>
                            </div>
                        </div>
                    </div>
                    <p class="text-[10px] text-zinc-500 mt-2 italic">Hover over condition bars above to inspect individual asset valuation breakdowns.</p>
                </div>
            </div>

        </aside>

    </div>

</body>

</html>