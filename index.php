<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/admin_permissions.php";

$config = [
    "db_host" => getenv("CATALOGUE_DB_HOST") ?: (getenv("DB_HOST") ?: "127.0.0.1"),
    "db_port" => (int)(getenv("CATALOGUE_DB_PORT") ?: (getenv("DB_PORT") ?: 3306)),
    "db_name" => getenv("CATALOGUE_DB_NAME") ?: (getenv("DB_NAME") ?: "catalogue"),
    "db_user" => getenv("CATALOGUE_DB_USER") ?: (getenv("DB_USER") ?: "catalogue_user"),
    "db_pass" => getenv("CATALOGUE_DB_PASS") ?: (getenv("DB_PASS") ?: ""),
];

function get_pdo(array $config): PDO
{
    $dsn = sprintf(
        "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
        $config["db_host"],
        $config["db_port"],
        $config["db_name"]
    );

    return new PDO(
        $dsn,
        $config["db_user"],
        $config["db_pass"],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function ensure_shared_admin_token(): string
{
    if (empty($_SESSION["admin_shared_token"])) {
        $_SESSION["admin_shared_token"] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION["admin_shared_token"];
}

if (isset($_GET["logout"])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: index.php");
    exit;
}

$error = "";
$info = "";

try {
    if ($config["db_user"] === "" || $config["db_pass"] === "") {
        throw new RuntimeException("Configuration DB incomplète (DB_USER/DB_PASS).");
    }
    $pdo = get_pdo($config);
    ensure_admin_user_schema($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erreur de connexion base de données: " . h($e->getMessage());
    exit;
}

$adminCount = (int)$pdo->query("SELECT COUNT(*) FROM catalogue_admin_users")->fetchColumn();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");
    $username = trim((string)($_POST["username"] ?? ""));
    $password = (string)($_POST["password"] ?? "");

    if ($action === "register_first" && $adminCount === 0) {
        if ($username === "" || strlen($username) < 3) {
            $error = "Nom d'utilisateur invalide (min 3 caractères).";
        } elseif (strlen($password) < 8) {
            $error = "Mot de passe invalide (min 8 caractères).";
        } else {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO catalogue_admin_users (username, password_hash)
                     VALUES (:username, :password_hash)"
                );
                $stmt->execute([
                    ":username" => $username,
                    ":password_hash" => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $adminCount = 1;
                $info = "Compte admin créé. Connecte-toi.";
            } catch (Throwable $e) {
                $error = "Création du compte impossible.";
            }
        }
    } elseif ($action === "login") {
        if ($username === "" || $password === "") {
            $error = "Identifiants requis.";
        } else {
            $stmt = $pdo->prepare(
                "SELECT " . admin_user_columns_sql() . "
                 FROM catalogue_admin_users
                 WHERE username = :username
                 LIMIT 1"
            );
            $stmt->execute([":username" => $username]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, (string)$user["password_hash"])) {
                $error = "Identifiants invalides.";
            } else {
                session_regenerate_id(true);
                store_admin_session_user($user);
                ensure_shared_admin_token();
                header("Location: deviseur.php");
                exit;
            }
        }
    } elseif ($action === "regenerate_token") {
        $isLoggedIn = !empty($_SESSION["catalogue_admin"]) && $_SESSION["catalogue_admin"] === true;
        if ($isLoggedIn) {
            $_SESSION["admin_shared_token"] = bin2hex(random_bytes(24));
            $info = "Token régénéré.";
        }
    }
}

$isLoggedIn = !empty($_SESSION["catalogue_admin"]) && $_SESSION["catalogue_admin"] === true;
$sharedToken = $isLoggedIn ? ensure_shared_admin_token() : "";
$currentPermissions = current_admin_permissions();
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Authentification admin</title>
    <style>
      :root {
        --ink: #101014;
        --muted: #5f6472;
        --line: #d6dae3;
        --panel: #fff;
        --accent: #1652f0;
        --bg: #eef2f9;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 24px;
        color: var(--ink);
        background: radial-gradient(circle at 10% 10%, #fff, var(--bg));
        font-family: "Avenir Next", "Segoe UI", sans-serif;
      }
      .card {
        width: min(520px, 100%);
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 24px;
        box-shadow: 0 18px 45px rgba(0, 0, 0, 0.08);
      }
      h1 { margin: 0 0 8px; font-size: 28px; }
      p { margin: 0 0 18px; color: var(--muted); }
      form { display: grid; gap: 12px; }
      label { display: grid; gap: 6px; font-size: 14px; }
      input {
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 15px;
      }
      button, .link {
        border: 0;
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
      }
      button { background: var(--accent); color: #fff; }
      .link { background: #f2f5ff; color: #1f3d9a; border: 1px solid #cbd8ff; }
      .error { color: #b10f2e; margin-bottom: 10px; font-size: 14px; }
      .info { color: #0d6a2c; margin-bottom: 10px; font-size: 14px; }
      .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
    </style>
  </head>
  <body>
    <main class="card">
      <h1>Authentification admin</h1>
      <?php if ($isLoggedIn): ?>
        <p>Connecté en tant que <strong><?= h((string)$_SESSION["catalogue_admin_username"]) ?></strong>.</p>
        <label>
          Token admin partagé (catalogue + deviseur)
          <input type="text" readonly value="<?= h($sharedToken) ?>" />
        </label>
        <div class="actions">
          <a class="link" href="catalogue.php">Ouvrir le catalogue</a>
          <a class="link" href="deviseur.php">Ouvrir le deviseur</a>
          <?php if (!empty($currentPermissions["administration"])): ?>
            <a class="link" href="administration.php">Administration</a>
          <?php endif; ?>
          <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="regenerate_token" />
            <button type="submit">Regénérer le token</button>
          </form>
          <a class="link" href="index.php?logout=1">Se déconnecter</a>
        </div>
      <?php else: ?>
        <p>Connecte-toi pour gérer le catalogue.</p>
        <?php if ($error !== ""): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <?php if ($info !== ""): ?><div class="info"><?= h($info) ?></div><?php endif; ?>

        <?php if ($adminCount === 0): ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="register_first" />
            <label>
              Créer le premier admin (identifiant)
              <input type="text" name="username" required minlength="3" />
            </label>
            <label>
              Mot de passe admin
              <input type="password" name="password" required minlength="8" />
            </label>
            <button type="submit">Créer le compte admin</button>
          </form>
        <?php else: ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="login" />
            <label>
              Identifiant
              <input type="text" name="username" required />
            </label>
            <label>
              Mot de passe
              <input type="password" name="password" required />
            </label>
            <button type="submit">Se connecter</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </main>
  </body>
</html>
