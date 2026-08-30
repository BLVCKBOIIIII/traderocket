<?php
session_start();
require_once 'config/database.php';

// Security check: If not logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['user_role'] ?? 'partner';
$userId = $_SESSION['user_id'];

// Capture Filter Parameters from GET
$sortFilter = $_GET['sort'] ?? 'default';       
$stockFilter = $_GET['stock'] ?? 'all';         
$valueFilter = $_GET['value'] ?? 'all';         // Price filter parameter
$rarityFilter = $_GET['rarity'] ?? 'all';       
$ownershipFilter = $_GET['ownership'] ?? 'all'; 
$typeFilter = $_GET['type'] ?? 'all';           

// Build base SQL query - Safely fetch cards without breaking if 'language' column doesn't exist
$stocksSql = "
    SELECT 
        i.*, 
        c.id AS card_master_id,
        c.name AS card_name, 
        c.card_number, 
        c.rarity, 
        c.pokemon_type AS types, 
        s.name AS set_name,
        COALESCE(NULLIF(c.image_url, ''), '') AS card_image,
        COALESCE(NULLIF(i.condition_status, ''), 'NM') AS card_condition_name,
        COALESCE(i.price, c.price, 0) AS card_price,
        COALESCE(i.quantity, 0) AS quantity
    FROM Inventory_stocks i
    INNER JOIN cards c ON i.card_id = c.id
    LEFT JOIN sets s ON c.set_id = s.id
";

$params = [];
$whereClauses = [];

if ($role !== 'admin') {
    $whereClauses[] = "i.owner_id = ?";
    $params[] = $userId;
}

if ($stockFilter === 'low') {
    $whereClauses[] = "i.quantity <= 2 AND i.quantity > 0";
} else {
    $whereClauses[] = "i.quantity > 0";
}

if ($ownershipFilter === 'cosigned') {
    $whereClauses[] = "i.is_cosigned = 1";
} else if ($ownershipFilter === 'non_cosigned') {
    $whereClauses[] = "(i.is_cosigned = 0 OR i.is_cosigned IS NULL)";
}

if (!empty($whereClauses)) {
    $stocksSql .= " WHERE " . implode(" AND ", $whereClauses);
}

$stocksSql .= " ORDER BY i.id DESC";

try {
    $stmtCards = $pdo->prepare($stocksSql);
    $stmtCards->execute($params);
    $rawCards = $stmtCards->fetchAll(PDO::FETCH_ASSOC);

    $inventoryCards = [];
    foreach ($rawCards as $card) {
        $price = floatval($card['card_price']);
        $rarity = strtolower(trim($card['rarity'] ?? ''));
        $cardTypes = strtolower(trim($card['types'] ?? ''));

        // Price Quick Filter Logic
        if ($valueFilter === 'free' && $price > 0) continue;
        if ($valueFilter === '5' && $price != 5) continue;
        if ($valueFilter === '10' && $price != 10) continue;
        if ($valueFilter === '15' && $price != 15) continue;
        if ($valueFilter === '20' && $price != 20) continue;
        if ($valueFilter === '25' && $price != 25) continue;
        if ($valueFilter === '50_below' && ($price <= 0 || $price > 50)) continue;
        if ($valueFilter === '100_below' && ($price <= 0 || $price > 100)) continue;
        if ($valueFilter === 'above_100' && $price <= 100) continue;

        if ($rarityFilter === 'common_uncommon') {
            if (strpos($rarity, 'common') === false && strpos($rarity, 'uncommon') === false) continue;
        } else if ($rarityFilter === 'rare_holo') {
            if (strpos($rarity, 'rare') === false && strpos($rarity, 'holo') === false) continue;
        } else if ($rarityFilter === 'double_rare') {
            if (strpos($rarity, 'double') === false && strpos($rarity, 'ultra') === false && strpos($rarity, 'secret') === false && strpos($rarity, 'ex') === false && strpos($rarity, 'gx') === false && strpos($rarity, 'vmax') === false) continue;
        }

        if ($typeFilter !== 'all') {
            $searchType = strtolower(trim($typeFilter));
            $matchsType = false;
            $typesArray = preg_split('/[\s,\/]+/', $cardTypes);

            if ($searchType === 'electric') {
                $matchsType = in_array('electric', $typesArray) || in_array('lightning', $typesArray) || 
                              strpos($cardTypes, 'electric') !== false || strpos($cardTypes, 'lightning') !== false;
            } else if ($searchType === 'metal') {
                $matchsType = in_array('metal', $typesArray) || in_array('steel', $typesArray) || 
                              strpos($cardTypes, 'metal') !== false || strpos($cardTypes, 'steel') !== false;
            } else if ($searchType === 'dark') {
                $matchsType = in_array('dark', $typesArray) || in_array('darkness', $typesArray) || 
                              strpos($cardTypes, 'dark') !== false || strpos($cardTypes, 'darkness') !== false;
            } else if ($searchType === 'normal') {
                $matchsType = in_array('colorless', $typesArray) || in_array('normal', $typesArray) || 
                              strpos($cardTypes, 'colorless') !== false || strpos($cardTypes, 'normal') !== false;
            } else if ($searchType === 'ground') {
                $matchsType = in_array('ground', $typesArray) || in_array('fighting', $typesArray) || 
                              strpos($cardTypes, 'ground') !== false || strpos($cardTypes, 'fighting') !== false;
            } else {
                $matchsType = in_array($searchType, $typesArray) || strpos($cardTypes, $searchType) !== false;
            }
            
            $matchsCategory = (isset($card['category']) && strtolower(trim($card['category'])) === $searchType);
            if (!$matchsType && !$matchsCategory) continue;
        }

        $inventoryCards[] = $card;
    }

    if ($sortFilter === 'random') {
        shuffle($inventoryCards);
    } else if ($sortFilter === 'card_number') {
        usort($inventoryCards, function ($a, $b) {
            return strnatcmp($a['card_number'] ?? '', $b['card_number'] ?? '');
        });
    }

} catch (PDOException $e) {
    $inventoryCards = [];
}

