<?php
session_start();
require_once 'config/database.php';

// Security Check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['user_role'] ?? 'user';

$message = '';
$messageType = '';

// Handle Sales Record Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_sale') {
    $stockId = (int)($_POST['stock_id'] ?? 0);
    $quantitySold = (int)($_POST['quantity_sold'] ?? 0);
    $salePrice = (float)($_POST['sale_price'] ?? 0.0);
    $buyerName = trim($_POST['buyer_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($stockId > 0 && $quantitySold > 0 && $salePrice >= 0) {
        try {
            $pdo->beginTransaction();

            // Fetch inventory stock item to check availability & permission
            if ($role === 'admin') {
                $stmt = $pdo->prepare("SELECT * FROM inventory_stocks WHERE id = ?");
                $stmt->execute([$stockId]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM inventory_stocks WHERE id = ? AND owner_id = ?");
                $stmt->execute([$stockId, $userId]);
            }
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($stock) {
                if ($stock['quantity'] >= $quantitySold) {
                    // Update inventory quantity
                    $newQty = $stock['quantity'] - $quantitySold;
                    $updateStmt = $pdo->prepare("UPDATE inventory_stocks SET quantity = ? WHERE id = ?");
                    $updateStmt->execute([$newQty, $stockId]);

                    // Insert sale record (matches POS checkout integration style)
                    $totalAmount = $quantitySold * $salePrice;
                    $insertSale = $pdo->prepare("INSERT INTO sales (stock_id, user_id, quantity_sold, sale_price, total_amount, buyer_name, notes, sale_date) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                    $insertSale->execute([$stockId, $stock['owner_id'], $quantitySold, $salePrice, $totalAmount, $buyerName, $notes]);

                    $pdo->commit();
                    $message = 'Sale recorded successfully!';
                    $messageType = 'success';
                } else {
                    $pdo->rollBack();
                    $message = 'Insufficient stock quantity available.';
                    $messageType = 'error';
                }
            } else {
                $pdo->rollBack();
                $message = 'Stock item not found or unauthorized.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = 'Please provide valid stock, quantity, and sale price.';
        $messageType = 'error';
    }
}

// Search and Filter Handling for Sales Log
$searchQuery = trim($_GET['search'] ?? '');
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

$whereClause = [];
$params = [];

if ($role !== 'admin') {
    $whereClause[] = "s.user_id = ?";
    $params[] = $userId;
}

if (!empty($searchQuery)) {
    $whereClause[] = "(c.name LIKE ? OR s.buyer_name LIKE ? OR c.card_number LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

if (!empty($startDate)) {
    $whereClause[] = "DATE(s.sale_date) >= ?";
    $params[] = $startDate;
}

if (!empty($endDate)) {
    $whereClause[] = "DATE(s.sale_date) <= ?";
    $params[] = $endDate;
}

$salesSql = "
    SELECT s.*, c.name AS card_name, c.card_number, c.image_url, u.username AS seller_name
    FROM sales s
    LEFT JOIN inventory_stocks inv ON s.stock_id = inv.id
    LEFT JOIN cards c ON inv.card_id = c.id
    LEFT JOIN users u ON s.user_id = u.id
";

if (!empty($whereClause)) {
    $salesSql .= " WHERE " . implode(' AND ', $whereClause);
}
$salesSql .= " ORDER BY s.sale_date DESC";

try {
    $salesStmt = $pdo->prepare($salesSql);
    $salesStmt->execute($params);
    $salesLogs = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch total revenue calculation
    $totalRevenue = array_reduce($salesLogs, function($carry, $item) {
        return $carry + ($item['total_amount'] ?? ($item['quantity_sold'] * $item['sale_price']));
    }, 0);

    // Fetch user inventory for sale recording modal
    if ($role === 'admin') {
        $invStmt = $pdo->query("
            SELECT inv.id, c.name, c.card_number, inv.quantity, inv.price, u.username 
            FROM inventory_stocks inv
            JOIN cards c ON inv.card_id = c.id
            JOIN users u ON inv.owner_id = u.id
            WHERE inv.quantity > 0
            ORDER BY c.name ASC
        ");
    } else {
        $invStmt = $pdo->prepare("
            SELECT inv.id, c.name, c.card_number, inv.quantity, inv.price, u.username 
            FROM inventory_stocks inv
            JOIN cards c ON inv.card_id = c.id
            JOIN users u ON inv.owner_id = u.id
            WHERE inv.owner_id = ? AND inv.quantity > 0
            ORDER BY c.name ASC
        ");
        $invStmt->execute([$userId]);
    }
    $availableInventory = $invStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $salesLogs = [];
    $availableInventory = [];
    $totalRevenue = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History & Analytics | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
</head>
<body class="min-h-screen flex flex-col bg-[#0b0b0e]">

    <?php include 'includes/header.php'; ?>

    <!-- Main Content Area -->
    <main class="flex-1 max-w-[1600px] w-full mx-auto p-6 lg:p-8">

        <!-- Top Header & Actions -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
            <div>
                <h1 class="text-2xl font-bold tracking-tight mb-1 text-white">Sales & Analytics</h1>
                <p class="text-purple-300/60 text-xs">
                    <?php echo ($role === 'admin') ? 'Tracking overall store revenues, sales records, and seller activity.' : 'Track your personal card sales and revenue analytics.'; ?>
                </p>
            </div>
            <div class="flex items-center space-x-3 self-start md:self-auto">
                <a href="pos_module.php" class="bg-purple-600/20 hover:bg-purple-600/30 border border-purple-500/30 text-purple-200 text-xs font-semibold px-4 py-2.5 rounded-lg flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    <span>POS Terminal</span>
                </a>
                <button onclick="openSaleModal()" class="purple-btn text-white text-xs font-semibold px-4 py-2.5 rounded-lg flex items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    <span>Record New Sale</span>
                </button>
            </div>
        </div>

        <!-- Alert Banner -->
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl border <?php echo $messageType === 'success' ? 'bg-emerald-950/40 border-emerald-500/30 text-emerald-300' : 'bg-rose-950/40 border-rose-500/30 text-rose-300'; ?> text-xs flex items-center space-x-2">
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Summary Metric Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="tr-sidebar p-6 border border-purple-900/30 relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 text-purple-600/10 font-bold text-6xl select-none">₱</div>
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-semibold mb-2">Total Sales Revenue</p>
                <h3 class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-fuchsia-300">
                    ₱<?php echo number_format($totalRevenue, 2); ?>
                </h3>
            </div>
            <div class="tr-sidebar p-6 border border-purple-900/30 relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 text-purple-600/10 font-bold text-6xl select-none">#</div>
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-semibold mb-2">Total Transactions</p>
                <h3 class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-fuchsia-300">
                    <?php echo number_format(count($salesLogs)); ?>
                </h3>
            </div>
            <div class="tr-sidebar p-6 border border-purple-900/30 relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 text-purple-600/10 font-bold text-6xl select-none">AVG</div>
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-semibold mb-2">Avg Transaction Value</p>
                <h3 class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-fuchsia-300">
                    ₱<?php echo count($salesLogs) > 0 ? number_format($totalRevenue / count($salesLogs), 2) : '0.00'; ?>
                </h3>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="tr-sidebar p-5 mb-8">
            <form method="GET" action="sales.php" class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">Search Sales</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Card name, buyer, or #..." class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none placeholder-zinc-600">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="purple-btn text-white text-xs font-semibold px-4 py-2 rounded-lg flex-1 h-[36px]">Filter Log</button>
                    <a href="sales.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-semibold px-4 py-2 rounded-lg h-[36px] flex items-center justify-center">Reset</a>
                </div>
            </form>
        </div>

        <!-- Sales Logs Data Table -->
        <div class="tr-sidebar overflow-hidden">
            <div class="px-6 py-4 border-b border-purple-900/30 flex justify-between items-center">
                <h2 class="text-sm font-bold text-white uppercase tracking-wider">Transaction History</h2>
                <span class="text-xs text-purple-300/60"><?php echo count($salesLogs); ?> record(s)</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-[#0a0810]/60 border-b border-purple-900/30 text-purple-300/70 uppercase tracking-wider font-semibold">
                            <th class="py-3.5 px-6">Date</th>
                            <th class="py-3.5 px-6">Card Description</th>
                            <?php if ($role === 'admin'): ?>
                                <th class="py-3.5 px-6">Seller</th>
                            <?php endif; ?>
                            <th class="py-3.5 px-6">Buyer</th>
                            <th class="py-3.5 px-6 text-center">Qty Sold</th>
                            <th class="py-3.5 px-6 text-right">Unit Price</th>
                            <th class="py-3.5 px-6 text-right">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-purple-900/20 text-zinc-300">
                        <?php if (empty($salesLogs)): ?>
                            <tr>
                                <td colspan="<?php echo $role === 'admin' ? '7' : '6'; ?>" class="py-12 text-center text-zinc-500">
                                    No sales history found matching the filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($salesLogs as $sale): ?>
                                <tr class="hover:bg-purple-900/10 transition-colors">
                                    <td class="py-3.5 px-6 text-purple-200/80 whitespace-nowrap">
                                        <?php echo date('M d, Y h:i A', strtotime($sale['sale_date'])); ?>
                                    </td>
                                    <td class="py-3.5 px-6 font-medium text-white">
                                        <div class="flex items-center space-x-3">
                                            <?php if (!empty($sale['image_url'])): ?>
                                                <img src="<?php echo htmlspecialchars($sale['image_url']); ?>" class="w-7 h-10 object-contain rounded border border-purple-900/30 shrink-0">
                                            <?php endif; ?>
                                            <div>
                                                <div class="text-white font-semibold"><?php echo htmlspecialchars($sale['card_name'] ?? 'Unknown Card'); ?></div>
                                                <div class="text-[10px] text-purple-300/50"><?php echo htmlspecialchars($sale['card_number'] ?? '-'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <?php if ($role === 'admin'): ?>
                                        <td class="py-3.5 px-6 text-purple-300/80 font-medium">
                                            <?php echo htmlspecialchars($sale['seller_name'] ?? 'System'); ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="py-3.5 px-6 text-zinc-400">
                                        <?php echo htmlspecialchars($sale['buyer_name'] ?: 'Guest'); ?>
                                    </td>
                                    <td class="py-3.5 px-6 text-center font-semibold text-white">
                                        <?php echo (int)$sale['quantity_sold']; ?>
                                    </td>
                                    <td class="py-3.5 px-6 text-right font-medium text-purple-300/90 whitespace-nowrap">
                                        ₱<?php echo number_format($sale['sale_price'], 2); ?>
                                    </td>
                                    <td class="py-3.5 px-6 text-right font-bold text-purple-300 whitespace-nowrap">
                                        ₱<?php echo number_format($sale['total_amount'] ?? ($sale['quantity_sold'] * $sale['sale_price']), 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal for Recording New Sale -->
    <div id="saleModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="tr-sidebar max-w-md w-full p-6 border border-purple-900/40 relative">
            <h2 class="text-base font-bold text-white mb-4">Record New Card Sale</h2>
            
            <form method="POST" action="sales.php" class="space-y-4">
                <input type="hidden" name="action" value="record_sale">

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Select Item from Inventory</label>
                    <select name="stock_id" id="modalStockSelect" required onchange="updateDefaultPrice()" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                        <option value="" data-price="0">Choose stock item...</option>
                        <?php foreach ($availableInventory as $inv): ?>
                            <option value="<?php echo $inv['id']; ?>" data-price="<?php echo $inv['price']; ?>">
                                <?php echo htmlspecialchars($inv['name']); ?> (#<?php echo htmlspecialchars($inv['card_number']); ?>) - In Stock: <?php echo $inv['quantity']; ?> <?php echo ($role === 'admin') ? '[' . htmlspecialchars($inv['username']) . ']' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Quantity Sold</label>
                        <input type="number" name="quantity_sold" min="1" value="1" required class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Unit Price (₱)</label>
                        <input type="number" step="0.01" name="sale_price" id="modalSalePrice" min="0" required placeholder="0.00" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Buyer Name (Optional)</label>
                    <input type="text" name="buyer_name" placeholder="Customer name..." class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Notes (Optional)</label>
                    <textarea name="notes" rows="2" placeholder="Condition details, discounts, etc." class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none"></textarea>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeSaleModal()" class="text-xs text-zinc-400 hover:text-white px-4 py-2">Cancel</button>
                    <button type="submit" class="purple-btn text-white text-xs font-semibold px-5 py-2 rounded-lg">Confirm Sale</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openSaleModal() {
            document.getElementById('saleModal').classList.remove('hidden');
        }

        function closeSaleModal() {
            document.getElementById('saleModal').classList.add('hidden');
        }

        function updateDefaultPrice() {
            const select = document.getElementById('modalStockSelect');
            const selectedOption = select.options[select.selectedIndex];
            const price = selectedOption.getAttribute('data-price');
            if (price) {
                document.getElementById('modalSalePrice').value = price;
            }
        }
    </script>

</body>
</html>