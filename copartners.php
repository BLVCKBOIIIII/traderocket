<?php
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Strict Authentication & Role Check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? 'partner';

// Only allow admins and partners to access this file
if ($role !== 'admin' && $role !== 'partner') {
    header("Location: login.php");
    exit();
}

$message = '';
$error = '';

// Handle Stock Updates or Deletions via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update') {
            $stockId = intval($_POST['stock_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 0);
            $buyPrice = floatval($_POST['buy_price'] ?? 0);
            $sellPrice = floatval($_POST['sell_price'] ?? 0);
            $conditionStatus = trim($_POST['condition_status'] ?? 'Near Mint');
            $cardCategory = trim($_POST['card_category'] ?? '');
            $cardCategoryValue = ($cardCategory !== '') ? $cardCategory : null;

            // Optional: If partners can edit any cosigned card, you can remove the strict owner_id check here, 
            // or keep it if they can only update items they own. For store-wide cosigned pool, we allow updating cosigned items.
            $updateStmt = $pdo->prepare("
                UPDATE inventory_stocks 
                SET quantity = ?, buy_price = ?, sell_price = ?, price = ?, condition_status = ?, card_category = ?
                WHERE id = ? AND (is_cosigned = 1 OR is_cosigned IS NULL)
            ");
            $updateStmt->execute([$quantity, $buyPrice, $sellPrice, $sellPrice, $conditionStatus, $cardCategoryValue, $stockId]);
            $message = "Cosigned stock updated successfully!";
            
        } elseif ($_POST['action'] === 'delete') {
            $stockId = intval($_POST['stock_id'] ?? 0);
            $delStmt = $pdo->prepare("DELETE FROM inventory_stocks WHERE id = ? AND (is_cosigned = 1 OR is_cosigned IS NULL)");
            $delStmt->execute([$stockId]);
            $message = "Cosigned item removed successfully!";
        }
    }
}

$searchQuery = trim($_GET['search'] ?? '');
$conditionFilter = trim($_GET['condition_status'] ?? '');
$categoryFilter = trim($_GET['card_category'] ?? '');

// Base query targeting all cosigned records store-wide
$commonQuery = "
    FROM inventory_stocks i
    LEFT JOIN cards c ON i.card_id = c.id
    LEFT JOIN sets s ON c.set_id = s.id
    LEFT JOIN users u ON i.owner_id = u.id
    WHERE (i.is_cosigned = 1 OR i.is_cosigned IS NULL)
";

