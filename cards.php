<?php
session_start();
require_once 'config/database.php';

// Security check: If not logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$successMsg = '';
$errorMsg = '';
$userId = $_SESSION['user_id'];

// Helper function to detect card type from card name or types array
function detectCardType($cardName, $apiTypes = [])
{
    if (!empty($apiTypes) && is_array($apiTypes)) {
        foreach ($apiTypes as $t) {
            $lowerT = strtolower(trim($t));
            switch ($lowerT) {
                case 'water':
                    return 'water';
                case 'grass':
                    return 'grass';
                case 'fire':
                    return 'fire';
                case 'lightning':
                case 'electric':
                    return 'electric';
                case 'ground':
                case 'fighting':
                    return 'ground';
                case 'psychic':
                    return 'psychic';
                case 'fairy':
                    return 'fairy';
                case 'darkness':
                case 'dark':
                    return 'dark';
                case 'metal':
                case 'steel':
                    return 'metal';
                case 'dragon':
                    return 'dragon';
                case 'colorless':
                case 'normal':
                    return 'normal';
            }
        }
    }

    $nameLower = strtolower($cardName);
    if (strpos($nameLower, 'water') !== false || strpos($nameLower, 'blastoise') !== false || strpos($nameLower, 'gyarados') !== false || strpos($nameLower, 'squirtle') !== false) return 'water';
    if (strpos($nameLower, 'grass') !== false || strpos($nameLower, 'venusaur') !== false || strpos($nameLower, 'bulbasaur') !== false || strpos($nameLower, 'celebi') !== false) return 'grass';
    if (strpos($nameLower, 'fire') !== false || strpos($nameLower, 'charizard') !== false || strpos($nameLower, 'charmander') !== false || strpos($nameLower, 'moltres') !== false) return 'fire';
    if (strpos($nameLower, 'electric') !== false || strpos($nameLower, 'pikachu') !== false || strpos($nameLower, 'raichu') !== false || strpos($nameLower, 'zapdos') !== false) return 'electric';
    if (strpos($nameLower, 'ground') !== false || strpos($nameLower, 'sandshrew') !== false || strpos($nameLower, 'garchomp') !== false) return 'ground';
    if (strpos($nameLower, 'psychic') !== false || strpos($nameLower, 'mewtwo') !== false || strpos($nameLower, 'mew') !== false || strpos($nameLower, 'alakazam') !== false || strpos($nameLower, 'gengar') !== false) return 'psychic';
    if (strpos($nameLower, 'fairy') !== false || strpos($nameLower, 'clefairy') !== false || strpos($nameLower, 'sylveon') !== false) return 'fairy';
    if (strpos($nameLower, 'dark') !== false || strpos($nameLower, 'darkrai') !== false || strpos($nameLower, 'umbreon') !== false) return 'dark';
    if (strpos($nameLower, 'metal') !== false || strpos($nameLower, 'scizor') !== false || strpos($nameLower, 'lucario') !== false) return 'metal';
    if (strpos($nameLower, 'dragon') !== false || strpos($nameLower, 'rayquaza') !== false || strpos($nameLower, 'dragonite') !== false || strpos($nameLower, 'garchomp') !== false) return 'dragon';

    return 'normal';
}