// Helper function to safely detect language and format as JP or EN
function formatLanguageDisplay($card) {
    $lang = $card['language'] ?? $card['card_language'] ?? $card['lang'] ?? '';
    $cleanLang = strtolower(trim($lang));
    
    if (in_array($cleanLang, ['jp', 'ja', 'japanese', 'jpn'])) {
        return 'JP';
    }
    
    $name = $card['card_name'] ?? '';
    if (preg_match('/[\x{3000}-\x{303F}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{FF00}-\x{FFEF}\x{4E00}-\x{9FAF}]/u', $name)) {
        return 'JP';
    }

    return 'EN';
}

// Helper function to build dynamic infinite loop array
function prepareLoopCards($cards) {
    if (empty($cards)) return [];
    
    $loopCards = $cards;
    while (count($loopCards) < 20) {
        $loopCards = array_merge($loopCards, $cards);
    }
    
    return array_merge($loopCards, $loopCards);
}

// Helper function to render cards HTML output
function renderCardsHtml($cards) {
    if (empty($cards)): ?>
        <div class="tr-sidebar p-12 text-center max-w-lg w-full border border-purple-900/30 mx-auto">
            <h2 class="text-xl font-bold mb-2 text-white">No Inventory Found</h2>
            <p class="text-xs text-purple-300/60 mb-6">No cards match your current filter combination.</p>
        </div>
    <?php else: 
        $loopCards = prepareLoopCards($cards);
        foreach ($loopCards as $card): 
            $cardImg = !empty($card['card_image']) ? $card['card_image'] : 'https://via.placeholder.com/250x350?text=No+Image';
            $langDisplay = formatLanguageDisplay($card);
            $encodedCard = htmlspecialchars(json_encode([
                'name' => $card['card_name'] ?? 'Unknown Card',
                'price' => number_format((float)$card['card_price'], 2),
                'condition' => $card['card_condition_name'],
                'quantity' => (int)$card['quantity'],
                'image' => $cardImg,
                'rarity' => $card['rarity'] ?? 'N/A',
                'cardNumber' => $card['card_number'] ?? 'N/A',
                'setName' => $card['set_name'] ?? 'N/A',
                'types' => $card['types'] ?? 'N/A',
                'language' => $langDisplay,
                'isCosigned' => !empty($card['is_cosigned'])
            ]), ENT_QUOTES, 'UTF-8');
        ?>
            <div onclick="openCardModal(<?php echo $encodedCard; ?>)" 
                 class="flex-shrink-0 group relative transition-transform duration-300 hover:scale-105 flex flex-col items-center pt-3 cursor-pointer">
                <?php if (!empty($card['is_cosigned'])): ?>
                    <div class="absolute top-0 left-1/2 transform -translate-x-1/2 z-25 bg-[#0b0b0e]/95 border border-yellow-500/50 px-3 py-1 rounded-full shadow-lg flex items-center space-x-1.5 whitespace-nowrap">
                        <span class="w-2.5 h-2.5 rounded-full bg-yellow-400 inline-block shadow-[0_0_8px_#facc15]"></span>
                        <span class="text-[9px] font-bold text-yellow-300 uppercase tracking-wider">Co-signed</span>
                    </div>
                <?php endif; ?>

                <img src="<?php echo htmlspecialchars($cardImg); ?>" 
                     alt="<?php echo htmlspecialchars($card['card_name'] ?? 'Card'); ?>" 
                     class="card-image h-[280px] md:h-[320px] w-auto object-contain rounded-xl shadow-2xl border border-purple-900/30 group-hover:border-purple-500/50 transition-all duration-300"
                     onerror="this.onerror=null; this.src='https://via.placeholder.com/250x350?text=No+Image';">
                
                <div class="w-full mt-3 bg-[#09080e] border border-purple-950/80 p-2.5 rounded-xl text-center shadow-[0_4px_20px_rgba(0,0,0,0.8)] flex flex-col items-center space-y-1">
                    <p class="text-base md:text-lg font-black text-amber-300 truncate max-w-[270px] leading-tight tracking-wider drop-shadow-[0_2px_4px_rgba(0,0,0,1)] [-webkit-text-stroke:0.5px_#ffffff]"><?php echo htmlspecialchars($card['card_name'] ?? 'Unknown Card'); ?></p>
                    <div class="flex items-center justify-center space-x-2.5 w-full pt-1 border-t border-purple-950/60">
                        <span class="text-lg md:text-xl font-black text-emerald-400">₱<?php echo number_format((float)$card['card_price'], 2); ?></span>
                        <span class="text-xs font-black px-1.5 py-0.5 rounded border <?php echo $langDisplay === 'JP' ? 'bg-red-950/80 text-red-300 border-red-500/50' : 'bg-blue-950/80 text-blue-300 border-blue-500/50'; ?>"><?php echo $langDisplay; ?></span>
                        <span class="text-lg md:text-xl font-black text-purple-200">Qty: <strong class="text-white"><?php echo (int)$card['quantity']; ?></strong></span>
                    </div>
                </div>
            </div>
        <?php endforeach; 
    endif;
}

