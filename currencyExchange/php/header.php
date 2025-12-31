<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACCQURA | Smart Currency Exchange</title>
    <link rel="icon" type="image/jpg" href="./img/sl_020622_4930_21.jpg">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2e7d32;
            --primary-dark: #1b5e20;
            --secondary: #4caf50;
            --accent: #81c784;
            --dark: #1e3a1e;
            --light: #f0f9f0;
            --card-bg: #ffffff;
            --card-shadow: rgba(0, 0, 0, 0.05);
            --text-primary: #2d3748;
            --text-secondary: #6b7280;
            --border-radius: 16px;
            --border-radius-sm: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f0f9f0 0%, #d4edda 25%, #a8e0b8 65%, #7ac29a 100%);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-weight: 700;
        }
        
        /* Navbar Styling */
        .navbar {
            background: linear-gradient(135deg, #2e7d32, #4caf50);
            padding: 1rem 0;
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.2);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .navbar-brand i {
            font-size: 1.8rem;
        }
        
        .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
            margin: 0 3px;
        }
        
        .nav-link:hover, .nav-link.active {
            color: white !important;
            background: rgba(255, 255, 255, 0.15);
        }
        
        .btn-login {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-weight: 600;
            padding: 8px 20px;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }
        
        .btn-login:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .btn-register {
            background: white;
            color: var(--primary);
            font-weight: 600;
            padding: 8px 20px;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-register:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        /* Card Styling */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            transition: var(--transition);
            background: var(--card-bg);
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        }
        
        /* Button Styling */
        .btn {
            font-weight: 600;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
            padding: 10px 20px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            border: none;
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #43a047, #1b5e20);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
            color: white;
        }
        
        .btn-outline-primary {
            border: 2px solid #4caf50;
            color: #4caf50;
            background: transparent;
        }
        
        .btn-outline-primary:hover {
            background: #4caf50;
            color: white;
            transform: translateY(-2px);
        }
        
        /* Form Styling */
        .form-control, .form-select {
            border-radius: var(--border-radius-sm);
            padding: 0.75rem 1rem;
            border: 1px solid #e0e0e0;
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #4caf50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.15);
        }
        
        /* Custom Utilities */
        .text-gradient {
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .bg-gradient-primary {
            background: linear-gradient(135deg, #4caf50, #2e7d32);
        }
        
        .section-padding {
            padding: 80px 0;
        }
        
        @media (max-width: 768px) {
            .section-padding {
                padding: 50px 0;
            }
        }
        
        /* Currency Ticker */
        .currency-ticker {
            background: rgba(0, 0, 0, 0.2);
            color: white;
            border-radius: var(--border-radius-sm);
            padding: 12px 0;
            font-size: 1rem;
            font-weight: 600;
        }
        
        .currency-ticker span {
            margin: 0 20px;
        }
        
        .up { color: #00ff99; }
        .down { color: #ff4d4d; }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-exchange-alt"></i>
                <span>ACCQURA</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <i class="fas fa-bars text-white"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto me-3">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#converter">Converter</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#rates">Rates</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                </ul>
                <div class="d-flex gap-2">
                    <a href="../html/login.html" class="btn btn-login">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </a>
                    <a href="../html/registration.html" class="btn btn-register">
                        <i class="fas fa-user-plus me-2"></i>Register
                    </a>
                </div>
            </div>
        </div>
    </nav>