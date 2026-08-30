<?php
// Absolute path based on current directory to prevent include/require path errors
require_once __DIR__ . '/config/database.php';

// Ensure session is started safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? 'partner';
$message = '';
$error = '';

// Handle Stock Updates, Deletions, Batch Deletions, Type Changes, Category Changes, or Shelf Location via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update') {
            $stockId = intval($_POST['stock_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 0);
            $buyPrice = floatval($_POST['buy_price'] ?? 0);
            $sellPrice = floatval($_POST['sell_price'] ?? 0);
            $condition = trim($_POST['card_condition'] ?? 'NM');
            $isCosigned = isset($_POST['is_cosigned']) ? 1 : 0;
            $imageUrl = trim($_POST['image_url'] ?? ''); 
            $shelfLocation = trim($_POST['shelf_location'] ?? '');
            
            // Optional Card Category
            $cardCategory = trim($_POST['card_category'] ?? '');
            $cardCategoryValue = ($cardCategory !== '') ? $cardCategory : null;

            // Verify ownership if not admin and grab card_id
            $cardId = 0;
            if ($role !== 'admin') {
                $checkStmt = $pdo->prepare("SELECT id, card_id FROM inventory_stocks WHERE id = ? AND owner_id = ?");
                $checkStmt->execute([$stockId, $userId]);
                $stockData = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if (!$stockData) {
                    $error = "Unauthorized action or stock not found.";
                } else {
                    $cardId = $stockData['card_id'];
                }
            } else {
                $checkStmt = $pdo->prepare("SELECT card_id FROM inventory_stocks WHERE id = ?");
                $checkStmt->execute([$stockId]);
                $stockData = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if ($stockData) {
                    $cardId = $stockData['card_id'];
                }
            }

            if (empty($error)) {
                // Update stock record including shelf_location
                $updateStmt = $pdo->prepare("
                    UPDATE inventory_stocks 
                    SET quantity = ?, purchase_price = ?, buy_price = ?, price = ?, sell_price = ?, condition_status = ?, is_cosigned = ?, card_category = ?, shelf_location = ?
                    WHERE id = ? " . ($role !== 'admin' ? "AND owner_id = ?" : "")
                );

                $params = [$quantity, $buyPrice, $buyPrice, $sellPrice, $sellPrice, $condition, $isCosigned, $cardCategoryValue, $shelfLocation, $stockId];
                if ($role !== 'admin') {
                    $params[] = $userId;
                }
                $updateStmt->execute($params);

                // Update master card image_url if card_id exists
                if ($cardId > 0) {
                    $cardImgStmt = $pdo->prepare("UPDATE cards SET image_url = ? WHERE id = ?");
                    $cardImgStmt->execute([$imageUrl, $cardId]);
                }

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'stock' => [
                            'id' => $stockId,
                            'quantity' => $quantity,
                            'buy_price' => $buyPrice,
                            'sell_price' => $sellPrice,
                            'condition_status' => $condition,
                            'is_cosigned' => $isCosigned,
                            'image_url' => $imageUrl,
                            'shelf_location' => $shelfLocation,
                            'card_category' => $cardCategoryValue
                        ]
                    ]);
                    exit();
                }

                $message = "Stock and card details updated successfully!";
            } else {
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => $error]);
                    exit();
                }
            }
        } elseif ($_POST['action'] === 'update_shelf_location') {
            $stockId = intval($_POST['stock_id'] ?? 0);
            $shelfLoc = trim($_POST['shelf_location'] ?? '');

            $stmt = $pdo->prepare("UPDATE inventory_stocks SET shelf_location = ? WHERE id = ? " . ($role !== 'admin' ? "AND owner_id = ?" : ""));
            $params = ($role !== 'admin') ? [$shelfLoc, $stockId, $userId] : [$shelfLoc, $stockId];
            $stmt->execute($params);

            // Handle AJAX Async Requests
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'shelf_location' => $shelfLoc]);
                exit();
            }

            $message = "Physical shelf location updated successfully!";
        } elseif ($_POST['action'] === 'update_quantity') {
            $stockId = intval($_POST['stock_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 0);

            $stmt = $pdo->prepare("UPDATE inventory_stocks SET quantity = ? WHERE id = ? " . ($role !== 'admin' ? "AND owner_id = ?" : ""));
            $params = ($role !== 'admin') ? [$quantity, $stockId, $userId] : [$quantity, $stockId];
            $stmt->execute($params);

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'quantity' => $quantity]);
                exit();
            }

            $message = "Stock quantity updated successfully!";
        } elseif ($_POST['action'] === 'update_type') {
            // Update Pokémon Type for the card
            $cardId = intval($_POST['card_id'] ?? 0);
            $newPokemonType = trim($_POST['pokemon_type'] ?? '');

            if ($cardId > 0 && !empty($newPokemonType)) {
                if ($role !== 'admin') {
                    $checkCardStmt = $pdo->prepare("
                        SELECT i.id FROM inventory_stocks i 
                        WHERE i.card_id = ? AND i.owner_id = ? LIMIT 1
                    ");
                    $checkCardStmt->execute([$cardId, $userId]);
                    if (!$checkCardStmt->fetch()) {
                        $error = "Unauthorized action or card not found in your inventory.";
                    }
                }

                if (empty($error)) {
                    $typeUpdateStmt = $pdo->prepare("UPDATE cards SET pokemon_type = ? WHERE id = ?");
                    $typeUpdateStmt->execute([$newPokemonType, $cardId]);

                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'pokemon_type' => $newPokemonType]);
                        exit();
                    }

                    $message = "Pokémon type updated successfully!";
                }
            } else {
                $error = "Invalid card or type provided.";
            }
        } elseif ($_POST['action'] === 'batch_update_type') {
            // Batch Update Pokémon Type
            $stockIds = $_POST['stock_ids'] ?? [];
            $newPokemonType = trim($_POST['pokemon_type'] ?? '');

            if (!empty($stockIds) && is_array($stockIds) && !empty($newPokemonType)) {
                $stockIds = array_map('intval', $stockIds);
                $placeholders = implode(',', array_fill(0, count($stockIds), '?'));
                
                if ($role !== 'admin') {
                    $getCardsSql = "SELECT DISTINCT card_id FROM inventory_stocks WHERE id IN ($placeholders) AND owner_id = ?";
                    $params = array_merge($stockIds, [$userId]);
                } else {
                    $getCardsSql = "SELECT DISTINCT card_id FROM inventory_stocks WHERE id IN ($placeholders)";
                    $params = $stockIds;
                }

                $stmtCards = $pdo->prepare($getCardsSql);
                $stmtCards->execute($params);
                $cardRecords = $stmtCards->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($cardRecords)) {
                    $cardIds = array_column($cardRecords, 'card_id');
                    $cardPlaceholders = implode(',', array_fill(0, count($cardIds), '?'));
                    
                    $updateCardsSql = "UPDATE cards SET pokemon_type = ? WHERE id IN ($cardPlaceholders)";
                    $updateParams = array_merge([$newPokemonType], $cardIds);
                    
                    $stmtUpdate = $pdo->prepare($updateCardsSql);
                    $stmtUpdate->execute($updateParams);

                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'pokemon_type' => $newPokemonType, 'stock_ids' => $stockIds]);
                        exit();
                    }
                    
                    $message = "Pokémon types updated successfully for selected records!";
                } else {
                    $error = "No valid cards found or unauthorized action.";
                }
            } else {
                $error = "No stock items selected or invalid type provided.";
            }
        } elseif ($_POST['action'] === 'batch_update_category') {
            // Batch Update Card Category
            $stockIds = $_POST['stock_ids'] ?? [];
            $cardCategory = trim($_POST['card_category'] ?? '');
            $cardCategoryValue = ($cardCategory !== '') ? $cardCategory : null;

            if (!empty($stockIds) && is_array($stockIds)) {
                $stockIds = array_map('intval', $stockIds);
                $placeholders = implode(',', array_fill(0, count($stockIds), '?'));

                if ($role !== 'admin') {
                    $updateSql = "UPDATE inventory_stocks SET card_category = ? WHERE id IN ($placeholders) AND owner_id = ?";
                    $updateParams = array_merge([$cardCategoryValue], $stockIds, [$userId]);
                } else {
                    $updateSql = "UPDATE inventory_stocks SET card_category = ? WHERE id IN ($placeholders)";
                    $updateParams = array_merge([$cardCategoryValue], $stockIds);
                }

                $stmtUpdate = $pdo->prepare($updateSql);
                $stmtUpdate->execute($updateParams);

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'card_category' => $cardCategoryValue, 'stock_ids' => $stockIds]);
                    exit();
                }

                $message = "Card category updated successfully for selected items!";
            } else {
                $error = "No stock items selected for batch category update.";
            }
        } elseif ($_POST['action'] === 'batch_update_location') {
            // Batch Update Shelf Location
            $stockIds = $_POST['stock_ids'] ?? [];
            $shelfLocation = trim($_POST['shelf_location'] ?? '');

            if (!empty($stockIds) && is_array($stockIds)) {
                $stockIds = array_map('intval', $stockIds);
                $placeholders = implode(',', array_fill(0, count($stockIds), '?'));

                if ($role !== 'admin') {
                    $updateSql = "UPDATE inventory_stocks SET shelf_location = ? WHERE id IN ($placeholders) AND owner_id = ?";
                    $updateParams = array_merge([$shelfLocation], $stockIds, [$userId]);
                } else {
                    $updateSql = "UPDATE inventory_stocks SET shelf_location = ? WHERE id IN ($placeholders)";
                    $updateParams = array_merge([$shelfLocation], $stockIds);
                }

                $stmtUpdate = $pdo->prepare($updateSql);
                $stmtUpdate->execute($updateParams);

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'shelf_location' => $shelfLocation, 'stock_ids' => $stockIds]);
                    exit();
                }

                $message = "Shelf location updated successfully for selected items!";
            } else {
                $error = "No stock items selected for batch location update.";
            }
        } elseif ($_POST['action'] === 'delete') {
            $stockId = intval($_POST['stock_id'] ?? 0);

            $delStmt = $pdo->prepare("DELETE FROM inventory_stocks WHERE id = ? " . ($role !== 'admin' ? "AND owner_id = ?" : ""));
            if ($role !== 'admin') {
                $delStmt->execute([$stockId, $userId]);
            } else {
                $delStmt->execute([$stockId]);
            }
            $message = "Stock item removed successfully!";
        } elseif ($_POST['action'] === 'batch_delete') {
            $stockIds = $_POST['stock_ids'] ?? [];
            if (!empty($stockIds) && is_array($stockIds)) {
                $stockIds = array_map('intval', $stockIds);
                $placeholders = implode(',', array_fill(0, count($stockIds), '?'));
                
                if ($role !== 'admin') {
                    $delSql = "DELETE FROM inventory_stocks WHERE id IN ($placeholders) AND owner_id = ?";
                    $params = array_merge($stockIds, [$userId]);
                } else {
                    $delSql = "DELETE FROM inventory_stocks WHERE id IN ($placeholders)";
                    $params = $stockIds;
                }

                $batchStmt = $pdo->prepare($delSql);
                $batchStmt->execute($params);
                $message = "Selected stock items removed successfully!";
            } else {
                $error = "No stock items selected for batch deletion.";
            }
        }
    }
}

// Search and Filter Handling
$searchQuery = trim($_GET['search'] ?? '');
$conditionFilter = trim($_GET['condition'] ?? '');
$cosignFilter = trim($_GET['cosigned'] ?? '');
$categoryFilter = trim($_GET['card_category'] ?? '');
$typeFilter = trim($_GET['pokemon_type'] ?? '');

