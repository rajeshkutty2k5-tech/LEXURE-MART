<?php
// ============================================================
// LUXE MART — PHP Backend
// File: api/index.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ============================================================
// ★  CONFIGURATION — FILL THESE IN BEFORE TESTING  ★
// ============================================================
define('DB_HOST',       'localhost');
define('DB_USER',       'root');
define('DB_PASS',       '');                          // blank for default XAMPP
define('DB_NAME',       'luxemart');

define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_PORT',     587);
define('MAIL_USERNAME', 'your_gmail@gmail.com');      // ← your Gmail address
define('MAIL_PASSWORD', 'xxxx xxxx xxxx xxxx');       // ← 16-char Gmail App Password
define('MAIL_FROM',     'your_gmail@gmail.com');      // ← same Gmail address
define('MAIL_FROM_NAME','Luxe Mart');
define('SITE_URL',      'http://localhost:8080/luxemart'); // ← your XAMPP URL

// ============================================================
// DATABASE CONNECTION
// ============================================================
function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            http_response_code(500);
            echo json_encode(['error' => 'DB connection failed: ' . $conn->connect_error]);
            exit;
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// ============================================================
// ROUTER
// ============================================================
$method = $_SERVER['REQUEST_METHOD'];

// Support both:
// 1. Direct: /luxemart/api/index.php?action=login  (no .htaccess needed)
// 2. Path:   /luxemart/api/auth?action=login        (needs .htaccess)
$action = $_GET['action'] ?? '';

// Map action directly to handler — works without .htaccess
$authActions = ['login','register','logout','me','forgot-password','verify-otp','reset-password'];
if (in_array($action, $authActions)) {
    handleAuth($method);
} elseif ($action === 'orders') {
    $id = $_GET['id'] ?? null;
    handleOrders($method, $id);
} elseif ($action === 'products') {
    $id = $_GET['id'] ?? null;
    handleProducts($method, $id);
} elseif ($action === 'cart')       { handleCart($method);
} elseif ($action === 'wishlist')   { handleWishlist($method);
} elseif ($action === 'categories') { handleCategories($method, $_GET['id'] ?? null);
} elseif ($action === 'coupons')    { handleCoupons($method, $_GET['id'] ?? null);
} elseif ($action === 'reviews')    { handleReviews($method, $_GET['id'] ?? null);
} elseif ($action === 'admin-stats'){ handleAdmin($method, 'stats');
} elseif ($action === 'upload')     { handleUpload();
} else {
    // Fallback: path-based routing (when .htaccess works)
    $path     = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $segments = explode('/', $path);
    $apiIndex = array_search('api', $segments);
    if ($apiIndex !== false) {
        $resource = $segments[$apiIndex + 1] ?? '';
        $id       = $segments[$apiIndex + 2] ?? null;
    } else {
        $resource = '';
        $id       = null;
    }
    switch ($resource) {
        case 'products':   handleProducts($method, $id);   break;
        case 'categories': handleCategories($method, $id); break;
        case 'orders':     handleOrders($method, $id);     break;
        case 'users':      handleUsers($method, $id);      break;
        case 'auth':       handleAuth($method);            break;
        case 'cart':       handleCart($method);            break;
        case 'wishlist':   handleWishlist($method);        break;
        case 'coupons':    handleCoupons($method, $id);    break;
        case 'reviews':    handleReviews($method, $id);    break;
        case 'admin':      handleAdmin($method, $id);      break;
        case 'upload':     handleUpload();                 break;
        default:
            json(['message' => 'Luxe Mart API v1.0 — OK', 'status' => 'running']);
    }
}

// ============================================================
// HELPERS
// ============================================================
function json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function body() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function auth() {
    $headers = getallheaders();
    $token   = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
    if (!$token) json(['error' => 'Unauthorized'], 401);
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, role FROM users WHERE token = ? AND token_expires > NOW()");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if (!$result) json(['error' => 'Invalid or expired token'], 401);
    return $result;
}

function adminAuth() {
    $user = auth();
    if ($user['role'] !== 'admin') json(['error' => 'Admin access required'], 403);
    return $user;
}

