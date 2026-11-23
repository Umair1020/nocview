<?php
session_start();

// Prevent caching
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Redirect if already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: index.php"); // Redirect to index.php instead of login.php
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Spotcomm Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      height: 100vh;
    }

    .desktop-layout, .mobile-layout {
      display: none;
    }

    @media (min-width: 769px) {
      .desktop-layout {
        display: flex;
        height: 100vh;
        overflow: hidden;
      }

      .left-panel {
        flex: 1;
        background: url('../assets/images/noclogo.png') no-repeat center center;
        background-size: cover;
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .right-panel {
        flex: 0.5;
        background: #fff;
        padding: 40px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
      }

      .spotcomm-logo {
        position: absolute;
        top: 30px;
        height: 40px;
      }

      .form-box {
        max-width: 350px;
        margin: 0 auto;
      }

      input[type="text"], input[type="password"] {
        width: 100%;
        padding: 10px 12px;
        margin-bottom: 15px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
      }

      button {
        width: 100%;
        padding: 10px;
        background-color: #6b21a8;
        color: #fff;
        border: none;
        border-radius: 4px;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 4px 10px rgba(107, 33, 168, 0.5);
        transition: box-shadow 0.3s ease;
      }

      button:hover {
        box-shadow: 0 6px 15px rgba(107, 33, 168, 0.7);
      }

      .footer {
        position: absolute;
        bottom: 20px;
        font-size: 13px;
        color: #666;
        text-align: center;
        width: 100%;
      }
    }

    @media (max-width: 768px) {
      .mobile-layout {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        height: 100vh;
        background: url('../assets/images/background.png') no-repeat center center;
        background-size: cover;
        position: relative;
      }

      .form-box {
        backdrop-filter: blur(20px);
        background: rgba(255, 255, 255, 0.15);
        border-radius: 20px;
        padding: 25px;
        max-width: 360px;
        width: 100%;
        color: #fff;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
      }

      .form-box h2 {
        text-align: center;
        margin-bottom: 20px;
        font-size: 22px;
        font-weight: 600;
        color: #fff;
      }

      input[type="text"], input[type="password"] {
        width: 100%;
        padding: 12px;
        margin-bottom: 15px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
      }

      .remember {
        display: flex;
        flex-direction: column;
        font-size: 12px;
        color: #eee;
        gap: 5px;
      }

      .para {
        list-style: none;
        color: #fff;
        text-decoration: none;
      }

      .para a {
        color: #fff;
        text-decoration: underline;
        font-weight: 600;
      }

      .footer {
        margin-top: 20px;
        font-size: 12px;
        color: #eee;
        text-align: center;
      }

      button {
        width: 100%;
        padding: 10px;
        background-color: #6b21a8;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(107, 33, 168, 0.5);
      }

      button:hover {
        box-shadow: 0 6px 15px rgba(107, 33, 168, 0.7);
      }

      .form-box img.spotcomm-logo {
        display: block;
        margin: 20px auto 10px;
        height: 30px;
      }
    }

    h2 {
      text-align: center !important;
      margin-bottom: 30px;
    }
  </style>
</head>
<body>
  <!-- Toast Notification -->
  <?php if (isset($_SESSION['toast'])): ?>
    <div class="position-fixed top-0 end-0 p-3" style="z-index: 9999">
      <div class="toast align-items-center text-white bg-<?= $_SESSION['toast']['type'] === 'success' ? 'success' : 'danger' ?> border-0" role="alert">
        <div class="d-flex">
          <div class="toast-body">
            <?= htmlspecialchars($_SESSION['toast']['message']) ?>
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>
    </div>
    <?php unset($_SESSION['toast']); ?>
  <?php endif; ?>

  <!-- Desktop Layout -->
  <div class="desktop-layout">
    <div class="left-panel"></div>
    <div class="right-panel">
      <img src="../assets/images/logo.png" alt="Spotcomm Logo" class="spotcomm-logo">
      <div class="form-box">
        <h2>Log in</h2>
        <form action="login_auth.php" method="POST">
          <input type="text" name="username" placeholder="Username" required>
          <input type="password" name="password" placeholder="Password" required>
          <div class="remember">
            <input type="checkbox" name="remember" id="remember">
            <label for="remember">Remember Password</label>
            <p class="para">For account changes <a href="#" data-bs-toggle="modal" data-bs-target="#accountModal">Click here</a></p>
          </div>
          <button type="submit" name="submit">Log In</button>
        </form>
      </div>
      <div class="footer">Whitelabelled for Ziauddin Group – © Spotcomm Global. All rights reserved.</div>
    </div>
  </div>

  <!-- Mobile Layout -->
  <div class="mobile-layout">
    <img src="../assets/images/noclogo1.png" alt="Nocview Logo" class="spotcomm-logo">
    <div class="form-box">
      <h2>Log in</h2>
      <form action="login_auth.php" method="POST">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <div class="form-check d-flex align-items-center justify-content-between flex-wrap">
          <label class="form-check-label" for="remember_mobile">
            <input class="form-check-input" type="checkbox" id="remember_mobile"> Remember Password
          </label>
        </div>
        <button type="submit" name="submit">Log In</button>
        <div class="flex justify-content-evenly items-end flex-wrap mt-4">
          <h6 class="font-medium mb-0">
            For Account Changes
            <a href="#" class="color fw-bold para" data-bs-toggle="modal" data-bs-target="#accountModal">Click here</a>
          </h6>
        </div>
      </form>
    </div>
    <img src="../assets/images/logo1.png" alt="Spotcomm Logo" class="w-25">
    <div class="footer">Whitelabelled for Ziauddin Group – © Spotcomm Global. All rights reserved.</div>
  </div>

  <!-- Modal -->
  <div class="modal fade" id="accountModal" tabindex="-1" aria-labelledby="accountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content p-4">
        <div class="modal-header">
          <h5 class="modal-title" id="accountModalLabel">Contact Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p><strong>Email:</strong> support@spotcommglobal.com</p>
          <p><strong>Phone:</strong> +9221 3223 7628</p>
          <p><strong>Website:</strong> https://spotcommglobal.com/</p>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Toast handling
    document.addEventListener('DOMContentLoaded', () => {
      const toastEl = document.querySelector('.toast');
      if (toastEl) {
        const toast = new bootstrap.Toast(toastEl);
        toast.show();
        setTimeout(() => toast.hide(), 3000); // Reduced timeout for faster dismissal
      }
    });

    // Prevent back navigation
    window.history.pushState(null, null, window.location.href);
    window.onpopstate = function () {
      window.history.pushState(null, null, window.location.href);
    };
  </script>
</body>
</html>