<?php
// orders_process.php - 5-Stage Order Processing & Fulfillment Module
session_start();
require_once __DIR__ . '/config/database.php';

$userId = $_SESSION['user_id'] ?? 1;
$error = '';
$success = '';

// Handle AJAX Request to fetch Invoice/Receipt Data
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_order_details') {
    header('Content-Type: application/json');
    try {
        $orderId = intval($_GET['order_id']);
        
        // Fetch order info
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch itemized order details
        $stmtItems = $pdo->prepare("
            SELECT oi.quantity, oi.price, c.name as card_name, c.card_number, s.name as set_name
            FROM order_items oi
            LEFT JOIN inventory_stocks i ON oi.card_id = i.id
            LEFT JOIN cards c ON i.card_id = c.id
            LEFT JOIN sets s ON c.set_id = s.id
            WHERE oi.order_id = ?
        ");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'order' => $order, 'items' => $items]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle Actions for the 5 Stages or POS AJAX Checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Handle POS Checkout Submission
        if (isset($_POST['customer_name']) && isset($_POST['items'])) {
            $customerName = trim($_POST['customer_name']);
            $rawItems = $_POST['items'];

            if (empty($customerName)) {
                throw new Exception("Customer name is required.");
            }
            if (empty($rawItems) || !is_array($rawItems)) {
                throw new Exception("No items provided in the cart.");
            }

            $pdo->beginTransaction();

            $totalAmount = 0;
            $validatedItems = [];

            foreach ($rawItems as $item) {
                $productId = intval($item['id']);
                $qty = intval($item['quantity']);

                if ($qty <= 0) continue;

                $prodStmt = $pdo->prepare("SELECT price, quantity AS stock_quantity FROM inventory_stocks WHERE id = ?");
                $prodStmt->execute([$productId]);
                $product = $prodStmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    throw new Exception("Product/Card ID {$productId} not found in inventory.");
                }

                if ($product['stock_quantity'] < $qty) {
                    throw new Exception("Insufficient stock for product ID {$productId}.");
                }

                $subtotal = $product['price'] * $qty;
                $totalAmount += $subtotal;

                $validatedItems[] = [
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'price' => $product['price']
                ];
            }

            if (empty($validatedItems)) {
                throw new Exception("Valid cart items are required.");
            }

            // Insert into orders table with fulfillment_status = 'Pending' (New Orders)
            $orderStmt = $pdo->prepare("INSERT INTO orders (customer_name, total_amount, payment_status, fulfillment_status, created_at) VALUES (?, ?, 'Unpaid', 'Pending', NOW())");
            $orderStmt->execute([$customerName, $totalAmount]);

            $orderId = $pdo->lastInsertId('orders_id_seq');

            foreach ($validatedItems as $vItem) {
                $itemInsert = $pdo->prepare("INSERT INTO order_items (order_id, card_id, quantity, price, created_at) VALUES (?, ?, ?, ?, NOW())");
                $itemInsert->execute([$orderId, $vItem['product_id'], $vItem['quantity'], $vItem['price']]);

                $stockUpdate = $pdo->prepare("UPDATE inventory_stocks SET quantity = quantity - ? WHERE id = ?");
                $stockUpdate->execute([$vItem['quantity'], $vItem['product_id']]);
            }

            $pdo->commit();

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                echo json_encode(['success' => true, 'order_id' => $orderId]);
                exit;
            }

            $success = "Order #{$orderId} successfully processed from POS!";
        } elseif (isset($_POST['action'])) {
            $action = $_POST['action'];

            if ($action === 'cancel_order') {
                $orderId = intval($_POST['order_id']);
                $pdo->beginTransaction();

                $itemsStmt = $pdo->prepare("SELECT card_id AS product_id, quantity FROM order_items WHERE order_id = ?");
                $itemsStmt->execute([$orderId]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $restockStmt = $pdo->prepare("UPDATE inventory_stocks SET quantity = quantity + ? WHERE id = ?");
                    $restockStmt->execute([$item['quantity'], $item['product_id']]);
                }

                $delStmt = $pdo->prepare("UPDATE orders SET fulfillment_status = 'Cancelled', payment_status = 'Voided' WHERE id = ?");
                $delStmt->execute([$orderId]);

                $pdo->commit();
                $success = "Order #{$orderId} was cancelled and stocks have been replenished.";
            } elseif ($action === 'process_payment') {
                $orderId = intval($_POST['order_id']);
                $amountPaid = floatval($_POST['amount_paid']);
                $totalAmount = floatval($_POST['total_amount']);

                if ($amountPaid < $totalAmount) {
                    throw new Exception("Insufficient payment amount provided.");
                }

                $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'Paid' WHERE id = ?");
                $stmt->execute([$orderId]);

                $success = "Payment confirmed for Order #{$orderId}!";
            } elseif ($action === 'stash_order') {
                $orderId = intval($_POST['order_id']);
                $stmt = $pdo->prepare("UPDATE orders SET fulfillment_status = 'Stashed' WHERE id = ?");
                $stmt->execute([$orderId]);
                $success = "Order #{$orderId} has been moved to Stashed Orders.";
            } elseif ($action === 'to_checkout_order') {
                $orderId = intval($_POST['order_id']);
                $stmt = $pdo->prepare("UPDATE orders SET fulfillment_status = 'Checkout' WHERE id = ?");
                $stmt->execute([$orderId]);
                $success = "Order #{$orderId} has been moved to Checkout Orders.";
            } elseif ($action === 'prepare_shipment') {
                $orderId = intval($_POST['order_id']);
                $stmt = $pdo->prepare("UPDATE orders SET fulfillment_status = 'Prepare Order' WHERE id = ?");
                $stmt->execute([$orderId]);
                $success = "Order #{$orderId} moved to Shipments.";
            } elseif ($action === 'order_shipped') {
                $orderId = intval($_POST['order_id']);
                $stmt = $pdo->prepare("UPDATE orders SET fulfillment_status = 'Shipped' WHERE id = ?");
                $stmt->execute([$orderId]);
                $success = "Order #{$orderId} marked as shipped.";
            } elseif ($action === 'order_received') {
                $orderId = intval($_POST['order_id']);
                $stmt = $pdo->prepare("UPDATE orders SET fulfillment_status = 'Completed' WHERE id = ?");
                $stmt->execute([$orderId]);
                $success = "Order #{$orderId} marked as completed.";
            } elseif ($action === 'order_returned') {
                $orderId = intval($_POST['order_id']);
                $stmt = $pdo->prepare("UPDATE orders SET fulfillment_status = 'Returned' WHERE id = ?");
                $stmt->execute([$orderId]);
                $success = "Order #{$orderId} marked as returned.";
            } elseif ($action === 'void_order') {
                $orderId = intval($_POST['order_id']);
                $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'Refunded', fulfillment_status = 'Voided' WHERE id = ?");
                $stmt->execute([$orderId]);
                $success = "Order #{$orderId} has been voided.";
            } elseif ($action === 'restock_order') {
                $orderId = intval($_POST['order_id']);
                $pdo->beginTransaction();

                $itemsStmt = $pdo->prepare("SELECT card_id AS product_id, quantity FROM order_items WHERE order_id = ?");
                $itemsStmt->execute([$orderId]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $restockStmt = $pdo->prepare("UPDATE inventory_stocks SET quantity = quantity + ? WHERE id = ?");
                    $restockStmt->execute([$item['quantity'], $item['product_id']]);
                }

                $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'Refunded', fulfillment_status = 'Restocked' WHERE id = ?");
                $stmt->execute([$orderId]);

                $pdo->commit();
                $success = "Order #{$orderId} items restocked successfully.";
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Action failed: " . $e->getMessage();
    }
}