// ============================================================
// PHPMAILER — SEND EMAIL
// ============================================================
function sendMail($toEmail, $toName, $subject, $htmlBody) {
    // PHPMailer is installed inside api/vendor/ by Composer
    require_once __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        // Recipients
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

// ============================================================
// OTP EMAIL TEMPLATE
// ============================================================
function buildOtpEmail($userName, $otp) {
    $name = htmlspecialchars($userName);
    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f8f5f0;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f5f0;padding:40px 20px;">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0"
       style="background:#ffffff;border-radius:16px;overflow:hidden;
              box-shadow:0 4px 24px rgba(0,0,0,.10);">

  <!-- Header -->
  <tr>
    <td style="background:#1a1a2e;padding:32px;text-align:center;">
      <h1 style="margin:0;font-size:2rem;color:#e8b86d;letter-spacing:3px;
                 font-family:Georgia,serif;">LUXE MART</h1>
      <p style="margin:6px 0 0;color:rgba(255,255,255,.45);font-size:.82rem;
                letter-spacing:1px;">PREMIUM SHOPPING</p>
    </td>
  </tr>

  <!-- Body -->
  <tr>
    <td style="padding:40px 40px 32px;">
      <h2 style="margin:0 0 16px;font-size:1.35rem;color:#1a1a2e;">
        Password Reset OTP 🔐
      </h2>
      <p style="margin:0 0 8px;color:#444;line-height:1.7;font-size:.95rem;">
        Hi <strong>' . $name . '</strong>,
      </p>
      <p style="margin:0 0 28px;color:#555;line-height:1.7;font-size:.92rem;">
        We received a request to reset your Luxe Mart password.<br>
        Use the OTP below — it expires in <strong>15 minutes</strong>.
      </p>

      <!-- OTP Box -->
      <table cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
        <tr>
          <td style="background:#f8f5f0;border:2px dashed #e8b86d;
                     border-radius:16px;padding:24px 52px;text-align:center;">
            <p style="margin:0 0 8px;font-size:.75rem;color:#999;
                      letter-spacing:3px;text-transform:uppercase;">
              Your One-Time Password
            </p>
            <p style="margin:0;font-size:3.2rem;font-weight:700;color:#1a1a2e;
                      letter-spacing:14px;font-family:Georgia,serif;">
              ' . $otp . '
            </p>
          </td>
        </tr>
      </table>

      <p style="color:#888;font-size:.85rem;text-align:center;margin:0 0 28px;">
        Enter this OTP in the password reset form.<br>
        <strong>Do not share this OTP with anyone.</strong>
      </p>

      <hr style="border:none;border-top:1px solid #eee;margin-bottom:20px;">
      <p style="color:#bbb;font-size:.8rem;line-height:1.6;margin:0;">
        If you did not request a password reset, please ignore this email.<br>
        Your password will remain unchanged.
      </p>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="background:#f8f5f0;padding:20px 40px;text-align:center;
               border-top:1px solid #ece8e0;">
      <p style="margin:0 0 4px;color:#999;font-size:.8rem;">
        Need help?
        <a href="mailto:support@luxemart.com"
           style="color:#e8b86d;text-decoration:none;">support@luxemart.com</a>
      </p>
      <p style="margin:0;color:#ccc;font-size:.75rem;">
        &copy; 2025 Luxe Mart. All rights reserved.
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>';
}

// ============================================================
// ORDER CONFIRMATION EMAIL TEMPLATE
// ============================================================
function buildOrderEmail($userName, $orderNum, $items, $subtotal, $shipping, $tax, $total, $address, $paymentMethod) {
    $name      = htmlspecialchars($userName);
    $orderNum  = htmlspecialchars($orderNum);
    $address   = htmlspecialchars(is_array($address) ? implode(', ', array_filter((array)$address)) : $address);
    $payLabel  = strtoupper($paymentMethod);
    $date      = date('d M Y, h:i A');

    // Build items rows
    $rows = '';
    foreach ($items as $item) {
        $lineTotal = number_format($item['price'] * $item['qty'], 2);
        $rows .= '<tr>
          <td style="padding:12px 16px;border-bottom:1px solid #f0ebe3;font-size:.9rem;">
            <strong style="color:#1a1a2e;">' . htmlspecialchars($item['name']) . '</strong>
            <span style="color:#999;"> &times; ' . intval($item['qty']) . '</span>
          </td>
          <td style="padding:12px 16px;border-bottom:1px solid #f0ebe3;
                     text-align:right;font-weight:700;color:#1a1a2e;font-size:.9rem;">
            &#8377;' . $lineTotal . '
          </td>
        </tr>';
    }

    $shippingStr = $shipping == 0 ? 'Free' : '&#8377;' . number_format($shipping, 2);

    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f8f5f0;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f5f0;padding:40px 20px;">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0"
       style="background:#fff;border-radius:16px;overflow:hidden;
              box-shadow:0 4px 24px rgba(0,0,0,.10);">

  <!-- Header -->
  <tr>
    <td style="background:#1a1a2e;padding:32px;text-align:center;">
      <h1 style="margin:0;font-size:2rem;color:#e8b86d;letter-spacing:3px;
                 font-family:Georgia,serif;">LUXE MART</h1>
      <p style="margin:6px 0 0;color:rgba(255,255,255,.45);font-size:.82rem;">
        PREMIUM SHOPPING
      </p>
    </td>
  </tr>

  <!-- Success Banner -->
  <tr>
    <td style="background:linear-gradient(135deg,#e8b86d,#c97b4b);
               padding:22px;text-align:center;">
      <p style="margin:0;font-size:1.25rem;font-weight:700;color:#1a1a2e;">
        &#10003; Order Confirmed!
      </p>
      <p style="margin:6px 0 0;color:rgba(26,26,46,.65);font-size:.88rem;">
        Thank you for shopping with Luxe Mart
      </p>
    </td>
  </tr>

  <!-- Greeting + Order Meta -->
  <tr>
    <td style="padding:32px 40px 0;">
      <p style="color:#444;line-height:1.7;margin:0 0 20px;font-size:.93rem;">
        Hi <strong>' . $name . '</strong>,<br>
        Your order has been placed and is being processed.
      </p>
      <table width="100%" cellpadding="0" cellspacing="0"
             style="background:#f8f5f0;border-radius:12px;padding:16px 20px;
                    margin-bottom:24px;">
        <tr>
          <td style="font-size:.83rem;color:#999;padding-bottom:8px;">Order Number</td>
          <td style="font-size:.93rem;font-weight:700;color:#1a1a2e;
                     text-align:right;padding-bottom:8px;">' . $orderNum . '</td>
        </tr>
        <tr>
          <td style="font-size:.83rem;color:#999;padding-bottom:8px;">Date</td>
          <td style="font-size:.88rem;color:#555;text-align:right;
                     padding-bottom:8px;">' . $date . '</td>
        </tr>
        <tr>
          <td style="font-size:.83rem;color:#999;">Payment Method</td>
          <td style="font-size:.88rem;color:#555;text-align:right;">' . $payLabel . '</td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Items Table -->
  <tr>
    <td style="padding:0 40px;">
      <h3 style="margin:0 0 12px;color:#1a1a2e;font-size:.95rem;">
        Items Ordered
      </h3>
      <table width="100%" cellpadding="0" cellspacing="0"
             style="border:1px solid #f0ebe3;border-radius:10px;overflow:hidden;">
        ' . $rows . '
        <tr style="background:#fafaf8;">
          <td style="padding:10px 16px;font-size:.83rem;color:#999;">Shipping</td>
          <td style="padding:10px 16px;text-align:right;color:#555;
                     font-size:.88rem;">' . $shippingStr . '</td>
        </tr>
        <tr style="background:#fafaf8;">
          <td style="padding:10px 16px;font-size:.83rem;color:#999;">GST (18%)</td>
          <td style="padding:10px 16px;text-align:right;color:#555;
                     font-size:.88rem;">&#8377;' . number_format($tax, 2) . '</td>
        </tr>
        <tr style="background:#1a1a2e;">
          <td style="padding:14px 16px;font-weight:700;color:#e8b86d;font-size:1rem;">
            Total
          </td>
          <td style="padding:14px 16px;text-align:right;font-weight:700;
                     color:#e8b86d;font-size:1rem;">
            &#8377;' . number_format($total, 2) . '
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Shipping Address -->
  <tr>
    <td style="padding:24px 40px 0;">
      <h3 style="margin:0 0 10px;color:#1a1a2e;font-size:.95rem;">Shipping To</h3>
      <p style="margin:0;color:#555;line-height:1.7;font-size:.88rem;
                background:#f8f5f0;border-radius:10px;padding:14px 16px;">
        &#128205; ' . $address . '
      </p>
    </td>
  </tr>

  <!-- CTA Button -->
  <tr>
    <td style="padding:28px 40px;text-align:center;">
      <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
        <tr>
          <td style="background:linear-gradient(135deg,#e8b86d,#c97b4b);
                     border-radius:30px;padding:14px 40px;">
            <a href="' . SITE_URL . '/index.html"
               style="color:#1a1a2e;font-weight:700;font-size:.95rem;
                      text-decoration:none;display:block;">
              View My Orders &rarr;
            </a>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="background:#f8f5f0;padding:20px 40px;text-align:center;
               border-top:1px solid #ece8e0;">
      <p style="margin:0 0 4px;color:#999;font-size:.8rem;">
        Questions? Email
        <a href="mailto:support@luxemart.com"
           style="color:#e8b86d;text-decoration:none;">support@luxemart.com</a>
      </p>
      <p style="margin:0;color:#ccc;font-size:.75rem;">
        &copy; 2025 Luxe Mart. All rights reserved.
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>';
}

// ============================================================
// SEND ORDER CONFIRMATION
// ============================================================
function sendOrderConfirmationEmail($userId, $orderNum, $total, $items, $shippingAddress, $paymentMethod) {
    $db   = getDB();
    $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) return;

    $subtotal = 0;
    foreach ($items as $item) $subtotal += $item['price'] * $item['qty'];
    $shipping = $subtotal > 499 ? 0 : 49;
    $tax      = round($subtotal * 0.18, 2);

    $html = buildOrderEmail(
        $user['name'],
        $orderNum,
        $items,
        $subtotal,
        $shipping,
        $tax,
        $total,
        $shippingAddress,
        $paymentMethod
    );

    sendMail(
        $user['email'],
        $user['name'],
        'Order Confirmed — ' . $orderNum . ' | Luxe Mart',
        $html
    );
}

