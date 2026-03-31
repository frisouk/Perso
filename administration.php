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

function posted_permission_values(): array
{
    $posted = $_POST["permissions"] ?? [];
    $values = [];
    foreach (admin_permission_columns() as $column => $meta) {
        $values[$column] = is_array($posted) && isset($posted[$column]) ? 1 : 0;
    }
    return $values;
}

function validate_manager_guard(PDO $pdo, ?array $targetUser, array $nextPermissions): ?string
{
    $managerCount = count_manage_users($pdo);
    $targetIsManager = !empty($targetUser["can_manage_users"]);
    $nextIsManager = !empty($nextPermissions["can_manage_users"]);

    if ($targetUser && $targetIsManager && !$nextIsManager && $managerCount <= 1) {
        return "Au moins un utilisateur doit conserver le droit Administration.";
    }

    return null;
}

try {
    if ($config["db_user"] === "" || $config["db_pass"] === "") {
        throw new RuntimeException("Configuration DB incomplète.");
    }
    $pdo = get_pdo($config);
    ensure_admin_user_schema($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erreur de connexion base de données: " . h($e->getMessage());
    exit;
}

$currentAdminId = (int)($_SESSION["catalogue_admin_id"] ?? $_SESSION["admin_user_id"] ?? 0);
if ($currentAdminId > 0) {
    $currentUser = load_admin_user_by_id($pdo, $currentAdminId);
    if (is_array($currentUser)) {
        store_admin_session_user($currentUser);
    }
}

require_manage_users_permission();

$error = "";
$info = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");
    $username = trim((string)($_POST["username"] ?? ""));
    $password = (string)($_POST["password"] ?? "");
    $permissions = posted_permission_values();

    if ($action === "create_user") {
        if ($username === "" || strlen($username) < 3) {
            $error = "Nom d'utilisateur invalide (min 3 caractères).";
        } elseif (strlen($password) < 8) {
            $error = "Mot de passe invalide (min 8 caractères).";
        } elseif (empty($permissions["can_manage_users"]) && count_manage_users($pdo) === 0) {
            $error = "Le premier utilisateur doit conserver le droit Administration.";
        } else {
            try {
                $sql = "INSERT INTO catalogue_admin_users (
                    username,
                    password_hash,
                    can_manage_users,
                    can_view_perimetre,
                    can_view_rvtools,
                    can_view_catalogue,
                    can_view_besoin,
                    can_view_devis
                ) VALUES (
                    :username,
                    :password_hash,
                    :can_manage_users,
                    :can_view_perimetre,
                    :can_view_rvtools,
                    :can_view_catalogue,
                    :can_view_besoin,
                    :can_view_devis
                )";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ":username" => $username,
                    ":password_hash" => password_hash($password, PASSWORD_DEFAULT),
                    ":can_manage_users" => $permissions["can_manage_users"],
                    ":can_view_perimetre" => $permissions["can_view_perimetre"],
                    ":can_view_rvtools" => $permissions["can_view_rvtools"],
                    ":can_view_catalogue" => $permissions["can_view_catalogue"],
                    ":can_view_besoin" => $permissions["can_view_besoin"],
                    ":can_view_devis" => $permissions["can_view_devis"],
                ]);
                $info = "Utilisateur créé.";
            } catch (Throwable $e) {
                $error = "Création impossible. Vérifie que le nom d'utilisateur est unique.";
            }
        }
    } elseif ($action === "update_user") {
        $userId = (int)($_POST["user_id"] ?? 0);
        $targetUser = $userId > 0 ? load_admin_user_by_id($pdo, $userId) : null;
        if (!$targetUser) {
            $error = "Utilisateur introuvable.";
        } elseif ($username === "" || strlen($username) < 3) {
            $error = "Nom d'utilisateur invalide (min 3 caractères).";
        } else {
            $guardError = validate_manager_guard($pdo, $targetUser, $permissions);
            if ($guardError !== null) {
                $error = $guardError;
            } else {
                try {
                    $fields = [
                        "username = :username",
                        "can_manage_users = :can_manage_users",
                        "can_view_perimetre = :can_view_perimetre",
                        "can_view_rvtools = :can_view_rvtools",
                        "can_view_catalogue = :can_view_catalogue",
                        "can_view_besoin = :can_view_besoin",
                        "can_view_devis = :can_view_devis",
                    ];
                    $params = [
                        ":id" => $userId,
                        ":username" => $username,
                        ":can_manage_users" => $permissions["can_manage_users"],
                        ":can_view_perimetre" => $permissions["can_view_perimetre"],
                        ":can_view_rvtools" => $permissions["can_view_rvtools"],
                        ":can_view_catalogue" => $permissions["can_view_catalogue"],
                        ":can_view_besoin" => $permissions["can_view_besoin"],
                        ":can_view_devis" => $permissions["can_view_devis"],
                    ];
                    if ($password !== "") {
                        if (strlen($password) < 8) {
                            throw new RuntimeException("Mot de passe invalide (min 8 caractères).");
                        }
                        $fields[] = "password_hash = :password_hash";
                        $params[":password_hash"] = password_hash($password, PASSWORD_DEFAULT);
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE catalogue_admin_users
                         SET " . implode(", ", $fields) . "
                         WHERE id = :id"
                    );
                    $stmt->execute($params);

                    if ($currentAdminId === $userId) {
                        $updatedUser = load_admin_user_by_id($pdo, $userId);
                        if ($updatedUser) {
                            store_admin_session_user($updatedUser);
                        }
                    }

                    $info = "Utilisateur mis à jour.";
                } catch (Throwable $e) {
                    $error = $e->getMessage() !== ""
                        ? $e->getMessage()
                        : "Mise à jour impossible.";
                }
            }
        }
    } elseif ($action === "delete_user") {
        $userId = (int)($_POST["user_id"] ?? 0);
        $targetUser = $userId > 0 ? load_admin_user_by_id($pdo, $userId) : null;
        if (!$targetUser) {
            $error = "Utilisateur introuvable.";
        } elseif ($userId === $currentAdminId) {
            $error = "Impossible de supprimer l'utilisateur connecté.";
        } elseif (!empty($targetUser["can_manage_users"]) && count_manage_users($pdo) <= 1) {
            $error = "Impossible de supprimer le dernier utilisateur disposant du droit Administration.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM catalogue_admin_users WHERE id = :id");
            $stmt->execute([":id" => $userId]);
            $info = "Utilisateur supprimé.";
        }
    }
}

