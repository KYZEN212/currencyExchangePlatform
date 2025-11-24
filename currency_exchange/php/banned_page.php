<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Suspended</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .pulse-animation {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-100 to-gray-200 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-md w-full">
        <div class="text-center">
            <div class="bg-red-100 rounded-full w-24 h-24 flex items-center justify-center mx-auto mb-6 pulse-animation">
                <i class="fas fa-ban text-red-600 text-5xl"></i>
            </div>
            
            <h1 class="text-3xl font-bold text-gray-900 mb-3">Account Suspended</h1>
            <p class="text-gray-600 mb-6">Your account has been suspended by an administrator</p>
            
            <div class="bg-red-50 border-2 border-red-200 rounded-xl p-6 mb-6">
                <div class="text-sm text-gray-600 mb-2">
                    <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>
                    Your session has been terminated
                </div>
                <p class="text-sm text-gray-700 mt-3">
                    You cannot access your account during this suspension period.
                </p>
            </div>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <p class="text-sm text-gray-700">
                    <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                    If you believe this is a mistake, please contact support.
                </p>
            </div>
            
            <a href="home.php" class="block w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold py-3 px-6 rounded-lg transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                <i class="fas fa-home mr-2"></i>Go to Home Page
            </a>
        </div>
    </div>
</body>
</html>