// ============================================================
// AUTHENTICATION
// ============================================================
function handleAuth($method) {
    $db     = getDB();
    $action = $_GET['action'] ?? '';
    $data   = body();

    // ── REGISTER ──────────────────────────────────────────────
    if ($action === 'register') {
        $required = ['name', 'email', 'password'];
        foreach ($required as $f) {
            if (empty($data[$f])) json(['error' => "$f is required"], 422);
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param('s', $data['email']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            json(['error' => 'Email already registered'], 409);
        }

        $hash    = password_hash($data['password'], PASSWORD_BCRYPT);
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        $phone   = $data['phone'] ?? null;

        $stmt = $db->prepare("INSERT INTO users (name, email, password, phone, token, token_expires) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('ssssss', $data['name'], $data['email'], $hash, $phone, $token, $expires);
        $stmt->execute();

        json([
            'token' => $token,
            'user'  => [
                'id'    => $db->insert_id,
                'name'  => $data['name'],
                'email' => $data['email'],
                'role'  => 'customer',
            ]
        ], 201);
    }

    // ── LOGIN ──────────────────────────────────────────────────
    if ($action === 'login') {
        if (empty($data['email']) || empty($data['password'])) {
            json(['error' => 'Email and password required'], 422);
        }

        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param('s', $data['email']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user || !password_verify($data['password'], $user['password'])) {
            json(['error' => 'Invalid email or password'], 401);
        }
        if ($user['status'] === 'blocked') {
            json(['error' => 'Account blocked. Contact support.'], 403);
        }

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        $stmt    = $db->prepare("UPDATE users SET token = ?, token_expires = ? WHERE id = ?");
        $stmt->bind_param('ssi', $token, $expires, $user['id']);
        $stmt->execute();

        unset($user['password']);
        $user['token'] = $token;
        json($user);
    }

    // ── LOGOUT ────────────────────────────────────────────────
    if ($action === 'logout') {
        $user = auth();
        $stmt = $db->prepare("UPDATE users SET token = NULL WHERE id = ?");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        json(['message' => 'Logged out successfully']);
    }

    // ── ME ────────────────────────────────────────────────────
    if ($action === 'me') {
        $user = auth();
        $stmt = $db->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        json($stmt->get_result()->fetch_assoc());
    }

    // ── FORGOT PASSWORD ───────────────────────────────────────
    // Validates email in DB, generates 6-digit OTP,
    // saves it, and sends it directly to the user's email via PHPMailer.
    // The OTP is NEVER returned in the API response.
    if ($action === 'forgot-password') {
        if (empty($data['email'])) {
            json(['error' => 'Email address is required'], 422);
        }

        $email = strtolower(trim($data['email']));

        // Step 1: Validate email exists in database
        $stmt = $db->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        // Always return same message — do not reveal if email exists
        if (!$user) {
            json(['message' => 'If that email is registered, an OTP has been sent to it.']);
        }

        // Step 2: Generate secure 6-digit OTP
        $otp       = strval(random_int(100000, 999999));
        $otpExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $otpStored = 'otp_' . $otp;   // prefix so we can identify it later

        // Step 3: Save OTP to database
        $stmt = $db->prepare("UPDATE users SET token = ?, token_expires = ? WHERE id = ?");
        $stmt->bind_param('ssi', $otpStored, $otpExpiry, $user['id']);
        $stmt->execute();

        // Step 4: Build HTML email with OTP
        $html = buildOtpEmail($user['name'], $otp);

        // Step 5: Send email via PHPMailer — OTP goes to inbox, NOT to API response
        $sent = sendMail($email, $user['name'], 'Your Luxe Mart Password Reset OTP', $html);

        if ($sent) {
            // Success — do NOT include OTP in response
            json(['message' => 'OTP sent to ' . $email . '. Please check your inbox.']);
        } else {
            // Email failed — clear the OTP so it can't be guessed
            $null = null;
            $stmt = $db->prepare("UPDATE users SET token = NULL, token_expires = NULL WHERE id = ?");
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            json(['error' => 'Failed to send OTP email. Please check SMTP settings in index.php.'], 500);
        }
    }

    // ── VERIFY OTP ────────────────────────────────────────────
    // Checks the OTP the user typed against what's in the DB.
    // If valid, returns a short-lived reset_token for the next step.
    if ($action === 'verify-otp') {
        if (empty($data['email']) || empty($data['otp'])) {
            json(['error' => 'Email and OTP are required'], 422);
        }

        $email     = strtolower(trim($data['email']));
        $otpInput  = trim($data['otp']);

        // Validate OTP length
        if (strlen($otpInput) !== 6 || !ctype_digit($otpInput)) {
            json(['error' => 'OTP must be a 6-digit number'], 422);
        }

        $otpLookup = 'otp_' . $otpInput;

        // Check OTP matches and hasn't expired
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND token = ? AND token_expires > NOW()");
        $stmt->bind_param('ss', $email, $otpLookup);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            json(['error' => 'Invalid or expired OTP. Please request a new one.'], 400);
        }

        // OTP verified — generate a short-lived password reset token (10 min)
        $resetToken  = bin2hex(random_bytes(32));
        $resetExpiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $resetStored = 'pwd_reset_' . $resetToken;

        $stmt = $db->prepare("UPDATE users SET token = ?, token_expires = ? WHERE id = ?");
        $stmt->bind_param('ssi', $resetStored, $resetExpiry, $user['id']);
        $stmt->execute();

        json([
            'message'     => 'OTP verified successfully.',
            'reset_token' => $resetToken,
            'email'       => $email,
        ]);
    }

    // ── RESET PASSWORD ────────────────────────────────────────
    // Uses the reset_token from verify-otp to update the password.
    if ($action === 'reset-password') {
        if (empty($data['token']) || empty($data['email']) || empty($data['password'])) {
            json(['error' => 'Token, email and new password are required'], 422);
        }
        if (strlen($data['password']) < 6) {
            json(['error' => 'Password must be at least 6 characters'], 422);
        }

        $email       = strtolower(trim($data['email']));
        $tokenLookup = 'pwd_reset_' . $data['token'];

        // Validate reset token
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND token = ? AND token_expires > NOW()");
        $stmt->bind_param('ss', $email, $tokenLookup);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            json(['error' => 'Reset session expired. Please request a new OTP.'], 400);
        }

        // Update password and clear token
        $newHash = password_hash($data['password'], PASSWORD_BCRYPT);
        $stmt    = $db->prepare("UPDATE users SET password = ?, token = NULL, token_expires = NULL WHERE id = ?");
        $stmt->bind_param('si', $newHash, $user['id']);
        $stmt->execute();

        json(['message' => 'Password updated successfully. You can now log in.']);
    }
}

