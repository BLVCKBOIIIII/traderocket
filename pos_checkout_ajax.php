<?php
// pos_checkout_ajax.php
session_start();
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $customerName = trim($input['customer_name'] ?? '');
        $cartItems = $input['items'] ?? [];

        if (empty($customerName)) {
            throw new Exception("Customer name is required.");
        }
        if (empty($cartItems) || !is_array($cartItems)) {
            throw new Exception("Cart is empty.");
        }

        $pdo->beginTransaction();

        $totalAmount = 0;
        $validatedItems = [];

        // 1. Validate stock and calculate totals
        foreach ($cartItems as $item) {
            $cardId = intval($item['id']);
            $qty = intval($item['quantity']);

            if ($qty <= 0) continue;

            $stmt = $pdo->prepare("SELECT id, price, quantity FROM inventory_stocks WHERE id = ?");
            $stmt->execute([$cardId]);
            $stockItem = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$stockItem) {
                throw new Exception("Stock item ID {$cardId} not found.");
            }

            if ($stockItem['quantity'] < $qty) {
                throw new Exception("Insufficient stock for item ID {$cardId}. Available: {$stockItem['quantity']}");
            }

            $subtotal = $stockItem['price'] * $qty;
            $totalAmount += $subtotal;

            $validatedItems[] = [
                'card_id' => $cardId,
                'quantity' => $qty,
                'price' => $stockItem['price']
            ];
        }

        if (empty($validatedItems)) {
            throw new Exception("No valid items in cart.");
        }

        // 2. Insert into orders table (Fulfillment: Pending, Payment: Unpaid)
        $orderStmt = $pdo->prepare("INSERT INTO orders (customer_name, total_amount, payment_status, fulfillment_status, created_at) VALUES (?, ?, 'Unpaid', 'Pending', NOW())");
        $orderStmt->execute([$customerName, $totalAmount]);
        $orderId = $pdo->lastInsertId('orders_id_seq');

        // 3. Insert order items AND instantly deduct stock from inventory_stocks
        foreach ($validatedItems as $vItem) {
            $itemInsert = $pdo->prepare("INSERT INTO order_items (order_id, card_id, quantity, price, created_at) VALUES (?, ?, ?, ?, NOW())");
            $itemInsert->execute([$orderId, $vItem['card_id'], $vItem['quantity'], $vItem['price']]);

            $stockUpdate = $pdo->prepare("UPDATE inventory_stocks SET quantity = quantity - ? WHERE id = ?");
            $stockUpdate->execute([$vItem['quantity'], $vItem['card_id']]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true, 
            'order_id' => $orderId, 
            'message' => "Order #{$orderId} successfully processed and stocks deducted!"
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}