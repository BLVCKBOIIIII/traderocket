<?php
// sales_costing.php - Sales Generation & Costing Analytics Module
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Fetch financial metrics & aggregated sales data including co-signed totals
try {
    // 1. Overall Metrics (Paid Orders)
    $stmtMetrics = $pdo->query("
        SELECT 
            COUNT(o.id) as total_orders,
            COALESCE(SUM(o.total_amount), 0) as gross_revenue,
            COALESCE(SUM(o.packaging_cost), 0) as total_packaging_spent
        FROM orders o 
        WHERE o.payment_status = 'Paid'
    ");
    $metrics = $stmtMetrics->fetch(PDO::FETCH_ASSOC);

    // 2. Total Sold Amount specifically for Co-signed Cards
    $stmtCosignedSales = $pdo->query("
        SELECT COALESCE(SUM(oi.quantity * oi.price), 0) as total_cosigned_revenue
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN inventory_stocks i ON oi.card_id = i.id
        WHERE o.payment_status = 'Paid' AND i.is_cosigned = 1
    ");
    $totalCosignedRevenue = $stmtCosignedSales->fetchColumn();

    // 3. Total COGS for Paid Orders
    $stmtItemsCost = $pdo->query("
        SELECT COALESCE(SUM(oi.quantity * oi.buy_price), 0) as total_cogs
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.payment_status = 'Paid'
    ");
    $totalCogs = $stmtItemsCost->fetchColumn();

    $grossRevenue = floatval($metrics['gross_revenue']);
    $totalPackaging = floatval($metrics['total_packaging_spent']);
    $netProfit = $grossRevenue - $totalCogs - $totalPackaging;

    // 4. Fetch detailed order item breakdowns (including co-signed status & packaging cost per order)
    $stmtSalesLog = $pdo->query("
        SELECT o.id as order_id, o.customer_name, o.created_at, o.total_amount, o.packaging_cost,
               c.name as card_name, oi.quantity, oi.price as sell_price, oi.buy_price, i.is_cosigned
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN inventory_stocks i ON oi.card_id = i.id
        JOIN cards c ON i.card_id = c.id
        WHERE o.payment_status = 'Paid'
        ORDER BY o.created_at DESC
        LIMIT 50
    ");
    $salesLog = $stmtSalesLog->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $grossRevenue = 0;
    $totalCosignedRevenue = 0;
    $totalCogs = 0;
    $totalPackaging = 0;
    $netProfit = 0;
    $salesLog = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Generation & Costing | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap');
        body { font-family: 'Space Grotesk', sans-serif; background-color: #0b0b0e; color: #f4f4f5; }
        .tr-panel { background: rgba(18, 15, 29, 0.6); border: 1px solid rgba(147, 51, 234, 0.3); border-radius: 1rem; backdrop-filter: blur(12px); }
    </style>
</head>
<body class="min-h-screen flex flex-col p-6 lg:p-8">

    <?php include 'includes/header.php'; ?>

    <div class="max-w-7xl mx-auto w-full flex-1 space-y-8">
        <div class="flex justify-between items-center pb-4 border-b border-purple-900/40">
            <div>
                <h1 class="text-xl font-bold tracking-wider text-purple-200 uppercase">Sales Generation & Costing Dashboard</h1>
                <p class="text-xs text-purple-300/60 mt-0.5">Detail Financial Tracking</p>
            </div>
            <a href="index.php" class="text-xs bg-purple-950/60 text-purple-200 border border-purple-800/40 px-3.5 py-2 rounded-xl">Back to Dashboard</a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-5 gap-5">
            <div class="tr-panel p-5 border border-purple-900/40">
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-bold mb-1">Gross Revenue</p>
                <h3 class="text-2xl font-extrabold text-emerald-400">₱<?php echo number_format($grossRevenue, 2); ?></h3>
            </div>
            <div class="tr-panel p-5 border border-purple-900/40">
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-bold mb-1">Co-Signed Sales Total</p>
                <h3 class="text-2xl font-extrabold text-fuchsia-400">₱<?php echo number_format($totalCosignedRevenue, 2); ?></h3>
            </div>
            <div class="tr-panel p-5 border border-purple-900/40">
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-bold mb-1">Total Card COGS</p>
                <h3 class="text-2xl font-extrabold text-amber-400">₱<?php echo number_format($totalCogs, 2); ?></h3>
            </div>
            <div class="tr-panel p-5 border border-purple-900/40">
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-bold mb-1">Packaging Overhead</p>
                <h3 class="text-2xl font-extrabold text-blue-400">₱<?php echo number_format($totalPackaging, 2); ?></h3>
            </div>
            <div class="tr-panel p-5 border border-purple-900/40 bg-purple-950/20">
                <p class="text-[11px] uppercase tracking-wider text-purple-200 font-bold mb-1">Net Profit Margin</p>
                <h3 class="text-2xl font-extrabold text-purple-300">₱<?php echo number_format($netProfit, 2); ?></h3>
            </div>
        </div>

        <div class="tr-panel p-6">
            <h3 class="text-xs font-bold uppercase tracking-wider text-purple-200 mb-4">Itemized Sales & Cost Log</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-purple-900/40 text-purple-300/70">
                            <th class="py-3 px-3">Order ID</th>
                            <th class="py-3 px-3">Customer</th>
                            <th class="py-3 px-3">Card Item / Type</th>
                            <th class="py-3 px-3">Qty</th>
                            <th class="py-3 px-3">Buy Cost (COGS)</th>
                            <th class="py-3 px-3">Sell Price</th>
                            <th class="py-3 px-3">Packaging Fee</th>
                            <th class="py-3 px-3">Estimated Profit</th>
                            <th class="py-3 px-3">Date of Transaction</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-purple-900/20">
                        <?php if (count($salesLog) > 0): ?>
                            <?php foreach ($salesLog as $row): 
                                $itemProfit = ($row['sell_price'] - $row['buy_price']) * $row['quantity'];
                            ?>
                                <tr class="hover:bg-purple-950/20">
                                    <td class="py-3 px-3 font-bold text-white">#<?php echo $row['order_id']; ?></td>
                                    <td class="py-3 px-3 text-purple-200"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                    <td class="py-3 px-3 text-purple-200">
                                        <?php echo htmlspecialchars($row['card_name']); ?>
                                        <?php if ($row['is_cosigned'] == 1): ?>
                                            <span class="ml-1.5 bg-amber-950/60 border border-amber-700/40 text-amber-300 px-1.5 py-0.5 rounded text-[9px] font-bold">Co-signed</span>
                                        <?php else: ?>
                                            <span class="ml-1.5 bg-zinc-800 text-zinc-400 px-1.5 py-0.5 rounded text-[9px]">Store</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3"><?php echo $row['quantity']; ?></td>
                                    <td class="py-3 px-3 text-amber-400">₱<?php echo number_format($row['buy_price'] * $row['quantity'], 2); ?></td>
                                    <td class="py-3 px-3 text-emerald-400">₱<?php echo number_format($row['sell_price'] * $row['quantity'], 2); ?></td>
                                    <td class="py-3 px-3 text-blue-400">₱<?php echo number_format($row['packaging_cost'], 2); ?></td>
                                    <td class="py-3 px-3 font-bold text-purple-300">₱<?php echo number_format($itemProfit, 2); ?></td>
                                    <td class="py-3 px-3 text-zinc-400"><?php echo $row['created_at']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-8 text-purple-300/40">No completed sales records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>