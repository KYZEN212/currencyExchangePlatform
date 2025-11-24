<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FX Nexus | Real-Time Currency Exchange</title>
    <link rel="icon" type="image/x-icon" href="/static/favicon.ico">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Custom CSS -->
     <link rel="icon" href="./img/sl_020622_4930_21.jpg" type="image/jpg" sizes="16x16">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #7e22ce;
            --dark: #0f172a;
            --light: #f8fafc;
            --accent: #f59e0b;
            --card-bg: #ffffff;
            --card-shadow: rgba(0, 0, 0, 0.05);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1f5f9;
            color: var(--text-primary);
            line-height: 1.6;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-weight: 700;
        }
        
        /* Gradient backgrounds */
        .gradient-bg {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }
        
        .gradient-bg-reverse {
            background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
        }
        
        /* Card styling */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            transition: var(--transition);
            background: var(--card-bg);
        }
        
        .card-hover {
            transition: var(--transition);
        }
        
        .card-hover:hover {
            transform: translateY(-8px);
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.12);
        }
        
        /* Exchange rate display */
        .exchange-rate-display {
            font-size: 2.75rem;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.5px;
        }
        
        /* Currency flag */
        .currency-flag {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        /* Chart container */
        .chart-container {
            height: 320px;
            background: white;
            border-radius: var(--border-radius);
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }
        
        /* Navbar styling */
        .navbar {
            padding: 1rem 0;
            background: #ffffff !important; /* white navbar for user side */
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            transition: background 0.3s ease, box-shadow 0.3s ease;
        }
        /* On scroll, make navbar slightly darker */
        .navbar.scrolled {
            background: #ffffff !important;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
        }

        .navbar-brand img {
            height: 42px;
        }
        
        .nav-link {
            font-weight: 600;
            color: #1e293b !important; /* slate-800 */
            padding: 0.5rem 1rem !important;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }
        
        .nav-link:hover {
            background: rgba(37, 99, 235, 0.10);
            color: #2563eb !important; /* primary blue */
            transform: translateY(-2px);
        }
        
        .navbar-brand span {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, #38bdf8, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    -webkit-text-fill-color: transparent;
}

        /* Button styling */
        .btn {
            font-weight: 600;
    border-radius: var(--border-radius-sm);
    transition: var(--transition);
        }
        
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.25);
        }
        
        
.navbar .btn-outline-light {
    border: 1px solid rgba(255, 255, 255, 0.7);
}

.navbar .btn-outline-light:hover {
    background: rgba(255, 255, 255, 0.15);
    border-color: #fff;
}

.navbar .btn-light {
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    color: #fff;
    border: none;
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
}

.navbar .btn-light:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(59, 130, 246, 0.6);
}
.currency-ticker{
    /* background: linear-gradient(130deg, #ccc, #000); */
    background-color: rgb(0 0 0 / .2);
    color: #fff;
    border-radius: var(--border-radius-sm);
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
    margin-bottom: 20px;
    font-size: 1.1rem;
    font-weight: 600;
    padding: 10px 0;
    letter-spacing: 0.5px;
    text-align: center;
    
}
#currency-ticker {
  font-weight: 600;
  font-size: 1rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}
