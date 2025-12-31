<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password - Accqura</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inria+Sans:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Inria Sans', sans-serif;
    }
    
    body {
      background: linear-gradient(135deg, #f0f9f0 0%, #d4edda 25%, #a8e0b8 65%, #7ac29a 100%);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 20px;
      color: #2d3748;
    }
    
    .card {
      background: #fff;
      width: 100%;
      max-width: 450px;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
      padding: 40px 35px;
      border: 1px solid rgba(0, 0, 0, 0.05);
    }
    
    h1 {
      color: #2e7d32;
      font-size: 24px;
      font-weight: 700;
      margin-bottom: 8px;
      text-align: center;
      letter-spacing: 0.5px;
    }
    
    p {
      color: #555;
      font-size: 15px;
      line-height: 1.5;
      margin: 0 0 25px;
      text-align: center;
    }
    
    input {
      width: 100%;
      padding: 14px 16px;
      background: #f8f9fa;
      border: 1px solid #e0e0e0;
      border-radius: 10px;
      font-size: 15px;
      color: #2d3748;
      transition: all 0.3s ease;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
      margin-bottom: 20px;
    }
    
    input:focus {
      outline: none;
      background: white;
      border-color: #4caf50;
      box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
    }
    
    input::placeholder {
      color: #a0aec0;
    }
    
    button {
      width: 100%;
      padding: 14px;
      background: #4caf50;
      color: white;
      border: none;
      border-radius: 10px;
      font-size: 15px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin: 10px 0 20px;
      box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
    }
    
    button:hover {
      background: #43a047;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(76, 175, 80, 0.4);
    }
    
    .link {
      display: block;
      text-align: center;
      color: #2e7d32;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.3s;
      border-bottom: 1px dashed transparent;
      padding-bottom: 1px;
      font-size: 14px;
      margin-top: 15px;
    }
    
    .link:hover {
      color: #1b5e20;
      border-bottom-color: #2e7d32;
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>Forgot your password?</h1>
    <p>Enter your account email and we'll send you a password reset link to create a new password.</p>
    <form method="POST" action="forgot_password_send.php">
      <input type="email" name="email" placeholder="Enter your email address" required>
      <button type="submit">Send Reset Link</button>
    </form>
    <a class="link" href="../html/login.html">← Back to login</a>
  </div>
</body>
</html>
