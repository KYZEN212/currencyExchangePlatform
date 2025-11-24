<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body{font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#f3f4f6; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px}
    .card{background:#fff; width:100%; max-width:420px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.08); padding:24px}
    h1{font-size:20px; margin:0 0 8px}
    p{color:#6b7280; font-size:14px; margin:0 0 16px}
    input,button{width:100%; padding:12px; border-radius:8px; border:1px solid #d1d5db}
    input{margin:8px 0 12px}
    button{background:#2563eb; color:#fff; border:0; font-weight:600; cursor:pointer}
    button:hover{background:#1d4ed8}
    .link{display:block; margin-top:12px; text-align:center; color:#2563eb; text-decoration:none}
  </style>
</head>
<body>
  <div class="card">
    <h1>Forgot your password?</h1>
    <p>Enter your account email and we'll send a reset link.</p>
    <form method="POST" action="forgot_password_send.php">
      <input type="email" name="email" placeholder="Email" required>
      <button type="submit">Send reset link</button>
    </form>
    <a class="link" href="../html/login.html">Back to login</a>
  </div>
</body>
</html>