// Handle AJAX filter requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    ob_start();
    renderCardsHtml($inventoryCards);
    $content = ob_get_clean();
    echo json_encode(['html' => $content, 'count' => count($inventoryCards)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Card Carousel | Trade Rocket TCG</title>
    <?php include 'includes/styles.php'; ?>
    <style>
        .filter-btn {
            background: rgba(18, 15, 29, 0.7);
            border: 1px solid rgba(147, 51, 234, 0.3);
            color: #d8b4fe;
            font-size: 11px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 8px;
            transition: all 0.2s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .filter-btn:hover, .filter-btn.active {
            background: linear-gradient(135deg, #a855f7 0%, #7e22ce 100%);
            color: #ffffff;
            border-color: #a855f7;
        }
        .speed-btn {
            background: rgba(18, 15, 29, 0.7);
            border: 1px solid rgba(147, 51, 234, 0.3);
            color: #d8b4fe;
            font-size: 11px;
            font-weight: 600;
            padding: 6px 10px;
            border-radius: 6px;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .speed-btn:hover, .speed-btn.active {
            background: linear-gradient(135deg, #a855f7 0%, #7e22ce 100%);
            color: #ffffff;
            border-color: #a855f7;
        }
        input[type=range] {
            accent-color: #a855f7;
        }
        .carousel-track {
            display: flex;
            width: max-content;
            will-change: transform;
            transition: opacity 0.15s ease;
        }

        /* Modal Smooth Transitions */
        #card-modal {
            transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #card-modal-content {
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
        }
        #card-modal.modal-closed {
            opacity: 0;
            pointer-events: none;
        }
        #card-modal.modal-closed #card-modal-content {
            transform: scale(0.9) translateY(20px);
            opacity: 0;
        }
        #card-modal.modal-open {
            opacity: 1;
            pointer-events: auto;
        }
        #card-modal.modal-open #card-modal-content {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        /* Fullscreen Layout Overrides */
        body.is-fullscreen {
            justify-content: center;
            padding: 0;
        }
        body.is-fullscreen #page-header,
        body.is-fullscreen #filter-control-bar,
        body.is-fullscreen #top-controls-container,
        body.is-fullscreen #subtext-desc {
            display: none !important;
        }
        body.is-fullscreen #fullscreen-header-title {
            display: block !important;
            margin-top: 1.5rem !important;
        }
        body.is-fullscreen #carousel-container {
            margin-top: 1rem !important;
        }
        body.is-fullscreen #fullscreen-footer-bar {
            display: flex !important;
            margin-top: 1.5rem !important;
        }
        body.is-fullscreen .card-image {
            height: 380px !important;
        }
    </style>