$whereClause = [];
$params = [];

if ($role !== 'admin') {
    $whereClause[] = "i.owner_id = ?";
    $params[] = $userId;
}

if (!empty($searchQuery)) {
    // Case-insensitive search using LOWER()
    $whereClause[] = "(LOWER(c.name) LIKE LOWER(?) OR LOWER(c.card_number) LIKE LOWER(?) OR LOWER(i.shelf_location) LIKE LOWER(?))";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

if (!empty($conditionFilter)) {
    $whereClause[] = "i.condition_status = ?";
    $params[] = $conditionFilter;
}

if ($cosignFilter !== '') {
    $whereClause[] = "i.is_cosigned = ?";
    $params[] = (int)$cosignFilter;
}

if (!empty($categoryFilter)) {
    if ($categoryFilter === 'Normal') {
        $whereClause[] = "(i.card_category IS NULL OR i.card_category = '' OR i.card_category = 'Normal')";
    } else {
        $whereClause[] = "i.card_category = ?";
        $params[] = $categoryFilter;
    }
}

if (!empty($typeFilter)) {
    $whereClause[] = "LOWER(c.pokemon_type) = LOWER(?)";
    $params[] = $typeFilter;
}

$stocksSql = "
    SELECT i.*, c.id AS card_master_id, c.name AS card_name, c.card_number, c.image_url, c.pokemon_type, u.username AS owner_name
    FROM inventory_stocks i
    LEFT JOIN cards c ON i.card_id = c.id
    LEFT JOIN users u ON i.owner_id = u.id
";

if (!empty($whereClause)) {
    $stocksSql .= " WHERE " . implode(' AND ', $whereClause);
}
$stocksSql .= " ORDER BY i.id DESC";

try {
    $stmtStocks = $pdo->prepare($stocksSql);
    $stmtStocks->execute($params);
    $stocks = $stmtStocks->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    $totalStockQty = array_sum(array_column($stocks, 'quantity'));
    $totalStockValue = array_reduce($stocks, function ($carry, $item) {
        return $carry + ($item['quantity'] * ($item['sell_price'] ?? $item['price'] ?? 0));
    }, 0);
} catch (PDOException $e) {
    $stocks = [];
    $totalStockQty = 0;
    $totalStockValue = 0;
}

// Storage containers layout configuration dynamically calculated from database stocks
$baseContainers = [
    ['id' => 'box-1', 'title' => 'Box #01', 'code' => 'Box 1', 'max' => 250],
    ['id' => 'box-2', 'title' => 'Box #02', 'code' => 'Box 2', 'max' => 250],
    ['id' => 'box-3', 'title' => 'Box #03', 'code' => 'Box 3', 'max' => 250],
    ['id' => 'box-4', 'title' => 'Box #04', 'code' => 'Box 4', 'max' => 250],
    ['id' => 'shelf-1', 'title' => 'Shelf #01', 'code' => 'Shelf 1', 'max' => 100],
];

// Map actual stock quantities to containers based on shelf_location matches
$storageContainers = array_map(function ($container) use ($stocks) {
    $containerStocks = array_filter($stocks, function ($stock) use ($container) {
        return strcasecmp(trim($stock['shelf_location'] ?? ''), $container['code']) === 0;
    });
    $container['stocks'] = array_sum(array_column($containerStocks, 'quantity'));
    return $container;
}, $baseContainers);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        .drag-over {
            border-color: #a855f7 !important;
            background-color: rgba(168, 85, 247, 0.2) !important;
            transform: scale(1.02);
            box-shadow: 0 0 15px rgba(168, 85, 247, 0.4);
        }
        .dragging {
            opacity: 0.4;
            border: 2px dashed #a855f7;
        }
        .storage-card {
            background: linear-gradient(180deg, rgba(22, 17, 35, 0.9) 0%, rgba(12, 10, 20, 0.95) 100%);
            border: 1px solid rgba(139, 92, 246, 0.2);
            transition: all 0.2s ease-in-out;
        }
        .storage-card:hover {
            border-color: rgba(168, 85, 247, 0.5);
        }
        .storage-card.active-container {
            border-color: #a855f7 !important;
            box-shadow: 0 0 15px rgba(168, 85, 247, 0.4);
            background: linear-gradient(180deg, rgba(88, 28, 135, 0.4) 0%, rgba(15, 23, 42, 0.95) 100%);
        }
        /* Sticky Scroll-Proof Upper Right Batch Panel */
        #batchChangerPanel {
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease-in-out;
        }
        #batchChangerPanel.hidden-panel {
            transform: translateY(-20px) scale(0.95);
            opacity: 0;
            pointer-events: none;
        }
        #batchChangerPanel.visible-panel {
            transform: translateY(0) scale(1);
            opacity: 1;
            pointer-events: auto;
        }
        /* Notification Toast Styling */
        #notificationToast {
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.4s ease-in-out;
        }
        #notificationToast.toast-hidden {
            transform: translateY(20px) scale(0.95);
            opacity: 0;
            pointer-events: none;
        }
        #notificationToast.toast-visible {
            transform: translateY(0) scale(1);
            opacity: 1;
            pointer-events: auto;
        }

        /* Container Color Coding Rules */
        .container-box-1 {
            background: linear-gradient(180deg, rgba(30, 27, 75, 0.9) 0%, rgba(15, 23, 42, 0.95) 100%);
            border-color: rgba(99, 102, 241, 0.4) !important;
        }
        .container-box-2 {
            background: linear-gradient(180deg, rgba(6, 78, 59, 0.9) 0%, rgba(6, 95, 70, 0.95) 100%);
            border-color: rgba(16, 185, 129, 0.4) !important;
        }
        .container-box-3 {
            background: linear-gradient(180deg, rgba(120, 53, 15, 0.9) 0%, rgba(146, 64, 14, 0.95) 100%);
            border-color: rgba(245, 158, 11, 0.4) !important;
        }
        .container-box-4 {
            background: linear-gradient(180deg, rgba(131, 24, 67, 0.9) 0%, rgba(157, 23, 77, 0.95) 100%);
            border-color: rgba(244, 63, 94, 0.4) !important;
        }
        .container-shelf-1 {
            background: linear-gradient(180deg, rgba(88, 28, 135, 0.9) 0%, rgba(107, 33, 168, 0.95) 100%);
            border-color: rgba(168, 85, 247, 0.4) !important;
        }
        .container-default {
            background: linear-gradient(180deg, rgba(39, 39, 42, 0.9) 0%, rgba(24, 24, 27, 0.95) 100%);
            border-color: rgba(113, 113, 122, 0.4) !important;
        }

        /* Dynamic Color Pill Styles for In-Table Shelf Location */
        .shelf-pill-box-1 { background-color: rgba(99, 102, 241, 0.2); border: 1px solid rgba(99, 102, 241, 0.5); color: #a5b4fc; }
        .shelf-pill-box-2 { background-color: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.5); color: #6ee7b7; }
        .shelf-pill-box-3 { background-color: rgba(245, 158, 11, 0.2); border: 1px solid rgba(245, 158, 11, 0.5); color: #fcd34d; }
        .shelf-pill-box-4 { background-color: rgba(244, 63, 94, 0.2); border: 1px solid rgba(244, 63, 94, 0.5); color: #fca5a5; }
        .shelf-pill-shelf-1 { background-color: rgba(168, 85, 247, 0.2); border: 1px solid rgba(168, 85, 247, 0.5); color: #d8b4fe; }
        .shelf-pill-default { background-color: rgba(113, 113, 122, 0.2); border: 1px solid rgba(113, 113, 122, 0.5); color: #d4d4d8; }

        /* Color Coded Dropdown Selectors for Pokemon Types */
        select.type-select {
            transition: all 0.2s ease;
            font-weight: 600;
        }
        select.type-select[data-type="grass"] { background-color: #2e6f40; color: #a7f3d0; border-color: #4ade80; }
        select.type-select[data-type="fire"] { background-color: #852520; color: #fecaca; border-color: #f87171; }
        select.type-select[data-type="water"] { background-color: #1d4ed8; color: #bfdbfe; border-color: #60a5fa; }
        select.type-select[data-type="electric"] { background-color: #a16207; color: #fef08a; border-color: #facc15; }
        select.type-select[data-type="psychic"] { background-color: #831843; color: #fbcfe8; border-color: #f472b6; }
        select.type-select[data-type="ground"] { background-color: #713f12; color: #fde68a; border-color: #fbbf24; }
        select.type-select[data-type="dark"] { background-color: #3f3f46; color: #e4e4e7; border-color: #a1a1aa; }
        select.type-select[data-type="metal"] { background-color: #475569; color: #e2e8f0; border-color: #94a3b8; }
        select.type-select[data-type="fairy"] { background-color: #9d174d; color: #fce7f3; border-color: #f472b6; }
        select.type-select[data-type="dragon"] { background-color: #581c87; color: #e9d5ff; border-color: #c084fc; }
        select.type-select[data-type="normal"] { background-color: #27272a; color: #d4d4d8; border-color: #52525b; }
        select.type-select[data-type="trainers"] { background-color: #1e293b; color: #cbd5e1; border-color: #64748b; }
        select.type-select[data-type="tools"] { background-color: #334155; color: #f1f5f9; border-color: #94a3b8; }
        select.type-select[data-type="map location"] { background-color: #134e4a; color: #99f6e4; border-color: #2dd4bf; }

        select.type-select option {
            background-color: #0d0a14;
            color: #ffffff;
        }

        /* Tight Table Layout & Font Sizing Adjustments */
        .inventory-table th {
            padding: 8px 10px;
            font-size: 11px;
            line-height: 1.2;
        }
        .inventory-table td {
            padding: 6px 10px;
            font-size: 13px;
            line-height: 1.3;
        }

        /* Custom Quantity Number Spinner */
        input[type=number].qty-inline-input::-webkit-inner-spin-button, 
        input[type=number].qty-inline-input::-webkit-outer-spin-button { 
            opacity: 0.7;
        }

        /* Floating Sticky Quick Action Button */
        .floating-save-btn {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 40;
            box-shadow: 0 10px 25px -5px rgba(168, 85, 247, 0.4), 0 8px 10px -6px rgba(168, 85, 247, 0.2);
            transition: all 0.2s ease-in-out;
        }
        .floating-save-btn:hover {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 15px 30px -5px rgba(168, 85, 247, 0.6), 0 10px 12px -6px rgba(168, 85, 247, 0.3);
        }

        /* PRINT STYLES - Specifically engineered for the 2-column inventory layout */
        @media print {
            body * {
                visibility: hidden;
            }
            #printSection, #printSection * {
                visibility: visible;
            }
            #printSection {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                display: block !important;
                background-color: white;
                color: black;
            }
            .print-header {
                text-align: center;
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 10px;
                color: black !important;
                border-bottom: 2px solid #000;
                padding-bottom: 6px;
            }
            .print-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                column-gap: 24px;
                row-gap: 0;
            }
            .print-item {
                padding: 3px 0;
                page-break-inside: avoid;
                border-bottom: 1px solid #ddd;
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 8px;
                white-space: nowrap;
                overflow: hidden;
            }
            .item-name {
                font-weight: bold;
                font-size: 11px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                flex: 1;
            }
            .item-details {
                font-size: 10px;
                display: flex;
                gap: 8px;
                flex-shrink: 0;
            }
            .item-stat strong {
                font-size: 9px;
                color: #555;
                text-transform: uppercase;
                margin-right: 2px;
            }
            @page { 
                margin: 1cm; 
            }
        }
    </style>
</head>

<body class="min-h-screen flex flex-col bg-[#0b0b0e] text-zinc-100">

    <?php include 'includes/styles.php'; ?>
    <?php include 'includes/header.php'; ?>

    <main class="flex-1 max-w-[1600px] w-full mx-auto p-6 lg:p-8 relative">

        <!-- Top Header Action Bar -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
            <div>
                <h1 class="text-2xl font-bold tracking-tight mb-1 text-white">Inventory Management</h1>
                <p class="text-purple-300/60 text-xs">
                    <?php echo ($role === 'admin') ? 'Manage store-wide inventory stock counts, buy/sell pricing, conditions, and shelf locations.' : 'Manage your assigned bulk inventory stocks, pricing, and shelf locations.'; ?>
                </p>
            </div>
            <div class="flex flex-wrap gap-2 self-start md:self-auto">
                <button onclick="window.print()" class="bg-blue-950/60 hover:bg-blue-900 border border-blue-800/40 text-blue-200 text-xs font-semibold px-4 py-2.5 rounded-lg flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    <span>Print Inventory</span>
                </button>
                <button onclick="downloadInventoryPDF()" class="bg-emerald-950/60 hover:bg-emerald-900 border border-emerald-800/40 text-emerald-200 text-xs font-semibold px-4 py-2.5 rounded-lg flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                    </svg>
                    <span>Download PDF</span>
                </button>
                <a href="binder.php" class="bg-purple-950/60 hover:bg-purple-900 border border-purple-800/40 text-purple-200 text-xs font-semibold px-4 py-2.5 rounded-lg flex items-center space-x-2 transition-colors">
                    <span>📖 Open Binder View</span>
                </a>
                <a href="cards.php" class="purple-btn text-white text-xs font-semibold px-4 py-2.5 rounded-lg flex items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>+ Add / Import Cards</span>
                </a>
            </div>
        </div>

        <!-- Stat Overview Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="tr-sidebar p-6 border border-purple-900/30 relative overflow-hidden rounded-2xl">
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-semibold mb-2">Total Unique Entries</p>
                <h3 class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-fuchsia-300">
                    <?php echo number_format(count($stocks)); ?>
                </h3>
            </div>
            <div class="tr-sidebar p-6 border border-purple-900/30 relative overflow-hidden rounded-2xl">
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-semibold mb-2">Total Physical Quantity</p>
                <h3 class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-fuchsia-300">
                    <?php echo number_format($totalStockQty); ?>
                </h3>
            </div>
            <div class="tr-sidebar p-6 border border-purple-900/30 relative overflow-hidden rounded-2xl">
                <p class="text-[11px] uppercase tracking-wider text-purple-300/70 font-semibold mb-2">Est. Inventory Retail Value</p>
                <h3 class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-fuchsia-300">
                    ₱<?php echo number_format($totalStockValue, 2); ?>
                </h3>
            </div>
        </div>

        <!-- Main Workspace Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">

            <!-- LEFT SIDEBAR: Card Preview & Drag-and-Drop Containers -->
            <div class="lg:col-span-1 space-y-4 sticky top-6">
                
                <!-- Card Preview Drop Zone Container -->
                <div class="tr-sidebar p-5 rounded-2xl border border-purple-900/30">
                    <h2 class="text-xs font-bold text-white uppercase tracking-wider mb-3 pb-2 border-b border-purple-900/30">Card Preview</h2>
                    <div id="cardPreviewContainer" class="flex flex-col items-center justify-center text-center min-h-[260px]">
                        <div id="previewDefaultState" class="text-zinc-600 flex flex-col items-center justify-center space-y-3 py-10">
                            <p class="text-[11px] text-zinc-500 font-medium">Hover or drag stock row here to preview card</p>
                        </div>
                        <div id="previewActiveState" class="hidden w-full flex flex-col items-center space-y-3 text-left">
                            <div class="w-full h-52 bg-[#0a0810] border border-purple-900/40 rounded-xl p-2 flex items-center justify-center overflow-hidden relative">
                                <span id="previewDot" class="absolute top-2 right-2 w-3.5 h-3.5 rounded-full z-10 hidden shadow-md"></span>
                                <img id="previewImage" src="" alt="Card Preview" class="max-h-full max-w-full object-contain rounded drop-shadow-2xl">
                            </div>
                            <div class="w-full space-y-1">
                                <h3 id="previewCardName" class="text-sm font-bold text-white leading-tight"></h3>
                                <p id="previewShelfLoc" class="text-[11px] font-semibold text-purple-300/80"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Storage Containers Side List -->
                <div class="space-y-3" id="dropzoneContainers">
                    <div class="flex items-center justify-between px-1">
                        <span class="text-[11px] font-bold text-purple-300/70 uppercase tracking-wider">Containers</span>
                        <button type="button" onclick="resetContainerFilter()" id="clearContainerBtn" class="text-[10px] text-purple-400 hover:text-white hidden underline">Show All</button>
                    </div>
                    <?php foreach ($storageContainers as $container): 
                        $boxClass = 'container-default';
                        $codeLower = strtolower($container['code']);
                        if ($codeLower === 'box 1') $boxClass = 'container-box-1';
                        elseif ($codeLower === 'box 2') $boxClass = 'container-box-2';
                        elseif ($codeLower === 'box 3') $boxClass = 'container-box-3';
                        elseif ($codeLower === 'box 4') $boxClass = 'container-box-4';
                        elseif ($codeLower === 'shelf 1') $boxClass = 'container-shelf-1';
                    ?>
                        <div class="box-dropzone storage-card p-4 rounded-xl flex items-center justify-between cursor-pointer border <?php echo $boxClass; ?>"
                             data-box-code="<?php echo htmlspecialchars($container['code']); ?>"
                             data-box-id="<?php echo $container['id']; ?>"
                             onclick="filterByContainer('<?php echo htmlspecialchars($container['code']); ?>', this)">
                            <div>
                                <h3 class="text-base font-bold text-white tracking-wide"><?php echo htmlspecialchars($container['title']); ?></h3>
                                <p class="text-xs text-zinc-400 font-medium mt-0.5">
                                    Stocks: <span class="stock-count text-zinc-200 font-bold" id="count-<?php echo $container['id']; ?>"><?php echo $container['stocks']; ?></span> / <?php echo $container['max']; ?>
                                </p>
                            </div>
                            <div class="text-purple-400/50 text-xl font-bold">
                                📦
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Add New Container Button -->
                <button type="button" onclick="openAddContainerModal()" class="w-full py-3 bg-purple-950/40 hover:bg-purple-900/50 border border-dashed border-purple-600/40 hover:border-purple-500 text-purple-300 text-xs font-bold rounded-xl transition-all flex items-center justify-center space-x-2">
                    <span>+ Add New Box / Shelf</span>
                </button>

            </div>

            <!-- CENTER COLUMN: Stock Inventory Table -->
            <div class="lg:col-span-3 tr-sidebar overflow-hidden rounded-2xl border border-purple-900/30">
                <form id="inventoryForm" method="POST" action="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>" onkeydown="handleEnterSubmit(event)">
                    <input type="hidden" name="action" id="inventoryFormAction" value="batch_delete">

                    <div class="px-6 py-4 border-b border-purple-900/30 flex justify-between items-center bg-[#0d0a14]/95">
                        <h2 class="text-xs font-bold text-white uppercase tracking-wider">Inventory Stock Records</h2>
                        <span id="activeContainerLabel" class="text-xs font-bold text-purple-400 hidden"></span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse inventory-table table-fixed">
                            <thead>
                                <tr class="bg-[#0a0810]/80 border-b border-purple-900/30 text-purple-300/70 uppercase tracking-wider font-semibold">
                                    <th class="text-center w-10">
                                        <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)" class="w-4 h-4 bg-[#0a0810] border border-purple-900/40 rounded text-purple-600 cursor-pointer">
                                    </th>
                                    <th class="w-1/3">Card Description</th>
                                    <th class="w-28">Type</th>
                                    <th class="text-right w-20">CC</th>
                                    <th class="text-right w-20">SP</th>
                                    <th class="text-center w-20">Stock Qty</th>
                                    <th class="w-32">Container</th>
                                    <th class="text-center w-14">C/NC</th>
                                    <th class="text-right w-20"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-purple-900/20 text-zinc-300" id="stockTableBody">
                                <?php if (empty($stocks)): ?>
                                    <tr id="emptyStockRow">
                                        <td colspan="9" class="py-12 text-center text-zinc-500">
                                            No inventory stocks found matching your filters.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stocks as $stock): ?>
                                        <tr class="hover:bg-purple-950/30 transition-colors stock-row cursor-grab active:cursor-grabbing" 
                                            draggable="true"
                                            id="stock-row-<?php echo $stock['id']; ?>"
                                            data-stock-id="<?php echo $stock['id']; ?>"
                                            data-card-id="<?php echo $stock['card_master_id']; ?>"
                                            data-quantity="<?php echo (int)$stock['quantity']; ?>"
                                            data-buy-price="<?php echo (float)($stock['buy_price'] ?? 0); ?>"
                                            data-sell-price="<?php echo (float)($stock['sell_price'] ?? $stock['price'] ?? 0); ?>"
                                            data-price="<?php echo (float)($stock['sell_price'] ?? $stock['price'] ?? 0); ?>"
                                            data-condition="<?php echo htmlspecialchars($stock['condition_status'] ?? 'Near Mint (NM)'); ?>"
                                            data-cosigned="<?php echo (int)($stock['is_cosigned'] ?? 0); ?>"
                                            data-name="<?php echo htmlspecialchars($stock['card_name'] ?? 'Unknown Card'); ?>"
                                            data-number="<?php echo htmlspecialchars($stock['card_number'] ?? '-'); ?>"
                                            data-image="<?php echo htmlspecialchars($stock['image_url'] ?? ''); ?>"
                                            data-type="<?php echo htmlspecialchars(strtolower(trim($stock['pokemon_type'] ?? ''))); ?>"
                                            data-category="<?php echo htmlspecialchars($stock['card_category'] ?? ''); ?>"
                                            data-shelf="<?php echo htmlspecialchars($stock['shelf_location'] ?? 'Unassigned'); ?>">
                                            
                                            <td class="text-center align-middle" onclick="event.stopPropagation();">
                                                <input type="checkbox" name="stock_ids[]" value="<?php echo $stock['id']; ?>" onchange="updateBatchActionState()" class="stock-checkbox w-4 h-4 bg-[#0a0810] border border-purple-900/40 rounded text-purple-600 cursor-pointer">
                                            </td>

                                            <!-- 1. Card Description -->
                                            <td class="font-medium text-white align-middle">
                                                <div class="flex items-center space-x-2.5">
                                                    <?php if (!empty($stock['image_url'])): ?>
                                                        <img src="<?php echo htmlspecialchars($stock['image_url']); ?>" class="card-thumb w-7 h-10 object-contain rounded border border-purple-900/30 shrink-0">
                                                    <?php endif; ?>
                                                    <div class="space-y-0.5 min-w-0 flex-1">
                                                        <div class="text-white font-semibold truncate leading-tight text-xs card-name-display"><?php echo htmlspecialchars($stock['card_name'] ?? 'Unknown Card'); ?></div>
                                                        <div class="text-[10px] text-purple-300/50">No. <?php echo htmlspecialchars($stock['card_number'] ?? '-'); ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- 2. Pokemon Type Column -->
                                            <td class="align-middle" onclick="event.stopPropagation();">
                                                <?php $currentType = strtolower(trim($stock['pokemon_type'] ?? '')); ?>
                                                <select onchange="saveTypeChange(<?php echo (int)$stock['card_master_id']; ?>, this)" 
                                                        data-type="<?php echo htmlspecialchars($currentType); ?>"
                                                        class="type-select w-full bg-[#0a0810] border text-[11px] rounded px-2 py-1 focus:outline-none focus:border-purple-400 cursor-pointer capitalize">
                                                    <option value="" class="bg-[#0d0a14] text-zinc-400" <?php echo empty($currentType) ? 'selected' : ''; ?>>-- Type --</option>
                                                    <option value="grass" <?php echo $currentType === 'grass' ? 'selected' : ''; ?>>Grass</option>
                                                    <option value="fire" <?php echo $currentType === 'fire' ? 'selected' : ''; ?>>Fire</option>
                                                    <option value="water" <?php echo $currentType === 'water' ? 'selected' : ''; ?>>Water</option>
                                                    <option value="electric" <?php echo $currentType === 'electric' ? 'selected' : ''; ?>>Electric</option>
                                                    <option value="psychic" <?php echo $currentType === 'psychic' ? 'selected' : ''; ?>>Psychic</option>
                                                    <option value="ground" <?php echo $currentType === 'ground' ? 'selected' : ''; ?>>Ground</option>
                                                    <option value="dark" <?php echo $currentType === 'dark' ? 'selected' : ''; ?>>Darkness</option>
                                                    <option value="metal" <?php echo $currentType === 'metal' ? 'selected' : ''; ?>>Metal</option>
                                                    <option value="fairy" <?php echo $currentType === 'fairy' ? 'selected' : ''; ?>>Fairy</option>
                                                    <option value="dragon" <?php echo $currentType === 'dragon' ? 'selected' : ''; ?>>Dragon</option>
                                                    <option value="normal" <?php echo $currentType === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                                    <option value="trainers" <?php echo $currentType === 'trainers' ? 'selected' : ''; ?>>Trainers</option>
                                                    <option value="tools" <?php echo $currentType === 'tools' ? 'selected' : ''; ?>>Tools</option>
                                                    <option value="map location" <?php echo $currentType === 'map location' ? 'selected' : ''; ?>>Map Location Cards</option>
                                                </select>
                                            </td>

                                            <!-- 3. Buy Price (CC) -->
                                            <td class="text-right font-medium text-purple-300/80 align-middle buy-price-display">
                                                ₱<?php echo number_format($stock['buy_price'] ?? 0, 2); ?>
                                            </td>

                                            <!-- 4. Sell Price (SP) -->
                                            <td class="text-right font-bold text-emerald-400 align-middle sell-price-display">
                                                ₱<?php echo number_format($stock['sell_price'] ?? $stock['price'] ?? 0, 2); ?>
                                            </td>

                                            <!-- 5. Stock Qty -->
                                            <td class="text-center align-middle" onclick="event.stopPropagation();">
                                                <input type="number" min="0" value="<?php echo (int)$stock['quantity']; ?>" 
                                                       onchange="saveQuantityChange(<?php echo $stock['id']; ?>, this.value)"
                                                       class="qty-inline-input w-12 text-center bg-[#140f26] border border-purple-600/60 text-purple-200 font-bold px-1 py-0.5 rounded shadow-inner focus:outline-none focus:ring-1 focus:ring-purple-400 text-xs">
                                            </td>

                                            <!-- 6. Physical Shelf Location (Container) -->
                                            <td class="align-middle" onclick="event.stopPropagation();">
                                                <?php 
                                                    $shelfVal = trim($stock['shelf_location'] ?? '');
                                                    $shelfLower = strtolower($shelfVal);
                                                    $pillClass = 'shelf-pill-default';
                                                    if ($shelfLower === 'box 1') $pillClass = 'shelf-pill-box-1';
                                                    elseif ($shelfLower === 'box 2') $pillClass = 'shelf-pill-box-2';
                                                    elseif ($shelfLower === 'box 3') $pillClass = 'shelf-pill-box-3';
                                                    elseif ($shelfLower === 'box 4') $pillClass = 'shelf-pill-box-4';
                                                    elseif ($shelfLower === 'shelf 1') $pillClass = 'shelf-pill-shelf-1';
                                                ?>
                                                <select onchange="saveIndividualShelfLocation(this, <?php echo $stock['id']; ?>)" 
                                                        class="shelf-select w-full rounded px-2 py-1 text-[11px] focus:outline-none focus:border-purple-500 font-semibold cursor-pointer truncate <?php echo $pillClass; ?>">
                                                    <option value="" class="bg-[#0d0a14] text-white" <?php echo empty($shelfVal) ? 'selected' : ''; ?>>Unassigned</option>
                                                    <?php foreach ($storageContainers as $container): ?>
                                                        <option value="<?php echo htmlspecialchars($container['code']); ?>" 
                                                                class="bg-[#0d0a14] text-white"
                                                                <?php echo strcasecmp($shelfVal, $container['code']) === 0 ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($container['title']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>

                                            <!-- 7. Status (C/NC) -->
                                            <td class="text-center align-middle cosigned-display">
                                                <?php if (!empty($stock['is_cosigned'])): ?>
                                                    <span class="text-amber-400 text-sm" title="Consigned (C)">★</span>
                                                <?php else: ?>
                                                    <span class="text-blue-400 text-xs" title="Non-Consigned (NC)">●</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- 8. Actions -->
                                            <td class="text-right whitespace-nowrap align-middle" onclick="event.stopPropagation();">
                                                <button type="button" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($stock), ENT_QUOTES, 'UTF-8'); ?>)' class="text-[11px] text-purple-300 hover:text-white px-2 py-0.5 bg-purple-950/40 border border-purple-900/30 rounded font-medium">Edit</button>
                                                <button type="button" onclick="submitSingleDelete(<?php echo $stock['id']; ?>)" class="text-[11px] text-rose-300 hover:text-rose-100 px-2 py-0.5 bg-rose-950/40 border border-rose-900/30 rounded font-medium">X</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <input type="hidden" name="stock_id" id="single_stock_id" value="">
                </form>
            </div>

            <!-- RIGHT SIDEBAR: Sticky Filter Panel & Permanent Sortment -->
            <div class="lg:col-span-1 sticky top-6 space-y-4">
                
                <!-- 1. Filters Block -->
                <div class="tr-sidebar p-5 rounded-2xl border border-purple-900/30 shadow-xl">
                    <h2 class="text-xs font-bold text-white uppercase tracking-wider mb-4 pb-2 border-b border-purple-900/30 flex items-center justify-between">
                        <span>Filters</span>
                        <a href="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>" class="text-[10px] text-purple-400 hover:text-purple-200 normal-case font-medium">Reset All</a>
                    </h2>
                    
                    <form method="GET" action="" id="filterForm" class="space-y-4">
                        <!-- Global Search Bar -->
                        <div>
                            <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">Global Search</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search cards..." class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none placeholder-zinc-600 focus:border-purple-500 transition-colors">
                        </div>

                        <!-- Pokemon Type Filter -->
                        <div>
                            <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">Pokemon Type</label>
                            <select name="pokemon_type" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none focus:border-purple-500 transition-colors">
                                <option value="">All Types</option>
                                <option value="grass" <?php echo strtolower($typeFilter) === 'grass' ? 'selected' : ''; ?>>Grass</option>
                                <option value="fire" <?php echo strtolower($typeFilter) === 'fire' ? 'selected' : ''; ?>>Fire</option>
                                <option value="water" <?php echo strtolower($typeFilter) === 'water' ? 'selected' : ''; ?>>Water</option>
                                <option value="electric" <?php echo strtolower($typeFilter) === 'electric' ? 'selected' : ''; ?>>Electric</option>
                                <option value="psychic" <?php echo strtolower($typeFilter) === 'psychic' ? 'selected' : ''; ?>>Psychic</option>
                                <option value="ground" <?php echo strtolower($typeFilter) === 'ground' ? 'selected' : ''; ?>>Ground</option>
                                <option value="dark" <?php echo strtolower($typeFilter) === 'dark' ? 'selected' : ''; ?>>Darkness</option>
                                <option value="metal" <?php echo strtolower($typeFilter) === 'metal' ? 'selected' : ''; ?>>Metal</option>
                                <option value="fairy" <?php echo strtolower($typeFilter) === 'fairy' ? 'selected' : ''; ?>>Fairy</option>
                                <option value="dragon" <?php echo strtolower($typeFilter) === 'dragon' ? 'selected' : ''; ?>>Dragon</option>
                                <option value="normal" <?php echo strtolower($typeFilter) === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                <option value="trainers" <?php echo strtolower($typeFilter) === 'trainers' ? 'selected' : ''; ?>>Trainers</option>
                                <option value="tools" <?php echo strtolower($typeFilter) === 'tools' ? 'selected' : ''; ?>>Tools</option>
                                <option value="map location" <?php echo strtolower($typeFilter) === 'map location' ? 'selected' : ''; ?>>Map Location Cards</option>
                            </select>
                        </div>

                        <!-- Category Filter (Cute, Cool, Normal) -->
                        <div>
                            <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">Category Filter</label>
                            <select name="card_category" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none focus:border-purple-500 transition-colors">
                                <option value="">All Categories</option>
                                <option value="Cute" <?php echo $categoryFilter === 'Cute' ? 'selected' : ''; ?>>Cute</option>
                                <option value="Cool" <?php echo $categoryFilter === 'Cool' ? 'selected' : ''; ?>>Cool</option>
                                <option value="Normal" <?php echo $categoryFilter === 'Normal' ? 'selected' : ''; ?>>Normal</option>
                            </select>
                        </div>

                        <!-- Status Filter (Consigned / Non-Consigned) -->
                        <div>
                            <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">Cosigned Status</label>
                            <select name="cosigned" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none focus:border-purple-500 transition-colors">
                                <option value="">All Statuses</option>
                                <option value="1" <?php echo $cosignFilter === '1' ? 'selected' : ''; ?>>Consigned</option>
                                <option value="0" <?php echo $cosignFilter === '0' ? 'selected' : ''; ?>>Non-Consigned</option>
                            </select>
                        </div>

                        <!-- Condition Filter -->
                        <div>
                            <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">Condition Filter</label>
                            <select name="condition" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none focus:border-purple-500 transition-colors">
                                <option value="">All Conditions</option>
                                <option value="Near Mint (NM)" <?php echo $conditionFilter === 'Near Mint (NM)' ? 'selected' : ''; ?>>Near Mint (NM)</option>
                                <option value="Lightly Played (LP)" <?php echo $conditionFilter === 'Lightly Played (LP)' ? 'selected' : ''; ?>>Lightly Played (LP)</option>
                                <option value="Moderately Played (MP)" <?php echo $conditionFilter === 'Moderately Played (MP)' ? 'selected' : ''; ?>>Moderately Played (MP)</option>
                                <option value="Heavily Played (HP)" <?php echo $conditionFilter === 'Heavily Played (HP)' ? 'selected' : ''; ?>>Heavily Played (HP)</option>
                                <option value="Damaged (DMG)" <?php echo $conditionFilter === 'Damaged (DMG)' ? 'selected' : ''; ?>>Damaged (DMG)</option>
                            </select>
                        </div>

                        <div class="pt-2">
                            <button type="submit" class="w-full purple-btn text-white text-xs font-semibold py-2.5 rounded-lg flex items-center justify-center space-x-2">
                                <span>Apply Filters</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- 2. Permanent Sortment Section (Directly Underneath Filters) -->
                <div class="tr-sidebar p-5 rounded-2xl border border-purple-900/30 shadow-xl">
                    <h2 class="text-xs font-bold text-white uppercase tracking-wider mb-3 pb-2 border-b border-purple-900/30 flex items-center justify-between">
                        <span>Permanent Sortment</span>
                        <span class="text-[10px] text-purple-400 font-normal">Auto-Saved</span>
                    </h2>
                    
                    <div class="space-y-2">
                        <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider">Display Sort Order</label>
                        <select id="persistentSortSelect" onchange="changePersistentSort(this.value)" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2.5 focus:outline-none focus:border-purple-500 font-semibold cursor-pointer">
                            <option value="default">Default (Newest First)</option>
                            <optgroup label="Alphabetical & Numerical">
                                <option value="a-z">Card Name (A-Z)</option>
                                <option value="z-a">Card Name (Z-A)</option>
                                <option value="qty-desc">Quantity (High to Low)</option>
                                <option value="qty-asc">Quantity (Low to High)</option>
                                <option value="price-desc">Price (High to Low)</option>
                                <option value="price-asc">Price (Low to High)</option>
                            </optgroup>
                            <optgroup label="Pokémon Type Priority">
                                <option value="type-grass">Priority: Grass</option>
                                <option value="type-fire">Priority: Fire</option>
                                <option value="type-water">Priority: Water</option>
                                <option value="type-electric">Priority: Electric</option>
                                <option value="type-ground">Priority: Ground</option>
                                <option value="type-psychic">Priority: Psychic</option>
                                <option value="type-fairy">Priority: Fairy</option>
                                <option value="type-dark">Priority: Darkness</option>
                                <option value="type-metal">Priority: Metal</option>
                                <option value="type-dragon">Priority: Dragon</option>
                                <option value="type-normal">Priority: Normal</option>
                                <option value="type-trainers">Priority: Trainers</option>
                                <option value="type-tools">Priority: Tools</option>
                                <option value="type-map location">Priority: Map Location Cards</option>
                            </optgroup>
                        </select>
                        <p class="text-[10px] text-zinc-500 mt-1">This preference is saved on your device and will stay active across sessions.</p>
                    </div>
                </div>

            </div>

        </div>

        <!-- FLOATING STICKY QUICK ACTION BUTTON -->
        <button type="button" onclick="submitBatchChangesFromFloatingBtn()" class="floating-save-btn bg-gradient-to-r from-purple-600 to-fuchsia-600 hover:from-purple-500 hover:to-fuchsia-500 text-white font-bold text-xs px-5 py-3.5 rounded-full flex items-center space-x-2 border border-purple-400/30">
            <span class="text-base">💾</span>
            <span>Save Stock Changes</span>
            <span id="floatingSelectedCount" class="bg-purple-950/80 text-purple-200 border border-purple-400/40 text-[10px] px-2 py-0.5 rounded-full font-extrabold ml-1">0</span>
        </button>

        <!-- STICKY SCROLL-PROOF BATCH CHANGER FLOATING UPPER RIGHT CONTROL PANEL -->
        <div id="batchChangerPanel" class="fixed top-6 right-6 z-50 w-72 p-4 bg-[#120e21]/95 border border-purple-600/50 rounded-2xl shadow-2xl backdrop-blur-md hidden-panel">
            <div class="flex items-center justify-between border-b border-purple-900/40 pb-3 mb-3">
                <div class="flex items-center space-x-2">
                    <span class="w-2.5 h-2.5 bg-purple-500 rounded-full animate-pulse"></span>
                    <h3 class="text-xs font-bold text-white uppercase tracking-wider">Batch Operations</h3>
                </div>
                <span class="text-[11px] font-bold bg-purple-900/60 border border-purple-600/40 text-purple-300 px-2 py-0.5 rounded-full">
                    <span class="selected-count-display">0</span> Selected
                </span>
            </div>

            <div class="grid grid-cols-1 gap-2">
                <button type="button" onclick="openBatchChangeLocationModal()" class="w-full text-left text-xs text-emerald-300 hover:bg-emerald-950/80 px-3.5 py-2 bg-emerald-950/40 border border-emerald-900/50 rounded-xl font-semibold transition-all flex items-center justify-between">
                    <span>📍 Change Location</span>
                    <span class="text-[10px] text-emerald-400">⚡</span>
                </button>
                <button type="button" onclick="openBatchChangeCategoryModal()" class="w-full text-left text-xs text-fuchsia-300 hover:bg-fuchsia-950/80 px-3.5 py-2 bg-fuchsia-950/40 border border-fuchsia-900/50 rounded-xl font-semibold transition-all flex items-center justify-between">
                    <span>🏷️ Change Category</span>
                    <span class="text-[10px] text-fuchsia-400">⚡</span>
                </button>
                <button type="button" onclick="openBatchChangeTypeModal()" class="w-full text-left text-xs text-amber-300 hover:bg-amber-950/80 px-3.5 py-2 bg-amber-950/40 border border-amber-900/50 rounded-xl font-semibold transition-all flex items-center justify-between">
                    <span>🔥 Change Pokemon Type</span>
                    <span class="text-[10px] text-amber-400">⚡</span>
                </button>
                <button type="button" onclick="submitBatchDelete()" class="w-full text-left text-xs text-rose-300 hover:bg-rose-950/80 px-3.5 py-2 bg-rose-950/40 border border-rose-900/50 rounded-xl font-semibold transition-all flex items-center justify-between">
                    <span>🗑️ Delete Selected</span>
                    <span class="text-[10px] text-rose-400">⚡</span>
                </button>
            </div>
        </div>

        <!-- TIMED POPUP NOTIFICATION TOAST (LOWER RIGHT) -->
        <div id="notificationToast" class="fixed bottom-6 right-6 z-50 max-w-sm w-full p-4 rounded-2xl shadow-2xl border backdrop-blur-md toast-hidden flex items-start space-x-3">
            <div id="toastIcon" class="text-xl shrink-0">✨</div>
            <div class="flex-1">
                <h4 id="toastTitle" class="text-xs font-bold uppercase tracking-wider mb-0.5 text-white">Notification</h4>
                <p id="toastMessage" class="text-xs font-medium leading-relaxed text-zinc-200"></p>
            </div>
            <button type="button" onclick="hideToast()" class="text-zinc-400 hover:text-white text-xs font-bold px-1">✕</button>
        </div>

    </main>

    <!-- Modal: Add New Container -->
    <div id="addContainerModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="tr-sidebar max-w-sm w-full p-6 border border-purple-900/40 relative rounded-2xl">
            <h2 class="text-base font-bold text-white mb-4">Add New Box or Shelf</h2>
            <form id="addContainerForm" onsubmit="handleAddNewContainer(event)" class="space-y-4" onkeydown="handleEnterSubmit(event)">
                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Container Title</label>
                    <input type="text" id="newContainerTitle" placeholder="e.g. Box #05 or Shelf #02" required class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Shelf Code / Identifier</label>
                    <input type="text" id="newContainerCode" placeholder="e.g. Box 5" required class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Max Capacity</label>
                    <input type="number" id="newContainerMax" value="250" min="1" required class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                </div>
                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeAddContainerModal()" class="text-xs text-zinc-400 hover:text-white px-4 py-2">Cancel</button>
                    <button type="submit" class="purple-btn text-white text-xs font-semibold px-5 py-2 rounded-lg">Create Container</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Standard Edit Stock Modal -->
    <div id="editStockModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="tr-sidebar max-w-md w-full p-6 border border-purple-900/40 relative rounded-2xl">
            <h2 class="text-base font-bold text-white mb-4">Edit Stock Record</h2>

            <form id="editStockForm" method="POST" action="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>" onsubmit="handleEditStockSubmit(event)" class="space-y-4" onkeydown="handleEnterSubmit(event)">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="stock_id" id="editStockId" value="">

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Card Item</label>
                    <input type="text" id="editCardName" readonly class="w-full bg-[#0a0810]/60 border border-purple-900/20 text-purple-300/70 text-xs rounded-lg px-3.5 py-2 cursor-not-allowed">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Card Image URL</label>
                    <input type="url" name="image_url" id="editImageUrl" placeholder="https://example.com/card-image.jpg" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Physical Shelf Location</label>
                    <input type="text" name="shelf_location" id="editShelfLocation" placeholder="e.g. Box 1" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Quantity</label>
                        <input type="number" name="quantity" id="editQuantity" min="0" required class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Condition</label>
                        <select name="card_condition" id="editCondition" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                            <option value="Near Mint (NM)">Near Mint (NM)</option>
                            <option value="Lightly Played (LP)">Lightly Played (LP)</option>
                            <option value="Moderately Played (MP)">Moderately Played (MP)</option>
                            <option value="Heavily Played (HP)">Heavily Played (HP)</option>
                            <option value="Damaged (DMG)">Damaged (DMG)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">Card Category</label>
                    <div class="flex items-center space-x-6 bg-[#0a0810] border border-purple-900/40 rounded-lg px-3.5 py-2.5">
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="radio" name="card_category" value="" id="editCategoryNone" class="w-4 h-4 bg-[#0a0810] border border-purple-900/40 text-purple-600">
                            <span class="text-xs text-zinc-400">Standard (None)</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="radio" name="card_category" value="Cute" id="editCategoryCute" class="w-4 h-4 bg-[#0a0810] border border-purple-900/40 text-fuchsia-600">
                            <span class="text-xs text-fuchsia-300 font-semibold">Cute Card</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="radio" name="card_category" value="Cool" id="editCategoryCool" class="w-4 h-4 bg-[#0a0810] border border-purple-900/40 text-purple-600">
                            <span class="text-xs text-purple-300 font-semibold">Cool Card</span>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Buy Price (₱)</label>
                        <input type="number" step="0.01" name="buy_price" id="editBuyPrice" min="0" required class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Sell Price (₱)</label>
                        <input type="number" step="0.01" name="sell_price" id="editSellPrice" min="0" required class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                    </div>
                </div>

                <div class="flex items-center space-x-2 pt-2">
                    <input type="checkbox" name="is_cosigned" id="editIsCosigned" value="1" class="w-4 h-4 bg-[#0a0810] border border-purple-900/40 rounded text-purple-600">
                    <label for="editIsCosigned" class="text-xs font-medium text-purple-200/80">Mark as Consigned Stock</label>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeEditModal()" class="text-xs text-zinc-400 hover:text-white px-4 py-2">Cancel</button>
                    <button type="submit" class="purple-btn text-white text-xs font-semibold px-5 py-2 rounded-lg">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Batch Change Pokémon Type Modal -->
    <div id="batchChangeTypeModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="tr-sidebar max-w-sm w-full p-6 border border-purple-900/40 relative rounded-2xl">
            <h2 class="text-base font-bold text-white mb-4">Batch Change Pokémon Type</h2>

            <form method="POST" action="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>" class="space-y-4" id="batchChangeTypeForm" onsubmit="handleBatchTypeSubmit(event)" onkeydown="handleEnterSubmit(event)">
                <input type="hidden" name="action" value="batch_update_type">
                <div id="batchChangeTypeInputs"></div>

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-1">Select New Type for <span id="batchTypeCount">0</span> Cards</label>
                    <select name="pokemon_type" required class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2">
                        <option value="grass">Grass</option>
                        <option value="fire">Fire</option>
                        <option value="water">Water</option>
                        <option value="electric">Electric</option>
                        <option value="psychic">Psychic</option>
                        <option value="ground">Ground</option>
                        <option value="dark">Darkness</option>
                        <option value="metal">Metal</option>
                        <option value="fairy">Fairy</option>
                        <option value="dragon">Dragon</option>
                        <option value="normal">Normal</option>
                        <option value="trainers">Trainers</option>
                        <option value="tools">Tools</option>
                        <option value="map location">Map Location Cards</option>
                    </select>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeBatchChangeTypeModal()" class="text-xs text-zinc-400 hover:text-white px-4 py-2">Cancel</button>
                    <button type="submit" class="purple-btn text-white text-xs font-semibold px-5 py-2 rounded-lg">Update Type</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Batch Change Card Category Modal -->
    <div id="batchChangeCategoryModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="tr-sidebar max-w-sm w-full p-6 border border-purple-900/40 relative rounded-2xl">
            <h2 class="text-base font-bold text-white mb-4">Batch Change Card Category</h2>

            <form method="POST" action="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>" class="space-y-4" id="batchChangeCategoryForm" onsubmit="handleBatchCategorySubmit(event)" onkeydown="handleEnterSubmit(event)">
                <input type="hidden" name="action" value="batch_update_category">
                <div id="batchChangeCategoryInputs"></div>

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">Select Category for <span id="batchCategoryCount">0</span> Items</label>
                    <div class="space-y-2 bg-[#0a0810] border border-purple-900/40 rounded-lg p-3">
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="radio" name="card_category" value="" checked class="w-4 h-4 bg-[#0a0810] border border-purple-900/40 text-purple-600">
                            <span class="text-xs text-zinc-400">Standard / Clear Category</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="radio" name="card_category" value="Cute" class="w-4 h-4 bg-[#0a0810] border border-purple-900/40 text-fuchsia-600">
                            <span class="text-xs text-fuchsia-300 font-semibold">Cute Card</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="radio" name="card_category" value="Cool" class="w-4 h-4 bg-[#0a0810] border border-purple-900/40 text-purple-600">
                            <span class="text-xs text-purple-300 font-semibold">Cool Card</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeBatchChangeCategoryModal()" class="text-xs text-zinc-400 hover:text-white px-4 py-2">Cancel</button>
                    <button type="submit" class="purple-btn text-white text-xs font-semibold px-5 py-2 rounded-lg">Update Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Batch Change Location Modal -->
    <div id="batchChangeLocationModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="tr-sidebar max-w-sm w-full p-6 border border-purple-900/40 relative rounded-2xl">
            <h2 class="text-base font-bold text-white mb-4">Batch Change Shelf Location</h2>

            <form method="POST" action="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>" class="space-y-4" id="batchChangeLocationForm" onsubmit="handleBatchLocationSubmit(event)" onkeydown="handleEnterSubmit(event)">
                <input type="hidden" name="action" value="batch_update_location">
                <div id="batchChangeLocationInputs"></div>

                <div>
                    <label class="block text-[11px] font-semibold text-purple-200/80 uppercase tracking-wider mb-2">New Shelf Location for <span id="batchLocationCount">0</span> Items</label>
                    <select name="shelf_location" class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-xs rounded-lg px-3.5 py-2 focus:outline-none">
                        <option value="">Unassigned</option>
                        <?php foreach ($storageContainers as $container): ?>
                            <option value="<?php echo htmlspecialchars($container['code']); ?>">
                                <?php echo htmlspecialchars($container['title']); ?> (<?php echo htmlspecialchars($container['code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeBatchChangeLocationModal()" class="text-xs text-zinc-400 hover:text-white px-4 py-2">Cancel</button>
                    <button type="submit" class="purple-btn text-white text-xs font-semibold px-5 py-2 rounded-lg">Update Location</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- PRINT ONLY SECTION: Inventory Stock List Grid -->
    <div id="printSection" class="hidden">
        <h2 class="print-header">Inventory Stock List</h2>
        <div class="print-grid">
            <?php if (!empty($stocks)): ?>
                <?php foreach ($stocks as $stock): ?>
                    <div class="print-item">
                        <div class="item-name"><?php echo htmlspecialchars($stock['card_name'] ?? 'Unknown Card'); ?> (No. <?php echo htmlspecialchars($stock['card_number'] ?? '-'); ?>)</div>
                        <div class="item-details">
                            <span class="item-stat"><strong>Qty:</strong><?php echo (int)$stock['quantity']; ?></span>
                            <span class="item-stat"><strong>Cost:</strong>₱<?php echo number_format($stock['buy_price'] ?? 0, 2); ?></span>
                            <span class="item-stat"><strong>Sell:</strong>₱<?php echo number_format($stock['sell_price'] ?? $stock['price'] ?? 0, 2); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="print-item" style="grid-column: span 2; text-align: center;">No items found.</div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let toastTimer = null;
        let activeContainerCode = null;

        // Permanent Sortment Logic
        function changePersistentSort(sortKey) {
            localStorage.setItem('stocks_sort_order', sortKey);
            applyTableSort(sortKey);
        }

        function applyTableSort(sortKey) {
            const tbody = document.getElementById('stockTableBody');
            if (!tbody) return;

            const rows = Array.from(tbody.querySelectorAll('.stock-row'));
            if (rows.length === 0) return;

            rows.sort((a, b) => {
                const nameA = (a.getAttribute('data-name') || '').toLowerCase();
                const nameB = (b.getAttribute('data-name') || '').toLowerCase();
                const qtyA = parseInt(a.getAttribute('data-quantity') || '0', 10);
                const qtyB = parseInt(b.getAttribute('data-quantity') || '0', 10);
                const priceA = parseFloat(a.getAttribute('data-price') || '0');
                const priceB = parseFloat(b.getAttribute('data-price') || '0');
                const idA = parseInt(a.getAttribute('data-stock-id') || '0', 10);
                const idB = parseInt(b.getAttribute('data-stock-id') || '0', 10);
                const typeA = (a.getAttribute('data-type') || '').toLowerCase();
                const typeB = (b.getAttribute('data-type') || '').toLowerCase();

                if (sortKey === 'a-z') return nameA.localeCompare(nameB);
                if (sortKey === 'z-a') return nameB.localeCompare(nameA);
                if (sortKey === 'qty-desc') return qtyB - qtyA;
                if (sortKey === 'qty-asc') return qtyA - qtyB;
                if (sortKey === 'price-desc') return priceB - priceA;
                if (sortKey === 'price-asc') return priceA - priceB;
                if (sortKey === 'default') return idB - idA;

                if (sortKey.startsWith('type-')) {
                    const targetType = sortKey.replace('type-', '').toLowerCase();
                    const isATarget = typeA === targetType;
                    const isBTarget = typeB === targetType;

                    if (isATarget && !isBTarget) return -1;
                    if (!isATarget && isBTarget) return 1;
                    return nameA.localeCompare(nameB);
                }

                return 0;
            });

            rows.forEach(row => tbody.appendChild(row));
        }

        function initPersistentSort() {
            const savedSort = localStorage.getItem('stocks_sort_order') || 'default';
            const sortSelect = document.getElementById('persistentSortSelect');
            if (sortSelect) {
                sortSelect.value = savedSort;
            }
            applyTableSort(savedSort);
        }

        // Container Filtering Mechanism
        function filterByContainer(code, cardElement) {
            const rows = document.querySelectorAll('.stock-row');
            const clearBtn = document.getElementById('clearContainerBtn');
            const label = document.getElementById('activeContainerLabel');

            if (activeContainerCode === code) {
                resetContainerFilter();
                return;
            }

            activeContainerCode = code;
            document.querySelectorAll('.box-dropzone').forEach(el => el.classList.remove('active-container'));
            if (cardElement) cardElement.classList.add('active-container');

            let visibleCount = 0;
            rows.forEach(row => {
                const shelf = row.getAttribute('data-shelf') || '';
                if (shelf.toLowerCase() === code.toLowerCase()) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (clearBtn) clearBtn.classList.remove('hidden');
            if (label) {
                label.textContent = `Showing cards in ${code}`;
                label.classList.remove('hidden');
            }
        }

        function resetContainerFilter() {
            activeContainerCode = null;
            document.querySelectorAll('.box-dropzone').forEach(el => el.classList.remove('active-container'));
            document.querySelectorAll('.stock-row').forEach(row => row.style.display = '');

            const clearBtn = document.getElementById('clearContainerBtn');
            const label = document.getElementById('activeContainerLabel');

            if (clearBtn) clearBtn.classList.add('hidden');
            if (label) label.classList.add('hidden');
        }

        // Press Enter key listener handler to trigger submission across forms/inputs
        function handleEnterSubmit(e) {
            if (e.key === 'Enter') {
                if (e.target.tagName === 'TEXTAREA') return;
                e.preventDefault();
                if (e.target.form) {
                    e.target.blur();
                    e.target.form.requestSubmit();
                }
            }
        }

        // Play Synthesized Pop Sound via Web Audio API
        function playPopSound() {
            try {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx) return;
                const ctx = new AudioCtx();

                const osc = ctx.createOscillator();
                const gain = ctx.createGain();

                osc.type = 'sine';
                osc.frequency.setValueAtTime(400, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(800, ctx.currentTime + 0.08);

                gain.gain.setValueAtTime(0.3, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.08);

                osc.connect(gain);
                gain.connect(ctx.destination);

                osc.start();
                osc.stop(ctx.currentTime + 0.08);
            } catch (e) {
                console.warn('Audio playback not permitted or supported yet:', e);
            }
        }

        // Show Timed Floating Toast Notification
        function showNotificationToast(msg, type = 'success') {
            const toast = document.getElementById('notificationToast');
            const icon = document.getElementById('toastIcon');
            const title = document.getElementById('toastTitle');
            const text = document.getElementById('toastMessage');

            if (!toast) return;

            if (type === 'error') {
                toast.className = 'fixed bottom-6 right-6 z-50 max-w-sm w-full p-4 rounded-2xl shadow-2xl border backdrop-blur-md bg-rose-950/90 border-rose-500/50 text-rose-200 toast-visible flex items-start space-x-3';
                icon.textContent = '⚠️';
                title.textContent = 'Action Failed';
            } else {
                toast.className = 'fixed bottom-6 right-6 z-50 max-w-sm w-full p-4 rounded-2xl shadow-2xl border backdrop-blur-md bg-emerald-950/90 border-emerald-500/50 text-emerald-200 toast-visible flex items-start space-x-3';
                icon.textContent = '✅';
                title.textContent = 'Update Successful';
            }

            text.textContent = msg;

            playPopSound();

            if (toastTimer) clearTimeout(toastTimer);

            toastTimer = setTimeout(() => {
                hideToast();
            }, 3000);
        }

        function hideToast() {
            const toast = document.getElementById('notificationToast');
            if (toast) {
                toast.classList.remove('toast-visible');
                toast.classList.add('toast-hidden');
            }
        }

        function openEditModal(stock) {
            document.getElementById('editStockId').value = stock.id;
            document.getElementById('editCardName').value = (stock.card_name || 'Card') + ' (No. ' + (stock.card_number || 'N/A') + ')';
            document.getElementById('editImageUrl').value = stock.image_url || '';
            document.getElementById('editShelfLocation').value = stock.shelf_location || '';
            document.getElementById('editQuantity').value = stock.quantity || 1;
            document.getElementById('editCondition').value = stock.condition_status || 'Near Mint (NM)';
            document.getElementById('editBuyPrice').value = stock.buy_price || 0.00;
            document.getElementById('editSellPrice').value = stock.sell_price || stock.price || 0.00;
            document.getElementById('editIsCosigned').checked = stock.is_cosigned == 1;

            const category = stock.card_category || '';
            if (category === 'Cute') {
                document.getElementById('editCategoryCute').checked = true;
            } else if (category === 'Cool') {
                document.getElementById('editCategoryCool').checked = true;
            } else {
                document.getElementById('editCategoryNone').checked = true;
            }

            document.getElementById('editStockModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editStockModal').classList.add('hidden');
        }

        // Handle Edit Card Modal Submission via AJAX to preserve scroll position
        function handleEditStockSubmit(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.stock) {
                    const stock = data.stock;
                    const row = document.getElementById(`stock-row-${stock.id}`);

                    if (row) {
                        row.setAttribute('data-quantity', stock.quantity);
                        row.setAttribute('data-buy-price', stock.buy_price);
                        row.setAttribute('data-sell-price', stock.sell_price);
                        row.setAttribute('data-price', stock.sell_price);
                        row.setAttribute('data-condition', stock.condition_status);
                        row.setAttribute('data-cosigned', stock.is_cosigned);
                        row.setAttribute('data-image', stock.image_url);
                        row.setAttribute('data-category', stock.card_category || '');

                        const oldShelf = row.getAttribute('data-shelf') || '';
                        const newShelf = stock.shelf_location || '';
                        row.setAttribute('data-shelf', newShelf);

                        // Update Row Displays
                        const qtyInput = row.querySelector('.qty-inline-input');
                        if (qtyInput) qtyInput.value = stock.quantity;

                        const buyDisplay = row.querySelector('.buy-price-display');
                        if (buyDisplay) buyDisplay.textContent = `₱${parseFloat(stock.buy_price).toFixed(2)}`;

                        const sellDisplay = row.querySelector('.sell-price-display');
                        if (sellDisplay) sellDisplay.textContent = `₱${parseFloat(stock.sell_price).toFixed(2)}`;

                        const cosignedDisplay = row.querySelector('.cosigned-display');
                        if (cosignedDisplay) {
                            cosignedDisplay.innerHTML = stock.is_cosigned == 1 
                                ? '<span class="text-amber-400 text-sm" title="Consigned (C)">★</span>' 
                                : '<span class="text-blue-400 text-xs" title="Non-Consigned (NC)">●</span>';
                        }

                        const thumbImg = row.querySelector('.card-thumb');
                        if (thumbImg && stock.image_url) {
                            thumbImg.src = stock.image_url;
                        }

                        const shelfSelect = row.querySelector('.shelf-select');
                        if (shelfSelect) {
                            shelfSelect.value = newShelf;
                            shelfSelect.className = `shelf-select w-full rounded px-2 py-1 text-[11px] focus:outline-none focus:border-purple-500 font-semibold cursor-pointer truncate ${getShelfPillClass(newShelf)}`;
                        }

                        // Sync Container Counts
                        if (oldShelf.toLowerCase() !== newShelf.toLowerCase()) {
                            document.querySelectorAll('.box-dropzone').forEach(dz => {
                                const code = dz.getAttribute('data-box-code');
                                const id = dz.getAttribute('data-box-id');
                                if (code && oldShelf && code.toLowerCase() === oldShelf.toLowerCase()) {
                                    const countEl = document.getElementById(`count-${id}`);
                                    if (countEl) {
                                        const currentCount = parseInt(countEl.textContent, 10) || 0;
                                        countEl.textContent = Math.max(0, currentCount - parseInt(stock.quantity, 10));
                                    }
                                }
                                if (code && newShelf && code.toLowerCase() === newShelf.toLowerCase()) {
                                    const countEl = document.getElementById(`count-${id}`);
                                    if (countEl) {
                                        const currentCount = parseInt(countEl.textContent, 10) || 0;
                                        countEl.textContent = currentCount + parseInt(stock.quantity, 10);
                                    }
                                }
                            });
                        }
                    }

                    closeEditModal();
                    showNotificationToast("Stock details updated successfully!");
                } else if (data.error) {
                    showNotificationToast(data.error, "error");
                }
            })
            .catch(err => {
                console.error("Error submitting edit stock:", err);
                showNotificationToast("An error occurred while saving.", "error");
            });
        }

        // Save Type Dropdown Change directly & Update Color Attribute
        function saveTypeChange(cardId, selectElement) {
            if (!cardId) return;
            const newType = selectElement.value.trim().toLowerCase();

            selectElement.setAttribute('data-type', newType);
            const row = selectElement.closest('.stock-row');
            if (row) row.setAttribute('data-type', newType);

            const formData = new FormData();
            formData.append('action', 'update_type');
            formData.append('card_id', cardId);
            formData.append('pokemon_type', newType);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showNotificationToast(`Pokémon type changed to "${newType || 'Unassigned'}"`);
                    const savedSort = localStorage.getItem('stocks_sort_order') || 'default';
                    if (savedSort.startsWith('type-')) {
                        applyTableSort(savedSort);
                    }
                }
            })
            .catch(err => console.error('Error updating type:', err));
        }

        // Save Quantity Change directly
        function saveQuantityChange(stockId, newQty) {
            const qty = parseInt(newQty, 10);
            if (isNaN(qty) || qty < 0) return;

            const formData = new FormData();
            formData.append('action', 'update_quantity');
            formData.append('stock_id', stockId);
            formData.append('quantity', qty);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const row = document.getElementById(`stock-row-${stockId}`);
                    if (row) row.setAttribute('data-quantity', qty);
                    showNotificationToast(`Stock quantity updated to ${qty}`);
                }
            })
            .catch(err => console.error('Error updating stock quantity:', err));
        }

        // --- Container Creation ---
        function openAddContainerModal() {
            document.getElementById('addContainerModal').classList.remove('hidden');
        }

        function closeAddContainerModal() {
            document.getElementById('addContainerModal').classList.add('hidden');
        }

        function handleAddNewContainer(e) {
            e.preventDefault();
            const title = document.getElementById('newContainerTitle').value.trim();
            const code = document.getElementById('newContainerCode').value.trim();
            const max = document.getElementById('newContainerMax').value || 250;
            const id = 'custom-' + Date.now();

            if (!title || !code) return;

            let boxClass = 'container-default';
            const codeLower = code.toLowerCase();
            if (codeLower === 'box 1') boxClass = 'container-box-1';
            else if (codeLower === 'box 2') boxClass = 'container-box-2';
            else if (codeLower === 'box 3') boxClass = 'container-box-3';
            else if (codeLower === 'box 4') boxClass = 'container-box-4';
            else if (codeLower === 'shelf 1') boxClass = 'container-shelf-1';

            const containerDiv = document.createElement('div');
            containerDiv.className = `box-dropzone storage-card p-4 rounded-xl flex items-center justify-between cursor-pointer border ${boxClass}`;
            containerDiv.setAttribute('data-box-code', code);
            containerDiv.setAttribute('data-box-id', id);
            containerDiv.onclick = function() { filterByContainer(code, this); };
            containerDiv.innerHTML = `
                <div>
                    <h3 class="text-base font-bold text-white tracking-wide">${title}</h3>
                    <p class="text-xs text-zinc-400 font-medium mt-0.5">
                        Stocks: <span class="stock-count text-zinc-200 font-bold" id="count-${id}">0</span> / ${max}
                    </p>
                </div>
                <div class="text-purple-400/50 text-xl font-bold">📦</div>
            `;

            attachDropzoneEvents(containerDiv);
            document.getElementById('dropzoneContainers').appendChild(containerDiv);

            // Add new container to location dropdown options across the page
            document.querySelectorAll('select.shelf-select, select[name="shelf_location"]').forEach(select => {
                const opt = document.createElement('option');
                opt.value = code;
                opt.className = 'bg-[#0d0a14] text-white';
                opt.textContent = `${title} (${code})`;
                select.appendChild(opt);
            });

            document.getElementById('addContainerForm').reset();
            closeAddContainerModal();
            showNotificationToast('New storage container added!');
        }

        // --- Batch Actions ---
        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.stock-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
            updateBatchActionState();
        }

        function updateBatchActionState() {
            const checkboxes = document.querySelectorAll('.stock-checkbox:checked');
            const batchPanel = document.getElementById('batchChangerPanel');
            const count = checkboxes.length;
            
            document.querySelectorAll('.selected-count-display').forEach(el => el.textContent = count);
            const floatingCount = document.getElementById('floatingSelectedCount');
            if (floatingCount) floatingCount.textContent = count;

            if (count > 0) {
                batchPanel.classList.remove('hidden-panel');
                batchPanel.classList.add('visible-panel');
            } else {
                batchPanel.classList.remove('visible-panel');
                batchPanel.classList.add('hidden-panel');
            }
        }

        function submitFloatingSave() {
            const checkboxes = document.querySelectorAll('.stock-checkbox:checked');
            if (checkboxes.length > 0) {
                openBatchChangeLocationModal();
            } else {
                showNotificationToast("All pending stock changes & inline edits are automatically saved!", "success");
            }
        }

        function submitBatchChangesFromFloatingBtn() {
            submitFloatingSave();
        }

        function submitSingleDelete(stockId) {
            if (confirm("Are you sure you want to remove this stock record?")) {
                const form = document.getElementById('inventoryForm');
                document.getElementById('inventoryFormAction').value = 'delete';
                document.getElementById('single_stock_id').value = stockId;
                form.submit();
            }
        }

        function submitBatchDelete() {
            const checkboxes = document.querySelectorAll('.stock-checkbox:checked');
            if (checkboxes.length === 0) return;

            if (confirm(`Are you sure you want to remove the selected ${checkboxes.length} stock record(s)?`)) {
                const form = document.getElementById('inventoryForm');
                document.getElementById('inventoryFormAction').value = 'batch_delete';
                document.getElementById('single_stock_id').value = '';
                form.submit();
            }
        }

        function openBatchChangeTypeModal() {
            const checkboxes = document.querySelectorAll('.stock-checkbox:checked');
            if (checkboxes.length === 0) return;

            const container = document.getElementById('batchChangeTypeInputs');
            container.innerHTML = ''; 

            checkboxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'stock_ids[]';
                input.value = cb.value;
                container.appendChild(input);
            });

            document.getElementById('batchTypeCount').textContent = checkboxes.length;
            document.getElementById('batchChangeTypeModal').classList.remove('hidden');
        }

        function closeBatchChangeTypeModal() {
            document.getElementById('batchChangeTypeModal').classList.add('hidden');
        }

        function handleBatchTypeSubmit(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.stock_ids) {
                        data.stock_ids.forEach(id => {
                            const row = document.getElementById(`stock-row-${id}`);
                            if (row) {
                                row.setAttribute('data-type', data.pokemon_type.toLowerCase());
                                const select = row.querySelector('.type-select');
                                if (select) {
                                    select.value = data.pokemon_type.toLowerCase();
                                    select.setAttribute('data-type', data.pokemon_type.toLowerCase());
                                }
                            }
                        });
                    }
                    closeBatchChangeTypeModal();
                    showNotificationToast("Pokémon types updated successfully!");
                }
            })
            .catch(err => console.error("Error batch updating types:", err));
        }

        function openBatchChangeCategoryModal() {
            const checkboxes = document.querySelectorAll('.stock-checkbox:checked');
            if (checkboxes.length === 0) return;

            const container = document.getElementById('batchChangeCategoryInputs');
            container.innerHTML = '';

            checkboxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'stock_ids[]';
                input.value = cb.value;
                container.appendChild(input);
            });

            document.getElementById('batchCategoryCount').textContent = checkboxes.length;
            document.getElementById('batchChangeCategoryModal').classList.remove('hidden');
        }

        function closeBatchChangeCategoryModal() {
            document.getElementById('batchChangeCategoryModal').classList.add('hidden');
        }

        function handleBatchCategorySubmit(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.stock_ids) {
                        data.stock_ids.forEach(id => {
                            const row = document.getElementById(`stock-row-${id}`);
                            if (row) {
                                row.setAttribute('data-category', data.card_category || '');
                            }
                        });
                    }
                    closeBatchChangeCategoryModal();
                    showNotificationToast("Card categories updated successfully!");
                }
            })
            .catch(err => console.error("Error batch updating categories:", err));
        }

        function openBatchChangeLocationModal() {
            const checkboxes = document.querySelectorAll('.stock-checkbox:checked');
            if (checkboxes.length === 0) return;

            const container = document.getElementById('batchChangeLocationInputs');
            container.innerHTML = '';

            checkboxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'stock_ids[]';
                input.value = cb.value;
                container.appendChild(input);
            });

            document.getElementById('batchLocationCount').textContent = checkboxes.length;
            document.getElementById('batchChangeLocationModal').classList.remove('hidden');
        }

        function closeBatchChangeLocationModal() {
            document.getElementById('batchChangeLocationModal').classList.add('hidden');
        }

        function handleBatchLocationSubmit(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            const newLoc = formData.get('shelf_location') || '';

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.stock_ids) {
                        data.stock_ids.forEach(id => {
                            updateCardShelfLocation(id, newLoc, null);
                        });
                    }
                    closeBatchChangeLocationModal();
                    showNotificationToast("Shelf locations updated successfully!");
                }
            })
            .catch(err => console.error("Error batch updating location:", err));
        }

        // Preview & Drag-and-Drop Script
        document.addEventListener('DOMContentLoaded', () => {
            initPersistentSort();

            <?php if (!empty($message)): ?>
                showNotificationToast(<?php echo json_encode($message); ?>, 'success');
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                showNotificationToast(<?php echo json_encode($error); ?>, 'error');
            <?php endif; ?>

            const stockRows = document.querySelectorAll('.stock-row');
            const defaultState = document.getElementById('previewDefaultState');
            const activeState = document.getElementById('previewActiveState');
            
            const previewImage = document.getElementById('previewImage');
            const previewCardName = document.getElementById('previewCardName');
            const previewShelfLoc = document.getElementById('previewShelfLoc');
            const previewDot = document.getElementById('previewDot');

            stockRows.forEach(row => {
                row.addEventListener('mouseenter', () => {
                    const name = row.getAttribute('data-name');
                    const number = row.getAttribute('data-number');
                    const image = row.getAttribute('data-image');
                    const category = row.getAttribute('data-category');
                    const shelf = row.getAttribute('data-shelf');

                    previewCardName.textContent = `${name} (No. ${number})`;
                    previewShelfLoc.textContent = shelf ? `Container: ${shelf}` : 'Container: Unassigned';

                    if (category === 'Cute') {
                        previewDot.className = "absolute top-2 right-2 w-3.5 h-3.5 rounded-full z-10 block bg-pink-500 shadow-md shadow-pink-500/50";
                    } else if (category === 'Cool') {
                        previewDot.className = "absolute top-2 right-2 w-3.5 h-3.5 rounded-full z-10 block bg-blue-500 shadow-md shadow-blue-500/50";
                    } else {
                        previewDot.className = "hidden";
                    }

                    if (image) {
                        previewImage.src = image;
                        previewImage.style.display = 'block';
                    } else {
                        previewImage.style.display = 'none';
                    }

                    defaultState.classList.add('hidden');
                    activeState.classList.remove('hidden');
                });

                // HTML5 Drag Events with Multi-selection support
                row.addEventListener('dragstart', (e) => {
                    const selectedCheckboxes = document.querySelectorAll('.stock-checkbox:checked');
                    let draggedIds = [];

                    const currentId = row.getAttribute('data-stock-id');
                    const isCurrentChecked = row.querySelector('.stock-checkbox').checked;

                    if (isCurrentChecked && selectedCheckboxes.length > 0) {
                        draggedIds = Array.from(selectedCheckboxes).map(cb => cb.value);
                    } else {
                        draggedIds = [currentId];
                    }

                    e.dataTransfer.setData('text/plain', JSON.stringify(draggedIds));
                    e.dataTransfer.effectAllowed = 'move';

                    draggedIds.forEach(id => {
                        const targetRow = document.getElementById(`stock-row-${id}`);
                        if (targetRow) targetRow.classList.add('dragging');
                    });
                });

                row.addEventListener('dragend', () => {
                    document.querySelectorAll('.stock-row.dragging').forEach(r => r.classList.remove('dragging'));
                });
            });

            // Storage Box Dropzone Setup
            document.querySelectorAll('.box-dropzone').forEach(attachDropzoneEvents);
        });

        function attachDropzoneEvents(dz) {
            dz.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                dz.classList.add('drag-over');
            });

            dz.addEventListener('dragleave', () => {
                dz.classList.remove('drag-over');
            });

            dz.addEventListener('drop', (e) => {
                e.preventDefault();
                dz.classList.remove('drag-over');
                
                const rawData = e.dataTransfer.getData('text/plain');
                const boxCode = dz.getAttribute('data-box-code');
                const boxId = dz.getAttribute('data-box-id');

                if (rawData && boxCode) {
                    try {
                        const stockIds = JSON.parse(rawData);
                        if (Array.isArray(stockIds)) {
                            stockIds.forEach(id => updateCardShelfLocation(id, boxCode, boxId));
                        } else {
                            updateCardShelfLocation(rawData, boxCode, boxId);
                        }
                    } catch (err) {
                        updateCardShelfLocation(rawData, boxCode, boxId);
                    }
                }
            });
        }

        function getShelfPillClass(shelf) {
            const shelfLower = (shelf || '').toLowerCase().trim();
            if (shelfLower === 'box 1') return 'shelf-pill-box-1';
            if (shelfLower === 'box 2') return 'shelf-pill-box-2';
            if (shelfLower === 'box 3') return 'shelf-pill-box-3';
            if (shelfLower === 'box 4') return 'shelf-pill-box-4';
            if (shelfLower === 'shelf 1') return 'shelf-pill-shelf-1';
            return 'shelf-pill-default';
        }

        function saveIndividualShelfLocation(selectElement, stockId) {
            const newBoxLocation = selectElement.value.trim();
            
            let targetBoxId = null;
            document.querySelectorAll('.box-dropzone').forEach(dz => {
                if (dz.getAttribute('data-box-code').toLowerCase() === newBoxLocation.toLowerCase()) {
                    targetBoxId = dz.getAttribute('data-box-id');
                }
            });

            updateCardShelfLocation(stockId, newBoxLocation, targetBoxId);
        }

        function updateCardShelfLocation(stockId, newBoxLocation, boxId) {
            const row = document.getElementById(`stock-row-${stockId}`);
            if (!row) return;

            const oldBoxLocation = row.getAttribute('data-shelf') || '';
            const itemQty = parseInt(row.getAttribute('data-quantity'), 10) || 1;

            const formData = new FormData();
            formData.append('action', 'update_shelf_location');
            formData.append('stock_id', stockId);
            formData.append('shelf_location', newBoxLocation);

            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    row.setAttribute('data-shelf', newBoxLocation);

                    if (activeContainerCode && oldBoxLocation.toLowerCase() === activeContainerCode.toLowerCase() && newBoxLocation.toLowerCase() !== activeContainerCode.toLowerCase()) {
                        row.style.display = 'none';
                    }
                    
                    const shelfSelect = row.querySelector('.shelf-select');
                    if (shelfSelect) {
                        shelfSelect.value = newBoxLocation;
                        shelfSelect.className = `shelf-select w-full rounded px-2 py-1 text-[11px] focus:outline-none focus:border-purple-500 font-semibold cursor-pointer truncate ${getShelfPillClass(newBoxLocation)}`;
                    }

                    const previewShelfLoc = document.getElementById('previewShelfLoc');
                    if (previewShelfLoc) {
                        previewShelfLoc.textContent = `Container: ${newBoxLocation || 'Unassigned'}`;
                    }

                    document.querySelectorAll('.box-dropzone').forEach(dz => {
                        const code = dz.getAttribute('data-box-code');
                        const id = dz.getAttribute('data-box-id');
                        if (code && oldBoxLocation && code.toLowerCase() === oldBoxLocation.toLowerCase() && code.toLowerCase() !== newBoxLocation.toLowerCase()) {
                            const countEl = document.getElementById(`count-${id}`);
                            if (countEl) {
                                const currentCount = parseInt(countEl.textContent, 10) || 0;
                                countEl.textContent = Math.max(0, currentCount - itemQty);
                            }
                        }
                    });

                    if (boxId) {
                        const countEl = document.getElementById(`count-${boxId}`);
                        if (countEl) {
                            const currentCount = parseInt(countEl.textContent, 10) || 0;
                            countEl.textContent = currentCount + itemQty;
                        }
                    } else {
                        document.querySelectorAll('.box-dropzone').forEach(dz => {
                            const code = dz.getAttribute('data-box-code');
                            const id = dz.getAttribute('data-box-id');
                            if (code && newBoxLocation && code.toLowerCase() === newBoxLocation.toLowerCase() && code.toLowerCase() !== oldBoxLocation.toLowerCase()) {
                                const countEl = document.getElementById(`count-${id}`);
                                if (countEl) {
                                    const currentCount = parseInt(countEl.textContent, 10) || 0;
                                    countEl.textContent = currentCount + itemQty;
                                }
                            }
                        });
                    }

                    showNotificationToast(`Container location changed to "${newBoxLocation || 'Unassigned'}"`);
                }
            })
            .catch(error => {
                console.error('Error updating shelf location:', error);
            });
        }

        // Download the inventory list as a PDF (single column, so copy/pasted or
        // re-extracted text always comes out in the correct order — 2-column PDFs
        // scramble text when copied because PDF text has no concept of columns)
        function downloadInventoryPDF() {
            if (!window.jspdf) {
                alert('PDF library failed to load. Please check your internet connection and try again.');
                return;
            }

            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ unit: 'mm', format: 'a4' });

            const items = Array.from(document.querySelectorAll('#printSection .print-item')).map(el => {
                const nameEl = el.querySelector('.item-name');
                const statEls = el.querySelectorAll('.item-stat');
                // Use "P" instead of ₱ — jsPDF's built-in fonts don't include the
                // peso glyph and silently substitute the wrong character for it.
                const stats = Array.from(statEls)
                    .map(s => s.textContent.replace(/\s+/g, ' ').trim().replace(/₱/g, 'P'))
                    .join('   ');
                return { name: nameEl ? nameEl.textContent.trim() : '', stats };
            });

            const pageWidth = doc.internal.pageSize.getWidth();
            const pageHeight = doc.internal.pageSize.getHeight();
            const margin = 14;
            const rowHeight = 5.5;
            const headerHeight = 16;
            const contentWidth = pageWidth - margin * 2;
            const maxNameWidth = contentWidth * 0.62;

            function drawHeader() {
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(14);
                doc.text('Inventory Stock List', pageWidth / 2, margin + 4, { align: 'center' });
                doc.setLineWidth(0.5);
                doc.setDrawColor(0, 0, 0);
                doc.line(margin, margin + 7, pageWidth - margin, margin + 7);
            }

            drawHeader();
            let y = margin + headerHeight;

            if (items.length === 0) {
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(10);
                doc.text('No items found.', pageWidth / 2, y, { align: 'center' });
            } else {
                items.forEach((item) => {
                    if (y + rowHeight > pageHeight - margin) {
                        doc.addPage();
                        drawHeader();
                        y = margin + headerHeight;
                    }

                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(8.5);
                    let name = item.name;
                    while (doc.getTextWidth(name) > maxNameWidth && name.length > 3) {
                        name = name.slice(0, -1);
                    }
                    if (name !== item.name) name = name.trim() + '…';
                    doc.text(name, margin, y);

                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(7.5);
                    doc.text(item.stats, pageWidth - margin, y, { align: 'right' });

                    doc.setDrawColor(225, 225, 225);
                    doc.setLineWidth(0.1);
                    doc.line(margin, y + 1.6, pageWidth - margin, y + 1.6);

                    y += rowHeight;
                });
            }

            const today = new Date().toISOString().split('T')[0];
            doc.save(`inventory-stock-list-${today}.pdf`);
        }
    </script>
</body>
</html>