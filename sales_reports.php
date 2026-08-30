<?php
// sales_reports.php - Comprehensive Sales, Refunds, Costing & Packaging Fee Analytics Module
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Ensure a system settings or configuration table exists or handle persistent override
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");
} catch (Exception $e) {}

// Handle persistent packaging override via GET/Form submission
if (isset($_GET['packaging_deduction'])) {
    $newPackagingValue = floatval($_GET['packaging_deduction']);
    $_SESSION['packaging_override'] = $newPackagingValue;
    
    // Save persistently to database so it applies globally as default
    $stmtSet = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('default_packaging_fee', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value");
    $stmtSet->execute([$newPackagingValue]);
}

// Retrieve default packaging fee from database if not set in session
if (!isset($_SESSION['packaging_override'])) {
    $stmtGet = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_packaging_fee'");
    $dbPkg = $stmtGet->fetchColumn();
    $_SESSION['packaging_override'] = ($dbPkg !== false) ? floatval($dbPkg) : null;
}
$packagingOverride = $_SESSION['packaging_override'];

// Filter and Date Range Inputs
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all'; // 'all', 'Profiting', 'Paid', 'Refunded', 'Unpaid'
$ownershipFilter = $_GET['ownership'] ?? 'all'; // 'all', 'cosigned', 'non_cosigned'
$rangeFilter = $_GET['range'] ?? 'monthly'; // 'daily', 'weekly', 'monthly', 'annual', 'custom'
$viewMode = $_GET['view_mode'] ?? 'items'; // 'items' (default view per item) or 'orders' (view per order)

// Use 'STARTING DATE' and 'END DATE' inputs directly
$customStart = $_GET['start_date'] ?? date('Y-m-01');
$customEnd = $_GET['end_date'] ?? date('Y-m-d');
$sortBy = $_GET['sort'] ?? 'date_desc'; // 'date_desc', 'date_asc', 'amount_desc', 'amount_asc'

// Determine Date Boundaries based on range filter or custom date pickers
$startDate = '';
$endDate = date('Y-m-d 23:59:59');

switch ($rangeFilter) {
    case 'daily':
        $startDate = date('Y-m-d 00:00:00');
        break;
    case 'weekly':
        $startDate = date('Y-m-d 00:00:00', strtotime('monday this week'));
        break;
    case 'annual':
        $startDate = date('Y-01-01 00:00:00');
        break;
    case 'custom':
    case 'monthly':
    default:
        $startDate = !empty($customStart) ? $customStart . ' 00:00:00' : date('Y-m-01 00:00:00');
        $endDate = !empty($customEnd) ? $customEnd . ' 23:59:59' : date('Y-m-d 23:59:59');
        break;
}

try {
    // 1. Base SQL Query for Summary Metrics & Orders Report
    $sql = "SELECT DISTINCT o.*, 
            COALESCE(o.total_amount, 0) AS calculated_total 
            FROM orders o 
            LEFT JOIN order_items oi ON o.id = oi.order_id
            LEFT JOIN inventory_stocks i ON oi.card_id = i.id
            WHERE o.created_at BETWEEN ? AND ?";
    $params = [$startDate, $endDate];

    if ($statusFilter !== 'all') {
        if ($statusFilter === 'Profiting') {
            $sql .= " AND o.payment_status = 'Paid' AND o.fulfillment_status NOT IN ('Restocked', 'Voided')";
        } else if ($statusFilter === 'Refunded') {
            $sql .= " AND (o.payment_status = 'Refunded' OR o.fulfillment_status IN ('Restocked', 'Voided'))";
        } else {
            $sql .= " AND o.payment_status = ?";
            $params[] = $statusFilter;
        }
    }

    if ($ownershipFilter === 'cosigned') {
        $sql .= " AND i.is_cosigned = 1";
    } else if ($ownershipFilter === 'non_cosigned') {
        $sql .= " AND (i.is_cosigned = 0 OR i.is_cosigned IS NULL)";
    }

    if (!empty($search)) {
        $sql .= " AND (o.customer_name LIKE ? OR o.id::text LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    switch ($sortBy) {
        case 'date_asc':
            $sql .= " ORDER BY o.created_at ASC";
            break;
        case 'amount_desc':
            $sql .= " ORDER BY o.total_amount DESC";
            break;
        case 'amount_asc':
            $sql .= " ORDER BY o.total_amount ASC";
            break;
        case 'date_desc':
        default:
            $sql .= " ORDER BY o.created_at DESC";
            break;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ordersReport = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate Summary Metrics
    $totalOrdersCount = count($ordersReport);
    $grossRevenue = 0;
    $refundedCount = 0;
    $refundedAmount = 0;
    $calculatedPackagingSum = 0;

    foreach ($ordersReport as $ord) {
        $amount = floatval($ord['total_amount']);
        $pkg = floatval($ord['packaging_cost'] ?? 0);
        
        if ($ord['payment_status'] === 'Refunded' || in_array($ord['fulfillment_status'], ['Restocked', 'Voided'])) {
            $refundedCount++;
            $refundedAmount += $amount;
        } else if ($ord['payment_status'] === 'Paid' && !in_array($ord['fulfillment_status'], ['Restocked', 'Voided'])) {
            $grossRevenue += $amount;
            $calculatedPackagingSum += $pkg;
        }
    }
    
    // If an override is set, multiply it per order count for accurate partner material cost pool deduction, otherwise use sum
    $perOrderFee = ($packagingOverride !== null) ? $packagingOverride : 5.00;
    $totalPackagingSpent = $perOrderFee * ($totalOrdersCount > 0 ? $totalOrdersCount : 1);

    $netRevenue = $grossRevenue - $refundedAmount;

    // Fetch Costing Metrics (COGS, Co-signed & Non-Cosigned Totals)
    $stmtCosignedSales = $pdo->query("
        SELECT COALESCE(SUM(oi.quantity * oi.price), 0) as total_cosigned_revenue
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN inventory_stocks i ON oi.card_id = i.id
        WHERE o.payment_status = 'Paid' AND i.is_cosigned = 1 AND o.created_at BETWEEN '$startDate' AND '$endDate'
    ");
    $totalCosignedRevenue = $stmtCosignedSales->fetchColumn();

    $stmtNonCosignedSales = $pdo->query("
        SELECT COALESCE(SUM(oi.quantity * oi.price), 0) as total_non_cosigned_revenue
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN inventory_stocks i ON oi.card_id = i.id
        WHERE o.payment_status = 'Paid' AND (i.is_cosigned = 0 OR i.is_cosigned IS NULL) AND o.created_at BETWEEN '$startDate' AND '$endDate'
    ");
    $totalNonCosignedRevenue = $stmtNonCosignedSales->fetchColumn();

    $stmtItemsCost = $pdo->query("
        SELECT COALESCE(SUM(oi.quantity * oi.buy_price), 0) as total_cogs
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.payment_status = 'Paid' AND o.created_at BETWEEN '$startDate' AND '$endDate'
    ");
    $totalCogs = $stmtItemsCost->fetchColumn();

    $netProfitMargin = $grossRevenue - floatval($totalCogs) - $totalPackagingSpent;

    // 2. Fetch Transaction Logs based on View Mode (Per Item vs Per Order)
    if ($viewMode === 'orders') {
        $logSql = "
            SELECT o.id as order_id, o.customer_name, o.created_at, o.total_amount, o.packaging_cost, 
                   o.payment_status, o.fulfillment_status,
                   SUM(oi.quantity) as total_qty,
                   SUM(oi.quantity * oi.buy_price) as order_cogs,
                   SUM(oi.quantity * oi.price) as order_sell_price,
                   COUNT(oi.id) as unique_items_count
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            WHERE o.created_at BETWEEN ? AND ?";
        
        $logParams = [$startDate, $endDate];
        if (!empty($search)) {
            $logSql .= " AND (o.customer_name LIKE ? OR o.id::text LIKE ?)";
            $logParams[] = "%$search%";
            $logParams[] = "%$search%";
        }
        $logSql .= " GROUP BY o.id, o.customer_name, o.created_at, o.total_amount, o.packaging_cost, o.payment_status, o.fulfillment_status ORDER BY o.created_at DESC LIMIT 100";
        
        $stmtLog = $pdo->prepare($logSql);
        $stmtLog->execute($logParams);
        $salesLog = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $logSql = "
            SELECT DISTINCT o.id as order_id, o.customer_name, o.created_at, o.total_amount, o.packaging_cost,
                   o.payment_status, o.fulfillment_status,
                   c.name as card_name, c.card_number, s.name as set_name, oi.quantity, oi.price as sell_price, oi.buy_price, i.is_cosigned
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN inventory_stocks i ON oi.card_id = i.id
            JOIN cards c ON i.card_id = c.id
            LEFT JOIN sets s ON c.set_id = s.id
            WHERE o.created_at BETWEEN ? AND ?";
        
        $logParams = [$startDate, $endDate];
        if (!empty($search)) {
            $logSql .= " AND (o.customer_name LIKE ? OR o.id::text LIKE ?)";
            $logParams[] = "%$search%";
            $logParams[] = "%$search%";
        }
        $logSql .= " ORDER BY o.created_at DESC LIMIT 100";

        $stmtLog = $pdo->prepare($logSql);
        $stmtLog->execute($logParams);
        $salesLog = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    $ordersReport = [];
    $grossRevenue = 0; $netRevenue = 0; $totalOrdersCount = 0; $refundedCount = 0; $refundedAmount = 0;
    $totalCosignedRevenue = 0; $totalNonCosignedRevenue = 0; $totalCogs = 0; $totalPackagingSpent = 0; $netProfitMargin = 0;
    $salesLog = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales, Costing & Reports | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap');
        body { font-family: 'Space Grotesk', sans-serif; background-color: #0b0b0e; color: #f4f4f5; }
        .tr-panel { background: rgba(18, 15, 29, 0.6); border: 1px solid rgba(147, 51, 234, 0.3); border-radius: 1rem; backdrop-filter: blur(12px); }
        .purple-btn { background: linear-gradient(135deg, #a855f7 0%, #7e22ce 50%, #6b21a8 100%); }

        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(100%) brightness(80%) sepia(100%) hue-rotate(200deg);
            cursor: pointer;
        }

        @media print {
            body { background: white !important; color: black !important; }
            .no-print { display: none !important; }
            .tr-panel { background: white !important; border: 1px solid #ccc !important; color: black !important; box-shadow: none !important; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 6px; color: black !important; }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col bg-[#0b0b0e] text-zinc-100 p-6 lg:p-8">

    <div class="no-print">
        <?php include 'includes/header.php'; ?>
    </div>

    <div class="max-w-[1600px] mx-auto w-full flex-1 flex flex-col space-y-6">
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center pb-4 border-b border-purple-900/40 gap-4">
            <div>
                <h1 class="text-xl font-bold tracking-wider text-purple-200 uppercase">Sales, Costing & Refund Reports Dashboard</h1>
                <p class="text-xs text-purple-300/60 mt-0.5">Comprehensive financial tracking, COGS analytics, co-signed sales, and custom packaging deductions.</p>
            </div>
            <div class="flex items-center space-x-3 no-print">
                <button onclick="openPackagingModal()" class="bg-blue-950/60 hover:bg-blue-900/50 border border-blue-800/40 text-blue-200 text-xs font-semibold px-4 py-2 rounded-lg transition-colors flex items-center space-x-2">
                    <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <span>Manage Packaging Deductions</span>
                </button>
                <button onclick="copyReportAsPNG()" id="copyBtn" class="bg-emerald-950/60 hover:bg-emerald-900/50 border border-emerald-800/40 text-emerald-200 text-xs font-semibold px-4 py-2 rounded-lg transition-colors flex items-center space-x-2">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                    <span id="copyBtnText">Copy as PNG</span>
                </button>
                <button onclick="window.print()" class="bg-purple-950/60 hover:bg-purple-900/50 border border-purple-800/40 text-purple-200 text-xs font-semibold px-4 py-2 rounded-lg transition-colors flex items-center space-x-2">
                    <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    <span>Print Report</span>
                </button>
            </div>
        </header>

        <!-- Filters Form -->
        <form method="GET" class="tr-panel p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-8 gap-3 items-end no-print">
            <input type="hidden" name="view_mode" id="viewModeInput" value="<?php echo htmlspecialchars($viewMode); ?>">

            <div>
                <label class="block text-[11px] font-semibold text-purple-300/80 uppercase mb-1">Search Customer / ID</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Customer name or Order #" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-2 text-xs text-white focus:outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-purple-300/80 uppercase mb-1">Time Range Preset</label>
                <select name="range" id="rangeSelect" onchange="switchToCustomRange()" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-2 text-xs text-white focus:outline-none">
                    <option value="daily" <?php echo $rangeFilter === 'daily' ? 'selected' : ''; ?>>Daily (Today)</option>
                    <option value="weekly" <?php echo $rangeFilter === 'weekly' ? 'selected' : ''; ?>>Weekly (This Week)</option>
                    <option value="monthly" <?php echo $rangeFilter === 'monthly' ? 'selected' : ''; ?>>Monthly (This Month)</option>
                    <option value="annual" <?php echo $rangeFilter === 'annual' ? 'selected' : ''; ?>>Annual (This Year)</option>
                    <option value="custom" <?php echo $rangeFilter === 'custom' ? 'selected' : ''; ?>>Custom Range Mode</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-purple-300/80 uppercase mb-1">Status Filter</label>
                <select name="status" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-2 text-xs text-white focus:outline-none">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="Profiting" <?php echo $statusFilter === 'Profiting' ? 'selected' : ''; ?>>Profiting Orders Only (Excl. Refunds)</option>
                    <option value="Paid" <?php echo $statusFilter === 'Paid' ? 'selected' : ''; ?>>All Paid Orders</option>
                    <option value="Refunded" <?php echo $statusFilter === 'Refunded' ? 'selected' : ''; ?>>Refunded / Restocked</option>
                    <option value="Unpaid" <?php echo $statusFilter === 'Unpaid' ? 'selected' : ''; ?>>Unpaid / Pending</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-purple-300/80 uppercase mb-1">Ownership Filter</label>
                <select name="ownership" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-2 text-xs text-white focus:outline-none">
                    <option value="all" <?php echo $ownershipFilter === 'all' ? 'selected' : ''; ?>>All Inventories</option>
                    <option value="cosigned" <?php echo $ownershipFilter === 'cosigned' ? 'selected' : ''; ?>>Co-signed / Co-partner</option>
                    <option value="non_cosigned" <?php echo $ownershipFilter === 'non_cosigned' ? 'selected' : ''; ?>>Non-Cosigned (Store Direct)</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-purple-300/80 uppercase mb-1">Sort By</label>
                <select name="sort" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-2 text-xs text-white focus:outline-none">
                    <option value="date_desc" <?php echo $sortBy === 'date_desc' ? 'selected' : ''; ?>>Date (Newest First)</option>
                    <option value="date_asc" <?php echo $sortBy === 'date_asc' ? 'selected' : ''; ?>>Date (Oldest First)</option>
                    <option value="amount_desc" <?php echo $sortBy === 'amount_desc' ? 'selected' : ''; ?>>Amount (High to Low)</option>
                    <option value="amount_asc" <?php echo $sortBy === 'amount_asc' ? 'selected' : ''; ?>>Amount (Low to High)</option>
                </select>
            </div>

            <!-- STARTING DATE Interactive Date Picker -->
            <div>
                <label class="block text-[11px] font-semibold text-purple-300/80 uppercase mb-1">Starting Date</label>
                <input type="date" name="start_date" id="startDateInput" value="<?php echo htmlspecialchars($customStart); ?>" onchange="switchToCustomRange()" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-2 text-xs text-white focus:outline-none cursor-pointer">
            </div>

            <!-- END DATE Interactive Date Picker -->
            <div>
                <label class="block text-[11px] font-semibold text-purple-300/80 uppercase mb-1">End Date</label>
                <input type="date" name="end_date" id="endDateInput" value="<?php echo htmlspecialchars($customEnd); ?>" onchange="switchToCustomRange()" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3 py-2 text-xs text-white focus:outline-none cursor-pointer">
            </div>

            <div class="flex justify-end space-x-2 pt-2">
                <a href="sales_reports.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs px-4 py-2 rounded-lg text-center">Reset</a>
                <button type="submit" class="purple-btn text-white text-xs font-semibold px-6 py-2 rounded-lg">Apply Filters</button>
            </div>
        </form>

        <div id="printableReportArea" class="space-y-6">
            <!-- Financial & Costing Metrics Grid (Reorganized Order) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-4">
                <div class="tr-panel p-4 flex flex-col justify-between">
                    <span class="text-xs text-purple-300/70 font-semibold uppercase">Total Card COGS</span>
                    <div class="text-2xl font-bold text-amber-400 mt-2">₱<?php echo number_format($totalCogs, 2); ?></div>
                    <span class="text-[10px] text-zinc-400 mt-1">Card buy costs</span>
                </div>
                <div class="tr-panel p-4 flex flex-col justify-between">
                    <span class="text-xs text-purple-300/70 font-semibold uppercase">Packaging Overhead</span>
                    <div class="text-2xl font-bold text-blue-400 mt-2">₱<?php echo number_format($totalPackagingSpent, 2); ?></div>
                    <span class="text-[10px] text-zinc-400 mt-1">₱<?php echo number_format($perOrderFee, 2); ?> × <?php echo $totalOrdersCount; ?> orders</span>
                </div>
                <div class="tr-panel p-4 flex flex-col justify-between">
                    <span class="text-xs text-purple-300/70 font-semibold uppercase">Refunds & Voids</span>
                    <div class="text-2xl font-bold text-rose-400 mt-2">₱<?php echo number_format($refundedAmount, 2); ?></div>
                    <span class="text-[10px] text-zinc-400 mt-1"><?php echo $refundedCount; ?> refunded orders</span>
                </div>
                <div class="tr-panel p-4 flex flex-col justify-between">
                    <span class="text-xs text-purple-300/70 font-semibold uppercase">Co-Signed Sales</span>
                    <div class="text-2xl font-bold text-fuchsia-400 mt-2">₱<?php echo number_format($totalCosignedRevenue, 2); ?></div>
                    <span class="text-[10px] text-zinc-400 mt-1">Co-partner pool total</span>
                </div>
                <div class="tr-panel p-4 flex flex-col justify-between">
                    <span class="text-xs text-purple-300/70 font-semibold uppercase">Non-Cosigned Sales</span>
                    <div class="text-2xl font-bold text-teal-400 mt-2">₱<?php echo number_format($totalNonCosignedRevenue, 2); ?></div>
                    <span class="text-[10px] text-zinc-400 mt-1">Store direct sales</span>
                </div>
                <div class="tr-panel p-4 flex flex-col justify-between bg-purple-950/20 border border-purple-500/40">
                    <span class="text-xs text-purple-200 font-semibold uppercase">Net Profit Margin</span>
                    <div class="text-2xl font-bold text-purple-300 mt-2">₱<?php echo number_format($netProfitMargin, 2); ?></div>
                    <span class="text-[10px] text-purple-300/60 mt-1">Revenue - COGS - Packaging</span>
                </div>
                <div class="tr-panel p-4 flex flex-col justify-between">
                    <span class="text-xs text-purple-300/70 font-semibold uppercase">Gross Revenue</span>
                    <div class="text-2xl font-bold text-emerald-400 mt-2">₱<?php echo number_format($grossRevenue, 2); ?></div>
                    <span class="text-[10px] text-zinc-400 mt-1"><?php echo $totalOrdersCount; ?> total orders</span>
                </div>
            </div>

            <!-- Itemized Costing & Transaction Logs Table with View Mode Toggle -->
            <div class="tr-panel p-5 space-y-4">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-purple-200">Itemized Costing & Transaction Logs</h3>
                        <span class="text-[10px] text-zinc-400">Range: <?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?></span>
                    </div>
                    <!-- View Mode Toggle Buttons -->
                    <div class="flex items-center space-x-2 no-print bg-[#0a0810] border border-purple-900/40 p-1 rounded-lg">
                        <button type="button" onclick="setViewMode('items')" class="<?php echo $viewMode === 'items' ? 'purple-btn text-white font-bold' : 'text-purple-300/70 hover:text-white'; ?> text-[11px] px-3 py-1.5 rounded-md transition-all">View per Item</button>
                        <button type="button" onclick="setViewMode('orders')" class="<?php echo $viewMode === 'orders' ? 'purple-btn text-white font-bold' : 'text-purple-300/70 hover:text-white'; ?> text-[11px] px-3 py-1.5 rounded-md transition-all">View per Order</button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-purple-900/40 text-purple-300/70 uppercase text-[10px]">
                                <th class="py-3 px-3">Order ID</th>
                                <th class="py-3 px-3">Customer Name</th>
                                <?php if ($viewMode === 'orders'): ?>
                                    <th class="py-3 px-3">Date</th>
                                    <th class="py-3 px-3">Status</th>
                                    <th class="py-3 px-3 text-center">Total Items</th>
                                <?php else: ?>
                                    <th class="py-3 px-3">Card item/type</th>
                                    <th class="py-3 px-3 text-center">Qty</th>
                                <?php endif; ?>
                                <th class="py-3 px-3 text-right">Card cost</th>
                                <th class="py-3 px-3 text-right">Selling price</th>
                                <th class="py-3 px-3 text-right">Material cost</th>
                                <th class="py-3 px-3 text-right">Estimated profit</th>
                                <th class="py-3 px-3 text-right">Total price</th>
                                <?php if ($viewMode === 'items'): ?>
                                    <th class="py-3 px-3">Date</th>
                                <?php endif; ?>
                                <th class="py-3 px-3 text-center no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-purple-900/20">
                            <?php if (count($salesLog) > 0): ?>
                                <?php foreach ($salesLog as $row): ?>
                                    <?php if ($viewMode === 'orders'): 
                                        $orderBuyCost = floatval($row['order_cogs']);
                                        $orderSellPrice = floatval($row['order_sell_price']);
                                        $orderMaterialCost = floatval($row['packaging_cost']);
                                        $orderProfit = ($orderSellPrice - $orderBuyCost);
                                        
                                        $payStatus = $row['payment_status'] ?? 'Unpaid';
                                        $fulStatus = $row['fulfillment_status'] ?? 'Pending';
                                        $statusBadgeClass = 'bg-zinc-800 text-zinc-300';
                                        $statusText = $payStatus . ' / ' . $fulStatus;

                                        if ($fulStatus === 'Restocked' || $fulStatus === 'Voided' || $payStatus === 'Refunded') {
                                            $statusBadgeClass = 'bg-rose-950/60 border border-rose-800/40 text-rose-300';
                                        } else if ($payStatus === 'Paid') {
                                            $statusBadgeClass = 'bg-emerald-950/60 border border-emerald-800/40 text-emerald-300';
                                        }
                                    ?>
                                        <tr class="hover:bg-purple-950/25 transition-colors">
                                            <td class="py-3 px-3 font-bold text-white">#<?php echo $row['order_id']; ?></td>
                                            <td class="py-3 px-3 text-purple-200"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                            <td class="py-3 px-3 text-zinc-400 text-[11px]"><?php echo $row['created_at']; ?></td>
                                            <td class="py-3 px-3">
                                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold inline-block <?php echo $statusBadgeClass; ?>">
                                                    <?php echo htmlspecialchars($statusText); ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-3 text-center font-semibold text-purple-300"><?php echo $row['total_qty']; ?> items</td>
                                            <td class="py-3 px-3 text-right text-amber-400">₱<?php echo number_format($orderBuyCost, 2); ?></td>
                                            <td class="py-3 px-3 text-right text-emerald-400">₱<?php echo number_format($orderSellPrice, 2); ?></td>
                                            <td class="py-3 px-3 text-right text-blue-400">₱<?php echo number_format($orderMaterialCost, 2); ?></td>
                                            <td class="py-3 px-3 text-right font-bold text-purple-300">₱<?php echo number_format($orderProfit, 2); ?></td>
                                            <td class="py-3 px-3 text-right font-bold text-emerald-300">₱<?php echo number_format(floatval($row['total_amount']), 2); ?></td>
                                            <td class="py-3 px-3 text-center space-x-1 no-print">
                                                <button onclick="viewOrderDetails(<?php echo $row['order_id']; ?>)" class="bg-purple-900/40 hover:bg-purple-800/60 text-purple-200 px-2 py-1 rounded text-[10px] font-semibold transition-colors">Details</button>
                                                <button onclick="viewReceipt(<?php echo $row['order_id']; ?>)" class="bg-indigo-900/40 hover:bg-indigo-800/60 text-indigo-200 px-2 py-1 rounded text-[10px] font-semibold transition-colors">Receipt</button>
                                            </td>
                                        </tr>
                                    <?php else: 
                                        $itemBuyCost = $row['buy_price'] * $row['quantity'];
                                        $itemSellPrice = $row['sell_price'] * $row['quantity'];
                                        $itemMaterialCost = floatval($row['packaging_cost']);
                                        $itemProfit = ($itemSellPrice - $itemBuyCost);
                                    ?>
                                        <tr class="hover:bg-purple-950/25 transition-colors">
                                            <td class="py-3 px-3 font-bold text-white">#<?php echo $row['order_id']; ?></td>
                                            <td class="py-3 px-3 text-purple-200"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                            <td class="py-3 px-3 text-purple-200">
                                                <?php echo htmlspecialchars($row['card_name']); ?> <span class="text-zinc-500 font-normal">#<?php echo htmlspecialchars($row['card_number'] ?? ''); ?></span>
                                                <?php if ($row['is_cosigned'] == 1): ?>
                                                    <span class="ml-1.5 bg-amber-950/60 border border-amber-700/40 text-amber-300 px-1.5 py-0.5 rounded text-[9px] font-bold">Co-signed</span>
                                                <?php else: ?>
                                                    <span class="ml-1.5 bg-zinc-800 text-zinc-400 px-1.5 py-0.5 rounded text-[9px]">Store</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-3 text-center"><?php echo $row['quantity']; ?></td>
                                            <td class="py-3 px-3 text-right text-amber-400">₱<?php echo number_format($itemBuyCost, 2); ?></td>
                                            <td class="py-3 px-3 text-right text-emerald-400">₱<?php echo number_format($itemSellPrice, 2); ?></td>
                                            <td class="py-3 px-3 text-right text-blue-400">₱<?php echo number_format($itemMaterialCost, 2); ?></td>
                                            <td class="py-3 px-3 text-right font-bold text-purple-300">₱<?php echo number_format($itemProfit, 2); ?></td>
                                            <td class="py-3 px-3 text-right font-bold text-emerald-300">₱<?php echo number_format($itemSellPrice, 2); ?></td>
                                            <td class="py-3 px-3 text-zinc-400 text-[11px]"><?php echo $row['created_at']; ?></td>
                                            <td class="py-3 px-3 text-center space-x-1 no-print">
                                                <button onclick="viewOrderDetails(<?php echo $row['order_id']; ?>)" class="bg-purple-900/40 hover:bg-purple-800/60 text-purple-200 px-2 py-1 rounded text-[10px] font-semibold transition-colors">Details</button>
                                                <button onclick="viewReceipt(<?php echo $row['order_id']; ?>)" class="bg-indigo-900/40 hover:bg-indigo-800/60 text-indigo-200 px-2 py-1 rounded text-[10px] font-semibold transition-colors">Receipt</button>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center py-10 text-purple-300/40">No costing logs found matching your filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Packaging Fee Deduction Module Modal -->
    <div id="packagingModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="tr-panel w-full max-w-md p-6 space-y-4 relative bg-[#120f1d] border border-blue-500/30 rounded-xl">
            <div class="flex justify-between items-center pb-3 border-b border-purple-900/40">
                <h3 class="text-sm font-bold text-blue-300 uppercase">Packaging Fee Deduction Module</h3>
                <button onclick="closePackagingModal()" class="text-zinc-400 hover:text-white text-xs font-bold">✕</button>
            </div>
            <p class="text-xs text-zinc-300">
                Set the default persistent packaging overhead deduction per order (e.g., 5.00). This multiplies across total orders to accurately factor into co-partner earnings and material deductions:
            </p>
            <form method="GET" action="sales_reports.php" class="space-y-4">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" name="range" value="<?php echo htmlspecialchars($rangeFilter); ?>">
                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($customStart); ?>">
                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($customEnd); ?>">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <input type="hidden" name="ownership" value="<?php echo htmlspecialchars($ownershipFilter); ?>">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                <input type="hidden" name="view_mode" value="<?php echo htmlspecialchars($viewMode); ?>">

                <div>
                    <label class="block text-[11px] font-semibold text-blue-300/80 uppercase mb-1">Packaging Fee Per Order (₱)</label>
                    <input type="number" step="0.01" name="packaging_deduction" value="<?php echo htmlspecialchars($packagingOverride ?? 5.00); ?>" required class="w-full bg-[#0a0810] border border-blue-900/40 rounded-lg px-3 py-2 text-xs text-white focus:outline-none">
                </div>

                <div class="flex justify-end space-x-2 pt-2">
                    <button type="button" onclick="closePackagingModal()" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs px-4 py-2 rounded-lg">Cancel</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold px-4 py-2 rounded-lg">Save & Apply Persistent Fee</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div id="orderDetailsModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="tr-panel w-full max-w-lg p-6 space-y-4 relative bg-[#120f1d] border border-purple-500/30 rounded-xl">
            <div class="flex justify-between items-center pb-3 border-b border-purple-900/40">
                <h3 class="text-sm font-bold text-purple-200 uppercase">Order #<span id="det_order_id"></span> Items Breakdown</h3>
                <button onclick="closeDetailsModal()" class="text-zinc-400 hover:text-white text-xs font-bold">✕</button>
            </div>
            <div class="overflow-x-auto max-h-64">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-purple-300/70 border-b border-purple-900/30 text-[10px] uppercase">
                            <th class="py-2">Item Name</th>
                            <th class="py-2 text-center">Qty</th>
                            <th class="py-2 text-right">Price</th>
                            <th class="py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody id="det_items_list" class="divide-y divide-purple-900/20">
                        <tr><td colspan="4" class="text-center py-4 text-zinc-400">Loading items...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end pt-2">
                <button onclick="closeDetailsModal()" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs px-4 py-2 rounded-lg">Close</button>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-white text-black w-full max-w-sm p-6 space-y-4 relative rounded-xl shadow-2xl">
            <div class="text-center pb-3 border-b border-zinc-200">
                <h2 class="font-bold text-base tracking-wide uppercase">Trade Rocket TCG</h2>
                <p class="text-[10px] text-zinc-500">Official Sales Receipt / Invoice</p>
            </div>
            <div class="text-xs space-y-1">
                <div><strong>Order ID:</strong> #<span id="rec_order_id"></span></div>
                <div><strong>Customer:</strong> <span id="rec_customer"></span></div>
                <div><strong>Date:</strong> <span id="rec_date"></span></div>
                <div><strong>Status:</strong> <span id="rec_status"></span></div>
            </div>
            <div class="border-t border-b border-zinc-200 py-2">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-[10px] text-zinc-500 uppercase border-b border-zinc-100">
                            <th class="pb-1">Item</th>
                            <th class="pb-1 text-center">Qty</th>
                            <th class="pb-1 text-right">Price</th>
                            <th class="pb-1 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody id="rec_items_list" class="divide-y divide-zinc-100">
                        <tr><td colspan="4" class="text-center py-4 text-zinc-400">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="space-y-1 text-xs pt-1">
                <div class="flex justify-between" id="rec_material_row">
                    <span class="text-zinc-600">Material Cost (Packaging):</span>
                    <span id="rec_material_cost" class="font-medium">₱0.00</span>
                </div>
                <div class="flex justify-between font-bold text-sm border-t border-zinc-200 pt-1">
                    <span>Total Amount:</span>
                    <span id="rec_total">₱0.00</span>
                </div>
            </div>
            <div class="flex justify-end space-x-2 pt-3">
                <button onclick="window.print()" class="bg-zinc-800 text-white text-xs px-3 py-1.5 rounded-lg">Print</button>
                <button onclick="closeReceiptModal()" class="bg-zinc-200 hover:bg-zinc-300 text-zinc-800 text-xs px-3 py-1.5 rounded-lg">Close</button>
            </div>
        </div>
    </div>

    <script>
        function setViewMode(mode) {
            document.getElementById('viewModeInput').value = mode;
            document.getElementById('viewModeInput').form.submit();
        }

        function switchToCustomRange() {
            const rangeSelect = document.getElementById('rangeSelect');
            if (rangeSelect) {
                rangeSelect.value = 'custom';
            }
        }

        function openPackagingModal() {
            document.getElementById('packagingModal').classList.remove('hidden');
        }

        function closePackagingModal() {
            document.getElementById('packagingModal').classList.add('hidden');
        }

        async function viewOrderDetails(orderId) {
            document.getElementById('det_order_id').innerText = orderId;
            document.getElementById('det_items_list').innerHTML = '<tr><td colspan="4" class="text-center py-4 text-zinc-400">Loading items...</td></tr>';
            document.getElementById('orderDetailsModal').classList.remove('hidden');

            try {
                const res = await fetch(`orders_process.php?ajax_action=get_order_details&order_id=${orderId}`);
                const data = await res.json();
                if (data.success) {
                    let html = '';
                    data.items.forEach(item => {
                        const sub = item.quantity * item.price;
                        html += `
                            <tr class="border-b border-purple-900/10">
                                <td class="py-2 text-purple-200">${item.card_name} <span class="text-[10px] text-zinc-400 block">${item.set_name || ''}</span></td>
                                <td class="py-2 text-center text-zinc-300">${item.quantity}</td>
                                <td class="py-2 text-right text-zinc-300">₱${parseFloat(item.price).toFixed(2)}</td>
                                <td class="py-2 text-right font-bold text-emerald-400">₱${sub.toFixed(2)}</td>
                            </tr>
                        `;
                    });
                    document.getElementById('det_items_list').innerHTML = html || '<tr><td colspan="4" class="text-center py-4 text-zinc-400">No items found.</td></tr>';
                }
            } catch (err) {
                document.getElementById('det_items_list').innerHTML = '<tr><td colspan="4" class="text-center py-4 text-rose-400">Network error fetching details.</td></tr>';
            }
        }

        function closeDetailsModal() {
            document.getElementById('orderDetailsModal').classList.add('hidden');
        }

        async function viewReceipt(orderId) {
            document.getElementById('rec_items_list').innerHTML = '<tr><td colspan="4" class="text-center py-4 text-zinc-400">Loading...</td></tr>';
            document.getElementById('receiptModal').classList.remove('hidden');

            try {
                const res = await fetch(`orders_process.php?ajax_action=get_order_details&order_id=${orderId}`);
                const data = await res.json();
                if (data.success) {
                    document.getElementById('rec_order_id').innerText = data.order.id;
                    document.getElementById('rec_customer').innerText = data.order.customer_name;
                    document.getElementById('rec_date').innerText = data.order.created_at;
                    document.getElementById('rec_status').innerText = data.order.payment_status + ' / ' + data.order.fulfillment_status;
                    document.getElementById('rec_material_cost').innerText = '₱' + parseFloat(data.order.packaging_cost || 0).toFixed(2);
                    document.getElementById('rec_total').innerText = '₱' + parseFloat(data.order.total_amount).toFixed(2);

                    let html = '';
                    data.items.forEach(item => {
                        const sub = item.quantity * item.price;
                        html += `
                            <tr>
                                <td class="py-2 font-medium">${item.card_name}</td>
                                <td class="py-2 text-center">${item.quantity}</td>
                                <td class="py-2 text-right">₱${parseFloat(item.price).toFixed(2)}</td>
                                <td class="py-2 text-right font-bold">₱${sub.toFixed(2)}</td>
                            </tr>
                        `;
                    });
                    document.getElementById('rec_items_list').innerHTML = html;
                }
            } catch (err) {
                alert('Error loading receipt data.');
            }
        }

        function closeReceiptModal() {
            document.getElementById('receiptModal').classList.add('hidden');
        }

        async function copyReportAsPNG() {
            const btnText = document.getElementById('copyBtnText');
            const reportArea = document.getElementById('printableReportArea');
            
            btnText.innerText = "Capturing...";

            try {
                const canvas = await html2canvas(reportArea, {
                    scale: 2,
                    backgroundColor: '#0b0b0e',
                    logging: false
                });

                canvas.toBlob(async (blob) => {
                    try {
                        await navigator.clipboard.write([
                            new ClipboardItem({ 'image/png': blob })
                        ]);
                        btnText.innerText = "Copied to Clipboard!";
                        setTimeout(() => { btnText.innerText = "Copy as PNG"; }, 3000);
                    } catch (clipErr) {
                        alert('Clipboard write failed.');
                        btnText.innerText = "Copy as PNG";
                    }
                });
            } catch (err) {
                alert('Failed to generate image snapshot.');
                btnText.innerText = "Copy as PNG";
            }
        }
    </script>
</body>
</html>