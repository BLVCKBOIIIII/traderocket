<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? 'partner';

// AJAX Handler for card drag-removal, clear page, placement, and physical shelf updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $stockId = intval($_POST['stock_id'] ?? 0);

    if ($_POST['action'] === 'clear_page_slots') {
        $stockIdsJson = $_POST['stock_ids'] ?? '[]';
        $stockIds = json_decode($stockIdsJson, true);

        if (is_array($stockIds) && !empty($stockIds)) {
            try {
                $cleanStockIds = array_map('intval', $stockIds);
                $inQuery = implode(',', $cleanStockIds);

                // 1. Retrieve full details of cards being cleared before updating
                $stmtFetch = $pdo->prepare("
                    SELECT i.*, c.name AS card_name, c.card_number, c.image_url, c.rarity, c.pokemon_type, s.name AS set_name 
                    FROM inventory_stocks i
                    LEFT JOIN cards c ON i.card_id = c.id
                    LEFT JOIN sets s ON c.set_id = s.id
                    WHERE i.id IN ($inQuery) " . ($role !== 'admin' ? "AND i.owner_id = ?" : "")
                );
                $paramsFetch = ($role !== 'admin') ? [$userId] : [];
                $stmtFetch->execute($paramsFetch);
                $clearedCards = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);

                // 2. Clear the binder slots in DB
                $stmt = $pdo->prepare("UPDATE inventory_stocks SET binder_slot = NULL WHERE id IN ($inQuery) " . ($role !== 'admin' ? "AND owner_id = ?" : ""));
                $params = ($role !== 'admin') ? [$userId] : [];
                $stmt->execute($params);

                echo json_encode([
                    'success' => true, 
                    'message' => 'Page cleared successfully',
                    'cleared_cards' => $clearedCards
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => true, 'message' => 'No cards on page to clear', 'cleared_cards' => []]);
        }
        exit;
    }

    if ($_POST['action'] === 'remove_from_binder') {
        try {
            $stmt = $pdo->prepare("UPDATE inventory_stocks SET binder_slot = NULL WHERE id = ? " . ($role !== 'admin' ? "AND owner_id = ?" : ""));
            $params = ($role !== 'admin') ? [$stockId, $userId] : [$stockId];
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Card removed from binder slot']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'assign_binder_slot') {
        $slotIndex = intval($_POST['slot_index'] ?? 0);
        try {
            $stmtPrevious = $pdo->prepare("
                SELECT i.*, c.name AS card_name, c.card_number, c.image_url, c.rarity, c.pokemon_type, s.name AS set_name 
                FROM inventory_stocks i
                LEFT JOIN cards c ON i.card_id = c.id
                LEFT JOIN sets s ON c.set_id = s.id
                WHERE i.binder_slot = ? " . ($role !== 'admin' ? "AND owner_id = ?" : "")
            );
            $paramsPrevious = ($role !== 'admin') ? [$slotIndex, $userId] : [$slotIndex];
            $stmtPrevious->execute($paramsPrevious);
            $previousCard = $stmtPrevious->fetch(PDO::FETCH_ASSOC);

            $stmtClear = $pdo->prepare("UPDATE inventory_stocks SET binder_slot = NULL WHERE binder_slot = ? " . ($role !== 'admin' ? "AND owner_id = ?" : ""));
            $stmtClear->execute($paramsPrevious);

            $stmt = $pdo->prepare("UPDATE inventory_stocks SET binder_slot = ? WHERE id = ? " . ($role !== 'admin' ? "AND owner_id = ?" : ""));
            $params = ($role !== 'admin') ? [$slotIndex, $stockId, $userId] : [$slotIndex, $stockId];
            $stmt->execute($params);

            $stmtCard = $pdo->prepare("
                SELECT i.*, c.name AS card_name, c.card_number, c.image_url, c.rarity, c.pokemon_type, s.name AS set_name 
                FROM inventory_stocks i
                LEFT JOIN cards c ON i.card_id = c.id
                LEFT JOIN sets s ON c.set_id = s.id
                WHERE i.id = ?
            ");
            $stmtCard->execute([$stockId]);
            $assignedCard = $stmtCard->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true, 
                'card' => $assignedCard,
                'swapped_card' => $previousCard ?: null
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'update_shelf') {
        $shelf = trim($_POST['shelf_location'] ?? '');
        try {
            $stmt = $pdo->prepare("UPDATE inventory_stocks SET shelf_location = ? WHERE id = ? " . ($role !== 'admin' ? "AND owner_id = ?" : ""));
            $params = ($role !== 'admin') ? [$shelf, $stockId, $userId] : [$shelf, $stockId];
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Shelf location updated']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Fetch cards assigned to the binder
$whereBinder = "WHERE i.binder_slot IS NOT NULL";
if ($role !== 'admin') {
    $whereBinder .= " AND i.owner_id = ?";
    $paramsBinder = [$userId];
} else {
    $paramsBinder = [];
}

$stmt = $pdo->prepare("
    SELECT i.*, c.name AS card_name, c.card_number, c.image_url, c.rarity, c.pokemon_type, s.name AS set_name 
    FROM inventory_stocks i
    LEFT JOIN cards c ON i.card_id = c.id
    LEFT JOIN sets s ON c.set_id = s.id
    $whereBinder
    ORDER BY i.binder_slot ASC
");
$stmt->execute($paramsBinder);
$binderCards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch unassigned cards and distribute across 4 Card Storage Boxes
$whereUnassigned = "WHERE i.binder_slot IS NULL";
if ($role !== 'admin') {
    $whereUnassigned .= " AND i.owner_id = ?";
    $paramsUnassigned = [$userId];
} else {
    $paramsUnassigned = [];
}

$stmtUnassigned = $pdo->prepare("
    SELECT i.*, c.name AS card_name, c.card_number, c.image_url, c.rarity, c.pokemon_type, s.name AS set_name 
    FROM inventory_stocks i
    LEFT JOIN cards c ON i.card_id = c.id
    LEFT JOIN sets s ON c.set_id = s.id
    $whereUnassigned
    ORDER BY i.id DESC
");
$stmtUnassigned->execute($paramsUnassigned);
$unassignedCards = $stmtUnassigned->fetchAll(PDO::FETCH_ASSOC);

$boxes = [
    'deck_box'   => [],
    'ultra_rare' => [],
    'holos'      => [],
    'standard'   => []
];

foreach ($unassignedCards as $card) {
    $rarity = strtolower($card['rarity'] ?? '');
    if (strpos($rarity, 'secret') !== false || strpos($rarity, 'ultra') !== false || strpos($rarity, 'hyper') !== false || strpos($rarity, 'promo') !== false) {
        $boxes['ultra_rare'][] = $card;
    } elseif (strpos($rarity, 'holo') !== false || strpos($rarity, 'rare') !== false) {
        $boxes['holos'][] = $card;
    } elseif (count($boxes['deck_box']) < 15) {
        $boxes['deck_box'][] = $card;
    } else {
        $boxes['standard'][] = $card;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Binder & Card Storage Box System | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <style>
        .binder-wrapper {
            background: #181528;
            border-radius: 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }
        .binder-spread {
            display: grid;
            gap: 2.5rem;
            position: relative;
            background: #120f1d;
            border-radius: 1.25rem;
            padding: 2.5rem;
            border: 2px solid rgba(147, 51, 234, 0.3);
            transition: all 0.3s ease;
        }
        .binder-spread.two-page-view {
            grid-template-columns: 1fr 1fr;
        }
        .binder-spread.single-page-view {
            grid-template-columns: 1fr;
            max-width: 680px;
            margin: 0 auto;
        }
        .binder-spread.two-page-view::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 6px;
            background: linear-gradient(to bottom, rgba(168, 85, 247, 0.1), rgba(168, 85, 247, 0.4), rgba(168, 85, 247, 0.1));
            transform: translateX(-50%);
            box-shadow: 0 0 12px rgba(0,0,0,0.8);
        }
        .binder-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.25rem;
        }
        .binder-slot {
            aspect-ratio: 3/4;
            background: rgba(10, 8, 16, 0.7);
            border: 1.5px dashed rgba(147, 51, 234, 0.3);
            border-radius: 0.75rem;
            position: relative;
            transition: border-color 0.15s ease, background-color 0.15s ease;
            overflow: hidden;
            min-height: 220px;
            contain: layout paint style;
        }
        .binder-slot.drag-over {
            border-color: #a855f7;
            background: rgba(168, 85, 247, 0.15);
        }
        .card-item {
            cursor: grab;
            user-select: none;
            width: 100%;
            height: 100%;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .card-item:active {
            cursor: grabbing;
        }

        .card-box-container {
            background: linear-gradient(180deg, #161129 0%, #090712 100%);
            border: 3px solid #332059;
            border-top: 6px solid #583399;
            box-shadow: inset 0 12px 24px rgba(0,0,0,0.85);
            border-radius: 1rem;
            scrollbar-width: none;
            -ms-overflow-style: none;
            contain: layout paint;
        }
        
        .card-box-container::-webkit-scrollbar {
            display: none;
        }

        .stacked-cards-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem 0 3rem 0;
        }

        .box-card-stacked {
            width: 100%;
            max-width: 270px;
            margin-top: -270px;
            cursor: grab;
            position: relative;
            border-radius: 1rem;
            overflow: hidden;
            background: #090712;
            box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.6);
            contain: layout paint style;
            will-change: transform;
        }

        .box-card-stacked.first-visible,
        .box-card-stacked:first-child {
            margin-top: 0 !important;
        }

        .box-card-stacked:active {
            cursor: grabbing;
        }

        .seamless-card-img {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 1rem;
            object-fit: cover;
            background-color: #090712;
        }

        .flying-swap-card {
            position: fixed;
            pointer-events: none;
            z-index: 9999;
            will-change: transform, opacity;
            transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1), opacity 0.35s ease-out;
            box-shadow: 0 12px 20px rgba(0, 0, 0, 0.5);
            border-radius: 1rem;
            overflow: hidden;
        }

        .is-hidden {
            display: none !important;
        }

        .search-card-draggable {
            cursor: grab;
            user-select: none;
            transition: transform 0.15s ease, border-color 0.15s ease;
        }
        .search-card-draggable:hover {
            border-color: #a855f7;
            transform: translateY(-2px);
        }
        .search-card-draggable:active {
            cursor: grabbing;
        }
    </style>
</head>
<body class="bg-[#0b0b0e] text-zinc-100 min-h-screen flex flex-col">

    <?php include 'includes/header.php'; ?>

    <div class="flex-1 max-w-[2100px] w-full mx-auto p-4 lg:p-8 flex flex-col lg:flex-row gap-8">

        <!-- Left Sidebar: Search -->
        <aside class="w-full lg:w-96 tr-sidebar p-6 flex flex-col space-y-6 h-fit">
            <div class="space-y-3 border-b border-purple-900/40 pb-6">
                <div>
                    <h3 class="text-sm font-bold text-purple-200 uppercase tracking-wider">Search Inventory Boxes</h3>
                    <p class="text-xs text-purple-300/60">Filter cards across all 4 store boxes.</p>
                </div>
                <div class="relative">
                    <input type="text" id="boxSearchInput" oninput="debouncedFilterCards()" placeholder="Filter current box cards..." class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl pl-9 pr-3 py-2.5 text-xs text-white focus:outline-none focus:border-purple-500">
                    <span class="absolute left-3 top-3 text-xs text-purple-400">🔍</span>
                </div>
            </div>

            <div class="space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-purple-200 uppercase tracking-wider">Global Catalog Search</h3>
                    <p class="text-xs text-purple-300/60">Search and drag cards directly into binder slots.</p>
                </div>

                <div class="flex bg-[#0a0810] p-1.5 rounded-xl border border-purple-900/45 text-xs">
                    <button type="button" id="sideModeNameBtn" onclick="setSideSearchMode('name')" class="flex-1 font-bold py-1.5 rounded-lg mode-btn-active text-white">Name / No.</button>
                    <button type="button" id="sideModeIllBtn" onclick="setSideSearchMode('illustrator')" class="flex-1 font-bold py-1.5 rounded-lg text-purple-300">Illustrator</button>
                </div>

                <div class="flex gap-2">
                    <input type="text" id="sideSearchInput" placeholder="e.g. Charizard, 001/100..." class="flex-1 bg-[#0a0810] border border-purple-900/40 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none" onkeydown="if(event.key === 'Enter') executeSideSearch()">
                    <button type="button" onclick="executeSideSearch()" class="purple-btn text-white text-xs font-bold px-4 py-2.5 rounded-xl">Search</button>
                </div>

                <div id="sideSearchResults" class="space-y-3 max-h-[450px] overflow-y-auto pr-1">
                    <div class="py-8 text-center text-xs text-purple-300/50 border border-dashed border-purple-900/30 rounded-xl">
                        Global search results will appear here.
                    </div>
                </div>
            </div>
        </aside>

        <!-- Center: Store Binder View -->
        <main class="flex-1 flex flex-col items-center min-w-0">
            
            <div class="w-full flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-5">
                <div>
                    <h1 class="text-3xl font-bold text-white">Store Card Binder</h1>
                    <p class="text-xs lg:text-sm text-purple-300/60">Pull cards from storage box or search panel and drop them into binder slots.</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <!-- Page View Mode Switcher -->
                    <div class="flex bg-[#0a0810] p-1 rounded-xl border border-purple-900/40 text-xs font-bold">
                        <button type="button" id="viewMode1Btn" onclick="togglePageViewMode(1)" class="px-3 py-1.5 rounded-lg text-purple-300 hover:text-white transition-all">1 Page</button>
                        <button type="button" id="viewMode2Btn" onclick="togglePageViewMode(2)" class="px-3 py-1.5 rounded-lg bg-purple-800 text-white transition-all">2 Pages</button>
                    </div>

                    <!-- Pagination Controls -->
                    <div class="flex items-center space-x-2 bg-purple-950/40 p-1.5 rounded-xl border border-purple-900/40">
                        <button type="button" onclick="changePage(-1)" class="px-3 py-1.5 bg-purple-900/50 hover:bg-purple-800 text-xs font-bold rounded-lg transition-all text-purple-200">
                            &larr; Prev
                        </button>
                        <span id="pageIndicator" class="text-xs font-bold px-2 text-purple-300">Pages 1 - 2</span>
                        <button type="button" onclick="changePage(1)" class="purple-btn px-4 py-1.5 text-xs font-bold rounded-lg transition-all text-white shadow-lg">
                            Next &rarr;
                        </button>
                    </div>
                </div>
            </div>

            <div id="dropRemoveZone" class="binder-wrapper w-full p-6 lg:p-8">
                <div id="binderSpread" class="binder-spread two-page-view">
                    
                    <!-- Left Page -->
                    <div class="flex flex-col" id="leftPageContainer">
                        <div class="flex justify-between items-center mb-3">
                            <span class="text-xs uppercase font-bold text-purple-300/70" id="leftPageNum">Page 1</span>
                            <button type="button" onclick="clearPage('left')" class="text-[11px] font-bold text-rose-300/80 hover:text-rose-200 bg-rose-950/40 hover:bg-rose-900/60 border border-rose-900/40 px-2.5 py-1 rounded-lg transition-all flex items-center gap-1 cursor-pointer">
                                🗑️ Clear Page
                            </button>
                        </div>
                        <div id="leftPageGrid" class="binder-grid"></div>
                    </div>

                    <!-- Right Page -->
                    <div class="flex flex-col" id="rightPageContainer">
                        <div class="flex justify-between items-center mb-3">
                            <span class="text-xs uppercase font-bold text-purple-300/70" id="rightPageNum">Page 2</span>
                            <button type="button" onclick="clearPage('right')" class="text-[11px] font-bold text-rose-300/80 hover:text-rose-200 bg-rose-950/40 hover:bg-rose-900/60 border border-rose-900/40 px-2.5 py-1 rounded-lg transition-all flex items-center gap-1 cursor-pointer">
                                🗑️ Clear Page
                            </button>
                        </div>
                        <div id="rightPageGrid" class="binder-grid"></div>
                    </div>

                </div>
            </div>
        </main>

        <!-- Right Side: 4 Card Storage Boxes -->
        <aside class="w-full lg:w-[440px] tr-sidebar p-6 flex flex-col space-y-5 h-[900px]">
            <div>
                <h3 class="text-sm font-bold text-purple-200 uppercase tracking-wider mb-3">Card Storage Vault</h3>
                
                <div class="grid grid-cols-2 gap-2 bg-[#0a0810] p-2 rounded-xl border border-purple-900/40 text-xs">
                    <button onclick="switchBox('deck_box')" id="btn-deck_box" class="box-tab font-bold py-2 px-3 rounded-lg text-white bg-purple-800/80 border border-purple-500/50">
                        📦 Box 1 (<span id="count-deck_box"><?php echo count($boxes['deck_box']); ?></span>)
                    </button>
                    <button onclick="switchBox('ultra_rare')" id="btn-ultra_rare" class="box-tab font-bold py-2 px-3 rounded-lg text-purple-300 hover:text-white">
                        ⭐ Box 2 (<span id="count-ultra_rare"><?php echo count($boxes['ultra_rare']); ?></span>)
                    </button>
                    <button onclick="switchBox('holos')" id="btn-holos" class="box-tab font-bold py-2 px-3 rounded-lg text-purple-300 hover:text-white">
                        ✨ Box 3 (<span id="count-holos"><?php echo count($boxes['holos']); ?></span>)
                    </button>
                    <button onclick="switchBox('standard')" id="btn-standard" class="box-tab font-bold py-2 px-3 rounded-lg text-purple-300 hover:text-white">
                        💧 Box 4 (<span id="count-standard"><?php echo count($boxes['standard']); ?></span>)
                    </button>
                </div>
            </div>

            <div id="cardBoxContainer" class="card-box-container flex-1 overflow-y-auto px-2">
                <?php foreach ($boxes as $boxKey => $boxCards): ?>
                    <div id="box-container-<?php echo $boxKey; ?>" class="box-content-group stacked-cards-wrapper <?php echo $boxKey !== 'deck_box' ? 'hidden' : ''; ?>">
                        <?php if (empty($boxCards)): ?>
                            <div class="empty-msg py-32 text-center text-xs text-purple-300/40 border border-dashed border-purple-900/30 rounded-xl my-auto w-full">
                                Storage box is empty.
                            </div>
                        <?php else: ?>
                            <?php foreach ($boxCards as $idx => $c): ?>
                                <div class="box-card-item box-card-stacked <?php echo $idx === 0 ? 'first-visible' : ''; ?>"
                                     id="stock-card-<?php echo $c['id']; ?>"
                                     data-stock-id="<?php echo $c['id']; ?>"
                                     data-card-name="<?php echo htmlspecialchars(strtolower($c['card_name'] ?? '')); ?>"
                                     style="z-index: <?php echo $idx + 1; ?>;"
                                     draggable="true" 
                                     ondragstart="handleDragStart(event, <?php echo $c['id']; ?>)">
                                    
                                    <img src="<?php echo htmlspecialchars($c['image_url'] ?: 'https://images.placeholders.dev/?width=300&height=420&text=TCG'); ?>" 
                                         alt="<?php echo htmlspecialchars($c['card_name'] ?? ''); ?>"
                                         loading="lazy"
                                         decoding="async"
                                         class="w-full aspect-[3/4] seamless-card-img pointer-events-none select-none">
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>

    </div>

    <!-- Shelf Modal -->
    <div id="shelfModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm hidden">
        <div class="tr-sidebar max-w-sm w-full p-6 border border-purple-900/50 rounded-2xl bg-[#120f1d] shadow-2xl">
            <h3 class="text-base font-bold text-white mb-2">Assign Physical Shelf Location</h3>
            <p class="text-xs text-purple-300/70 mb-5">Specify where this item is physically located in your store shelf.</p>
            
            <input type="hidden" id="modalStockId">
            <div class="mb-5">
                <label class="block text-xs uppercase font-bold text-purple-200/80 mb-2">Shelf / Section Name</label>
                <input type="text" id="modalShelfInput" placeholder="e.g. Shelf A-3, Cabinet 2" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none">
            </div>

            <div class="flex gap-2 justify-end">
                <button type="button" onclick="closeShelfModal()" class="px-4 py-2 text-xs text-purple-300 bg-purple-950/40 rounded-lg">Cancel</button>
                <button type="button" onclick="saveShelfLocation()" class="purple-btn px-5 py-2 text-xs text-white font-bold rounded-lg">Save Location</button>
            </div>
        </div>
    </div>

    <script>
        let binderData = <?php echo json_encode($binderCards); ?>;
        let currentPage = 1; 
        let viewModePages = 2; // Default 2 pages
        const SLOTS_PER_PAGE = 9;
        let activeBox = 'deck_box';
        let sideSearchMode = 'name';
        let filterTimeout = null;

        function togglePageViewMode(mode) {
            viewModePages = mode;
            const binderSpread = document.getElementById('binderSpread');
            const rightPageContainer = document.getElementById('rightPageContainer');
            const btn1 = document.getElementById('viewMode1Btn');
            const btn2 = document.getElementById('viewMode2Btn');

            if (mode === 1) {
                binderSpread.classList.remove('two-page-view');
                binderSpread.classList.add('single-page-view');
                rightPageContainer.classList.add('hidden');
                
                btn1.className = 'px-3 py-1.5 rounded-lg bg-purple-800 text-white transition-all';
                btn2.className = 'px-3 py-1.5 rounded-lg text-purple-300 hover:text-white transition-all';
            } else {
                binderSpread.classList.remove('single-page-view');
                binderSpread.classList.add('two-page-view');
                rightPageContainer.classList.remove('hidden');

                btn2.className = 'px-3 py-1.5 rounded-lg bg-purple-800 text-white transition-all';
                btn1.className = 'px-3 py-1.5 rounded-lg text-purple-300 hover:text-white transition-all';
            }
            renderBinderPages();
        }

        function renderBinderPages() {
            const leftGrid = document.getElementById('leftPageGrid');
            const rightGrid = document.getElementById('rightPageGrid');
            const pageIndicator = document.getElementById('pageIndicator');
            
            leftGrid.innerHTML = '';
            rightGrid.innerHTML = '';

            const leftPageNum = currentPage;
            document.getElementById('leftPageNum').innerText = `Page ${leftPageNum}`;

            const leftStartIndex = (leftPageNum - 1) * SLOTS_PER_PAGE;
            renderPageSlots(leftGrid, leftStartIndex);

            if (viewModePages === 2) {
                const rightPageNum = currentPage + 1;
                document.getElementById('rightPageNum').innerText = `Page ${rightPageNum}`;
                pageIndicator.innerText = `Pages ${leftPageNum} - ${rightPageNum}`;

                const rightStartIndex = (rightPageNum - 1) * SLOTS_PER_PAGE;
                renderPageSlots(rightGrid, rightStartIndex);
            } else {
                pageIndicator.innerText = `Page ${leftPageNum}`;
            }
        }

        function renderPageSlots(gridElement, startIndex) {
            const fragment = document.createDocumentFragment();
            for (let i = 0; i < SLOTS_PER_PAGE; i++) {
                const slotIndex = startIndex + i;
                const card = binderData.find(c => parseInt(c.binder_slot) === slotIndex);

                const slot = document.createElement('div');
                slot.className = 'binder-slot flex items-center justify-center relative group';
                slot.dataset.slotIndex = slotIndex;

                slot.addEventListener('dragover', (e) => { e.preventDefault(); slot.classList.add('drag-over'); });
                slot.addEventListener('dragleave', () => slot.classList.remove('drag-over'));
                slot.addEventListener('drop', (e) => handleDrop(e, slotIndex));

                if (card) {
                    slot.innerHTML = `
                        <div id="binder-card-${card.id}" class="card-item relative w-full h-full overflow-hidden" draggable="true" ondragstart="handleDragStart(event, ${card.id})">
                            <img src="${card.image_url || 'https://images.placeholders.dev/?width=300&height=420&text=TCG'}" class="w-full h-full object-contain seamless-card-img select-none" loading="lazy" decoding="async">
                            <button onclick="openShelfModal(${card.id}, '${card.shelf_location || ''}')" class="absolute bottom-1.5 right-1.5 bg-black/80 hover:bg-purple-900 text-[10px] text-purple-200 px-2 py-1 rounded-md backdrop-blur opacity-0 group-hover:opacity-100 transition-opacity z-10 font-bold">
                                🏷️ Shelf
                            </button>
                        </div>
                    `;
                }
                fragment.appendChild(slot);
            }
            gridElement.appendChild(fragment);
        }

        function changePage(direction) {
            const step = viewModePages;
            currentPage += (direction * step);
            if (currentPage < 1) currentPage = 1;
            renderBinderPages();
        }

        async function clearPage(side) {
            const pageNum = side === 'left' ? currentPage : currentPage + 1;
            const startIndex = (pageNum - 1) * SLOTS_PER_PAGE;
            const endIndex = startIndex + SLOTS_PER_PAGE;

            // Collect stock IDs directly from cards assigned to slots on this page
            const targetCards = binderData.filter(c => {
                const slot = parseInt(c.binder_slot);
                return slot >= startIndex && slot < endIndex;
            });

            if (targetCards.length === 0) return;

            const stockIdsToClear = targetCards.map(c => parseInt(c.id));

            const fd = new FormData();
            fd.append('action', 'clear_page_slots');
            fd.append('stock_ids', JSON.stringify(stockIdsToClear));

            try {
                const res = await fetch('binder.php', { method: 'POST', body: fd });
                const result = await res.json();

                if (result.success && Array.isArray(result.cleared_cards)) {
                    result.cleared_cards.forEach(card => {
                        binderData = binderData.filter(c => parseInt(c.id) !== parseInt(card.id));
                        addCardToBoxDOM(card);
                    });
                    renderBinderPages();
                }
            } catch (err) {
                console.error("Failed to clear page slots:", err);
            }
        }

        function switchBox(boxKey) {
            activeBox = boxKey;
            document.querySelectorAll('.box-content-group').forEach(el => el.classList.add('hidden'));
            const targetContainer = document.getElementById(`box-container-${boxKey}`);
            if (targetContainer) targetContainer.classList.remove('hidden');

            document.querySelectorAll('.box-tab').forEach(btn => {
                btn.className = 'box-tab font-bold py-2 px-3 rounded-lg text-purple-300 hover:text-white';
            });
            const activeBtn = document.getElementById(`btn-${boxKey}`);
            if (activeBtn) activeBtn.className = 'box-tab font-bold py-2 px-3 rounded-lg text-white bg-purple-800/80 border border-purple-500/50';
            
            filterBoxCards();
        }

        function debouncedFilterCards() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(filterBoxCards, 100);
        }

        function filterBoxCards() {
            const query = document.getElementById('boxSearchInput').value.toLowerCase().trim();
            const activeContainer = document.getElementById(`box-container-${activeBox}`);
            if (!activeContainer) return;

            const cards = activeContainer.querySelectorAll('.box-card-item');
            let visibleCount = 0;
            
            cards.forEach(card => {
                const name = card.dataset.cardName || '';
                if (name.includes(query)) {
                    card.classList.remove('is-hidden');
                    if (visibleCount === 0) {
                        card.classList.add('first-visible');
                    } else {
                        card.classList.remove('first-visible');
                    }
                    visibleCount++;
                } else {
                    card.classList.add('is-hidden');
                    card.classList.remove('first-visible');
                }
            });
        }

        let draggedStockId = null;
        function handleDragStart(e, stockId) {
            draggedStockId = stockId;
            e.dataTransfer.setData('text/plain', stockId);
        }

        async function handleDrop(e, slotIndex) {
            e.preventDefault();
            const slotElem = e.currentTarget;
            slotElem.classList.remove('drag-over');

            if (draggedStockId) {
                const existingCardElem = slotElem.querySelector('.card-item');
                let startRect = existingCardElem ? existingCardElem.getBoundingClientRect() : null;

                const fd = new FormData();
                fd.append('action', 'assign_binder_slot');
                fd.append('stock_id', draggedStockId);
                fd.append('slot_index', slotIndex);

                const res = await fetch('binder.php', { method: 'POST', body: fd });
                const result = await res.json();
                
                if (result.success && result.card) {
                    binderData = binderData.filter(c => parseInt(c.id) !== parseInt(draggedStockId));
                    binderData.push(result.card);

                    const stackCard = document.getElementById(`stock-card-${draggedStockId}`);
                    if (stackCard) {
                        stackCard.remove();
                        updateBoxCount(activeBox, -1);
                    }

                    if (result.swapped_card) {
                        binderData = binderData.filter(c => parseInt(c.id) !== parseInt(result.swapped_card.id));
                        if (startRect) {
                            await animateCardSlideToBox(startRect, result.swapped_card);
                        } else {
                            addCardToBoxDOM(result.swapped_card);
                        }
                    }

                    renderBinderPages();
                    filterBoxCards();
                }
            }
        }

        function animateCardSlideToBox(startRect, swappedCard) {
            return new Promise((resolve) => {
                const targetContainer = document.getElementById('cardBoxContainer');
                const targetRect = targetContainer.getBoundingClientRect();

                const clone = document.createElement('div');
                clone.className = 'flying-swap-card';
                clone.style.top = `${startRect.top}px`;
                clone.style.left = `${startRect.left}px`;
                clone.style.width = `${startRect.width}px`;
                clone.style.height = `${startRect.height}px`;

                clone.innerHTML = `<img src="${swappedCard.image_url || 'https://images.placeholders.dev/?width=300&height=420&text=TCG'}" class="w-full h-full object-contain seamless-card-img">`;
                document.body.appendChild(clone);

                requestAnimationFrame(() => {
                    const deltaX = (targetRect.left + targetRect.width / 2) - (startRect.left + startRect.width / 2);
                    const deltaY = (targetRect.top + targetRect.height / 3) - (startRect.top + startRect.height / 2);

                    clone.style.transform = `translate3d(${deltaX}px, ${deltaY}px, 0) scale(0.35) rotate(10deg)`;
                    clone.style.opacity = '0.2';
                });

                setTimeout(() => {
                    clone.remove();
                    addCardToBoxDOM(swappedCard);
                    resolve();
                }, 400);
            });
        }

        function addCardToBoxDOM(card) {
            if (!card || !card.id) return;

            let boxKey = 'standard';
            const rarity = (card.rarity || '').toLowerCase();
            if (rarity.includes('secret') || rarity.includes('ultra') || rarity.includes('hyper') || rarity.includes('promo')) {
                boxKey = 'ultra_rare';
            } else if (rarity.includes('holo') || rarity.includes('rare')) {
                boxKey = 'holos';
            }

            if (activeBox !== boxKey) {
                switchBox(boxKey);
            }

            const boxContainer = document.getElementById(`box-container-${boxKey}`);
            if (boxContainer) {
                const emptyMsg = boxContainer.querySelector('.empty-msg');
                if (emptyMsg) emptyMsg.remove();

                const existing = document.getElementById(`stock-card-${card.id}`);
                if (existing) existing.remove();

                const newCardDiv = document.createElement('div');
                newCardDiv.className = 'box-card-item box-card-stacked';
                newCardDiv.id = `stock-card-${card.id}`;
                newCardDiv.dataset.stockId = card.id;
                newCardDiv.dataset.cardName = (card.card_name || '').toLowerCase();
                newCardDiv.setAttribute('draggable', 'true');
                newCardDiv.setAttribute('ondragstart', `handleDragStart(event, ${card.id})`);

                const imgUrl = card.image_url || 'https://images.placeholders.dev/?width=300&height=420&text=TCG';
                const cardName = card.card_name || 'TCG Card';

                newCardDiv.innerHTML = `
                    <img src="${imgUrl}" 
                         alt="${cardName}"
                         loading="lazy"
                         decoding="async"
                         class="w-full aspect-[3/4] seamless-card-img pointer-events-none select-none">
                `;

                boxContainer.prepend(newCardDiv);
                updateBoxCount(boxKey, 1);
                filterBoxCards();
            }
        }

        function updateBoxCount(boxKey, delta) {
            const countSpan = document.getElementById(`count-${boxKey}`);
            if (countSpan) {
                let current = parseInt(countSpan.innerText) || 0;
                countSpan.innerText = Math.max(0, current + delta);
            }
        }

        document.body.addEventListener('dragover', (e) => e.preventDefault());
        document.body.addEventListener('drop', async (e) => {
            if (!e.target.closest('#binderSpread') && draggedStockId) {
                const cardIndex = binderData.findIndex(c => parseInt(c.id) === parseInt(draggedStockId));
                if (cardIndex !== -1) {
                    const fd = new FormData();
                    fd.append('action', 'remove_from_binder');
                    fd.append('stock_id', draggedStockId);
                    
                    const res = await fetch('binder.php', { method: 'POST', body: fd });
                    const result = await res.json();
                    if (result.success) {
                        const removedCard = binderData[cardIndex];
                        binderData.splice(cardIndex, 1);
                        renderBinderPages();
                        addCardToBoxDOM(removedCard);
                    }
                }
            }
        });

        function openShelfModal(stockId, currentShelf) {
            document.getElementById('modalStockId').value = stockId;
            document.getElementById('modalShelfInput').value = currentShelf;
            document.getElementById('shelfModal').classList.remove('hidden');
        }
        function closeShelfModal() {
            document.getElementById('shelfModal').classList.hidden = true;
            document.getElementById('shelfModal').classList.add('hidden');
        }
        async function saveShelfLocation() {
            const stockId = document.getElementById('modalStockId').value;
            const shelf = document.getElementById('modalShelfInput').value;

            const fd = new FormData();
            fd.append('action', 'update_shelf');
            fd.append('stock_id', stockId);
            fd.append('shelf_location', shelf);

            const res = await fetch('binder.php', { method: 'POST', body: fd });
            const result = await res.json();
            if (result.success) {
                const item = binderData.find(c => parseInt(c.id) === parseInt(stockId));
                if (item) item.shelf_location = shelf;
                closeShelfModal();
                renderBinderPages();
            }
        }

        function setSideSearchMode(mode) {
            sideSearchMode = mode;
            document.getElementById('sideModeNameBtn').className = mode === 'name' ? 'flex-1 font-bold py-1.5 rounded-lg mode-btn-active text-white' : 'flex-1 font-bold py-1.5 rounded-lg text-purple-300';
            document.getElementById('sideModeIllBtn').className = mode === 'illustrator' ? 'flex-1 font-bold py-1.5 rounded-lg mode-btn-active text-white' : 'flex-1 font-bold py-1.5 rounded-lg text-purple-300';
        }

        async function executeSideSearch() {
            const q = document.getElementById('sideSearchInput').value.trim();
            const container = document.getElementById('sideSearchResults');
            if (!q) return;

            container.innerHTML = '<div class="py-4 text-center text-xs text-purple-300 animate-pulse">Searching catalog...</div>';

            try {
                const res = await fetch(`api/card_image_proxy.php?query=${encodeURIComponent(q)}&lang=en&mode=${sideSearchMode}`);
                const data = await res.json();

                if (data.success && data.cards.length > 0) {
                    container.innerHTML = '';
                    const fragment = document.createDocumentFragment();
                    data.cards.slice(0, 10).forEach(card => {
                        const div = document.createElement('div');
                        const cardId = card.stock_id || card.id;
                        const imgUrl = card.image || card.image_url || 'https://images.placeholders.dev/?width=300&height=420&text=TCG';
                        
                        div.className = 'search-card-draggable bg-[#090712] p-2.5 flex items-center gap-3 border border-purple-900/40 rounded-xl hover:border-purple-500/50 transition-all';
                        div.setAttribute('draggable', 'true');
                        div.setAttribute('ondragstart', `handleDragStart(event, ${cardId})`);
                        div.id = `search-card-${cardId}`;

                        div.innerHTML = `
                            <div class="w-12 h-16 rounded-lg overflow-hidden flex-shrink-0 bg-[#090712] border border-purple-900/30">
                                <img src="${imgUrl}" 
                                     alt="${card.name || 'TCG Card'}"
                                     loading="lazy" 
                                     decoding="async" 
                                     class="w-full h-full object-cover seamless-card-img pointer-events-none select-none">
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-bold text-white truncate">${card.name}</div>
                                <div class="text-[10px] text-purple-300/60 font-medium">${card.set?.name || 'Set'}</div>
                                <div class="text-[9px] text-purple-400/80 mt-1 uppercase font-bold">⋮ Drag to binder</div>
                            </div>
                        `;
                        fragment.appendChild(div);
                    });
                    container.appendChild(fragment);
                } else {
                    container.innerHTML = '<div class="py-4 text-center text-xs text-purple-300/60">No results found.</div>';
                }
            } catch (e) {
                container.innerHTML = '<div class="py-4 text-center text-xs text-rose-400">Search error.</div>';
            }
        }

        renderBinderPages();
    </script>
</body>
</html>