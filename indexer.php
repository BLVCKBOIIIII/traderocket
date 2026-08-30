<?php
// Pre-indexes TCGdex card data into a fast local SQLite DB
$db = new PDO('sqlite:cards.sqlite');
$db->exec("CREATE TABLE IF NOT EXISTS cards (
    id TEXT PRIMARY KEY,
    name TEXT,
    localId TEXT,
    set_name TEXT,
    image TEXT
)");

$languages = ['en', 'ja'];

foreach ($languages as $lang) {
    $json = file_get_contents("https://api.tcgdex.net/v2/{$lang}/cards");
    $cards = json_decode($json, true);

    if (!is_array($cards)) continue;

    $stmt = $db->prepare("INSERT OR REPLACE INTO cards (id, name, localId, set_name, image) VALUES (?, ?, ?, ?, ?)");
    
    foreach ($cards as $card) {
        if (empty($card['id'])) continue;
        
        $img = isset($card['image']) ? $card['image'] . '/high.webp' : '';
        $stmt->execute([
            $card['id'],
            $card['name'] ?? 'Unknown',
            $card['localId'] ?? ($card['number'] ?? 'N/A'),
            $card['set']['name'] ?? 'Unknown Set',
            $img
        ]);
    }
}
echo "Database Indexed Successfully!";