#currency-ticker span {
  transition: color 0.3s ease;
}
.up { color: #00ff99; }   /* green */
.down { color: #ff4d4d; } /* red */

        /* currency converter */
        
        
        /* Form styling */
        .form-control, .form-select {
            border-radius: var(--border-radius-sm);
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        
        .input-group-text {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius-sm);
        }
        
        /* Badge styling */
        .badge {
            font-weight: 600;
            padding: 0.5rem 0.875rem;
            border-radius: 30px;
        }
        
        /* Section spacing */
        section {
            padding: 5rem 0;
        }
        
        /* News card */
        .news-card {
            border-left: 4px solid var(--accent);
            transition: var(--transition);
            height: 100%;
        }
        
        .news-card:hover {
            border-left: 4px solid var(--primary);
        }
        
        /* Hero section */
        .hero-section {
            background-image: linear-gradient(135deg, rgba(37, 99, 235, 0.8), rgba(124, 58, 237, 0.8)),url('./img/currency_home.jpg');
            background-size: cover;
            
            position: relative;
            overflow: hidden;
            display: flex;
    justify-content: center;
    align-items: center;
    text-align: center;
    min-height: 100vh; /* full-screen hero */
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
        }

        /* currency converter  */
        .converter-card {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 6px 28px rgba(0, 0, 0, 0.12);
      padding: 40px;
      max-width: 900px;
      margin: 10px auto;
    }
    .amount-box {
      position: relative;
      width: 100%;
    }
    .amount-box .form-control {
      font-size: 1.3rem;
      font-weight: 600;
      padding-right: 2.5rem;
      text-align: left;
      width: 100%;
      border: 2px solid #ddd;
      border-radius: 12px;
      padding: 14px;
    }
    .amount-box .currency-symbol {
      position: absolute;
      right: 14px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 1.2rem;
      font-weight: bold;
      color: #444;
    }
    .converted-amount {
      font-size: 1.7rem;
      font-weight: 700;
      color: #0a9449;
      background: #e8f9f0;
      border: 2px solid #bdf0d8;
      border-radius: 12px;
      text-align: center;
      padding: 14px;
      width: 100%;
    }
    .label {
      font-weight: 600;
      color: #444;
      display: block;
      margin-bottom: 8px;
      text-align: left;
    }
    .exchange-rate, .last-updated {
      font-weight: 600;
      color: #222;
    }
    .swap-btn {
      background: linear-gradient(135deg, #2563eb, #7c3aed);
      border: none;
      border-radius: 50%;
      color: #fff;
      width: 60px;
      height: 60px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
      margin: auto;
      cursor: pointer;
    }
    .swap-btn:hover {
      opacity: 0.9;
    }
    .update-btn {
      background: linear-gradient(to right, #2563eb, #7c3aed);
      color: #fff;
      font-weight: 600;
      border: none;
      border-radius: 12px;
      padding: 14px 42px;
      margin-top: 35px;
      transition: 0.3s;
    }
    .update-btn:hover {
      transform: scale(1.05);
      opacity: 0.95;
    }
    .form-select {
      font-size: 1.1rem;
      padding: 14px;
      border-radius: 10px;
    }

    canvas {
        height: 300px; /* or any height */
    }
    /*live rate section*/
    #currencyDropdown {
      max-height: 200px; /* Adjust this value to fit exactly 5 items */
      overflow-y: auto;
    }

    video{
        width: 80%;
        height: auto;
        border-radius: var(--border-radius);
        margin: auto;
        display: block;
    }
        
        /* Table styling */
        .table {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table th {
            border-top: 1px solid #e2e8f0;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .table td {
            border-top: 1px solid #f1f5f9;
            padding: 0.75rem;
        }
        
        /* Footer styling */
        footer {
            background: var(--dark);
        }
        
        footer a {
            transition: var(--transition);
        }
        
        footer a:hover {
            color: white !important;
            transform: translateY(-2px);
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.8s ease forwards;
        }
        
        /* Glassmorphism effect */
        .glass-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        /* Custom utilities */
        .text-gradient {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .border-radius-sm {
            border-radius: var(--border-radius-sm);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .exchange-rate-display {
                font-size: 2rem;
            }
            
            section {
                padding: 3rem 0;
            }
            
            .display-4 {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top">

        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="https://static.photos/finance/200x200/1" alt="FX Nexus Logo" class="me-2">
                <span class="fw-bold">FX Nexus</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="#">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#converters">Converter</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#rates">Rates</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#news">News</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                </ul>
                <div class="ms-3 d-flex">
                    <a href="../html/login.html" class="btn btn-outline-primary btn-sm me-2">Login</a>
                    <a href="../html/registration.html" class="btn btn-primary btn-sm text-white">Register</a>
                </div>
            </div>
        </div>
    </nav>