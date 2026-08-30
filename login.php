<?php
session_start();
require_once 'config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $passwordValid = false;

                // Support both plain-text (legacy) and hashed passwords
                if (password_verify($password, $user['password'])) {
                    $passwordValid = true;
                } else if ($password === $user['password']) {
                    $passwordValid = true;
                }

                if ($passwordValid) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_role'] = $user['role'] ?? 'user';

                    // Role-based redirection
                    if ($_SESSION['user_role'] === 'partner') {
                        header('Location: copartners.php');
                    } else {
                        header('Location: index.php');
                    }
                    exit;
                } else {
                    $error = 'Invalid credentials. Please try again.';
                }
            } else {
                $error = 'Invalid credentials. Please try again.';
            }
        } catch (PDOException $e) {
            $error = 'Database error encountered. Please check your connection.';
        }
    } else {
        $error = 'Please fill in both username and password fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | Trade Rocket TCG</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap');
        body {
            font-family: 'Space Grotesk', sans-serif;
            background-color: #0b0b0e;
            color: #f4f4f5;
        }
        .tr-sidebar {
            background: linear-gradient(145deg, #13101c 0%, #0d0b12 100%);
            border: 1px solid rgba(147, 51, 234, 0.2);
            border-radius: 1rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(168, 85, 247, 0.1);
        }
        .purple-btn {
            background: linear-gradient(135deg, #a855f7 0%, #7e22ce 50%, #6b21a8 100%);
            box-shadow: 0 0 20px rgba(168, 85, 247, 0.35);
            transition: all 0.2s ease;
        }
        .purple-btn:hover {
            background: linear-gradient(135deg, #c084fc 0%, #9333ea 50%, #7e22ce 100%);
            box-shadow: 0 0 25px rgba(168, 85, 247, 0.55);
        }
        input:focus {
            border-color: #a855f7 !important;
            box-shadow: 0 0 10px rgba(168, 85, 247, 0.25);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center bg-[#0b0b0e] p-4">

    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-extrabold tracking-widest text-transparent bg-clip-text bg-gradient-to-r from-purple-400 via-fuchsia-300 to-white">
                TRADE ROCKET
            </h1>
            <p class="text-xs uppercase tracking-widest text-purple-300/60 mt-1">
                Trading Card Game Inventory Management
            </p>
        </div>

        <div class="tr-sidebar p-8 border border-purple-900/40 relative overflow-hidden">
            <h2 class="text-lg font-bold text-white mb-2">Partner Sign In</h2>
            <p class="text-xs text-zinc-400 mb-6">Enter your credentials to access your store inventory.</p>

            <?php if ($error): ?>
                <div class="mb-6 p-3.5 rounded-lg bg-rose-900/30 border border-rose-500/40 text-rose-300 text-xs flex items-center space-x-2">
                    <svg class="w-4 h-4 text-rose-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="space-y-5">
                <div>
                    <label for="username" class="block text-xs font-semibold text-purple-200/80 uppercase tracking-wider mb-2">
                        Username
                    </label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        required 
                        placeholder="e.g. admin"
                        class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-sm rounded-lg px-4 py-2.5 focus:outline-none placeholder-zinc-600 transition-colors"
                    >
                </div>

                <div>
                    <label for="password" class="block text-xs font-semibold text-purple-200/80 uppercase tracking-wider mb-2">
                        Password
                    </label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required 
                        placeholder="••••••••"
                        class="w-full bg-[#0a0810] border border-purple-900/40 text-white text-sm rounded-lg px-4 py-2.5 focus:outline-none placeholder-zinc-600 transition-colors"
                    >
                </div>

                <button 
                    type="submit" 
                    class="w-full purple-btn text-white text-sm font-semibold py-3 rounded-lg mt-2 focus:outline-none"
                >
                    Sign In to Account
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-purple-300/40 mt-6">
            &copy; <?php echo date('Y'); ?> Trade Rocket TCG. All rights reserved.
        </p>
    </div>

</body>
</html>