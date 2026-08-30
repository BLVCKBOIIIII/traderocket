<?php
// ... existing code in invoice.php ...
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice - Trade Rocket TCG</title>
    <!-- Tailwind CSS / Stylesheets -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 font-sans antialiased">
    <div class="max-w-3xl mx-auto p-6">
        <!-- Navigation Header -->
        <div class="mb-6 flex justify-between items-center">
            <h1 class="text-xl font-bold tracking-tight text-white">Invoice Details</h1>
            <a href="pos_module.php" class="inline-flex items-center space-x-2 text-xs font-semibold text-purple-400 hover:text-purple-300 transition-colors">
                <span>Back to POS Terminal</span>
            </a>
        </div>
        
        <!-- Invoice Container Content -->
        <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 shadow-xl">
            <!-- Add your existing invoice rendering logic here -->
        </div>
    </div>
</body>
</html>