$users = $pdo->query(
    "SELECT " . admin_user_columns_sql() . "
     FROM catalogue_admin_users
     ORDER BY username ASC"
)->fetchAll();
$permissionColumns = admin_permission_columns();
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Administration</title>
    <style>
      :root {
        --ink: #151515;
        --muted: #5b5b5b;
        --line: #d8d8d8;
        --panel: #ffffff;
        --accent: #c1121f;
        --bg: #f2f2f2;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        color: var(--ink);
        background: linear-gradient(180deg, #f7f7f7 0%, #f2f2f2 45%, #ededed 100%);
        font-family: "Avenir Next", "Gill Sans", "Trebuchet MS", sans-serif;
      }
      .page {
        max-width: 1280px;
        margin: 0 auto;
        padding: 24px 20px 40px;
        display: grid;
        gap: 24px;
      }
      .topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
      }
      .links {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
      }
      .link, button {
        border: 1px solid transparent;
        border-radius: 10px;
        padding: 10px 14px;
        font: inherit;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
      }
      .link {
        background: #fff;
        color: var(--ink);
        border-color: var(--line);
      }
      button {
        background: var(--accent);
        color: #fff;
      }
      .secondary {
        background: transparent;
        color: var(--ink);
        border-color: var(--line);
      }
      .panel {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 24px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
      }
      h1, h2 {
        margin: 0 0 14px;
      }
      p {
        margin: 0;
        color: var(--muted);
      }
      .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 14px;
        align-items: start;
      }
      label {
        display: grid;
        gap: 6px;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--muted);
      }
      input[type="text"],
      input[type="password"] {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: 8px;
        font: inherit;
        background: #fff;
      }
      .permission-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 10px;
        margin-top: 16px;
      }
      .permission-box {
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 10px 12px;
        background: #fafafa;
      }
      .permission-box span {
        font-size: 13px;
        color: var(--ink);
      }
      .actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 18px;
      }
      .user-list {
        display: grid;
        gap: 16px;
      }
      .user-card {
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 18px;
        background: #fff;
      }
      .user-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 14px;
      }
      .badge {
        border: 1px solid #f0c6ca;
        color: var(--accent);
        background: #fff6f7;
        border-radius: 999px;
        padding: 4px 10px;
        font-size: 12px;
        font-weight: 700;
      }
      .message {
        padding: 12px 14px;
        border-radius: 10px;
        font-size: 14px;
      }
      .message.error {
        background: #fff0f2;
        color: #9f1239;
        border: 1px solid #fecdd3;
      }
      .message.info {
        background: #effbf3;
        color: #166534;
        border: 1px solid #bbf7d0;
      }
      @media (max-width: 720px) {
        .user-head {
          align-items: flex-start;
          flex-direction: column;
        }
      }
    </style>
  </head>
  <body>
    <main class="page">
      <section class="panel">
        <div class="topbar">
          <div>
            <h1>Administration</h1>
            <p>Gère les utilisateurs autorisés et les tuiles visibles dans la navigation du deviseur.</p>
          </div>
          <div class="links">
            <a class="link" href="deviseur.php">Retour au deviseur</a>
            <a class="link" href="index.php">Accueil</a>
          </div>
        </div>
      </section>

      <?php if ($error !== ""): ?>
        <div class="message error"><?= h($error) ?></div>
      <?php endif; ?>
      <?php if ($info !== ""): ?>
        <div class="message info"><?= h($info) ?></div>
      <?php endif; ?>

      <section class="panel">
        <h2>Créer un utilisateur</h2>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="create_user" />
          <div class="form-grid">
            <label>
              Identifiant
              <input type="text" name="username" required minlength="3" />
            </label>
            <label>
              Mot de passe
              <input type="password" name="password" required minlength="8" />
            </label>
          </div>
          <div class="permission-grid">
            <?php foreach ($permissionColumns as $column => $meta): ?>
              <label class="permission-box">
                <input type="checkbox" name="permissions[<?= h($column) ?>]" <?= !empty($meta["default"]) ? "checked" : "" ?> />
                <span><?= h((string)$meta["label"]) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="actions">
            <button type="submit">Créer l'utilisateur</button>
          </div>
        </form>
      </section>

      <section class="panel">
        <h2>Utilisateurs autorisés</h2>
        <div class="user-list">
          <?php foreach ($users as $user): ?>
            <article class="user-card">
              <div class="user-head">
                <div>
                  <strong><?= h((string)$user["username"]) ?></strong>
                  <?php if ((int)$user["id"] === $currentAdminId): ?>
                    <span class="badge">Connecté</span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($user["can_manage_users"])): ?>
                  <span class="badge">Administration</span>
                <?php endif; ?>
              </div>

              <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="update_user" />
                <input type="hidden" name="user_id" value="<?= (int)$user["id"] ?>" />
                <div class="form-grid">
                  <label>
                    Identifiant
                    <input type="text" name="username" required minlength="3" value="<?= h((string)$user["username"]) ?>" />
                  </label>
                  <label>
                    Nouveau mot de passe
                    <input type="password" name="password" minlength="8" placeholder="Laisser vide pour conserver" />
                  </label>
                </div>
                <div class="permission-grid">
                  <?php foreach ($permissionColumns as $column => $meta): ?>
                    <label class="permission-box">
                      <input type="checkbox" name="permissions[<?= h($column) ?>]" <?= !empty($user[$column]) ? "checked" : "" ?> />
                      <span><?= h((string)$meta["label"]) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="actions">
                  <button type="submit">Enregistrer</button>
                </div>
              </form>

              <form method="post" class="actions" onsubmit="return confirm('Supprimer cet utilisateur ?');">
                <input type="hidden" name="action" value="delete_user" />
                <input type="hidden" name="user_id" value="<?= (int)$user["id"] ?>" />
                <button type="submit" class="secondary">Supprimer</button>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </main>
  </body>
</html>