try {
    // 1. Fetch Active Cosigned Stocks (quantity > 0)
    $activeQuery = "SELECT i.*, c.name AS card_name, c.card_number, c.image_url, s.name AS set_name, u.username AS owner_name " . $commonQuery . " AND i.quantity > 0";
    $activeParams = [];

    if (!empty($searchQuery)) {
        $activeQuery .= " AND (c.name LIKE ? OR c.card_number LIKE ? OR s.name LIKE ?)";
        $activeParams[] = "%$searchQuery%";
        $activeParams[] = "%$searchQuery%";
        $activeParams[] = "%$searchQuery%";
    }
    if (!empty($conditionFilter)) {
        $activeQuery .= " AND i.condition_status = ?";
        $activeParams[] = $conditionFilter;
    }
    if (!empty($categoryFilter)) {
        $activeQuery .= " AND i.card_category = ?";
        $activeParams[] = $categoryFilter;
    }
    $activeQuery .= " ORDER BY i.id DESC";

    $stmtActive = $pdo->prepare($activeQuery);
    $stmtActive->execute($activeParams);
    $activeStocks = $stmtActive->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Sold Cosigned Stocks (quantity = 0)
    $soldQuery = "SELECT i.*, c.name AS card_name, c.card_number, c.image_url, s.name AS set_name, u.username AS owner_name " . $commonQuery . " AND i.quantity = 0";
    $soldParams = [];

    if (!empty($searchQuery)) {
        $soldQuery .= " AND (c.name LIKE ? OR c.card_number LIKE ? OR s.name LIKE ?)";
        $soldParams[] = "%$searchQuery%";
        $soldParams[] = "%$searchQuery%";
        $soldParams[] = "%$searchQuery%";
    }
    if (!empty($conditionFilter)) {
        $soldQuery .= " AND i.condition_status = ?";
        $soldParams[] = $conditionFilter;
    }
    if (!empty($categoryFilter)) {
        $soldQuery .= " AND i.card_category = ?";
        $soldParams[] = $categoryFilter;
    }
    $soldQuery .= " ORDER BY i.id DESC";

    $stmtSold = $pdo->prepare($soldQuery);
    $stmtSold->execute($soldParams);
    $soldStocks = $stmtSold->fetchAll(PDO::FETCH_ASSOC);

    // 3. Compute Total Sold Revenue for All Cosigned Cards from order records
    $salesRevenueSql = "
        SELECT COALESCE(SUM(oi.quantity * oi.price), 0) as total_sold_revenue
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN inventory_stocks i ON oi.card_id = i.id
        WHERE o.payment_status = 'Paid' AND (i.is_cosigned = 1 OR i.is_cosigned IS NULL)
    ";
    $stmtSalesRev = $pdo->query($salesRevenueSql);
    $totalSoldRevenue = $stmtSalesRev->fetchColumn();

    // Metrics calculation for active items
    $totalCosignedQty = array_sum(array_column($activeStocks, 'quantity'));
    $totalCosignedRetailValue = array_reduce($activeStocks, function ($carry, $item) {
        return $carry + ($item['quantity'] * ($item['sell_price'] ?? $item['price'] ?? 0));
    }, 0);
    $totalCosignedCostValue = array_reduce($activeStocks, function ($carry, $item) {
        return $carry + ($item['quantity'] * ($item['buy_price'] ?? 0));
    }, 0);
    $potentialCosignedProfit = $totalCosignedRetailValue - $totalCosignedCostValue;

} catch (PDOException $e) {
    $activeStocks = [];
    $soldStocks = [];
    $totalSoldRevenue = 0;
    $totalCosignedQty = 0;
    $totalCosignedRetailValue = 0;
    $totalCosignedCostValue = 0;
    $potentialCosignedProfit = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cosigned Inventory Binder & Reports | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <style>
        .tcg-binder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 1.25rem;
        }
        .tcg-pocket {
            background: linear-gradient(135deg, rgba(24, 20, 36, 0.95) 0%, rgba(12, 10, 18, 0.98) 100%);
            border: 2px solid rgba(217, 119, 6, 0.25);
            border-radius: 1rem;
            position: relative;
            overflow: hidden;
            transition: all 0.25s ease-in-out;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.6), 0 4px 6px rgba(0,0,0,0.3);
        }
        .tcg-pocket:hover {
            border-color: rgba(217, 119, 6, 0.7);
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(217, 119, 6, 0.2), inset 0 0 15px rgba(217, 119, 6, 0.05);
        }
        .tcg-pocket-sold {
            background: linear-gradient(135deg, rgba(24, 24, 27, 0.9) 0%, rgba(9, 9, 11, 0.95) 100%);
            border: 2px solid rgba(63, 63, 70, 0.4);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col bg-[#0b0b0e]">
    
    <?php if ($role === 'admin'): ?>
        <?php include 'includes/header.php'; ?>
    <?php else: ?>
        <header class="bg-[#120f1d]/90 border-b border-purple-900/30 px-6 py-4 flex justify-between items-center sticky top-0 z-40 backdrop-blur-md">
            <div>
                <h1 class="text-xl font-extrabold tracking-widest text-transparent bg-clip-text bg-gradient-to-r from-purple-400 via-fuchsia-300 to-white">
                    TRADE ROCKET
                </h1>
                <p class="text-[10px] uppercase tracking-widest text-purple-300/60 mt-0.5 font-bold">
                    Partner Portal
                </p>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-xs text-purple-300">Welcome, <b class="text-white"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Partner'); ?></b></span>
                <a href="logout.php" class="bg-rose-950/40 text-rose-300 border border-rose-900/50 hover:bg-rose-900 hover:text-white px-4 py-2 rounded-lg text-xs font-bold transition-all">
                    Sign Out
                </a>
            </div>
        </header>
    <?php endif; ?>

    <main class="flex-1 max-w-[1600px] w-full mx-auto p-6 lg:p-8">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
            <div>
                <h1 class="text-2xl font-bold tracking-tight mb-1 text-white">Co-Partner Binder Reports</h1>
                <p class="text-purple-300/60 text-xs">
                    Browse store-wide active and archived sold cosigned cards.
                </p>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="mb-6 p-4 rounded-xl border bg-emerald-950/40 border-emerald-500/30 text-emerald-300 text-xs">
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5 mb-8">
            <div class="tr-sidebar p-5 border border-fuchsia-900/40 relative bg-fuchsia-950/20">
                <p class="text-[11px] uppercase tracking-wider text-fuchsia-300/70 font-semibold mb-2">Total Sold Revenue</p>
                <h3 class="text-2xl font-extrabold text-fuchsia-400">₱<?php echo number_format($totalSoldRevenue, 2); ?></h3>
            </div>
            <div class="tr-sidebar p-5 border border-amber-900/30 relative">
                <p class="text-[11px] uppercase tracking-wider text-amber-300/70 font-semibold mb-2">Active Cards / Pcs</p>
                <h3 class="text-2xl font-extrabold text-amber-400"><?php echo number_format(count($activeStocks)); ?> <span class="text-xs text-zinc-400 font-normal">(<?php echo number_format($totalCosignedQty); ?> pcs)</span></h3>
            </div>
            <div class="tr-sidebar p-5 border border-amber-900/30 relative">
                <p class="text-[11px] uppercase tracking-wider text-amber-300/70 font-semibold mb-2">Total Cost Base</p>
                <h3 class="text-2xl font-extrabold text-amber-400">₱<?php echo number_format($totalCosignedCostValue, 2); ?></h3>
            </div>
            <div class="tr-sidebar p-5 border border-amber-900/30 relative">
                <p class="text-[11px] uppercase tracking-wider text-amber-300/70 font-semibold mb-2">Est. Retail Value</p>
                <h3 class="text-2xl font-extrabold text-amber-400">₱<?php echo number_format($totalCosignedRetailValue, 2); ?></h3>
            </div>
            <div class="tr-sidebar p-5 border border-emerald-900/30 relative">
                <p class="text-[11px] uppercase tracking-wider text-emerald-300/70 font-semibold mb-2">Potential Margin</p>
                <h3 class="text-2xl font-extrabold text-emerald-400">₱<?php echo number_format($potentialCosignedProfit, 2); ?></h3>
            </div>
        </div>

        <div class="tr-sidebar p-5 mb-8 border border-amber-950/40">
            <form method="GET" action="copartners.php" class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div>
                    <label class="block text-[11px] font-semibold text-amber-200/80 uppercase tracking-wider mb-2">Search Collection</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Card name, number..." class="w-full bg-[#0a0810] border border-amber-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-amber-200/80 uppercase tracking-wider mb-2">Condition Filter</label>
                    <select name="condition_status" class="w-full bg-[#0a0810] border border-amber-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                        <option value="">All Conditions</option>
                        <option value="Near Mint" <?php echo $conditionFilter === 'Near Mint' ? 'selected' : ''; ?>>Near Mint</option>
                        <option value="Lightly Played" <?php echo $conditionFilter === 'Lightly Played' ? 'selected' : ''; ?>>Lightly Played</option>
                        <option value="Moderately Played" <?php echo $conditionFilter === 'Moderately Played' ? 'selected' : ''; ?>>Moderately Played</option>
                        <option value="Heavily Played" <?php echo $conditionFilter === 'Heavily Played' ? 'selected' : ''; ?>>Heavily Played</option>
                        <option value="Damaged" <?php echo $conditionFilter === 'Damaged' ? 'selected' : ''; ?>>Damaged</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-amber-200/80 uppercase tracking-wider mb-2">Category Filter</label>
                    <select name="card_category" class="w-full bg-[#0a0810] border border-amber-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                        <option value="">All Categories</option>
                        <option value="Cute" <?php echo $categoryFilter === 'Cute' ? 'selected' : ''; ?>>Cute Cards</option>
                        <option value="Cool" <?php echo $categoryFilter === 'Cool' ? 'selected' : ''; ?>>Cool Cards</option>
                    </select>
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold px-4 py-2 rounded-lg flex-1 h-[36px]">Filter</button>
                    <a href="copartners.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-semibold px-4 py-2 rounded-lg h-[36px] flex items-center justify-center">Reset</a>
                </div>
            </form>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-amber-900/40 mb-6 gap-4">
            <div class="flex items-center space-x-2">
                <button onclick="switchTab('active')" id="tabBtnActive" class="px-6 py-3 text-xs font-bold uppercase tracking-wider rounded-t-xl transition-all border-t-2 border-x-2 bg-amber-600/20 border-amber-500 text-amber-400">
                    Active Binder (<?php echo count($activeStocks); ?>)
                </button>
                <button onclick="switchTab('sold')" id="tabBtnSold" class="px-6 py-3 text-xs font-bold uppercase tracking-wider rounded-t-xl transition-all border-t-2 border-x-2 border-transparent text-zinc-400 hover:text-white">
                    Sold Archive (<?php echo count($soldStocks); ?>)
                </button>
            </div>
            <div class="flex items-center space-x-2 bg-amber-950/20 p-1 rounded-lg border border-amber-900/40">
                <button onclick="switchView('binder')" id="viewBtnBinder" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all bg-amber-600 text-white flex items-center space-x-1.5">
                    <span>Binder View</span>
                </button>
                <button onclick="switchView('list')" id="viewBtnList" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all text-zinc-400 hover:text-white flex items-center space-x-1.5">
                    <span>List View</span>
                </button>
            </div>
        </div>

        <div id="sectionActive">
            <div id="activeBinderView" class="tcg-binder-grid">
                <?php if (count($activeStocks) > 0): ?>
                    <?php foreach ($activeStocks as $stock): ?>
                        <div class="tcg-pocket p-4 flex flex-col justify-between">
                            <div>
                                <div class="flex justify-between items-start mb-2">
                                    <span class="bg-amber-950/80 border border-amber-600/40 text-amber-300 px-2 py-0.5 rounded text-[10px] font-bold">Qty: <?php echo $stock['quantity']; ?></span>
                                    <?php if (!empty($stock['card_category'])): ?>
                                        <span class="bg-fuchsia-950/80 border border-fuchsia-600/40 text-fuchsia-300 px-2 py-0.5 rounded text-[10px] font-bold"><?php echo htmlspecialchars($stock['card_category']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="w-full h-40 bg-black/40 rounded-lg flex items-center justify-center overflow-hidden mb-3 border border-amber-950/40">
                                    <?php if (!empty($stock['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($stock['image_url']); ?>" alt="Card" class="max-h-full max-w-full object-contain">
                                    <?php else: ?>
                                        <span class="text-[10px] text-zinc-500">No Image</span>
                                    <?php endif; ?>
                                </div>
                                <h4 class="text-xs font-bold text-white truncate"><?php echo htmlspecialchars($stock['card_name'] ?? 'Unknown Card'); ?></h4>
                                <p class="text-[10px] text-amber-300/60 truncate"><?php echo htmlspecialchars($stock['set_name'] ?? 'Set'); ?> #<?php echo htmlspecialchars($stock['card_number'] ?? ''); ?></p>
                            </div>
                            <div class="mt-4 pt-3 border-t border-amber-900/30 flex justify-between items-center">
                                <div>
                                    <p class="text-[9px] text-zinc-400 uppercase">Sell Price</p>
                                    <p class="text-sm font-extrabold text-emerald-400">₱<?php echo number_format($stock['sell_price'] ?? $stock['price'] ?? 0, 2); ?></p>
                                </div>
                                <button onclick='openEditModal(<?php echo json_encode($stock); ?>)' class="bg-amber-950/60 hover:bg-amber-900 text-amber-300 border border-amber-700/50 px-2.5 py-1 rounded text-[10px] font-bold">Edit</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-span-full py-16 text-center text-amber-300/40 text-xs">No active cosigned cards found in the system.</div>
                <?php endif; ?>
            </div>

            <div id="activeListView" class="hidden tr-sidebar overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-amber-900/40 text-amber-300/70">
                            <th class="py-3 px-3">Card Name</th>
                            <th class="py-3 px-3">Set</th>
                            <th class="py-3 px-3">Condition</th>
                            <th class="py-3 px-3">Category</th>
                            <th class="py-3 px-3">Qty</th>
                            <th class="py-3 px-3">Buy Cost</th>
                            <th class="py-3 px-3">Sell Price</th>
                            <th class="py-3 px-3">Owner</th>
                            <th class="py-3 px-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-900/20">
                        <?php if (count($activeStocks) > 0): ?>
                            <?php foreach ($activeStocks as $stock): ?>
                                <tr class="hover:bg-amber-950/20">
                                    <td class="py-3 px-3 font-bold text-white"><?php echo htmlspecialchars($stock['card_name'] ?? ''); ?> <span class="text-zinc-500 font-normal">#<?php echo htmlspecialchars($stock['card_number'] ?? ''); ?></span></td>
                                    <td class="py-3 px-3 text-amber-200"><?php echo htmlspecialchars($stock['set_name'] ?? ''); ?></td>
                                    <td class="py-3 px-3 text-zinc-300"><?php echo htmlspecialchars($stock['condition_status'] ?? ''); ?></td>
                                    <td class="py-3 px-3 text-fuchsia-300"><?php echo htmlspecialchars($stock['card_category'] ?? '-'); ?></td>
                                    <td class="py-3 px-3"><?php echo $stock['quantity']; ?></td>
                                    <td class="py-3 px-3 text-amber-400">₱<?php echo number_format($stock['buy_price'], 2); ?></td>
                                    <td class="py-3 px-3 text-emerald-400">₱<?php echo number_format($stock['sell_price'] ?? $stock['price'], 2); ?></td>
                                    <td class="py-3 px-3 text-purple-300"><?php echo htmlspecialchars($stock['owner_name'] ?? 'Shared'); ?></td>
                                    <td class="py-3 px-3 text-right">
                                        <button onclick='openEditModal(<?php echo json_encode($stock); ?>)' class="text-amber-400 hover:underline">Edit</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center py-8 text-amber-300/40">No active records.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="sectionSold" class="hidden">
            <div id="soldBinderView" class="tcg-binder-grid">
                <?php if (count($soldStocks) > 0): ?>
                    <?php foreach ($soldStocks as $stock): ?>
                        <div class="tcg-pocket tcg-pocket-sold p-4 flex flex-col justify-between">
                            <div>
                                <div class="flex justify-between items-start mb-2">
                                    <span class="bg-zinc-800 text-zinc-400 px-2 py-0.5 rounded text-[10px] font-bold">SOLD OUT</span>
                                </div>
                                <div class="w-full h-40 bg-black/60 rounded-lg flex items-center justify-center overflow-hidden mb-3 border border-zinc-800">
                                    <?php if (!empty($stock['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($stock['image_url']); ?>" alt="Card" class="max-h-full max-w-full object-contain grayscale opacity-60">
                                    <?php else: ?>
                                        <span class="text-[10px] text-zinc-600">No Image</span>
                                    <?php endif; ?>
                                </div>
                                <h4 class="text-xs font-bold text-zinc-300 truncate"><?php echo htmlspecialchars($stock['card_name'] ?? 'Unknown Card'); ?></h4>
                                <p class="text-[10px] text-zinc-500 truncate"><?php echo htmlspecialchars($stock['set_name'] ?? 'Set'); ?> #<?php echo htmlspecialchars($stock['card_number'] ?? ''); ?></p>
                            </div>
                            <div class="mt-4 pt-3 border-t border-zinc-800 flex justify-between items-center">
                                <div>
                                    <p class="text-[9px] text-zinc-500 uppercase">Final Sold Price</p>
                                    <p class="text-sm font-extrabold text-zinc-400">₱<?php echo number_format($stock['sell_price'] ?? $stock['price'] ?? 0, 2); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-span-full py-16 text-center text-zinc-500 text-xs">No sold archived records found.</div>
                <?php endif; ?>
            </div>

            <div id="soldListView" class="hidden tr-sidebar overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-zinc-800 text-zinc-500">
                            <th class="py-3 px-3">Card Name</th>
                            <th class="py-3 px-3">Set</th>
                            <th class="py-3 px-3">Condition</th>
                            <th class="py-3 px-3">Category</th>
                            <th class="py-3 px-3">Final Sold Price</th>
                            <th class="py-3 px-3">Owner</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/40">
                        <?php if (count($soldStocks) > 0): ?>
                            <?php foreach ($soldStocks as $stock): ?>
                                <tr class="hover:bg-zinc-900/40 text-zinc-400">
                                    <td class="py-3 px-3 font-bold text-zinc-300"><?php echo htmlspecialchars($stock['card_name'] ?? ''); ?> <span class="text-zinc-600 font-normal">#<?php echo htmlspecialchars($stock['card_number'] ?? ''); ?></span></td>
                                    <td class="py-3 px-3"><?php echo htmlspecialchars($stock['set_name'] ?? ''); ?></td>
                                    <td class="py-3 px-3"><?php echo htmlspecialchars($stock['condition_status'] ?? ''); ?></td>
                                    <td class="py-3 px-3"><?php echo htmlspecialchars($stock['card_category'] ?? '-'); ?></td>
                                    <td class="py-3 px-3 text-zinc-300 font-bold">₱<?php echo number_format($stock['sell_price'] ?? $stock['price'], 2); ?></td>
                                    <td class="py-3 px-3"><?php echo htmlspecialchars($stock['owner_name'] ?? 'Shared'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-8 text-zinc-600">No sold records.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="editStockModal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="tr-panel max-w-md w-full p-6 border border-amber-600/40">
            <h3 class="text-sm font-bold text-white uppercase tracking-wider mb-4">Edit Cosigned Card Item</h3>
            <form method="POST" action="copartners.php" class="space-y-4">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="stock_id" id="editStockId">
                <div>
                    <label class="block text-[11px] font-semibold text-amber-200/80 uppercase tracking-wider mb-1">Card Item</label>
                    <input type="text" id="editCardName" disabled class="w-full bg-[#0a0810] border border-amber-900/40 text-zinc-400 text-xs rounded-lg px-3.5 py-2">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-amber-200/80 uppercase tracking-wider mb-1">Quantity</label>
                        <input type="number" name="quantity" id="editQuantity" min="0" required class="w-full bg-[#0a0810] border border-amber-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-amber-200/80 uppercase tracking-wider mb-1">Condition</label>
                        <select name="condition_status" id="editCondition" class="w-full bg-[#0a0810] border border-amber-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                            <option value="Near Mint">Near Mint</option>
                            <option value="Lightly Played">Lightly Played</option>
                            <option value="Moderately Played">Moderately Played</option>
                            <option value="Heavily Played">Heavily Played</option>
                            <option value="Damaged">Damaged</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-amber-200/80 uppercase tracking-wider mb-1">Buy Price (₱)</label>
                        <input type="number" step="0.01" name="buy_price" id="editBuyPrice" required class="w-full bg-[#0a0810] border border-amber-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-amber-200/80 uppercase tracking-wider mb-1">Sell Price (₱)</label>
                        <input type="number" step="0.01" name="sell_price" id="editSellPrice" required class="w-full bg-[#0a0810] border border-amber-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-amber-200/80 uppercase tracking-wider mb-1">Card Category</label>
                    <select name="card_category" id="editCategory" class="w-full bg-[#0a0810] border border-amber-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                        <option value="">None / Standard</option>
                        <option value="Cute">Cute</option>
                        <option value="Cool">Cool</option>
                    </select>
                </div>
                <div class="flex items-center justify-between pt-4 border-t border-amber-900/30">
                    <button type="submit" name="action" value="delete" onclick="return confirm('Are you sure you want to remove this item?');" class="text-xs text-red-400 hover:text-red-300 font-semibold">Delete Item</button>
                    <div class="space-x-3">
                        <button type="button" onclick="closeEditModal()" class="text-xs text-zinc-400 hover:text-white px-3 py-2">Cancel</button>
                        <button type="submit" class="bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold px-4 py-2 rounded-lg">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            const btnActive = document.getElementById('tabBtnActive');
            const btnSold = document.getElementById('tabBtnSold');
            const secActive = document.getElementById('sectionActive');
            const secSold = document.getElementById('sectionSold');

            if (tab === 'active') {
                btnActive.className = "px-6 py-3 text-xs font-bold uppercase tracking-wider rounded-t-xl transition-all border-t-2 border-x-2 bg-amber-600/20 border-amber-500 text-amber-400";
                btnSold.className = "px-6 py-3 text-xs font-bold uppercase tracking-wider rounded-t-xl transition-all border-t-2 border-x-2 border-transparent text-zinc-400 hover:text-white";
                secActive.classList.remove('hidden');
                secSold.classList.add('hidden');
            } else {
                btnSold.className = "px-6 py-3 text-xs font-bold uppercase tracking-wider rounded-t-xl transition-all border-t-2 border-x-2 bg-zinc-800 border-zinc-600 text-zinc-200";
                btnActive.className = "px-6 py-3 text-xs font-bold uppercase tracking-wider rounded-t-xl transition-all border-t-2 border-x-2 border-transparent text-zinc-400 hover:text-white";
                secSold.classList.remove('hidden');
                secActive.classList.add('hidden');
            }
        }

        function switchView(view) {
            const btnBinder = document.getElementById('viewBtnBinder');
            const btnList = document.getElementById('viewBtnList');
            const activeBinder = document.getElementById('activeBinderView');
            const activeList = document.getElementById('activeListView');
            const soldBinder = document.getElementById('soldBinderView');
            const soldList = document.getElementById('soldListView');

            if (view === 'binder') {
                btnBinder.className = "px-3 py-1.5 text-xs font-semibold rounded-md transition-all bg-amber-600 text-white flex items-center space-x-1.5";
                btnList.className = "px-3 py-1.5 text-xs font-semibold rounded-md transition-all text-zinc-400 hover:text-white flex items-center space-x-1.5";

                activeBinder.classList.remove('hidden');
                activeList.classList.add('hidden');
                soldBinder.classList.remove('hidden');
                soldList.classList.add('hidden');
            } else {
                btnList.className = "px-3 py-1.5 text-xs font-semibold rounded-md transition-all bg-amber-600 text-white flex items-center space-x-1.5";
                btnBinder.className = "px-3 py-1.5 text-xs font-semibold rounded-md transition-all text-zinc-400 hover:text-white flex items-center space-x-1.5";

                activeBinder.classList.add('hidden');
                activeList.classList.remove('hidden');
                soldBinder.classList.add('hidden');
                soldList.classList.remove('hidden');
            }
        }

        function openEditModal(stock) {
            document.getElementById('editStockId').value = stock.id;
            document.getElementById('editCardName').value = (stock.card_name || 'Card') + ' (No. ' + (stock.card_number || 'N/A') + ')';
            document.getElementById('editQuantity').value = stock.quantity || 1;
            document.getElementById('editCondition').value = stock.condition_status || 'Near Mint';
            document.getElementById('editCategory').value = stock.card_category || '';
            document.getElementById('editBuyPrice').value = stock.buy_price || 0.00;
            document.getElementById('editSellPrice').value = stock.sell_price || stock.price || 0.00;

            document.getElementById('editStockModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editStockModal').classList.add('hidden');
        }
    </script>
</body>
</html>