// Fetch active orders
$ordersQuery = $pdo->query("SELECT o.*, o.total_amount as calc_total FROM orders o WHERE o.fulfillment_status NOT IN ('Cancelled', 'Completed', 'Voided', 'Restocked') ORDER BY o.created_at DESC LIMIT 100");
$orders = $ordersQuery->fetchAll(PDO::FETCH_ASSOC);

// Separate orders into 5 specific stages
$newOrders = array_filter($orders, function ($o) {
    return in_array($o['fulfillment_status'], ['Pending', 'New']);
});
$stashedOrders = array_filter($orders, function ($o) {
    return ($o['fulfillment_status'] === 'Stashed');
});
$checkoutOrders = array_filter($orders, function ($o) {
    return ($o['fulfillment_status'] === 'Checkout');
});
$shipmentsOrders = array_filter($orders, function ($o) {
    return in_array($o['fulfillment_status'], ['Prepare Order', 'Shipped']);
});
$returnedOrders = array_filter($orders, function ($o) {
    return ($o['fulfillment_status'] === 'Returned');
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Processing Module (5 Stages) | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap');
        body { font-family: 'Space Grotesk', sans-serif; background-color: #0b0b0e; color: #f4f4f5; }
        .tr-panel { background: rgba(18, 15, 29, 0.6); border: 1px solid rgba(147, 51, 234, 0.3); border-radius: 1rem; backdrop-filter: blur(12px); }
        .purple-btn { background: linear-gradient(135deg, #a855f7 0%, #7e22ce 50%, #6b21a8 100%); }

        @media print {
            body * { visibility: hidden; }
            .printable-area, .printable-area * { visibility: visible; }
            .printable-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                background: white !important;
                color: black !important;
                padding: 20px;
                margin: 0;
            }
            .no-print { display: none !important; }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body class="min-h-screen flex flex-col bg-[#0b0b0e] text-zinc-100 p-6 lg:p-8">

    <?php include 'includes/header.php'; ?>

    <div class="max-w-[1600px] mx-auto w-full flex-1 flex flex-col space-y-6">
        <header class="flex justify-between items-center pb-4 border-b border-purple-900/40">
            <div>
                <h1 class="text-xl font-bold tracking-wider text-purple-200 uppercase">Order Processing Module (5 Stages)</h1>
                <p class="text-xs text-purple-300/60 mt-0.5">Manage New Orders, Stashed Orders, Checkout Orders, Shipments, and Returned Orders.</p>
            </div>
        </header>

        <?php if (!empty($error)): ?>
            <div class="p-4 rounded-xl border bg-rose-950/40 border-rose-500/30 text-rose-300 text-xs shadow-lg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="p-4 rounded-xl border bg-emerald-950/40 border-emerald-500/30 text-emerald-300 text-xs shadow-lg"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">

            <div class="tr-panel p-3.5 flex flex-col">
                <h3 class="text-xs font-bold uppercase tracking-wider text-amber-400 mb-3 flex justify-between items-center">
                    <span>1. New Orders</span>
                    <span class="text-xs bg-amber-950/60 text-amber-300 px-2 py-0.5 rounded-lg border border-amber-800/40"><?php echo count($newOrders); ?></span>
                </h3>
                <div class="space-y-3 flex-1 overflow-y-auto max-h-[600px]">
                    <?php if (count($newOrders) > 0): ?>
                        <?php foreach ($newOrders as $order):
                            $isPaid = ($order['payment_status'] === 'Paid');
                        ?>
                            <div class="bg-[#0a0810] p-3 rounded-xl border border-purple-900/35 text-xs space-y-2" data-order-id="<?php echo $order['id']; ?>">
                                <div class="flex justify-between font-bold">
                                    <span class="text-white">Order #<?php echo $order['id']; ?></span>
                                    <span class="text-emerald-400">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                </div>
                                <p class="text-purple-200/80">Customer: <strong class="customer-name"><?php echo htmlspecialchars($order['customer_name']); ?></strong></p>
                                <p class="text-[10px]">Status: <span class="<?php echo $isPaid ? 'text-emerald-400' : 'text-amber-400'; ?>"><?php echo $order['payment_status']; ?></span></p>

                                <div class="space-y-1.5 pt-2 border-t border-purple-900/30">
                                    
                                    <?php if (!$isPaid): ?>
                                        <button type="button" onclick="openInvoiceModal(<?php echo $order['id']; ?>)" class="w-full bg-[#120f1d] hover:bg-purple-950/40 text-purple-200 border border-purple-900/40 py-1.5 px-2 rounded text-center text-[11px] font-semibold transition-colors">
                                            Generate Invoice
                                        </button>
                                        <button onclick="openPaymentModal(<?php echo $order['id']; ?>, <?php echo $order['total_amount']; ?>)" class="w-full bg-emerald-950/60 hover:bg-emerald-900/60 text-emerald-200 border border-emerald-800/50 py-1.5 px-2 rounded text-[11px] text-center font-semibold transition-colors">
                                            Pay Order
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Cancel order and replenish stocks?');">
                                            <input type="hidden" name="action" value="cancel_order">
                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                            <button type="submit" class="w-full bg-rose-950/60 hover:bg-rose-900/60 text-rose-200 border border-rose-800/50 py-1.5 px-2 rounded text-[11px] text-center transition-colors">Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" onclick="openReceiptModal(<?php echo $order['id']; ?>)" class="w-full bg-[#120f1d] hover:bg-purple-950/40 text-emerald-400 border border-emerald-900/40 py-1.5 px-2 rounded text-center text-[11px] font-semibold transition-colors">
                                            View Receipt
                                        </button>
                                        <div class="grid grid-cols-2 gap-1.5">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="stash_order">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <button type="submit" class="w-full bg-blue-950/60 hover:bg-blue-900/60 text-blue-200 border border-blue-800/50 py-1.5 px-2 rounded text-[10px] text-center transition-colors">
                                                    Stash
                                                </button>
                                            </form>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="to_checkout_order">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <button type="submit" class="w-full bg-purple-950/60 hover:bg-purple-900/60 text-purple-200 border border-purple-800/50 py-1.5 px-2 rounded text-[10px] text-center transition-colors">
                                                    Checkout
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-10 text-purple-300/40 text-xs">No new orders.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tr-panel p-3.5 flex flex-col">
                <h3 class="text-xs font-bold uppercase tracking-wider text-purple-300 mb-3 flex justify-between items-center">
                    <span>2. Stashed Orders</span>
                    <span class="text-xs bg-purple-950/60 text-purple-300 px-2 py-0.5 rounded-lg border border-purple-800/40"><?php echo count($stashedOrders); ?></span>
                </h3>
                <div class="space-y-3 flex-1 overflow-y-auto max-h-[600px]">
                    <?php if (count($stashedOrders) > 0): ?>
                        <?php foreach ($stashedOrders as $order):
                            $isPaid = ($order['payment_status'] === 'Paid');
                        ?>
                            <div class="bg-[#0a0810] p-3 rounded-xl border border-purple-900/35 text-xs space-y-2" data-order-id="<?php echo $order['id']; ?>">
                                <div class="flex justify-between font-bold">
                                    <span class="text-white">Order #<?php echo $order['id']; ?></span>
                                    <span class="text-emerald-400">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                </div>
                                <p class="text-purple-200/80">Customer: <strong class="customer-name"><?php echo htmlspecialchars($order['customer_name']); ?></strong></p>

                                <div class="space-y-1.5 pt-2 border-t border-purple-900/30">
                                    <button type="button" onclick="<?php echo $isPaid ? 'openReceiptModal('.$order['id'].')' : 'openInvoiceModal('.$order['id'].')'; ?>" class="w-full bg-[#120f1d] hover:bg-purple-950/40 <?php echo $isPaid ? 'text-emerald-400 border-emerald-900/40' : 'text-purple-200 border-purple-900/40'; ?> border py-1.5 px-2 rounded text-center text-[11px] font-semibold transition-colors">
                                        <?php echo $isPaid ? 'View Receipt' : 'Generate Invoice'; ?>
                                    </button>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="to_checkout_order">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" class="w-full bg-purple-950/60 hover:bg-purple-900/60 text-purple-200 border border-purple-800/50 py-1.5 px-2 rounded text-[11px] transition-colors">
                                            Move to Checkout
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-10 text-purple-300/40 text-xs">No stashed orders.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tr-panel p-3.5 flex flex-col">
                <h3 class="text-xs font-bold uppercase tracking-wider text-fuchsia-400 mb-3 flex justify-between items-center">
                    <span>3. Checkout Orders</span>
                    <span class="text-xs bg-fuchsia-950/60 text-fuchsia-300 px-2 py-0.5 rounded-lg border border-fuchsia-800/40"><?php echo count($checkoutOrders); ?></span>
                </h3>
                <div class="space-y-3 flex-1 overflow-y-auto max-h-[600px]">
                    <?php if (count($checkoutOrders) > 0): ?>
                        <?php foreach ($checkoutOrders as $order):
                            $isPaid = ($order['payment_status'] === 'Paid');
                        ?>
                            <div class="bg-[#0a0810] p-3 rounded-xl border border-purple-900/35 text-xs space-y-2" data-order-id="<?php echo $order['id']; ?>">
                                <div class="flex justify-between font-bold">
                                    <span class="text-white">Order #<?php echo $order['id']; ?></span>
                                    <span class="text-emerald-400">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                </div>
                                <p class="text-purple-200/80">Customer: <strong class="customer-name"><?php echo htmlspecialchars($order['customer_name']); ?></strong></p>

                                <div class="space-y-1.5 pt-2 border-t border-purple-900/30">
                                    <button type="button" onclick="<?php echo $isPaid ? 'openReceiptModal('.$order['id'].')' : 'openInvoiceModal('.$order['id'].')'; ?>" class="w-full bg-[#120f1d] hover:bg-purple-950/40 <?php echo $isPaid ? 'text-emerald-400 border-emerald-900/40' : 'text-purple-200 border-purple-900/40'; ?> border py-1.5 px-2 rounded text-center text-[11px] font-semibold transition-colors">
                                        <?php echo $isPaid ? 'View Receipt' : 'Generate Invoice'; ?>
                                    </button>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="prepare_shipment">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" class="w-full bg-purple-950/60 hover:bg-purple-900/60 text-purple-200 border border-purple-800/50 py-1.5 px-2 rounded text-[11px] transition-colors">
                                            Send to Shipments
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-10 text-purple-300/40 text-xs">No checkout orders.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tr-panel p-3.5 flex flex-col">
                <h3 class="text-xs font-bold uppercase tracking-wider text-blue-400 mb-3 flex justify-between items-center">
                    <span>4. Shipments</span>
                    <span class="text-xs bg-blue-950/60 text-blue-300 px-2 py-0.5 rounded-lg border border-blue-800/40"><?php echo count($shipmentsOrders); ?></span>
                </h3>
                <div class="space-y-3 flex-1 overflow-y-auto max-h-[600px]">
                    <?php if (count($shipmentsOrders) > 0): ?>
                        <?php foreach ($shipmentsOrders as $order): 
                            $isShipped = ($order['fulfillment_status'] === 'Shipped');
                            $isPaid = ($order['payment_status'] === 'Paid');
                        ?>
                            <div class="bg-[#0a0810] p-3 rounded-xl border border-purple-900/35 text-xs space-y-2" data-order-id="<?php echo $order['id']; ?>">
                                <div class="flex justify-between font-bold">
                                    <span class="text-white">Order #<?php echo $order['id']; ?></span>
                                    <span class="text-emerald-400">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                </div>
                                <p class="text-purple-200/80">Customer: <strong class="customer-name"><?php echo htmlspecialchars($order['customer_name']); ?></strong></p>
                                <p class="text-[10px]">Fulfillment: <span class="text-blue-400"><?php echo $order['fulfillment_status']; ?></span></p>

                                <div class="space-y-1.5 pt-2 border-t border-purple-900/30">
                                    <button type="button" onclick="<?php echo $isPaid ? 'openReceiptModal('.$order['id'].')' : 'openInvoiceModal('.$order['id'].')'; ?>" class="w-full bg-[#120f1d] hover:bg-purple-950/40 <?php echo $isPaid ? 'text-emerald-400 border-emerald-900/40' : 'text-purple-200 border-purple-900/40'; ?> border py-1.5 px-2 rounded text-center text-[11px] font-semibold transition-colors">
                                        <?php echo $isPaid ? 'View Receipt' : 'Generate Invoice'; ?>
                                    </button>
                                    
                                    <?php if (!$isShipped): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="order_shipped">
                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                            <button type="submit" class="w-full bg-emerald-950/60 hover:bg-emerald-900/60 text-emerald-200 border border-emerald-800/50 py-1.5 px-2 rounded text-[11px] transition-colors">
                                                Mark as Shipped
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div class="grid grid-cols-2 gap-1.5">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="order_received">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <button type="submit" class="w-full bg-emerald-950/60 hover:bg-emerald-900/60 text-emerald-200 border border-emerald-800/50 py-1.5 px-2 rounded text-[10px] text-center transition-colors">
                                                    Received
                                                </button>
                                            </form>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="order_returned">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <button type="submit" class="w-full bg-rose-950/60 hover:bg-rose-900/60 text-rose-200 border border-rose-800/50 py-1.5 px-2 rounded text-[10px] text-center transition-colors">
                                                    Returned
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-10 text-purple-300/40 text-xs">No active shipments.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tr-panel p-3.5 flex flex-col">
                <h3 class="text-xs font-bold uppercase tracking-wider text-rose-400 mb-3 flex justify-between items-center">
                    <span>5. Returned Orders</span>
                    <span class="text-xs bg-rose-950/60 text-rose-300 px-2 py-0.5 rounded-lg border border-rose-800/40"><?php echo count($returnedOrders); ?></span>
                </h3>
                <div class="space-y-3 flex-1 overflow-y-auto max-h-[600px]">
                    <?php if (count($returnedOrders) > 0): ?>
                        <?php foreach ($returnedOrders as $order):
                            $isPaid = ($order['payment_status'] === 'Paid');
                        ?>
                            <div class="bg-[#0a0810] p-3 rounded-xl border border-purple-900/35 text-xs space-y-2" data-order-id="<?php echo $order['id']; ?>">
                                <div class="flex justify-between font-bold">
                                    <span class="text-white">Order #<?php echo $order['id']; ?></span>
                                    <span class="text-emerald-400">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                </div>
                                <p class="text-purple-200/80">Customer: <strong class="customer-name"><?php echo htmlspecialchars($order['customer_name']); ?></strong></p>

                                <div class="space-y-1.5 pt-2 border-t border-purple-900/30">
                                    <button type="button" onclick="<?php echo $isPaid ? 'openReceiptModal('.$order['id'].')' : 'openInvoiceModal('.$order['id'].')'; ?>" class="w-full bg-[#120f1d] hover:bg-purple-950/40 <?php echo $isPaid ? 'text-emerald-400 border-emerald-900/40' : 'text-purple-200 border-purple-900/40'; ?> border py-1.5 px-2 rounded text-center text-[11px] font-semibold transition-colors">
                                        <?php echo $isPaid ? 'View Receipt' : 'Generate Invoice'; ?>
                                    </button>
                                    <div class="flex gap-2">
                                        <form method="POST" onsubmit="return confirm('Void order?');" class="flex-1">
                                            <input type="hidden" name="action" value="void_order">
                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                            <button type="submit" class="w-full bg-rose-950/60 hover:bg-rose-900/60 text-rose-200 border border-rose-800/50 py-1 rounded text-[10px] transition-colors">Void</button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Restock items?');" class="flex-1">
                                            <input type="hidden" name="action" value="restock_order">
                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                            <button type="submit" class="w-full bg-amber-950/60 hover:bg-amber-900/60 text-amber-200 border border-amber-800/50 py-1 rounded text-[10px] transition-colors">Restock</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-10 text-purple-300/40 text-xs">No returned orders.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <div id="invoiceModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center p-4 z-50">
        <div class="tr-panel p-6 max-w-md w-full space-y-4 bg-[#120f1d] relative">
            
            <div id="printableInvoiceArea" class="printable-area bg-white text-zinc-900 p-6 rounded-lg shadow-xl text-xs space-y-4">
                <div class="text-center pb-3 border-b border-zinc-300">
                    <h2 class="font-extrabold text-xl uppercase tracking-wider text-black">Trade Rocket TCG</h2>
                    <p class="text-[10px] text-zinc-500 font-medium">Official Invoice / Order Summary</p>
                </div>
                
                <div class="space-y-1 font-medium">
                    <p><strong>Order No:</strong> <span id="inv_order_id"></span></p>
                    <p><strong>Customer:</strong> <span id="inv_customer"></span></p>
                    <p><strong>Date:</strong> <span id="inv_date"></span></p>
                </div>
                
                <div class="pb-2 border-b border-zinc-300">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-zinc-200 text-zinc-600">
                                <th class="pb-2 font-bold">Item</th>
                                <th class="pb-2 font-bold text-center">Qty</th>
                                <th class="pb-2 font-bold text-right">Price</th>
                                <th class="pb-2 font-bold text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody id="inv_items_list" class="divide-y divide-zinc-100 font-medium">
                            </tbody>
                    </table>
                </div>
                
                <div class="flex justify-between items-center text-sm font-bold text-black pt-1">
                    <span>Total Due:</span>
                    <span id="inv_total"></span>
                </div>

                <div class="mt-4 p-3 bg-amber-50 border border-amber-300 rounded-md text-[10px] text-amber-900 font-medium leading-relaxed">
                    <strong>⚠️ IMPORTANT REMINDER:</strong> Please settle payment for your pending order within <strong>24 hours</strong>. Unpaid orders will automatically be cancelled, items will be replenished back to stocks, and non-payment may lead to account suspension/banning.
                </div>
            </div>

            <div class="flex gap-2 pt-2 no-print">
                <button type="button" onclick="window.print()" class="flex-1 bg-purple-950/60 hover:bg-purple-900/60 text-purple-200 border border-purple-800/50 py-2.5 rounded-xl text-center font-semibold text-xs transition-colors">Print Invoice</button>
                <button type="button" onclick="copyElementAsImage('printableInvoiceArea', this)" class="flex-1 bg-blue-950/60 hover:bg-blue-900/60 text-blue-200 border border-blue-800/50 py-2.5 rounded-xl text-center font-semibold text-xs transition-colors">Copy as Image</button>
                <button type="button" onclick="closeInvoiceModal()" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 px-4 py-2.5 rounded-xl text-xs font-semibold transition-colors">Cancel</button>
            </div>
        </div>
    </div>

    <div id="paymentModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center p-4 z-50">
        <div class="tr-panel p-6 max-w-sm w-full space-y-4 bg-[#120f1d] no-print">
            <h3 class="text-sm font-bold text-purple-200 uppercase">Customer Payment</h3>
            <form id="paymentForm" method="POST" class="space-y-3" onsubmit="handlePaymentSubmit(event)">
                <input type="hidden" name="action" value="process_payment">
                <input type="hidden" id="modal_order_id" name="order_id">
                <input type="hidden" id="modal_total_amount" name="total_amount">
                
                <div>
                    <label class="block text-xs text-purple-300/70 mb-1">Total Price: <strong id="display_total" class="text-emerald-400 text-sm"></strong></label>
                </div>
                <div>
                    <label class="block text-xs text-purple-300/70 mb-1">Enter Customer Payment</label>
                    <input type="number" step="0.01" name="amount_paid" id="amount_paid" oninput="calculateChange()" required class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl p-3 text-sm text-white focus:outline-none" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-xs text-purple-300/70 mb-1">Change: <strong id="display_change" class="text-amber-400 text-sm">₱0.00</strong></label>
                </div>
                
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 purple-btn text-white text-xs font-semibold py-2.5 rounded-xl">Confirm Payment</button>
                    <button type="button" onclick="closePaymentModal()" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs px-4 py-2.5 rounded-xl transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="receiptModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center p-4 z-50">
        <div class="tr-panel p-6 max-w-md w-full space-y-4 bg-[#120f1d] relative">
            
            <div id="printableReceiptArea" class="printable-area bg-white text-zinc-900 p-6 rounded-lg shadow-xl text-xs space-y-4">
                <div class="text-center pb-3 border-b border-zinc-300">
                    <h2 class="font-extrabold text-xl uppercase tracking-wider text-black">Trade Rocket TCG</h2>
                    <p class="text-[10px] text-zinc-500 font-medium">Official Payment Receipt</p>
                </div>
                
                <div class="space-y-1 font-medium">
                    <p><strong>Order No:</strong> <span id="rec_order_id"></span></p>
                    <p><strong>Customer:</strong> <span id="rec_customer"></span></p>
                    <p><strong>Date:</strong> <span id="rec_date"></span></p>
                </div>
                
                <div class="pb-2 border-b border-zinc-300">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-zinc-200 text-zinc-600">
                                <th class="pb-2 font-bold">Item</th>
                                <th class="pb-2 font-bold text-center">Qty</th>
                                <th class="pb-2 font-bold text-right">Price</th>
                                <th class="pb-2 font-bold text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody id="rec_items_list" class="divide-y divide-zinc-100 font-medium">
                            </tbody>
                    </table>
                </div>
                
                <div class="pt-1 border-t border-zinc-200">
                    <table class="w-full text-xs font-medium">
                        <tr>
                            <td class="py-1 text-zinc-700">Total Amount:</td>
                            <td id="rec_total" class="py-1 text-right font-bold text-black"></td>
                        </tr>
                        <tr>
                            <td class="py-1 text-zinc-700">Amount Paid (Cash):</td>
                            <td id="rec_amount_paid" class="py-1 text-right text-zinc-900">₱0.00</td>
                        </tr>
                        <tr class="border-t border-dashed border-zinc-300 font-bold text-sm">
                            <td class="py-1.5 text-zinc-900">Change:</td>
                            <td id="rec_change" class="py-1.5 text-right text-emerald-700">₱0.00</td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="flex gap-2 pt-2 no-print">
                <button type="button" onclick="window.print()" class="flex-1 bg-purple-950/60 hover:bg-purple-900/60 text-purple-200 border border-purple-800/50 py-2.5 rounded-xl text-center font-semibold text-xs transition-colors">Print Receipt</button>
                <button type="button" onclick="copyElementAsImage('printableReceiptArea', this)" class="flex-1 bg-blue-950/60 hover:bg-blue-900/60 text-blue-200 border border-blue-800/50 py-2.5 rounded-xl text-center font-semibold text-xs transition-colors">Copy as Image</button>
                <button type="button" onclick="closeReceiptModalAndReload()" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 px-4 py-2.5 rounded-xl text-xs font-semibold transition-colors">Done</button>
            </div>
        </div>
    </div>

    <script>
        let currentOrderTotal = 0;
        let activeOrderId = null;
        let lastEnteredAmountPaid = 0;

        function formatDate(dateStr) {
            return new Date(dateStr).toLocaleString();
        }

        // Copy HTML Element as a PNG image to Clipboard
        async function copyElementAsImage(elementId, btnElement) {
            const originalText = btnElement.innerText;
            btnElement.innerText = "Generating...";
            btnElement.disabled = true;

            try {
                const element = document.getElementById(elementId);
                const canvas = await html2canvas(element, { scale: 2, useCORS: true, backgroundColor: "#ffffff" });
                
                canvas.toBlob(async (blob) => {
                    try {
                        const item = new ClipboardItem({ "image/png": blob });
                        await navigator.clipboard.write([item]);
                        btnElement.innerText = "Image Copied!";
                    } catch (err) {
                        console.error("Clipboard write failed", err);
                        alert("Failed to copy image to clipboard.");
                        btnElement.innerText = "Copy Failed";
                    }
                    
                    setTimeout(() => {
                        btnElement.innerText = originalText;
                        btnElement.disabled = false;
                    }, 2500);
                }, "image/png");

            } catch (error) {
                console.error("html2canvas failed", error);
                alert("Error generating image.");
                btnElement.innerText = "Error";
                btnElement.disabled = false;
            }
        }

        // Generate Invoice Modal Logic
        async function openInvoiceModal(orderId) {
            document.getElementById('invoiceModal').classList.remove('hidden');
            document.getElementById('inv_items_list').innerHTML = `<tr><td colspan="4" class="text-center py-6 text-zinc-500">Loading invoice details...</td></tr>`;
            
            try {
                const res = await fetch(`orders_process.php?ajax_action=get_order_details&order_id=${orderId}`);
                const data = await res.json();
                
                if(!data.success) throw new Error(data.error);

                const order = data.order;
                const items = data.items;

                document.getElementById('inv_order_id').innerText = '#' + order.id;
                document.getElementById('inv_customer').innerText = order.customer_name;
                document.getElementById('inv_date').innerText = formatDate(order.created_at);
                document.getElementById('inv_total').innerText = '₱' + parseFloat(order.total_amount).toFixed(2);

                let itemsHtml = '';
                items.forEach(item => {
                    const itemTotal = item.quantity * item.price;
                    itemsHtml += `
                        <tr>
                            <td class="py-2.5 pr-2">
                                <div class="text-black font-bold truncate max-w-[150px]">${item.card_name}</div>
                                <div class="text-[9px] text-zinc-500">${item.set_name || 'Set'} • No. ${item.card_number || '-'}</div>
                            </td>
                            <td class="py-2.5 text-center">${item.quantity}</td>
                            <td class="py-2.5 text-right">₱${parseFloat(item.price).toFixed(2)}</td>
                            <td class="py-2.5 text-right font-bold">₱${itemTotal.toFixed(2)}</td>
                        </tr>
                    `;
                });
                
                document.getElementById('inv_items_list').innerHTML = itemsHtml;

            } catch (e) {
                document.getElementById('inv_items_list').innerHTML = `<tr><td colspan="4" class="text-center py-6 text-red-500 font-bold">Failed to load data.</td></tr>`;
            }
        }

        function closeInvoiceModal() {
            document.getElementById('invoiceModal').classList.add('hidden');
        }

        // Payment Modal Controls
        function openPaymentModal(orderId, total) {
            currentOrderTotal = parseFloat(total);
            activeOrderId = orderId;
            document.getElementById('modal_order_id').value = orderId;
            document.getElementById('modal_total_amount').value = currentOrderTotal;
            document.getElementById('display_total').innerText = '₱' + currentOrderTotal.toFixed(2);
            document.getElementById('amount_paid').value = '';
            document.getElementById('display_change').innerText = '₱0.00';
            
            document.getElementById('paymentModal').classList.remove('hidden');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.add('hidden');
        }

        function calculateChange() {
            const paid = parseFloat(document.getElementById('amount_paid').value) || 0;
            const change = paid - currentOrderTotal;
            document.getElementById('display_change').innerText = '₱' + (change > 0 ? change.toFixed(2) : '0.00');
        }

        function handlePaymentSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            lastEnteredAmountPaid = parseFloat(formData.get('amount_paid')) || currentOrderTotal;

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(response => {
                closePaymentModal();
                openReceiptModal(activeOrderId);
            }).catch(err => {
                form.submit();
            });
        }

        // Receipt Modal Controls
        async function openReceiptModal(orderId) {
            document.getElementById('receiptModal').classList.remove('hidden');
            document.getElementById('rec_items_list').innerHTML = `<tr><td colspan="4" class="text-center py-6 text-zinc-500">Generating receipt...</td></tr>`;
            
            try {
                const res = await fetch(`orders_process.php?ajax_action=get_order_details&order_id=${orderId}`);
                const data = await res.json();
                
                if(!data.success) throw new Error(data.error);

                const order = data.order;
                const items = data.items;
                const totalAmt = parseFloat(order.total_amount);
                
                const amountPaid = lastEnteredAmountPaid > 0 ? lastEnteredAmountPaid : totalAmt;
                const change = amountPaid - totalAmt;

                document.getElementById('rec_order_id').innerText = '#' + order.id;
                document.getElementById('rec_customer').innerText = order.customer_name;
                document.getElementById('rec_date').innerText = formatDate(new Date());
                document.getElementById('rec_total').innerText = '₱' + totalAmt.toFixed(2);
                document.getElementById('rec_amount_paid').innerText = '₱' + amountPaid.toFixed(2);
                document.getElementById('rec_change').innerText = '₱' + (change > 0 ? change.toFixed(2) : '0.00');

                let itemsHtml = '';
                items.forEach(item => {
                    const itemTotal = item.quantity * item.price;
                    itemsHtml += `
                        <tr>
                            <td class="py-2.5 pr-2">
                                <div class="text-black font-bold truncate max-w-[150px]">${item.card_name}</div>
                                <div class="text-[9px] text-zinc-500">${item.set_name || 'Set'} • No. ${item.card_number || '-'}</div>
                            </td>
                            <td class="py-2.5 text-center">${item.quantity}</td>
                            <td class="py-2.5 text-right">₱${parseFloat(item.price).toFixed(2)}</td>
                            <td class="py-2.5 text-right font-bold">₱${itemTotal.toFixed(2)}</td>
                        </tr>
                    `;
                });
                
                document.getElementById('rec_items_list').innerHTML = itemsHtml;

            } catch (e) {
                document.getElementById('rec_items_list').innerHTML = `<tr><td colspan="4" class="text-center py-6 text-red-500 font-bold">Failed to load data.</td></tr>`;
            }
        }

        function closeReceiptModalAndReload() {
            document.getElementById('receiptModal').classList.add('hidden');
            window.location.reload();
        }
    </script>
</body>
</html>