// Handle card import / manual entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_with_pricing') {
    $apiCardName = trim($_POST['api_name'] ?? '');
    $apiCardNum = trim($_POST['api_card_number'] ?? '');
    $apiCardLang = strtolower(trim($_POST['api_language'] ?? 'en'));
    if ($apiCardLang === 'jp' || $apiCardLang === 'japanese' || $apiCardLang === 'jap') {
        $apiCardLang = 'ja';
    }
    $apiRarity = trim($_POST['api_rarity'] ?? '');
    $apiImageUrl = trim($_POST['api_image_url'] ?? '');
    $cardType = trim($_POST['card_type'] ?? 'normal');
    $isCosigned = isset($_POST['is_cosigned']) && $_POST['is_cosigned'] === '1' ? 1 : 0;

    // Inventory and Pricing fields
    $quantity = intval($_POST['quantity'] ?? 1);
    $buyPrice = floatval($_POST['buy_price'] ?? 0.50);
    $sellPrice = floatval($_POST['sell_price'] ?? 0.00);

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (!empty($apiCardName)) {
        try {
            // Default Set ID fallback when set names are omitted
            $setId = 1;

            // Always Insert Card into Catalog as a unique entry
            $fullNameWithRarity = $apiCardName . (!empty($apiRarity) ? " - " . strtoupper($apiRarity) : "") . " (" . strtoupper($apiCardLang) . ")";

            try {
                $stmtInsertCard = $pdo->prepare("INSERT INTO cards (name, set_id, card_number, language, image_url, pokemon_type) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
                $stmtInsertCard->execute([$fullNameWithRarity, $setId, $apiCardNum, $apiCardLang, $apiImageUrl, $cardType]);
                $cardId = $stmtInsertCard->fetchColumn();
            } catch (PDOException $exCardSeq) {
                $stmtMaxCard = $pdo->query("SELECT COALESCE(MAX(CAST(id AS INTEGER)), 0) + 1 FROM cards");
                $nextCardId = $stmtMaxCard->fetchColumn();

                try {
                    $stmtInsertCardAlt = $pdo->prepare("INSERT INTO cards (id, name, set_id, card_number, language, image_url, pokemon_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmtInsertCardAlt->execute([$nextCardId, $fullNameWithRarity, $setId, $apiCardNum, $apiCardLang, $apiImageUrl, $cardType]);
                } catch (PDOException $exCardFallback) {
                    $stmtInsertCardAlt = $pdo->prepare("INSERT INTO cards (id, name, set_id, card_number) VALUES (?, ?, ?, ?)");
                    $stmtInsertCardAlt->execute([$nextCardId, $fullNameWithRarity, $setId, $apiCardNum]);
                }
                $cardId = $nextCardId;
            }

            // Insert into inventory_stocks (with bought/buy price and sell price)
            if ($cardId) {
                try {
                    $stmtStock = $pdo->prepare("INSERT INTO inventory_stocks (card_id, quantity, buy_price, purchase_price, sell_price, price, is_cosigned, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtStock->execute([$cardId, $quantity, $buyPrice, $buyPrice, $sellPrice, $sellPrice, $isCosigned, $userId]);
                } catch (Exception $ex) {
                    $stmtStockFinal = $pdo->prepare("INSERT INTO inventory_stocks (card_id, quantity, buy_price, sell_price, is_cosigned, owner_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtStockFinal->execute([$cardId, $quantity, $buyPrice, $sellPrice, $isCosigned, $userId]);
                }
            }
            $modeText = $isCosigned ? "as Cosigned" : "to Inventory";
            $successMsg = "Successfully added/imported '$apiCardName' $modeText!";

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $successMsg
                ]);
                exit;
            }
        } catch (Exception $e) {
            $errorMsg = "Error processing card: " . $e->getMessage();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $errorMsg]);
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Card Catalog & Explorer | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap');

        body {
            font-family: 'Space Grotesk', sans-serif;
            background-color: #0b0b0e;
            color: #f4f4f5;
        }

        .tr-sidebar {
            background: rgba(18, 15, 29, 0.6);
            border: 1px solid rgba(147, 51, 234, 0.3);
            border-radius: 1rem;
            backdrop-filter: blur(12px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.37);
        }

        .tr-card {
            background: rgba(18, 15, 29, 0.6);
            border: 1px solid rgba(147, 51, 234, 0.3);
            border-radius: 0.9rem;
            backdrop-filter: blur(12px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tr-card:hover {
            border-color: rgba(168, 85, 247, 0.6);
            background: rgba(24, 19, 40, 0.8);
            transform: translateY(-3px);
            box-shadow: 0 15px 30px -10px rgba(147, 51, 234, 0.3);
        }

        .purple-btn {
            background: linear-gradient(135deg, #a855f7 0%, #7e22ce 50%, #6b21a8 100%);
            box-shadow: 0 4px 14px rgba(168, 85, 247, 0.3);
            transition: all 0.2s ease;
        }

        .purple-btn:hover {
            background: linear-gradient(135deg, #c084fc 0%, #9333ea 50%, #7e22ce 100%);
            box-shadow: 0 6px 20px rgba(168, 85, 247, 0.5);
        }

        .mode-btn-active {
            background: linear-gradient(135deg, #a855f7 0%, #7e22ce 100%) !important;
            border-color: #c084fc !important;
            color: white !important;
            box-shadow: 0 0 12px rgba(168, 85, 247, 0.4);
        }

        .modal-backdrop {
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
        }

        /* Type Badge Styles */
        .badge-water { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
        .badge-grass { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.4); }
        .badge-fire { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }
        .badge-electric { background: rgba(234, 179, 8, 0.2); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.4); }
        .badge-ground { background: rgba(180, 83, 9, 0.2); color: #fb923c; border: 1px solid rgba(180, 83, 9, 0.4); }
        .badge-psychic { background: rgba(217, 70, 239, 0.2); color: #e879f9; border: 1px solid rgba(217, 70, 239, 0.4); }
        .badge-fairy { background: rgba(244, 114, 182, 0.2); color: #f472b6; border: 1px solid rgba(244, 114, 182, 0.4); }
        .badge-dark { background: rgba(75, 85, 99, 0.3); color: #9ca3af; border: 1px solid rgba(75, 85, 99, 0.5); }
        .badge-metal { background: rgba(148, 163, 184, 0.2); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.4); }
        .badge-dragon { background: rgba(234, 88, 12, 0.2); color: #fb923c; border: 1px solid rgba(234, 88, 12, 0.4); }
        .badge-normal { background: rgba(156, 163, 175, 0.2); color: #d1d5db; border: 1px solid rgba(156, 163, 175, 0.4); }

        input:focus, select:focus {
            border-color: rgba(168, 85, 247, 0.6) !important;
            box-shadow: 0 0 12px rgba(168, 85, 247, 0.25);
        }

        /* Radio Button Tile Styling */
        .custom-radio-input { display: none; }
        .custom-radio-tile {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.4rem 0.25rem;
            border-radius: 0.5rem;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            opacity: 0.5;
            transition: all 0.2s ease-in-out;
            user-select: none;
            background: rgba(10, 8, 16, 0.8);
            border: 1px solid rgba(147, 51, 234, 0.3);
            color: #d1d5db;
        }

        .custom-radio-input:checked + .custom-radio-tile {
            opacity: 1;
            transform: translateY(-1px) scale(1.03);
            box-shadow: 0 0 10px rgba(168, 85, 247, 0.4);
            border-color: #c084fc;
            color: #ffffff;
            background: rgba(147, 51, 234, 0.3);
        }
    </style>
</head>

<body class="min-h-screen flex flex-col bg-[#0b0b0e] text-zinc-100">

    <?php include 'includes/header.php'; ?>

    <div class="flex-1 flex flex-col lg:flex-row p-6 lg:p-8 gap-8 max-w-[1600px] mx-auto w-full">

        <!-- Left Sidebar: Direct Quick Addition per Type -->
        <aside class="w-full lg:w-72 tr-sidebar p-6 flex-shrink-0 space-y-6 h-fit">
            <h3 class="text-xs font-bold text-purple-200/80 uppercase tracking-wider">Quick Add Card</h3>
            
            <div class="grid grid-cols-1 gap-1.5 max-h-72 overflow-y-auto pr-1">
                <button type="button" onclick="openManualCardModal('normal')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Normal</span>
                    <span class="badge-normal text-[9px] px-1.5 rounded">NORMAL</span>
                </button>
                <button type="button" onclick="openManualCardModal('grass')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Grass</span>
                    <span class="badge-grass text-[9px] px-1.5 rounded">GRASS</span>
                </button>
                <button type="button" onclick="openManualCardModal('fire')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Fire</span>
                    <span class="badge-fire text-[9px] px-1.5 rounded">FIRE</span>
                </button>
                <button type="button" onclick="openManualCardModal('water')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Water</span>
                    <span class="badge-water text-[9px] px-1.5 rounded">WATER</span>
                </button>
                <button type="button" onclick="openManualCardModal('electric')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Electric</span>
                    <span class="badge-electric text-[9px] px-1.5 rounded">ELECTRIC</span>
                </button>
                <button type="button" onclick="openManualCardModal('psychic')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Psychic</span>
                    <span class="badge-psychic text-[9px] px-1.5 rounded">PSYCHIC</span>
                </button>
                <button type="button" onclick="openManualCardModal('ground')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Ground</span>
                    <span class="badge-ground text-[9px] px-1.5 rounded">GROUND</span>
                </button>
                <button type="button" onclick="openManualCardModal('dark')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Dark</span>
                    <span class="badge-dark text-[9px] px-1.5 rounded">DARK</span>
                </button>
                <button type="button" onclick="openManualCardModal('metal')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Metal</span>
                    <span class="badge-metal text-[9px] px-1.5 rounded">METAL</span>
                </button>
                <button type="button" onclick="openManualCardModal('dragon')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Dragon</span>
                    <span class="badge-dragon text-[9px] px-1.5 rounded">DRAGON</span>
                </button>
                <button type="button" onclick="openManualCardModal('fairy')" class="text-left text-[11px] bg-purple-950/40 hover:bg-purple-900/60 text-purple-200 border border-purple-800/40 px-3 py-1.5 rounded-lg font-bold transition-all shadow-sm flex justify-between items-center">
                    <span>+ Add New Fairy</span>
                    <span class="badge-fairy text-[9px] px-1.5 rounded">FAIRY</span>
                </button>
            </div>

            <hr class="border-purple-900/40">

            <div class="space-y-2">
                <label class="block text-[11px] text-purple-200/70 uppercase tracking-wider font-semibold">Sort By:</label>
                <select id="sortBy" onchange="renderFilteredResults()" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3.5 py-2 text-white text-xs focus:outline-none cursor-pointer">
                    <option value="name_asc" class="bg-[#120f1d] text-white">Name (A - Z)</option>
                    <option value="name_desc" class="bg-[#120f1d] text-white">Name (Z - A)</option>
                    <option value="price_desc" class="bg-[#120f1d] text-white">Price (High to Low)</option>
                    <option value="price_asc" class="bg-[#120f1d] text-white">Price (Low to High)</option>
                </select>
            </div>

            <div class="space-y-2 pt-2">
                <label class="block text-[11px] text-purple-200/70 uppercase tracking-wider font-semibold">Language Filter:</label>
                <select id="filterLanguage" onchange="renderFilteredResults()" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3.5 py-2 text-white text-xs focus:outline-none cursor-pointer">
                    <option value="all" class="bg-[#120f1d] text-white">All Languages</option>
                    <option value="en" class="bg-[#120f1d] text-white">English</option>
                    <option value="ja" class="bg-[#120f1d] text-white">Japanese</option>
                    <option value="other" class="bg-[#120f1d] text-white">Others</option>
                </select>
            </div>

            <div class="space-y-2 pt-2">
                <label class="block text-[11px] text-purple-200/70 uppercase tracking-wider font-semibold">Type Filter:</label>
                <select id="filterType" onchange="renderFilteredResults()" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-lg px-3.5 py-2 text-white text-xs focus:outline-none cursor-pointer">
                    <option value="all" class="bg-[#120f1d] text-white">All Types</option>
                    <option value="water" class="bg-[#120f1d] text-white">Water</option>
                    <option value="grass" class="bg-[#120f1d] text-white">Grass</option>
                    <option value="fire" class="bg-[#120f1d] text-white">Fire</option>
                    <option value="electric" class="bg-[#120f1d] text-white">Electric</option>
                    <option value="ground" class="bg-[#120f1d] text-white">Ground</option>
                    <option value="psychic" class="bg-[#120f1d] text-white">Psychic</option>
                    <option value="fairy" class="bg-[#120f1d] text-white">Fairy</option>
                    <option value="dark" class="bg-[#120f1d] text-white">Dark</option>
                    <option value="metal" class="bg-[#120f1d] text-white">Metal</option>
                    <option value="dragon" class="bg-[#120f1d] text-white">Dragon</option>
                    <option value="normal" class="bg-[#120f1d] text-white">Normal</option>
                </select>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 flex flex-col min-w-0">

            <div id="ajaxAlertContainer">
                <?php if (!empty($successMsg)): ?>
                    <div class="p-4 rounded-xl border bg-emerald-950/40 border-emerald-500/30 text-emerald-300 text-xs flex items-center space-x-2 backdrop-blur-md mb-6 shadow-lg">
                        <span><?php echo htmlspecialchars($successMsg); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errorMsg)): ?>
                    <div class="p-4 rounded-xl border bg-rose-950/40 border-rose-500/30 text-rose-300 text-xs flex items-center space-x-2 backdrop-blur-md mb-6 shadow-lg">
                        <?php echo htmlspecialchars($errorMsg); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Universal Search Bar Box -->
            <div class="tr-sidebar p-5 rounded-2xl mb-8 shadow-xl">
                <div class="flex justify-between items-center mb-3">
                    <div id="searchModeTitle" class="text-xs font-bold text-purple-200/80 uppercase tracking-wider">Search Catalog</div>
                    <div class="flex items-center space-x-2 bg-[#0a0810] p-1 rounded-xl border border-purple-900/45">
                        <button type="button" id="modeNameBtn" onclick="setSearchMode('name')" class="text-[11px] font-bold px-3 py-1.5 rounded-lg transition-all mode-btn-active text-white">Search by Name / No.</button>
                        <button type="button" id="modeIllustratorBtn" onclick="setSearchMode('illustrator')" class="text-[11px] font-bold px-3 py-1.5 rounded-lg transition-all text-purple-300 hover:text-white">Search by Illustrator</button>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="relative flex-1">
                        <input type="text" id="universalSearchInput" placeholder="Search by name (e.g., Charizard, Charizard ex) or number (e.g., 001/100)..." class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl pl-4 pr-4 py-3 text-white text-sm focus:outline-none placeholder-zinc-600" onkeydown="if(event.key === 'Enter') searchExternalAPI()">
                    </div>
                    <button type="button" onclick="searchExternalAPI()" class="purple-btn text-white font-bold px-8 py-3 rounded-xl transition-all uppercase tracking-wider text-xs shadow-lg">
                        Search Catalog
                    </button>
                </div>
            </div>

            <!-- Search Results Grid -->
            <div>
                <div id="apiResultsContainer" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-5">
                    <div class="col-span-full py-12 text-center text-purple-300/60 text-sm tr-sidebar rounded-2xl">
                        Type a query above (e.g. "Charizard", "Gengar ex", "001/100") and click "Search Catalog".
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- CARD MODAL -->
    <div id="cardModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-backdrop hidden">
        <div class="tr-sidebar max-w-3xl w-full p-6 border border-purple-900/50 rounded-2xl bg-[#120f1d] shadow-2xl relative">
            <button type="button" onclick="closeCardModal()" class="absolute top-4 right-4 text-purple-300 hover:text-white text-lg font-bold w-8 h-8 rounded-full bg-[#0a0810] border border-purple-900/40 flex items-center justify-center">
                &times;
            </button>

            <form id="cardModalForm" onsubmit="handleCardSubmit(event)" class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <input type="hidden" name="action" value="import_with_pricing">
                <input type="hidden" id="modal_is_cosigned" name="is_cosigned" value="0">
                <input type="hidden" id="modal_card_type" name="card_type" value="normal">

                <div class="flex flex-col items-center">
                    <div class="w-52 aspect-[3/4] bg-[#0a0810] rounded-xl border border-purple-900/40 overflow-hidden mb-4 flex items-center justify-center relative">
                        <img id="modal_card_img" src="https://images.placeholders.dev/?width=300&height=420&text=Trade+Rocket+TCG&bgColor=120f1d&textColor=a855f7" alt="Card Preview" class="w-full h-full object-contain" onerror="handleImageError(this, document.getElementById('modal_api_name').value, document.getElementById('modal_api_card_number').value);">
                    </div>
                    <div class="w-full space-y-3">
                        <div>
                            <label class="block text-[11px] uppercase text-purple-200/80 mb-1 font-semibold">Card Name</label>
                            <input type="text" id="modal_api_name" name="api_name" required placeholder="e.g., Charizard V" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl px-3.5 py-2 text-white text-sm">
                        </div>
                        <div>
                            <label class="block text-[11px] uppercase text-purple-200/80 mb-1 font-semibold">Card Number</label>
                            <input type="text" id="modal_api_card_number" name="api_card_number" placeholder="e.g., 001/100" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl px-3.5 py-2 text-white text-sm">
                        </div>
                    </div>
                </div>

                <div class="flex flex-col justify-between space-y-3">
                    <div class="space-y-3">
                        
                        <!-- Radio Button Language Selection -->
                        <div>
                            <label class="block text-[11px] uppercase text-purple-200/80 mb-1 font-semibold">Card Language</label>
                            <div class="grid grid-cols-3 gap-2">
                                <label>
                                    <input type="radio" name="api_language" value="en" class="custom-radio-input" id="lang_en" checked>
                                    <span class="custom-radio-tile">ENG</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_language" value="ja" class="custom-radio-input" id="lang_ja">
                                    <span class="custom-radio-tile">JAP</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_language" value="other" class="custom-radio-input" id="lang_other">
                                    <span class="custom-radio-tile">OTHERS</span>
                                </label>
                            </div>
                        </div>

                        <!-- Radio Button Rarity Selection -->
                        <div>
                            <label class="block text-[11px] uppercase text-purple-200/80 mb-1 font-semibold">Rarity</label>
                            <div class="grid grid-cols-3 gap-1.5">
                                <label>
                                    <input type="radio" name="api_rarity" value="C" class="custom-radio-input" id="rarity_c" checked>
                                    <span class="custom-radio-tile">Common (C)</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_rarity" value="U" class="custom-radio-input" id="rarity_u">
                                    <span class="custom-radio-tile">Uncommon (U)</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_rarity" value="R" class="custom-radio-input" id="rarity_r">
                                    <span class="custom-radio-tile">Rare (R)</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_rarity" value="RR" class="custom-radio-input" id="rarity_rr">
                                    <span class="custom-radio-tile">Double Rare (RR)</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_rarity" value="SR" class="custom-radio-input" id="rarity_sr">
                                    <span class="custom-radio-tile">Super Rare (SR)</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_rarity" value="SAR" class="custom-radio-input" id="rarity_sar">
                                    <span class="custom-radio-tile">Special Art (SAR)</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_rarity" value="UR" class="custom-radio-input" id="rarity_ur">
                                    <span class="custom-radio-tile">Ultra Rare (UR)</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_rarity" value="AR" class="custom-radio-input" id="rarity_ar">
                                    <span class="custom-radio-tile">Art Rare (AR)</span>
                                </label>
                                <label>
                                    <input type="radio" name="api_rarity" value="PROMO" class="custom-radio-input" id="rarity_promo">
                                    <span class="custom-radio-tile">Promo</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[11px] uppercase text-purple-200/80 mb-1 font-semibold">Image URL (Optional)</label>
                            <input type="url" id="modal_api_image_url" name="api_image_url" placeholder="https://..." oninput="updateModalImagePreview(this.value)" class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl px-3.5 py-2 text-white text-sm">
                        </div>

                        <div>
                            <label class="block text-[11px] uppercase text-purple-200/80 mb-1 font-semibold">Quantity</label>
                            <input type="number" name="quantity" value="1" min="1" required class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl px-3.5 py-2 text-white text-sm">
                        </div>

                        <!-- Bought Price Input (For Profit Calculations) -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label class="block text-[11px] uppercase text-purple-200/80 font-semibold">Bought Price (₱)</label>
                                <div class="flex items-center gap-1">
                                    <button type="button" onclick="setBuyPriceValue(0.50)" class="text-[10px] bg-purple-950/80 hover:bg-purple-900 text-purple-200 border border-purple-800/50 px-2 py-0.5 rounded font-bold transition-all">0.50</button>
                                    <button type="button" onclick="setBuyPriceValue(5)" class="text-[10px] bg-purple-950/80 hover:bg-purple-900 text-purple-200 border border-purple-800/50 px-2 py-0.5 rounded font-bold transition-all">5</button>
                                    <button type="button" onclick="setBuyPriceValue(10)" class="text-[10px] bg-purple-950/80 hover:bg-purple-900 text-purple-200 border border-purple-800/50 px-2 py-0.5 rounded font-bold transition-all">10</button>
                                    <button type="button" onclick="addBuyPriceValue(5)" class="text-[10px] bg-purple-600 hover:bg-purple-500 text-white font-bold px-2 py-0.5 rounded transition-all">+5</button>
                                </div>
                            </div>
                            <input type="number" step="0.01" name="buy_price" id="modal_buy_price_input" value="0.50" min="0" required class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl px-3.5 py-2 text-white text-sm">
                        </div>

                        <!-- Editable Selling Price Input + Quick Buttons (5, 10, 15, 20, +5) -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label class="block text-[11px] uppercase text-purple-200/80 font-semibold">Selling Price (₱)</label>
                                <div class="flex items-center gap-1">
                                    <button type="button" onclick="setSellPriceValue(5)" class="text-[10px] bg-purple-950/80 hover:bg-purple-900 text-purple-200 border border-purple-800/50 px-2 py-0.5 rounded font-bold transition-all">5</button>
                                    <button type="button" onclick="setSellPriceValue(10)" class="text-[10px] bg-purple-950/80 hover:bg-purple-900 text-purple-200 border border-purple-800/50 px-2 py-0.5 rounded font-bold transition-all">10</button>
                                    <button type="button" onclick="setSellPriceValue(15)" class="text-[10px] bg-purple-950/80 hover:bg-purple-900 text-purple-200 border border-purple-800/50 px-2 py-0.5 rounded font-bold transition-all">15</button>
                                    <button type="button" onclick="setSellPriceValue(20)" class="text-[10px] bg-purple-950/80 hover:bg-purple-900 text-purple-200 border border-purple-800/50 px-2 py-0.5 rounded font-bold transition-all">20</button>
                                    <button type="button" onclick="addSellPriceValue(5)" class="text-[10px] bg-purple-600 hover:bg-purple-500 text-white font-bold px-2 py-0.5 rounded transition-all">+5</button>
                                </div>
                            </div>
                            <input type="number" step="0.01" name="sell_price" id="modal_sell_price_input" value="5.00" min="0" required class="w-full bg-[#0a0810] border border-purple-900/40 rounded-xl px-3.5 py-2 text-white text-sm">
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-purple-900/40 grid grid-cols-2 gap-3">
                        <button type="button" onclick="submitModalForm(true)" class="purple-btn text-white font-bold py-3 px-2 rounded-xl text-xs uppercase tracking-wider">
                            COSIGN THIS
                        </button>
                        <button type="button" onclick="submitModalForm(false)" class="purple-btn text-white font-bold py-3 px-2 rounded-xl text-xs uppercase tracking-wider">
                            ADD TO INVENTORY
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        let globalMasterResults = [];
        let currentSearchMode = 'name';
        const USD_TO_PHP_RATE = 57.0;
        const DEFAULT_FALLBACK_IMAGE = 'https://images.placeholders.dev/?width=300&height=420&text=Trade+Rocket+TCG&bgColor=120f1d&textColor=a855f7';

        function normalizeLang(lang) {
            if (!lang) return 'en';
            const l = String(lang).toLowerCase().trim();
            if (l === 'ja' || l === 'jp' || l === 'japanese' || l === 'jap') return 'ja';
            if (l === 'en' || l === 'english' || l === 'eng') return 'en';
            return 'other';
        }

        function setBuyPriceValue(val) {
            document.getElementById('modal_buy_price_input').value = parseFloat(val).toFixed(2);
        }

        function addBuyPriceValue(val) {
            const input = document.getElementById('modal_buy_price_input');
            const current = parseFloat(input.value) || 0;
            input.value = (current + val).toFixed(2);
        }

        function setSellPriceValue(val) {
            document.getElementById('modal_sell_price_input').value = parseFloat(val).toFixed(2);
        }

        function addSellPriceValue(val) {
            const input = document.getElementById('modal_sell_price_input');
            const current = parseFloat(input.value) || 0;
            input.value = (current + val).toFixed(2);
        }

        function detectCardType(card) {
            if (card.types && Array.isArray(card.types) && card.types.length > 0) {
                const t = card.types[0].toLowerCase();
                if (t.includes('water')) return 'water';
                if (t.includes('grass')) return 'grass';
                if (t.includes('fire')) return 'fire';
                if (t.includes('lightning') || t.includes('electric')) return 'electric';
                if (t.includes('fighting') || t.includes('ground')) return 'ground';
                if (t.includes('psychic')) return 'psychic';
                if (t.includes('fairy')) return 'fairy';
                if (t.includes('darkness') || t.includes('dark')) return 'dark';
                if (t.includes('metal') || t.includes('steel')) return 'metal';
                if (t.includes('dragon')) return 'dragon';
                if (t.includes('colorless') || t.includes('normal')) return 'normal';
            }

            const name = (card.englishDisplayName || card.name || '').toLowerCase();
            if (name.includes('water') || name.includes('blastoise') || name.includes('gyarados') || name.includes('squirtle')) return 'water';
            if (name.includes('grass') || name.includes('venusaur') || name.includes('bulbasaur') || name.includes('celebi')) return 'grass';
            if (name.includes('fire') || name.includes('charizard') || name.includes('charmander') || name.includes('moltres')) return 'fire';
            if (name.includes('electric') || name.includes('pikachu') || name.includes('raichu') || name.includes('zapdos')) return 'electric';
            if (name.includes('ground') || name.includes('sandshrew') || name.includes('garchomp')) return 'ground';
            if (name.includes('psychic') || name.includes('mewtwo') || name.includes('mew') || name.includes('alakazam') || name.includes('gengar')) return 'psychic';
            if (name.includes('fairy') || name.includes('clefairy') || name.includes('sylveon')) return 'fairy';
            if (name.includes('dark') || name.includes('darkrai') || name.includes('umbreon')) return 'dark';
            if (name.includes('metal') || name.includes('scizor') || name.includes('lucario')) return 'metal';
            if (name.includes('dragon') || name.includes('rayquaza') || name.includes('dragonite') || name.includes('garchomp')) return 'dragon';

            return 'normal';
        }

        function formatCardNumber(card) {
            const localId = card.localId || card.card_number || card.number || 'N/A';
            if (String(localId).includes('/')) {
                return localId;
            }
            let totalCards = 0;
            if (card.set && card.set.cardCount) {
                totalCards = card.set.cardCount.official || card.set.cardCount.total || 0;
            }
            return totalCards > 0 ? `${localId}/${totalCards}` : localId;
        }

        async function handleImageError(imgElement, cardName, cardNumber) {
            if (imgElement.dataset.fallbackAttempted) {
                imgElement.src = DEFAULT_FALLBACK_IMAGE;
                return;
            }
            imgElement.dataset.fallbackAttempted = "true";

            try {
                let proxyUrl = `api/card_image_proxy.php?name=${encodeURIComponent(cardName || '')}&number=${encodeURIComponent(cardNumber || '')}`;
                let res = await fetch(proxyUrl);
                if (res.ok) {
                    let data = await res.json();
                    if (data.success && data.image_url) {
                        imgElement.src = data.image_url;
                        return;
                    }
                }
            } catch (e) {}

            imgElement.src = DEFAULT_FALLBACK_IMAGE;
        }

        function buildCardImageUrl(card) {
            if (!card) return DEFAULT_FALLBACK_IMAGE;
            let rawImg = card.image || (card.images ? (card.images.large || card.images.small) : '') || '';
            if (!rawImg && card.set) {
                rawImg = card.set.image || card.set.logo || '';
            }
            if (!rawImg) return DEFAULT_FALLBACK_IMAGE;
            if (!rawImg.endsWith('.png') && !rawImg.endsWith('.jpg') && !rawImg.endsWith('.webp')) {
                return `${rawImg}/low.png`;
            }
            return rawImg;
        }

        function setSearchMode(mode) {
            currentSearchMode = mode;
            const input = document.getElementById('universalSearchInput');
            const nameBtn = document.getElementById('modeNameBtn');
            const illustratorBtn = document.getElementById('modeIllustratorBtn');
            const title = document.getElementById('searchModeTitle');

            if (mode === 'illustrator') {
                nameBtn.classList.remove('mode-btn-active', 'text-white');
                nameBtn.classList.add('text-purple-300');
                illustratorBtn.classList.add('mode-btn-active', 'text-white');
                illustratorBtn.classList.remove('text-purple-300');
                input.placeholder = "Enter illustrator name (e.g., Mitsuhiro Arita)...";
                title.innerText = "Search Catalog by Illustrator";
            } else {
                illustratorBtn.classList.remove('mode-btn-active', 'text-white');
                illustratorBtn.classList.add('text-purple-300');
                nameBtn.classList.add('mode-btn-active', 'text-white');
                nameBtn.classList.remove('text-purple-300');
                input.placeholder = "Search by name (e.g., Charizard, Charizard ex) or number (e.g., 001/100)...";
                title.innerText = "Search Catalog";
            }
            input.focus();
        }

        async function searchExternalAPI() {
            const rawInput = document.getElementById('universalSearchInput').value.trim();
            const container = document.getElementById('apiResultsContainer');

            if (!rawInput) return;

            container.innerHTML = '<div class="col-span-full py-12 text-center text-purple-300/80 text-sm animate-pulse tr-sidebar rounded-2xl">Searching English and Japanese databases...</div>';

            try {
                let searchMode = 'name';
                const isNumSearch = /^\d+(\/\d+)?$/i.test(rawInput);
                const rawNumPart = isNumSearch ? rawInput.split('/')[0] : rawInput;

                if (currentSearchMode === 'illustrator') {
                    searchMode = 'illustrator';
                } else if (isNumSearch) {
                    searchMode = 'number';
                }

                let enQuery = searchMode === 'number' ? rawNumPart : rawInput;

                const fetchPromises = [
                    fetch(`api/card_image_proxy.php?query=${encodeURIComponent(enQuery)}&lang=en&mode=${searchMode}`).then(r => r.json()).catch(() => ({ success: false, cards: [] })),
                    fetch(`api/card_image_proxy.php?query=${encodeURIComponent(enQuery)}&lang=ja&mode=${searchMode}`).then(r => r.json()).catch(() => ({ success: false, cards: [] }))
                ];

                const results = await Promise.all(fetchPromises);
                let allCards = [];

                results.forEach((res, index) => {
                    if (res && res.success && Array.isArray(res.cards)) {
                        res.cards.forEach(card => {
                            if (!card._apiLang) {
                                card._apiLang = (index === 0) ? 'en' : 'ja';
                            }
                            allCards.push(card);
                        });
                    }
                });

                const uniqueMap = new Map();
                allCards.forEach((c, idx) => {
                    if (c && (c.id || c.localId || c.name)) {
                        const lang = normalizeLang(c._apiLang);
                        const cardId = c.id || c.localId || `custom_${idx}`;
                        const uniqueKey = `${lang}_${cardId}`;
                        if (!uniqueMap.has(uniqueKey)) {
                            uniqueMap.set(uniqueKey, c);
                        }
                    }
                });

                globalMasterResults = Array.from(uniqueMap.values());

                if (globalMasterResults.length === 0) {
                    container.innerHTML = `<div class="col-span-full py-12 text-center text-purple-300/60 text-sm tr-sidebar rounded-2xl">No cards found matching "${rawInput}".</div>`;
                    return;
                }

                globalMasterResults = globalMasterResults.map(card => {
                    card.englishDisplayName = card.name || enQuery;
                    card.detectedType = detectCardType(card);
                    return card;
                });

                renderFilteredResults();

            } catch (err) {
                console.error(err);
                container.innerHTML = '<div class="col-span-full py-12 text-center text-rose-300 text-sm tr-sidebar rounded-2xl">Error connecting to backend search. Please try again.</div>';
            }
        }

        function extractConvertedPhpPrice(card) {
            if (!card) return 0.00;
            let rawVal = card.price || card.marketPrice || 0;
            return rawVal * USD_TO_PHP_RATE;
        }

        function renderFilteredResults() {
            const container = document.getElementById('apiResultsContainer');
            const selectedLang = normalizeLang(document.getElementById('filterLanguage').value);
            const selectedType = document.getElementById('filterType').value;
            const sortBy = document.getElementById('sortBy').value;

            if (globalMasterResults.length > 0) {
                container.innerHTML = '';
                let filtered = globalMasterResults.filter(card => {
                    if (selectedLang !== 'all' && normalizeLang(card._apiLang) !== selectedLang) return false;
                    if (selectedType !== 'all' && card.detectedType !== selectedType) return false;
                    return true;
                });

                if (sortBy === 'name_asc') {
                    filtered.sort((a, b) => (a.englishDisplayName || '').localeCompare(b.englishDisplayName || ''));
                } else if (sortBy === 'name_desc') {
                    filtered.sort((a, b) => (b.englishDisplayName || '').localeCompare(a.englishDisplayName || ''));
                } else if (sortBy === 'price_desc') {
                    filtered.sort((a, b) => extractConvertedPhpPrice(b) - extractConvertedPhpPrice(a));
                } else if (sortBy === 'price_asc') {
                    filtered.sort((a, b) => extractConvertedPhpPrice(a) - extractConvertedPhpPrice(b));
                }

                if (filtered.length === 0) {
                    container.innerHTML = '<div class="col-span-full py-12 text-center text-purple-300/60 text-sm tr-sidebar rounded-2xl">No cards match the selected filter.</div>';
                } else {
                    filtered.forEach((card) => {
                        const imageUrl = buildCardImageUrl(card);
                        const cardLang = normalizeLang(card._apiLang);
                        const displayName = card.englishDisplayName || card.name || 'Card';
                        const formattedCardNum = formatCardNumber(card);
                        const cardType = card.detectedType || 'normal';
                        const cardRarity = card.rarity || 'C';

                        const calculatedPhpPrice = extractConvertedPhpPrice(card);
                        const apiMarketPricePhp = calculatedPhpPrice > 0 ? calculatedPhpPrice.toFixed(2) : '5.00';

                        const cardElement = document.createElement('div');
                        cardElement.className = 'tr-card p-4 flex flex-col justify-between';
                        cardElement.innerHTML = `
                            <div>
                                <div class="w-full aspect-[3/4] bg-[#0a0810] rounded-lg mb-3 flex items-center justify-center overflow-hidden border border-purple-900/40 relative">
                                    <img src="${imageUrl}" alt="${displayName}" class="w-full h-full object-contain" loading="lazy" onerror="handleImageError(this, '${displayName.replace(/'/g, "\\'")}', '${formattedCardNum}');">
                                </div>
                                <div class="flex justify-between items-start mb-1">
                                    <h3 class="font-bold text-white text-xs truncate flex-1" title="${displayName}">${displayName}</h3>
                                    <span class="ml-1 text-[9px] badge-${cardType} px-1.5 py-0.5 rounded font-bold uppercase">${cardType}</span>
                                </div>
                                <div class="flex items-center justify-between mt-1">
                                    <span class="text-[9px] bg-purple-950/60 text-purple-300 border border-purple-800/40 px-1.5 py-0.5 rounded font-bold uppercase">${cardLang.toUpperCase()}</span>
                                    <p class="text-[10px] text-purple-300/80 font-semibold">No. ${formattedCardNum}</p>
                                </div>
                            </div>
                            <button type="button" onclick='openCardModal({
                                name: "${displayName.replace(/'/g, "\\'")}",
                                cardNumber: "${formattedCardNum}",
                                lang: "${cardLang}",
                                type: "${cardType}",
                                rarity: "${cardRarity}",
                                image: "${imageUrl === DEFAULT_FALLBACK_IMAGE ? '' : imageUrl}",
                                buyPrice: "0.50",
                                sellPrice: "${apiMarketPricePhp}"
                            })' class="mt-3 purple-btn text-white text-xs font-bold py-1.5 rounded-lg uppercase w-full">
                                Add Card
                            </button>
                        `;
                        container.appendChild(cardElement);
                    });
                }
            }
        }

        function openManualCardModal(type) {
            openCardModal({
                name: '',
                cardNumber: '001',
                lang: 'en',
                type: type || 'normal',
                rarity: 'C',
                image: '',
                buyPrice: '0.50',
                sellPrice: '5.00'
            });
        }

        function openCardModal(data) {
            document.getElementById('modal_api_name').value = data.name || '';
            document.getElementById('modal_api_card_number').value = data.cardNumber || '';
            document.getElementById('modal_api_image_url').value = data.image || '';
            document.getElementById('modal_card_type').value = data.type || 'normal';

            // Language Radio Selection
            const langVal = normalizeLang(data.lang);
            if (langVal === 'ja') {
                document.getElementById('lang_ja').checked = true;
            } else if (langVal === 'other') {
                document.getElementById('lang_other').checked = true;
            } else {
                document.getElementById('lang_en').checked = true;
            }

            // Rarity Radio Selection
            const rarityInput = (data.rarity || 'C').toUpperCase();
            let rarityRadio = document.querySelector(`input[name="api_rarity"][value="${rarityInput}"]`);
            if (rarityRadio) {
                rarityRadio.checked = true;
            } else {
                document.getElementById('rarity_c').checked = true;
            }

            const imgEl = document.getElementById('modal_card_img');
            imgEl.src = (data.image && data.image.trim() !== '') ? data.image : DEFAULT_FALLBACK_IMAGE;

            // Apply price values
            const parsedBuy = parseFloat(data.buyPrice);
            document.getElementById('modal_buy_price_input').value = (!isNaN(parsedBuy) && parsedBuy >= 0) ? parsedBuy.toFixed(2) : '0.50';

            const parsedSell = parseFloat(data.sellPrice);
            document.getElementById('modal_sell_price_input').value = (!isNaN(parsedSell) && parsedSell > 0) ? parsedSell.toFixed(2) : '5.00';

            document.getElementById('cardModal').classList.remove('hidden');
        }

        function updateModalImagePreview(url) {
            const imgEl = document.getElementById('modal_card_img');
            imgEl.src = (url && url.trim() !== '') ? url.trim() : DEFAULT_FALLBACK_IMAGE;
        }

        function closeCardModal() {
            document.getElementById('cardModal').classList.add('hidden');
        }

        function submitModalForm(isCosigned) {
            document.getElementById('modal_is_cosigned').value = isCosigned ? '1' : '0';
            const form = document.getElementById('cardModalForm');
            
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
        }

        async function handleCardSubmit(event) {
            event.preventDefault();
            const form = document.getElementById('cardModalForm');
            const formData = new FormData(form);
            const alertContainer = document.getElementById('ajaxAlertContainer');

            try {
                const response = await fetch('cards.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (response.ok) {
                    const result = await response.json();
                    if (result.success) {
                        alertContainer.innerHTML = `<div class="p-4 rounded-xl border bg-emerald-950/40 border-emerald-500/30 text-emerald-300 text-xs flex items-center space-x-2 backdrop-blur-md mb-6 shadow-lg"><span>${result.message}</span></div>`;
                        closeCardModal();
                    } else {
                        alertContainer.innerHTML = `<div class="p-4 rounded-xl border bg-rose-950/40 border-rose-500/30 text-rose-300 text-xs flex items-center space-x-2 backdrop-blur-md mb-6 shadow-lg"><span>${result.message}</span></div>`;
                    }
                }
            } catch (err) {}
        }
    </script>
</body>

</html>