</head>
<body id="main-body" class="min-h-screen flex flex-col bg-[#0b0b0e] text-white overflow-x-hidden">

    <div id="page-header">
        <?php include 'includes/header.php'; ?>
    </div>

    <main class="flex-1 w-full flex flex-col items-center justify-center p-6 relative">
        
        <div id="top-controls-container" class="w-full max-w-7xl flex flex-col md:flex-row items-center justify-between mb-6 px-4 gap-4">
            <div id="brand-title-box" class="text-center md:text-left">
                <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight text-transparent bg-clip-text bg-gradient-to-r from-purple-400 via-fuchsia-300 to-amber-300 uppercase">
                    RRRBINN - Trade Rocket TCG
                </h1>
                <p id="subtext-desc" class="text-xs text-purple-300/60 mt-1">Hover Left/Right edges to control scroll direction. Click any card to inspect full details.</p>
            </div>

            <div class="flex items-center space-x-3 shrink-0 flex-wrap justify-center gap-y-2">
                <div class="flex items-center space-x-1.5 bg-[rgba(18,15,29,0.7)] border border-purple-900/40 p-2 rounded-lg flex-wrap gap-y-1">
                    <span class="text-[10px] font-bold text-purple-300 px-1 uppercase">Speed:</span>
                    <button onclick="setSpeed(20)" id="speed-slowest" class="speed-btn" title="Slowest speed">Slowest</button>
                    <button onclick="setSpeed(45)" id="speed-slow" class="speed-btn" title="Slower speed">Slower</button>
                    <button onclick="setSpeed(80)" id="speed-normal" class="speed-btn" title="Default speed">Normal</button>
                    <button onclick="setSpeed(160)" id="speed-fast" class="speed-btn" title="Fast speed">Fast</button>
                    <button onclick="setSpeed(320)" id="speed-faster" class="speed-btn" title="Faster speed">Faster</button>
                    
                    <div class="flex items-center space-x-1 ml-2 border-l border-purple-900/50 pl-2">
                        <input type="range" class="speed-slider-control w-20 h-1 cursor-pointer" min="20" max="320" step="5" value="80" oninput="onSliderSpeedChange(this.value)">
                    </div>
                </div>

                <button id="fs-btn" onclick="toggleFullScreen()" class="purple-btn text-white text-xs font-semibold px-4 py-2.5 rounded-lg flex items-center space-x-2 shadow-lg">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l-5 5M11 5l-5-5v-4m0 4h-4" />
                    </svg>
                    <span id="fs-btn-text">Enter Fullscreen Mode</span>
                </button>
            </div>
        </div>

        <div id="filter-control-bar" class="w-full max-w-7xl mb-6 px-4 flex flex-wrap gap-3 items-center justify-between bg-[rgba(18,15,29,0.5)] border border-purple-900/30 p-4 rounded-xl backdrop-blur-md">
            
            <div class="flex items-center space-x-2 flex-wrap gap-y-2" data-filter-group="sort">
                <span class="text-[11px] font-bold text-purple-300 uppercase tracking-wide mr-1">Sort:</span>
                <button onclick="applyFilter('sort', 'default', this)" class="filter-btn <?php echo $sortFilter === 'default' ? 'active' : ''; ?>">Default</button>
                <button onclick="applyFilter('sort', 'random', this)" class="filter-btn <?php echo $sortFilter === 'random' ? 'active' : ''; ?>">Randomize</button>
                <button onclick="applyFilter('sort', 'card_number', this)" class="filter-btn <?php echo $sortFilter === 'card_number' ? 'active' : ''; ?>">Card #</button>
            </div>

            <div class="flex items-center space-x-2 flex-wrap gap-y-2" data-filter-group="stock">
                <span class="text-[11px] font-bold text-purple-300 uppercase tracking-wide mr-1">Stock:</span>
                <button onclick="applyFilter('stock', 'all', this)" class="filter-btn <?php echo $stockFilter === 'all' ? 'active' : ''; ?>">All Stock</button>
                <button onclick="applyFilter('stock', 'low', this)" class="filter-btn <?php echo $stockFilter === 'low' ? 'active' : ''; ?>">Low Stock (≤2)</button>
            </div>

            <div class="flex items-center space-x-2 flex-wrap gap-y-2" data-filter-group="value">
                <span class="text-[11px] font-bold text-purple-300 uppercase tracking-wide mr-1">Price:</span>
                <button onclick="applyFilter('value', 'all', this)" class="filter-btn <?php echo $valueFilter === 'all' ? 'active' : ''; ?>">All Price</button>
                <button onclick="applyFilter('value', 'free', this)" class="filter-btn <?php echo $valueFilter === 'free' ? 'active' : ''; ?>">Free (₱0)</button>
                <button onclick="applyFilter('value', '5', this)" class="filter-btn <?php echo $valueFilter === '5' ? 'active' : ''; ?>">₱5</button>
                <button onclick="applyFilter('value', '10', this)" class="filter-btn <?php echo $valueFilter === '10' ? 'active' : ''; ?>">₱10</button>
                <button onclick="applyFilter('value', '15', this)" class="filter-btn <?php echo $valueFilter === '15' ? 'active' : ''; ?>">₱15</button>
                <button onclick="applyFilter('value', '20', this)" class="filter-btn <?php echo $valueFilter === '20' ? 'active' : ''; ?>">₱20</button>
                <button onclick="applyFilter('value', '25', this)" class="filter-btn <?php echo $valueFilter === '25' ? 'active' : ''; ?>">₱25</button>
                <button onclick="applyFilter('value', '50_below', this)" class="filter-btn <?php echo $valueFilter === '50_below' ? 'active' : ''; ?>">≤ ₱50</button>
                <button onclick="applyFilter('value', '100_below', this)" class="filter-btn <?php echo $valueFilter === '100_below' ? 'active' : ''; ?>">≤ ₱100</button>
                <button onclick="applyFilter('value', 'above_100', this)" class="filter-btn <?php echo $valueFilter === 'above_100' ? 'active' : ''; ?>">> ₱100</button>
            </div>

            <div class="flex items-center space-x-2 flex-wrap gap-y-2" data-filter-group="rarity">
                <span class="text-[11px] font-bold text-purple-300 uppercase tracking-wide mr-1">Rarity:</span>
                <button onclick="applyFilter('rarity', 'all', this)" class="filter-btn <?php echo $rarityFilter === 'all' ? 'active' : ''; ?>" title="All Rarities">
                    All
                </button>
                <button onclick="applyFilter('rarity', 'common_uncommon', this)" class="filter-btn <?php echo $rarityFilter === 'common_uncommon' ? 'active' : ''; ?>" title="Common / Uncommon">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="8"/></svg>
                    <span>C/UC</span>
                </button>
                <button onclick="applyFilter('rarity', 'rare_holo', this)" class="filter-btn <?php echo $rarityFilter === 'rare_holo' ? 'active' : ''; ?>" title="Rare / Holo Rare">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    <span>Rare</span>
                </button>
                <button onclick="applyFilter('rarity', 'double_rare', this)" class="filter-btn <?php echo $rarityFilter === 'double_rare' ? 'active' : ''; ?>" title="Double Rare / Ultra / Secret">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.4 4.8 5.3.8-3.8 3.7.9 5.3-4.8-2.5-4.8 2.5.9-5.3-3.8-3.7 5.3-.8L12 2zm0 5l-1.4 2.8-3.1.5 2.2 2.2-.5 3.1 2.8-1.5 2.8 1.5-.5-3.1 2.2-2.2-3.1-.5L12 7z"/></svg>
                    <span>RR+</span>
                </button>
            </div>

            <div class="flex items-center space-x-2 flex-wrap gap-y-2" data-filter-group="type">
                <span class="text-[11px] font-bold text-purple-300 uppercase tracking-wide mr-1">Type:</span>
                <button onclick="applyFilter('type', 'all', this)" class="filter-btn <?php echo $typeFilter === 'all' ? 'active' : ''; ?>">All Types</button>
                <button onclick="applyFilter('type', 'fire', this)" class="filter-btn <?php echo $typeFilter === 'fire' ? 'active' : ''; ?>">Fire</button>
                <button onclick="applyFilter('type', 'water', this)" class="filter-btn <?php echo $typeFilter === 'water' ? 'active' : ''; ?>">Water</button>
                <button onclick="applyFilter('type', 'grass', this)" class="filter-btn <?php echo $typeFilter === 'grass' ? 'active' : ''; ?>">Grass</button>
                <button onclick="applyFilter('type', 'electric', this)" class="filter-btn <?php echo $typeFilter === 'electric' ? 'active' : ''; ?>">Electric</button>
                <button onclick="applyFilter('type', 'psychic', this)" class="filter-btn <?php echo $typeFilter === 'psychic' ? 'active' : ''; ?>">Psychic</button>
                <button onclick="applyFilter('type', 'dark', this)" class="filter-btn <?php echo $typeFilter === 'dark' ? 'active' : ''; ?>">Dark</button>
                <button onclick="applyFilter('type', 'ground', this)" class="filter-btn <?php echo $typeFilter === 'ground' ? 'active' : ''; ?>">Ground</button>
                <button onclick="applyFilter('type', 'metal', this)" class="filter-btn <?php echo $typeFilter === 'metal' ? 'active' : ''; ?>">Metal</button>
                <button onclick="applyFilter('type', 'normal', this)" class="filter-btn <?php echo $typeFilter === 'normal' ? 'active' : ''; ?>">Normal</button>
                <button onclick="applyFilter('type', 'fairy', this)" class="filter-btn <?php echo $typeFilter === 'fairy' ? 'active' : ''; ?>">Fairy</button>
                <button onclick="applyFilter('type', 'dragon', this)" class="filter-btn <?php echo $typeFilter === 'dragon' ? 'active' : ''; ?>">Dragon</button>
            </div>

            <div class="flex items-center space-x-2 flex-wrap gap-y-2" data-filter-group="ownership">
                <span class="text-[11px] font-bold text-purple-300 uppercase tracking-wide mr-1">Ownership:</span>
                <button onclick="applyFilter('ownership', 'all', this)" class="filter-btn <?php echo $ownershipFilter === 'all' ? 'active' : ''; ?>">All Inventories</button>
                <button onclick="applyFilter('ownership', 'cosigned', this)" class="filter-btn <?php echo $ownershipFilter === 'cosigned' ? 'active' : ''; ?>">Co-signed</button>
                <button onclick="applyFilter('ownership', 'non_cosigned', this)" class="filter-btn <?php echo $ownershipFilter === 'non_cosigned' ? 'active' : ''; ?>">Non-Cosigned</button>
            </div>
        </div>

        <!-- Fullscreen Header Title Placed Directly Above Slideshow Carousel -->
        <div id="fullscreen-header-title" class="hidden text-center mb-4 z-20">
            <h1 class="text-4xl md:text-5xl font-black tracking-tight text-transparent bg-clip-text bg-gradient-to-r from-purple-400 via-fuchsia-300 to-amber-300 uppercase">
                RRRBINN - TRADE ROCKET TCG
            </h1>
        </div>

        <!-- Dual Slideshow Carousel Container -->
        <div id="carousel-container" class="w-full flex flex-col space-y-6 relative py-4">
            
            <div class="absolute left-0 top-0 bottom-0 w-24 bg-gradient-to-r from-[#0b0b0e] to-transparent z-10 pointer-events-none flex items-center justify-start pl-2">
                <svg class="w-6 h-6 text-purple-400 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            </div>
            <div class="absolute right-0 top-0 bottom-0 w-24 bg-gradient-to-l from-[#0b0b0e] to-transparent z-10 pointer-events-none flex items-center justify-end pr-2">
                <svg class="w-6 h-6 text-purple-400 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
            </div>

            <!-- Top Carousel Track (Left-to-Right by default) -->
            <div id="carousel-viewport-top" class="carousel-viewport w-full overflow-hidden relative cursor-pointer">
                <div id="carousel-track-top" class="carousel-track flex space-x-8 items-center">
                    <?php renderCardsHtml($inventoryCards); ?>
                </div>
            </div>

            <!-- Bottom Carousel Track (Right-to-Left by default) -->
            <div id="carousel-viewport-bottom" class="carousel-viewport w-full overflow-hidden relative cursor-pointer">
                <div id="carousel-track-bottom" class="carousel-track flex space-x-8 items-center">
                    <?php renderCardsHtml($inventoryCards); ?>
                </div>
            </div>

        </div>

        <!-- Fullscreen Bottom Bar directly Underneath the Slideshow -->
        <div id="fullscreen-footer-bar" class="hidden w-full max-w-7xl justify-between items-center mt-4 px-6 z-30">
            <span class="text-2xl font-extrabold text-emerald-400 drop-shadow-[0_0_12px_rgba(52,211,153,0.5)] tracking-wide">
                Available Now!
            </span>

            <div class="flex items-center space-x-3 bg-[rgba(18,15,29,0.85)] border border-purple-900/50 px-4 py-2 rounded-xl backdrop-blur-md shadow-lg">
                <span class="text-xs font-bold text-purple-300 uppercase">Speed:</span>
                <input type="range" class="speed-slider-control w-28 h-1.5 cursor-pointer" min="20" max="320" step="5" value="80" oninput="onSliderSpeedChange(this.value)">
            </div>
        </div>

    </main>

    <!-- Detailed Card Zoom Modal -->
    <div id="card-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md modal-closed" onclick="closeCardModal(event)">
        <div id="card-modal-content" class="relative bg-[#09080e] border border-purple-500/40 rounded-2xl p-6 md:p-8 max-w-2xl w-full shadow-[0_0_50px_rgba(168,85,247,0.25)] flex flex-col md:flex-row gap-6 items-center" onclick="event.stopPropagation()">
            
            <button onclick="closeCardModal()" class="absolute top-4 right-4 text-purple-300 hover:text-white bg-purple-950/60 hover:bg-purple-800/80 p-2 rounded-full transition-colors z-10 border border-purple-500/30">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="relative shrink-0 flex flex-col items-center">
                <div id="modal-cosigned-badge" class="hidden absolute -top-3 bg-[#0b0b0e]/95 border border-yellow-500/50 px-3 py-1 rounded-full shadow-lg items-center space-x-1.5 z-10">
                    <span class="w-2.5 h-2.5 rounded-full bg-yellow-400 inline-block shadow-[0_0_8px_#facc15]"></span>
                    <span class="text-[9px] font-bold text-yellow-300 uppercase tracking-wider">Co-signed</span>
                </div>
                <img id="modal-card-image" src="" alt="" class="h-[420px] w-auto object-contain rounded-xl shadow-2xl border border-purple-500/40">
            </div>

            <div class="flex-1 w-full flex flex-col justify-between space-y-4 text-left">
                <div>
                    <h2 id="modal-card-name" class="text-2xl font-black text-amber-300 tracking-wide mb-1 drop-shadow-md"></h2>
                    <p id="modal-set-name" class="text-xs font-semibold text-purple-300/80 uppercase tracking-wider mb-3"></p>
                    <p id="modal-card-price" class="text-3xl font-black text-emerald-400 mb-4"></p>
                </div>

                <div class="grid grid-cols-2 gap-3 bg-[#050408] border border-purple-950/80 p-4 rounded-xl text-xs">
                    <div>
                        <span class="text-purple-400/80 block font-semibold text-[10px] uppercase">Condition</span>
                        <span id="modal-card-condition" class="font-bold text-purple-200 text-sm"></span>
                    </div>
                    <div>
                        <span class="text-purple-400/80 block font-semibold text-[10px] uppercase">Available Qty</span>
                        <span id="modal-card-qty" class="font-bold text-white text-sm"></span>
                    </div>
                    <div>
                        <span class="text-purple-400/80 block font-semibold text-[10px] uppercase">Language</span>
                        <span id="modal-card-lang" class="font-bold text-purple-200 text-sm"></span>
                    </div>
                    <div>
                        <span class="text-purple-400/80 block font-semibold text-[10px] uppercase">Rarity</span>
                        <span id="modal-card-rarity" class="font-bold text-purple-200 text-sm capitalize"></span>
                    </div>
                    <div>
                        <span class="text-purple-400/80 block font-semibold text-[10px] uppercase">Card #</span>
                        <span id="modal-card-number" class="font-bold text-purple-200 text-sm"></span>
                    </div>
                    <div class="col-span-2 border-t border-purple-950/60 pt-2 mt-1">
                        <span class="text-purple-400/80 block font-semibold text-[10px] uppercase">Types</span>
                        <span id="modal-card-types" class="font-bold text-purple-200 text-sm capitalize"></span>
                    </div>
                </div>

                <button onclick="closeCardModal()" class="w-full py-3 bg-gradient-to-r from-purple-600 to-purple-800 hover:from-purple-500 hover:to-purple-700 text-white font-bold text-xs uppercase tracking-wider rounded-xl transition-all shadow-lg border border-purple-400/30">
                    Return to Slideshow
                </button>
            </div>
        </div>
    </div>

    <script>
        const activeFilters = {
            sort: '<?php echo $sortFilter; ?>',
            stock: '<?php echo $stockFilter; ?>',
            value: '<?php echo $valueFilter; ?>',
            rarity: '<?php echo $rarityFilter; ?>',
            type: '<?php echo $typeFilter; ?>',
            ownership: '<?php echo $ownershipFilter; ?>'
        };

        const trackTop = document.getElementById('carousel-track-top');
        const trackBottom = document.getElementById('carousel-track-bottom');
        const viewports = document.querySelectorAll('.carousel-viewport');

        let posTop = 0;
        let posBottom = 0;
        let pxPerSecond = 80;

        // Base scroll directions: top moves L-to-R (-1), bottom moves R-to-L (1)
        let dirTop = -1;
        let dirBottom = 1;

        let isHoverPaused = false;
        let isModalOpen = false;
        let lastTimestamp = null;

        function setSpeed(speedPxPerSec) {
            pxPerSecond = parseFloat(speedPxPerSec);
            localStorage.setItem('tcg_slideshow_speed', pxPerSecond);

            document.querySelectorAll('.speed-slider-control').forEach(slider => {
                slider.value = pxPerSecond;
            });

            document.querySelectorAll('.speed-btn').forEach(btn => btn.classList.remove('active'));
            if (pxPerSecond <= 25) {
                document.getElementById('speed-slowest')?.classList.add('active');
            } else if (pxPerSecond <= 50) {
                document.getElementById('speed-slow')?.classList.add('active');
            } else if (pxPerSecond >= 250) {
                document.getElementById('speed-faster')?.classList.add('active');
            } else if (pxPerSecond >= 120) {
                document.getElementById('speed-fast')?.classList.add('active');
            } else {
                document.getElementById('speed-normal')?.classList.add('active');
            }
        }

        function onSliderSpeedChange(val) {
            setSpeed(val);
        }

        function animateCarousel(timestamp) {
            if (!lastTimestamp) lastTimestamp = timestamp;
            const delta = (timestamp - lastTimestamp) / 1000;
            lastTimestamp = timestamp;

            if (!isHoverPaused && !isModalOpen) {
                // Animate Top Track
                if (trackTop && trackTop.scrollWidth > 0) {
                    const halfWidth = trackTop.scrollWidth / 2;
                    posTop -= dirTop * pxPerSecond * delta;

                    if (posTop <= -halfWidth) posTop += halfWidth;
                    else if (posTop > 0) posTop -= halfWidth;

                    trackTop.style.transform = `translateX(${posTop}px)`;
                }

                // Animate Bottom Track
                if (trackBottom && trackBottom.scrollWidth > 0) {
                    const halfWidth = trackBottom.scrollWidth / 2;
                    posBottom -= dirBottom * pxPerSecond * delta;

                    if (posBottom <= -halfWidth) posBottom += halfWidth;
                    else if (posBottom > 0) posBottom -= halfWidth;

                    trackBottom.style.transform = `translateX(${posBottom}px)`;
                }
            }

            requestAnimationFrame(animateCarousel);
        }

        requestAnimationFrame(animateCarousel);

        // Hover direction/pause overrides
        viewports.forEach(vp => {
            vp.addEventListener('mousemove', (e) => {
                if (isModalOpen) return;
                const rect = vp.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const width = rect.width;
                const leftBoundary = width * 0.20;
                const rightBoundary = width * 0.80;

                if (mouseX < leftBoundary) {
                    isHoverPaused = false;
                    dirTop = -1;
                    dirBottom = -1;
                } else if (mouseX > rightBoundary) {
                    isHoverPaused = false;
                    dirTop = 1;
                    dirBottom = 1;
                } else {
                    isHoverPaused = true;
                }
            });

            vp.addEventListener('mouseleave', () => {
                isHoverPaused = false;
                dirTop = -1;
                dirBottom = 1;
            });
        });

        // Modal Controls
        function openCardModal(cardData) {
            isModalOpen = true;

            document.getElementById('modal-card-name').textContent = cardData.name;
            document.getElementById('modal-set-name').textContent = cardData.setName;
            document.getElementById('modal-card-price').textContent = '₱' + cardData.price;
            document.getElementById('modal-card-condition').textContent = cardData.condition;
            document.getElementById('modal-card-qty').textContent = cardData.quantity;
            document.getElementById('modal-card-lang').textContent = cardData.language;
            document.getElementById('modal-card-rarity').textContent = cardData.rarity;
            document.getElementById('modal-card-number').textContent = cardData.cardNumber;
            document.getElementById('modal-card-types').textContent = cardData.types;
            document.getElementById('modal-card-image').src = cardData.image;

            const badge = document.getElementById('modal-cosigned-badge');
            if (cardData.isCosigned) {
                badge.classList.remove('hidden');
                badge.classList.add('flex');
            } else {
                badge.classList.add('hidden');
                badge.classList.remove('flex');
            }

            const modal = document.getElementById('card-modal');
            modal.classList.remove('modal-closed');
            modal.classList.add('modal-open');
        }

        function closeCardModal(e) {
            if (e && e.target !== document.getElementById('card-modal') && e.type === 'click' && !e.target.closest('button')) {
                return;
            }
            const modal = document.getElementById('card-modal');
            modal.classList.remove('modal-open');
            modal.classList.add('modal-closed');

            setTimeout(() => {
                isModalOpen = false;
            }, 300);
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isModalOpen) {
                closeCardModal();
            }
        });

        function applyFilter(key, value, btn) {
            activeFilters[key] = value;

            const group = btn.closest('[data-filter-group]');
            if (group) {
                group.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            }

            const queryParams = new URLSearchParams(activeFilters).toString();
            window.history.replaceState({}, '', '?' + queryParams);

            if (trackTop) trackTop.style.opacity = '0';
            if (trackBottom) trackBottom.style.opacity = '0';

            setTimeout(() => {
                fetch('slideshow.php?' + queryParams, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (trackTop) trackTop.innerHTML = data.html;
                    if (trackBottom) trackBottom.innerHTML = data.html;

                    if (trackTop && trackTop.scrollWidth > 0) {
                        const newHalfWidth = trackTop.scrollWidth / 2;
                        posTop = posTop % newHalfWidth;
                        posBottom = posBottom % newHalfWidth;

                        trackTop.style.transform = `translateX(${posTop}px)`;
                        trackBottom.style.transform = `translateX(${posBottom}px)`;
                    }

                    if (trackTop) trackTop.style.opacity = '1';
                    if (trackBottom) trackBottom.style.opacity = '1';
                })
                .catch(() => {
                    if (trackTop) trackTop.style.opacity = '1';
                    if (trackBottom) trackBottom.style.opacity = '1';
                });
            }, 150);
        }

        window.addEventListener('DOMContentLoaded', () => {
            const savedSpeed = parseFloat(localStorage.getItem('tcg_slideshow_speed')) || 80;
            setSpeed(savedSpeed);
        });

        function toggleFullScreen() {
            const body = document.documentElement;
            if (!document.fullscreenElement) {
                if (body.requestFullscreen) body.requestFullscreen();
                else if (body.webkitRequestFullscreen) body.webkitRequestFullscreen();
            } else {
                if (document.exitFullscreen) document.exitFullscreen();
                else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
            }
        }

        document.addEventListener('fullscreenchange', () => {
            const body = document.body;
            const fsBtnText = document.getElementById('fs-btn-text');

            if (document.fullscreenElement) {
                body.classList.add('is-fullscreen');
                if (fsBtnText) fsBtnText.textContent = 'Exit Fullscreen Mode';
            } else {
                body.classList.remove('is-fullscreen');
                if (fsBtnText) fsBtnText.textContent = 'Enter Fullscreen Mode';
            }
        });
    </script>

</body>
</html>