// ============================================================
// PRODUCTS
// ============================================================
function handleProducts($method, $id) {
    $db = getDB();

    if ($method === 'GET') {
        if ($id) {
            $stmt = $db->prepare("
                SELECT p.*, c.name as category_name,
                       AVG(r.rating) as avg_rating, COUNT(r.id) as review_count
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN reviews r ON r.product_id = p.id
                WHERE p.id = ? AND p.status = 'active'
                GROUP BY p.id
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            if (!$product) json(['error' => 'Product not found'], 404);

            $imgStmt = $db->prepare("SELECT url FROM product_images WHERE product_id = ?");
            $imgStmt->bind_param('i', $id);
            $imgStmt->execute();
            $product['images'] = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            json($product);
        }

        $page      = max(1, intval($_GET['page']  ?? 1));
        $limit     = max(1, intval($_GET['limit'] ?? 12));
        $offset    = ($page - 1) * $limit;
        $category  = $_GET['category']  ?? null;
        $search    = $_GET['search']    ?? null;
        $sort      = $_GET['sort']      ?? 'created_at DESC';
        $min_price = floatval($_GET['min_price'] ?? 0);
        $max_price = floatval($_GET['max_price'] ?? 999999);

        $where  = "p.status = 'active' AND p.price BETWEEN ? AND ?";
        $params = [$min_price, $max_price];
        $types  = 'dd';

        if ($category) { $where .= " AND c.slug = ?";                                $params[] = $category;   $types .= 's';  }
        if ($search)   { $where .= " AND (p.name LIKE ? OR p.description LIKE ?)";   $params[] = "%$search%"; $params[] = "%$search%"; $types .= 'ss'; }

        $validSorts = ['price ASC', 'price DESC', 'created_at DESC', 'avg_rating DESC'];
        $orderBy    = in_array($sort, $validSorts) ? $sort : 'p.created_at DESC';

        $sql = "SELECT p.id, p.name, p.price, p.original_price, p.stock, p.badge, p.thumbnail,
                       c.name as category_name, c.slug as category_slug,
                       AVG(r.rating) as avg_rating, COUNT(r.id) as review_count
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN reviews r ON r.product_id = p.id
                WHERE $where
                GROUP BY p.id
                ORDER BY $orderBy
                LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types   .= 'ii';

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $countParams = array_slice($params, 0, -2);
        $countTypes  = substr($types, 0, -2);
        $countSql    = "SELECT COUNT(DISTINCT p.id) as total
                        FROM products p
                        LEFT JOIN categories c ON p.category_id = c.id
                        WHERE $where";
        $countStmt   = $db->prepare($countSql);
        if ($countTypes) $countStmt->bind_param($countTypes, ...$countParams);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        json([
            'data'  => $products,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => (int)ceil($total / $limit),
        ]);
    }

    if ($method === 'POST') {
        adminAuth();
        $data     = body();
        $required = ['name', 'price', 'category_id', 'description'];
        foreach ($required as $f) {
            if (empty($data[$f])) json(['error' => "$f is required"], 422);
        }
        $pOrigPrice = $data['original_price'] ?? null;
        $pStock     = intval($data['stock']  ?? 0);
        $pBadge     = $data['badge']     ?? null;
        $pThumb     = $data['thumbnail'] ?? null;
        $pStatus    = $data['status']    ?? 'active';

        $stmt = $db->prepare("INSERT INTO products (name, description, price, original_price, category_id, stock, badge, thumbnail, status) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssddiisss',
            $data['name'], $data['description'], $data['price'],
            $pOrigPrice, $data['category_id'], $pStock,
            $pBadge, $pThumb, $pStatus
        );
        $stmt->execute();
        json(['id' => $db->insert_id, 'message' => 'Product created'], 201);
    }

    if ($method === 'PUT' && $id) {
        adminAuth();
        $data       = body();
        $uOrigPrice = $data['original_price'] ?? null;
        $uStock     = intval($data['stock']  ?? 0);
        $uBadge     = $data['badge']     ?? null;
        $uThumb     = $data['thumbnail'] ?? null;
        $uStatus    = $data['status']    ?? 'active';

        $stmt = $db->prepare("UPDATE products SET name=?, description=?, price=?, original_price=?, stock=?, badge=?, thumbnail=?, status=? WHERE id=?");
        $stmt->bind_param('ssddiissi',
            $data['name'], $data['description'], $data['price'],
            $uOrigPrice, $uStock, $uBadge, $uThumb, $uStatus, $id
        );
        $stmt->execute();
        json(['message' => 'Product updated']);
    }

    if ($method === 'DELETE' && $id) {
        adminAuth();
        $stmt = $db->prepare("UPDATE products SET status = 'deleted' WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        json(['message' => 'Product deleted']);
    }
}

// ============================================================
// CATEGORIES
// ============================================================
function handleCategories($method, $id) {
    $db = getDB();

    if ($method === 'GET') {
        $stmt = $db->query("
            SELECT c.*, COUNT(p.id) as product_count
            FROM categories c
            LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
            GROUP BY c.id
            ORDER BY c.sort_order
        ");
        json($stmt->fetch_all(MYSQLI_ASSOC));
    }

    if ($method === 'POST') {
        adminAuth();
        $data      = body();
        $sortOrder = intval($data['sort_order'] ?? 0);
        $catImage  = $data['image'] ?? null;
        $stmt      = $db->prepare("INSERT INTO categories (name, slug, icon, image, sort_order) VALUES (?,?,?,?,?)");
        $stmt->bind_param('ssssi', $data['name'], $data['slug'], $data['icon'], $catImage, $sortOrder);
        $stmt->execute();
        json(['id' => $db->insert_id, 'message' => 'Category created'], 201);
    }

    if ($method === 'DELETE' && $id) {
        adminAuth();
        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        json(['message' => 'Category deleted']);
    }
}

// ============================================================
// ORDERS
// ============================================================
function handleOrders($method, $id) {
    $db   = getDB();
    $user = auth();

    if ($method === 'GET') {
        if ($id) {
            $isAdmin = $user['role'] === 'admin' ? 1 : 0;
            $stmt    = $db->prepare("
                SELECT o.*,
                       JSON_ARRAYAGG(JSON_OBJECT(
                           'product_id', oi.product_id,
                           'name', p.name,
                           'qty', oi.qty,
                           'price', oi.price
                       )) as items
                FROM orders o
                LEFT JOIN order_items oi ON oi.order_id = o.id
                LEFT JOIN products p    ON p.id = oi.product_id
                WHERE o.id = ? AND (o.user_id = ? OR ?)
                GROUP BY o.id
            ");
            $stmt->bind_param('iii', $id, $user['id'], $isAdmin);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            if (!$order) json(['error' => 'Order not found'], 404);
            $order['items'] = json_decode($order['items'], true);
            json($order);
        }

        $isAdmin = $user['role'] === 'admin';
        $sql     = $isAdmin
            ? "SELECT o.*, u.name as customer_name, u.email
               FROM orders o JOIN users u ON u.id = o.user_id
               ORDER BY o.created_at DESC LIMIT 100"
            : "SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC";
        $stmt = $db->prepare($sql);
        if (!$isAdmin) $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        json($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    if ($method === 'POST') {
        $data = body();
        if (empty($data['items'])) json(['error' => 'No items in order'], 422);

        $subtotal = 0;
        foreach ($data['items'] as $item) $subtotal += $item['price'] * $item['qty'];
        $shipping      = $subtotal > 499 ? 0 : 49;
        $tax           = round($subtotal * 0.18);
        $total         = $subtotal + $shipping + $tax;
        $orderNum      = 'LX' . date('Ymd') . strtoupper(substr(uniqid(), -5));
        $orderStatus   = 'pending';
        $payMethod     = $data['payment_method']  ?? 'card';
        $shippingAddr  = json_encode($data['shipping_address'] ?? []);

        $stmt = $db->prepare("INSERT INTO orders (user_id, order_number, subtotal, shipping, tax, total, status, payment_method, shipping_address) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('isddddsss',
            $user['id'], $orderNum, $subtotal, $shipping, $tax,
            $total, $orderStatus, $payMethod, $shippingAddr
        );
        $stmt->execute();
        $orderId = $db->insert_id;

        foreach ($data['items'] as $item) {
            $iStmt = $db->prepare("INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?,?,?,?)");
            $iStmt->bind_param('iiid', $orderId, $item['product_id'], $item['qty'], $item['price']);
            $iStmt->execute();

            $sStmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $sStmt->bind_param('ii', $item['qty'], $item['product_id']);
            $sStmt->execute();
        }

        // Send order confirmation email to user
        $emailItems = array_map(fn($i) => [
            'name'  => $i['name']  ?? 'Product',
            'qty'   => $i['qty']   ?? 1,
            'price' => $i['price'] ?? 0,
        ], $data['items']);

        sendOrderConfirmationEmail(
            $user['id'],
            $orderNum,
            $total,
            $emailItems,
            $data['shipping_address'] ?? [],
            $payMethod
        );

        json([
            'id'           => $orderId,
            'order_number' => $orderNum,
            'total'        => $total,
            'message'      => 'Order placed successfully',
        ], 201);
    }

    if ($method === 'PUT' && $id) {
        adminAuth();
        $data = body();
        $stmt = $db->prepare("UPDATE orders SET status = ?, payment_status = ? WHERE id = ?");
        $stmt->bind_param('ssi', $data['status'], $data['payment_status'], $id);
        $stmt->execute();
        json(['message' => 'Order updated']);
    }
}

// ============================================================
// USERS / PROFILE
// ============================================================
function handleUsers($method, $id) {
    $user = auth();
    $db   = getDB();

    if ($method === 'PUT' && $id == $user['id']) {
        $data = body();
        $addr = json_encode($data['address'] ?? []);
        $stmt = $db->prepare("UPDATE users SET name = ?, phone = ?, address = ? WHERE id = ?");
        $stmt->bind_param('sssi', $data['name'], $data['phone'], $addr, $user['id']);
        $stmt->execute();
        json(['message' => 'Profile updated']);
    }

    if ($method === 'GET' && $id) {
        adminAuth();
        $stmt = $db->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        json($stmt->get_result()->fetch_assoc());
    }
}

// ============================================================
// REVIEWS
// ============================================================
function handleReviews($method, $id) {
    $db = getDB();

    if ($method === 'GET') {
        $productId = $_GET['product_id'] ?? null;
        if (!$productId) json(['error' => 'product_id required'], 422);
        $stmt = $db->prepare("
            SELECT r.*, u.name as user_name
            FROM reviews r
            JOIN users u ON u.id = r.user_id
            WHERE r.product_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        json($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    if ($method === 'POST') {
        $user = auth();
        $data = body();
        $stmt = $db->prepare("INSERT INTO reviews (user_id, product_id, rating, title, body) VALUES (?,?,?,?,?)");
        $stmt->bind_param('iiiss', $user['id'], $data['product_id'], $data['rating'], $data['title'], $data['body']);
        $stmt->execute();
        json(['id' => $db->insert_id, 'message' => 'Review submitted'], 201);
    }

    if ($method === 'DELETE' && $id) {
        adminAuth();
        $stmt = $db->prepare("DELETE FROM reviews WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        json(['message' => 'Review deleted']);
    }
}

// ============================================================
// COUPONS
// ============================================================
function handleCoupons($method, $id) {
    $db = getDB();

    if ($method === 'GET') {
        $code = $_GET['code'] ?? null;
        if ($code) {
            $stmt = $db->prepare("
                SELECT * FROM coupons
                WHERE code = ? AND status = 'active'
                  AND (expiry IS NULL OR expiry > NOW())
                  AND (max_uses IS NULL OR used_count < max_uses)
            ");
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $coupon = $stmt->get_result()->fetch_assoc();
            if (!$coupon) json(['error' => 'Invalid or expired coupon'], 404);
            json($coupon);
        }
        adminAuth();
        json($db->query("SELECT * FROM coupons ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC));
    }

    if ($method === 'POST') {
        adminAuth();
        $data = body();
        $stmt = $db->prepare("INSERT INTO coupons (code, type, value, min_order, max_uses, expiry) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('ssddis',
            $data['code'], $data['type'], $data['value'],
            $data['min_order'], $data['max_uses'], $data['expiry']
        );
        $stmt->execute();
        json(['id' => $db->insert_id, 'message' => 'Coupon created'], 201);
    }
}

// ============================================================
// ADMIN STATS
// ============================================================
function handleAdmin($method, $action) {
    adminAuth();
    $db = getDB();

    if ($action === 'stats') {
        json([
            'total_revenue'   => $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status != 'cancelled'")->fetch_row()[0],
            'total_orders'    => $db->query("SELECT COUNT(*) FROM orders")->fetch_row()[0],
            'total_customers' => $db->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetch_row()[0],
            'total_products'  => $db->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetch_row()[0],
            'pending_orders'  => $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetch_row()[0],
            'low_stock'       => $db->query("SELECT COUNT(*) FROM products WHERE stock < 5 AND status = 'active'")->fetch_row()[0],
            'revenue_chart'   => $db->query("
                SELECT DATE(created_at) as date, SUM(total) as revenue
                FROM orders
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND status != 'cancelled'
                GROUP BY DATE(created_at)
                ORDER BY date
            ")->fetch_all(MYSQLI_ASSOC),
            'top_products'    => $db->query("
                SELECT p.id, p.name, p.thumbnail,
                       SUM(oi.qty) as total_sold,
                       SUM(oi.qty * oi.price) as revenue
                FROM order_items oi
                JOIN products p ON p.id = oi.product_id
                GROUP BY p.id
                ORDER BY total_sold DESC
                LIMIT 5
            ")->fetch_all(MYSQLI_ASSOC),
        ]);
    }
}

// ============================================================
// CART
// ============================================================
function handleCart($method) {
    $user = auth();
    $db   = getDB();

    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT c.*, p.name, p.price, p.thumbnail
            FROM cart c
            JOIN products p ON p.id = c.product_id
            WHERE c.user_id = ?
        ");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        json($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    if ($method === 'POST') {
        $data = body();
        $stmt = $db->prepare("INSERT INTO cart (user_id, product_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty = qty + ?");
        $stmt->bind_param('iiii', $user['id'], $data['product_id'], $data['qty'], $data['qty']);
        $stmt->execute();
        json(['message' => 'Added to cart']);
    }

    if ($method === 'DELETE') {
        $data = body();
        $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param('ii', $user['id'], $data['product_id']);
        $stmt->execute();
        json(['message' => 'Removed from cart']);
    }
}

// ============================================================
// WISHLIST
// ============================================================
function handleWishlist($method) {
    $user = auth();
    $db   = getDB();

    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT w.*, p.name, p.price, p.original_price, p.thumbnail
            FROM wishlist w
            JOIN products p ON p.id = w.product_id
            WHERE w.user_id = ?
        ");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        json($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    if ($method === 'POST') {
        $data = body();
        $stmt = $db->prepare("INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (?,?)");
        $stmt->bind_param('ii', $user['id'], $data['product_id']);
        $stmt->execute();
        json(['message' => 'Added to wishlist']);
    }

    if ($method === 'DELETE') {
        $data = body();
        $stmt = $db->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param('ii', $user['id'], $data['product_id']);
        $stmt->execute();
        json(['message' => 'Removed from wishlist']);
    }
}

// ============================================================
// FILE UPLOAD
// ============================================================
function handleUpload() {
    adminAuth();
    if (empty($_FILES['image'])) json(['error' => 'No file uploaded'], 422);

    $file    = $_FILES['image'];
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowed)) json(['error' => 'Only JPG, PNG, WebP allowed'], 422);
    if ($file['size'] > 5 * 1024 * 1024)   json(['error' => 'Max file size is 5MB'], 422);

    $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = uniqid('img_') . '.' . $ext;
    $dest = __DIR__ . '/../uploads/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) json(['error' => 'Upload failed'], 500);
    json(['url' => '/uploads/' . $name, 'message' => 'File uploaded successfully']);
}