<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/admin_permissions.php";

$config = [
    "db_host" => getenv("DB_HOST") ?: "127.0.0.1",
    "db_port" => (int) (getenv("DB_PORT") ?: 3306),
    "db_name" => getenv("DB_NAME") ?: "catalogue",
    "db_user" => getenv("DB_USER") ?: "catalogue_user",
    "db_pass" => getenv("DB_PASS") ?: "",
    "admin_token" => getenv("CATALOGUE_ADMIN_TOKEN") ?: "",
    "debug" => filter_var(getenv("APP_DEBUG"), FILTER_VALIDATE_BOOL) ?: false,
];

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_pdo(array $config): PDO
{
    if ($config["db_pass"] === "") {
        throw new RuntimeException("Missing DB_PASS environment variable");
    }

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

function is_admin_session_authenticated(): bool
{
    $legacy = !empty($_SESSION["catalogue_admin"]) && $_SESSION["catalogue_admin"] === true;
    $shared = !empty($_SESSION["admin_authenticated"]) && $_SESSION["admin_authenticated"] === true;
    return $legacy || $shared;
}

function is_valid_admin_token(array $config, string $provided): bool
{
    $provided = trim($provided);
    if ($provided === "") return false;

    $expectedTokens = [];
    $envToken = trim((string)($config["admin_token"] ?? ""));
    if ($envToken !== "") {
        $expectedTokens[] = $envToken;
    }
    $sessionToken = trim((string)($_SESSION["admin_shared_token"] ?? ""));
    if ($sessionToken !== "") {
        $expectedTokens[] = $sessionToken;
    }

    foreach ($expectedTokens as $expected) {
        if (hash_equals($expected, $provided)) {
            return true;
        }
    }
    return false;
}

function require_admin_access(array $config, bool $jsonMode = false): void
{
    if (is_admin_session_authenticated()) {
        return;
    }

    $provided = "";
    if (isset($_SERVER["HTTP_X_ADMIN_TOKEN"])) {
        $provided = (string)$_SERVER["HTTP_X_ADMIN_TOKEN"];
    } elseif (isset($_GET["admin_token"])) {
        $provided = (string)$_GET["admin_token"];
    } elseif (isset($_POST["admin_token"])) {
        $provided = (string)$_POST["admin_token"];
    }

    if (is_valid_admin_token($config, $provided)) {
        return;
    }

    if ($jsonMode) {
        json_response(["error" => "Unauthorized"], 401);
    }
    header("Location: index.php");
    exit;
}

function ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Unable to create directory: " . $path);
    }
}

function slugify_filename(string $value): string
{
    $value = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? 'rvtools';
    $value = trim($value, '-.');
    return $value !== '' ? $value : 'rvtools';
}

require_admin_access($config, isset($_GET["format"]) && $_GET["format"] === "json");

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["action"])
    && $_POST["action"] === "import_rvtools"
) {
    header("Content-Type: application/json; charset=utf-8");

    try {
        if (
            !isset($_FILES["rvtools_file"])
            || !is_array($_FILES["rvtools_file"])
            || (int) ($_FILES["rvtools_file"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            json_response(["error" => "Fichier RVTools manquant ou upload invalide."], 400);
        }

        $uploadedName = (string) ($_FILES["rvtools_file"]["name"] ?? "rvtools.xlsx");
        $extension = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
        if ($extension !== "xlsx") {
            json_response(["error" => "Le fichier doit etre un export RVTools au format .xlsx."], 400);
        }

        $baseTemp = rtrim(sys_get_temp_dir() ?: "/tmp", DIRECTORY_SEPARATOR);
        $baseDir = $baseTemp . "/deviseur_rvtools";
        $uploadDir = $baseDir . "/uploads";
        $dashboardDir = $baseDir . "/dashboards";
        ensure_directory($uploadDir);
        ensure_directory($dashboardDir);

        $stamp = date("Ymd_His");
        $safeBaseName = slugify_filename(pathinfo($uploadedName, PATHINFO_FILENAME));
        $storedXlsx = $uploadDir . "/" . $stamp . "_" . $safeBaseName . ".xlsx";
        $storedHtml = $dashboardDir . "/" . $stamp . "_" . $safeBaseName . ".html";
        $storedJson = $dashboardDir . "/" . $stamp . "_" . $safeBaseName . ".json";

        if (!move_uploaded_file((string) $_FILES["rvtools_file"]["tmp_name"], $storedXlsx)) {
            throw new RuntimeException("Impossible de deplacer le fichier importe.");
        }

        $scriptPath = __DIR__ . "/generate_rvtools_dashboard.py";
        if (!is_file($scriptPath)) {
            throw new RuntimeException("Generateur RVTools introuvable.");
        }

        $command = sprintf(
            'python3 %s %s -o %s --summary-output %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($storedXlsx),
            escapeshellarg($storedHtml),
            escapeshellarg($storedJson)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException(implode("\n", $output) ?: "Execution du generateur RVTools echouee.");
        }

        $dashboardHtml = @file_get_contents($storedHtml);
        if ($dashboardHtml === false) {
            throw new RuntimeException("Dashboard HTML genere mais illisible.");
        }

        $summary = [];
        if (is_file($storedJson)) {
            $decoded = json_decode((string) file_get_contents($storedJson), true);
            if (is_array($decoded)) {
                $summary = $decoded;
            }
        }

        json_response([
            "success" => true,
            "dashboard_html" => $dashboardHtml,
            "summary" => $summary,
            "file_name" => $uploadedName,
        ]);
    } catch (Throwable $e) {
        json_response([
            "error" => "Import RVTools impossible.",
            "detail" => $e->getMessage(),
        ], 500);
    }
}

try {
    $pdo = get_pdo($config);
    ensure_admin_user_schema($pdo);
} catch (Throwable $e) {
    if (isset($_GET["format"]) && $_GET["format"] === "json") {
        json_response([
            "error" => "Database connection failed",
            "detail" => $config["debug"] ? $e->getMessage() : null,
        ], 500);
    }
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}

$currentPermissions = current_admin_permissions();
$currentAdminId = (int)($_SESSION["catalogue_admin_id"] ?? $_SESSION["admin_user_id"] ?? 0);
if ($currentAdminId > 0) {
    $currentUser = load_admin_user_by_id($pdo, $currentAdminId);
    if (is_array($currentUser)) {
        store_admin_session_user($currentUser);
        $currentPermissions = current_admin_permissions();
    }
}

$allowedViews = [];
foreach (["perimetre", "rvtools", "catalogue", "besoin", "devis"] as $viewName) {
    if (!empty($currentPermissions[$viewName])) {
        $allowedViews[] = $viewName;
    }
}
$defaultView = "";

if (isset($_GET["format"]) && $_GET["format"] === "json") {
    $stmt = $pdo->query(
        "SELECT id, type, category, name, detail, price
         FROM catalogue_items
         WHERE is_active = 1
         ORDER BY type, category, name, id"
    );
    $rows = $stmt->fetchAll();
    json_response(["data" => $rows]);
}

header("Content-Type: text/html; charset=utf-8");
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Deviseur - Template</title>
    <style>
      :root {
        --ink: #151515;
        --muted: #5b5b5b;
        --line: #d8d8d8;
        --panel: #ffffff;
        --accent: #c1121f;
        --bg: #f2f2f2;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        font-family: "Avenir Next", "Gill Sans", "Trebuchet MS", sans-serif;
        color: var(--ink);
        background:
          linear-gradient(180deg, #f7f7f7 0%, #f2f2f2 45%, #ededed 100%);
        min-height: 100vh;
      }

      .app-shell {
        display: flex;
        min-height: 100vh;
      }

      .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        width: 240px;
        padding: 28px 18px;
        border-right: 1px solid var(--line);
        background: #f8f8f8;
        display: flex;
        flex-direction: column;
        gap: 18px;
      }

      .sidebar-title {
        margin: 0;
        font-size: 12px;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--accent);
      }

      .sidebar-nav {
        display: grid;
        gap: 10px;
      }

      .sidebar-link {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: #fff;
        color: var(--ink);
        font: inherit;
        font-weight: 600;
        text-align: left;
        cursor: pointer;
        text-decoration: none;
        display: block;
      }

      .sidebar-link.is-active {
        border-color: var(--accent);
        background: #fff7f7;
        color: var(--accent);
      }

      .sidebar-admin {
        margin-top: auto;
      }

      .sidebar-logout {
        color: var(--accent);
      }

      .permission-hidden {
        display: none !important;
      }

      .content-shell {
        flex: 1;
        margin-left: 240px;
        padding: 24px 20px 40px;
      }

      header {
        max-width: 1360px;
        margin: 0 auto 32px;
        display: grid;
        gap: 12px;
        border-bottom: 1px solid var(--line);
        padding-bottom: 18px;
      }

      .header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
      }

      .header-actions .btn {
        text-decoration: none;
      }

      header h1 {
        font-size: clamp(34px, 5vw, 54px);
        margin: 0;
      }

      header p {
        margin: 0;
        font-size: 18px;
        line-height: 1.6;
        max-width: 720px;
        color: var(--muted);
      }

      .layout {
        max-width: 1360px;
        margin: 0 auto;
        display: grid;
        gap: 24px;
      }

      .view-panel {
        display: none;
        gap: 24px;
      }

      .view-panel.is-active {
        display: grid;
      }

      section {
        background: var(--panel);
        border-radius: 14px;
        padding: 24px;
        border: 1px solid var(--line);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
      }

      section h2 {
        margin: 0 0 16px;
        font-size: 22px;
      }

      .form-grid {
        display: grid;
        gap: 14px;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      }

      .perimeter-grid {
        grid-template-columns: repeat(5, minmax(0, 1fr));
        align-items: start;
      }

      .field-wide {
        grid-column: 1 / -1;
      }

      label {
        display: grid;
        gap: 6px;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--muted);
      }

      select,
      input {
        font-family: inherit;
        padding: 10px 12px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: #fff;
        font-size: 15px;
        width: 100%;
      }

      .field-stack {
        display: grid;
        gap: 12px;
      }

      .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 16px;
      }

      .btn {
        padding: 10px 16px;
        border-radius: 8px;
        border: 1px solid transparent;
        background: var(--accent);
        color: #fff;
        font-weight: 600;
        cursor: pointer;
      }

      .btn.secondary {
        background: transparent;
        border-color: var(--line);
        color: var(--ink);
      }

      .options {
        display: grid;
        gap: 12px;
      }

      .service-list {
        display: grid;
        gap: 12px;
      }

      .service-block {
        display: grid;
        gap: 12px;
      }

      .service-item {
        display: flex;
        align-items: center;
        gap: 10px;
        justify-content: space-between;
        padding: 4px 0 10px;
        border-bottom: 2px solid #a3a3a3;
        font-weight: 600;
      }

      .service-item-left {
        display: inline-flex;
        align-items: center;
        gap: 10px;
      }

      .service-title-actions {
        display: inline-flex;
        align-items: center;
        gap: 6px;
      }

      .service-item-title {
        padding-top: 2px;
        font-weight: 700;
      }

      .service-title-stack {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
      }

      .service-item-detail {
        font-size: 12px;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }

      .service-item-button {
        width: 22px;
        height: 22px;
        border-radius: 6px;
        border: 1px solid var(--line);
        background: #ffffff;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='%23151515'><path d='M8 5l10 7-10 7V5z'/></svg>");
        background-repeat: no-repeat;
        background-position: center;
        cursor: pointer;
      }

      .service-item-button.is-collapsed {
        transform: rotate(-90deg);
      }

      .row-actions {
        display: inline-flex;
        align-items: center;
        gap: 6px;
      }

      .service-total {
        font-weight: 600;
        color: var(--ink);
      }

      .edit-row {
        width: 26px;
        height: 26px;
        border-radius: 6px;
        border: 1px solid var(--line);
        background-color: transparent;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23151515' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round' opacity='0.8'><path d='M12 20h9'/><path d='M16.5 3.5l4 4L7 21H3v-4L16.5 3.5z'/></svg>");
        background-repeat: no-repeat;
        background-position: center;
        cursor: pointer;
      }

      .delete-row {
        width: 26px;
        height: 26px;
        border-radius: 6px;
        border: 1px solid var(--line);
        background-color: transparent;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23c1121f' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round' opacity='0.8'><path d='M3 6h18'/><path d='M8 6V4h8v2'/><path d='M19 6l-1 14H6L5 6'/><path d='M10 11v6'/><path d='M14 11v6'/></svg>");
        background-repeat: no-repeat;
        background-position: center;
        cursor: pointer;
      }

      .service-template {
        padding-left: 28px;
        padding-top: 12px;
        transition: max-height 220ms ease, opacity 220ms ease, transform 220ms ease;
        max-height: 520px;
        opacity: 1;
        transform: translateY(0);
        overflow: hidden;
      }

      .service-template.is-collapsed {
        max-height: 0;
        opacity: 0;
        transform: translateY(-8px);
        padding-top: 0;
      }

      .service-template.sizing-template-root {
        max-height: 2200px;
      }

      .template-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--accent);
        margin-bottom: 8px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
      }

      .template-icon {
        width: 16px;
        height: 16px;
        display: inline-block;
        background-repeat: no-repeat;
        background-position: center;
        background-size: contain;
      }

      .template-icon.eye {
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23c1121f' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'><path d='M2 12s4-6 10-6 10 6 10 6-4 6-10 6-10-6-10-6z'/><circle cx='12' cy='12' r='3'/></svg>");
      }

      .template-icon.eye.closed {
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23c1121f' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'><path d='M17.94 17.94A10.94 10.94 0 0 1 12 20c-6 0-10-6-10-6a21.6 21.6 0 0 1 5.06-5.94'/><path d='M1 1l22 22'/><path d='M9.9 4.24A10.94 10.94 0 0 1 12 4c6 0 10 6 10 6a21.6 21.6 0 0 1-5.06 5.94'/><path d='M14.12 14.12a3 3 0 0 1-4.24-4.24'/></svg>");
      }

      .template-icon.servers {
        width: 72px;
        height: 72px;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='72' height='72' viewBox='0 0 24 24' fill='none' stroke='%23c1121f' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='4' width='18' height='4' rx='1'/><rect x='3' y='10' width='18' height='4' rx='1'/><rect x='3' y='16' width='18' height='4' rx='1'/><circle cx='7' cy='6' r='0.6'/><circle cx='7' cy='12' r='0.6'/><circle cx='7' cy='18' r='0.6'/></svg>");
      }

      .template-icon.cpu {
        width: 72px;
        height: 72px;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='72' height='72' viewBox='0 0 24 24' fill='none' stroke='%23c1121f' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'><rect x='7' y='7' width='10' height='10' rx='1'/><rect x='9' y='9' width='6' height='6' rx='0.8'/><path d='M9 2v3'/><path d='M15 2v3'/><path d='M9 19v3'/><path d='M15 19v3'/><path d='M2 9h3'/><path d='M2 15h3'/><path d='M19 9h3'/><path d='M19 15h3'/></svg>");
      }

      .sizing-result {
        font-weight: 600;
        font-size: 16px;
        text-align: center;
      }

      .sizing-title {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 32px;
        text-align: center;
        font-weight: 600;
        font-size: 12px;
        color: var(--accent);
      }

      .sizing-toggle {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border: 0;
        background: transparent;
        padding: 0;
        cursor: pointer;
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 10px 12px;
        min-height: 88px;
        transition: border-color 160ms ease, box-shadow 160ms ease;
      }

      .sizing-toggle:hover {
        border-color: var(--line);
        box-shadow: none;
      }

      .sizing-toggle.is-active {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(193, 18, 31, 0.18);
      }

      .sizing-label {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
      }

      .sizing-subtitle {
        display: inline-block;
        font-size: 10px;
        font-weight: 600;
        color: var(--accent);
      }

      .sizing-input {
        margin-top: 4px;
        width: 120px;
        text-align: center;
        font-weight: 600;
      }

      .sizing-wrap {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 24px;
        align-items: start;
      }

      .sizing-group {
        display: grid;
        gap: 10px;
        justify-items: center;
        align-content: start;
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 12px;
        background: #fcfcfc;
      }

      .sizing-group.is-wide {
        grid-column: auto;
        position: relative;
        border-left: 4px solid var(--accent);
        background: #fff9f9;
      }

      .sizing-group-title {
        font-family: inherit;
        font-size: 12px;
        font-weight: 400;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--muted);
        text-align: center;
        width: 100%;
        padding-bottom: 8px;
        border-bottom: 1px solid var(--line);
      }

      .sizing-divider {
        position: relative;
        width: 100%;
        height: 1px;
        background: var(--line);
        margin: 10px 0 6px;
      }

      .sizing-divider button {
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        background: #fff;
        border: 1px solid var(--line);
        color: var(--ink);
        font-size: 11px;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 999px;
        cursor: pointer;
        transition: border-color 160ms ease, box-shadow 160ms ease;
      }

      .sizing-divider button:hover {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(193, 18, 31, 0.12);
      }

      .sizing-button-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 24px;
        align-items: start;
        justify-items: center;
      }

      .sizing-details-row.is-hidden {
        display: none;
      }

      .sizing-details-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 24px;
        width: 100%;
      }

      .sizing-details-panel {
        display: grid;
        gap: 12px;
        align-content: start;
      }

      .sizing-details-panel-title {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--ink);
        text-align: left;
      }

      .sizing-details {
        display: grid;
        gap: 10px;
        justify-items: center;
      }

      .sizing-metrics {
        display: grid;
        gap: 6px;
        justify-items: center;
      }

      .sizing-actions {
        grid-column: 1 / -1;
        align-self: start;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        margin-top: 4px;
        gap: 10px;
      }

      .sizing-info {
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
      }

      .sizing-block {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
      }

      .sizing-memo {
        font-size: 12px;
        color: var(--ink);
        text-align: center;
      }

      .sizing-buttons {
        display: inline-flex;
        gap: 8px;
        margin-top: 0;
        justify-content: center;
        flex-wrap: wrap;
      }

      .sizing-buttons-row {
        display: flex;
        width: 100%;
        gap: 8px;
        justify-content: center;
      }

      .sizing-buttons .btn.is-active {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(193, 18, 31, 0.18);
      }

      .sizing-actions .btn.is-active {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(193, 18, 31, 0.18);
      }

      .sizing-commitments {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(3, max-content);
        gap: 8px 10px;
        justify-content: center;
      }

      .sizing-commitments .btn.is-active {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(193, 18, 31, 0.18);
      }

      .sizing-mutual-pools {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(2, max-content);
        gap: 8px 10px;
        justify-content: center;
        align-items: start;
      }

      .sizing-commitments-create {
        margin-top: 8px;
      }

      .sizing-rounded {
        margin-top: 6px;
        font-size: 12px;
        color: var(--ink);
        text-align: center;
      }

      .sizing-chart {
        width: 110px;
        height: 110px;
        border-radius: 50%;
        background: conic-gradient(#1e88e5 0deg, #1e88e5 0deg, #7cb342 0deg);
        position: relative;
        border: 1px solid var(--line);
      }

      .sizing-chart::after {
        content: "";
        position: absolute;
        inset: 16px;
        background: #fff;
        border-radius: 50%;
      }

      .sizing-legend {
        display: grid;
        gap: 6px;
        font-size: 11px;
        color: var(--muted);
        text-align: center;
      }

      .legend-row {
        display: flex;
        align-items: center;
        gap: 6px;
        justify-content: center;
      }

      .legend-swatch {
        width: 10px;
        height: 10px;
        border-radius: 2px;
        display: inline-block;
      }

      .template-title button {
        border: 0;
        background: transparent;
        padding: 0;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
      }

      .template-content {
        transition: max-height 220ms ease, opacity 220ms ease, transform 220ms ease;
        max-height: 1200px;
        opacity: 1;
        transform: translateY(0);
        overflow: hidden;
      }

      .template-content.is-hidden {
        max-height: 0;
        opacity: 0;
        transform: translateY(-8px);
      }

      .service-template.sizing-template-root .template-content {
        max-height: 2000px;
      }

      .template-table th,
      .template-table td {
        padding: 4px 4px;
        font-size: 12px;
        line-height: 1.2;
      }

      .template-table input[type="number"] {
        width: 56px;
        padding: 6px 6px;
        font-size: 12px;
        line-height: 1.2;
        text-align: right;
      }

      .template-table th:first-child,
      .template-table td:first-child {
        width: 48%;
      }

      .template-table th:nth-child(2),
      .template-table td:nth-child(2) {
        width: 16%;
      }

      .template-table th:nth-child(3),
      .template-table td:nth-child(3),
      .template-table th:nth-child(4),
      .template-table td:nth-child(4) {
        width: 18%;
      }

      .template-table th:last-child {
        white-space: nowrap;
      }

      .template-table th {
        white-space: nowrap;
      }

      .option-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: #fff;
      }

      .option-item span {
        font-size: 14px;
      }

      table {
        width: 100%;
        border-collapse: collapse;
      }

      th,
      td {
        text-align: left;
        padding: 10px 8px;
        border-bottom: 1px solid var(--line);
        font-size: 14px;
      }

      th {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--muted);
      }

      .total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 18px;
        padding: 12px 16px;
        border-radius: 10px;
        border: 1px solid var(--line);
        background: #fafafa;
        font-weight: 700;
      }

      .cost-distribution {
        display: grid;
        grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
        gap: 24px;
        align-items: center;
      }

      .cost-distribution-chart-wrap {
        display: grid;
        gap: 12px;
        justify-items: center;
      }

      .cost-distribution-chart {
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: conic-gradient(#d9e1ec 0deg 360deg);
        position: relative;
        box-shadow: inset 0 0 0 1px rgba(20, 33, 61, 0.05);
      }

      .cost-distribution-chart::after {
        content: "";
        position: absolute;
        inset: 42px;
        border-radius: 50%;
        background: #fff;
        box-shadow: 0 0 0 1px var(--line);
      }

      .cost-distribution-total {
        position: absolute;
        inset: 0;
        display: grid;
        place-items: center;
        z-index: 1;
        text-align: center;
        font-weight: 700;
        color: var(--ink);
      }

      .cost-distribution-total span {
        display: block;
        font-size: 12px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--muted);
        margin-bottom: 6px;
      }

      .cost-distribution-legend {
        display: grid;
        gap: 12px;
      }

      .cost-distribution-item {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 12px;
        align-items: center;
        padding: 12px 14px;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: #fafbfd;
      }

      .cost-distribution-swatch {
        width: 12px;
        height: 12px;
        border-radius: 999px;
      }

      .cost-distribution-label {
        display: grid;
        gap: 3px;
      }

      .cost-distribution-label strong {
        font-size: 14px;
      }

      .cost-distribution-label span {
        font-size: 12px;
        color: var(--muted);
      }

      .cost-distribution-value {
        text-align: right;
        font-weight: 700;
        white-space: nowrap;
      }

      .remove-row {
        width: 26px;
        height: 26px;
        border-radius: 6px;
        border: 1px solid var(--line);
        background-color: transparent;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23c1121f' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round' opacity='0.8'><path d='M3 6h18'/><path d='M8 6V4h8v2'/><path d='M19 6l-1 14H6L5 6'/><path d='M10 11v6'/><path d='M14 11v6'/></svg>");
        background-repeat: no-repeat;
        background-position: center;
        cursor: pointer;
      }

      .note {
        font-size: 13px;
        color: var(--muted);
        margin-top: 12px;
      }

      .import-status {
        margin-top: 10px;
        font-size: 13px;
        color: var(--muted);
      }

      .import-status.is-error {
        color: var(--accent);
      }

      .rvtools-frame {
        grid-column: 1 / -1;
        display: block;
      }

      .rvtools-frame.is-hidden {
        display: none;
      }

      .rvtools-frame iframe {
        width: 100%;
        min-height: 320px;
        border: 0;
        border-radius: 12px;
        background: transparent;
      }

      .catalogue-host {
        min-height: 600px;
        width: 100%;
        overflow-x: auto;
      }

      .catalogue-host.is-loading {
        display: grid;
        place-items: center;
        color: var(--muted);
        font-size: 14px;
      }

      .catalogue-host .card {
        width: 100% !important;
        max-width: 100% !important;
        padding: 24px !important;
        border-radius: 12px !important;
        box-shadow: none !important;
      }

      .catalogue-host .content {
        grid-template-columns: 1fr !important;
        gap: 20px !important;
      }

      .catalogue-host .filters {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px 18px !important;
        align-items: start !important;
        justify-items: stretch !important;
        text-align: left !important;
        padding: 18px !important;
      }

      .catalogue-host .filters-title {
        grid-column: 1 / -1;
        margin: 0 !important;
      }

      .catalogue-host .filters > div {
        display: grid;
        gap: 10px;
        align-content: start;
        padding: 14px;
        border: 1px solid var(--line);
        border-radius: 12px;
        background: #fafafa;
      }

      .catalogue-host .filters h4 {
        margin: 0 !important;
        font-size: 12px !important;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--muted);
      }

      .catalogue-host .filter-group {
        display: grid !important;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px 14px !important;
        justify-items: start !important;
        justify-content: start !important;
        text-align: left !important;
        max-height: 220px;
        overflow: auto;
        padding-right: 6px;
      }

      .catalogue-host .filter-item {
        width: 100%;
        display: inline-flex !important;
        align-items: center;
        justify-content: flex-start !important;
        gap: 8px;
        padding: 8px 10px;
        border: 1px solid #ececec;
        border-radius: 10px;
        background: #fff;
      }

      .catalogue-host .filter-item input {
        width: auto !important;
        margin: 0;
      }

      .catalogue-host .filter-clear {
        grid-column: 1 / -1;
        justify-self: start;
      }

      .catalogue-host .table-layout,
      .catalogue-host .table-main,
      .catalogue-host .pricing-table {
        width: 100% !important;
      }

      .catalogue-host .table-main {
        overflow-x: auto !important;
      }

      .catalogue-host .pricing-table {
        min-width: 1180px;
      }

      .catalogue-host .entry-form,
      .catalogue-host .filters,
      .catalogue-host .hello {
        width: 100% !important;
      }

      .catalogue-host .form-grid {
        grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
      }

      .view-panel[data-view-panel="besoin"] section {
        width: 100%;
        overflow-x: auto;
      }

      .view-panel[data-view-panel="besoin"] #sizing-host,
      .view-panel[data-view-panel="besoin"] .service-list {
        width: 100%;
        min-width: 0;
      }

      .view-panel[data-view-panel="besoin"] .service-template.sizing-template-root,
      .view-panel[data-view-panel="besoin"] .service-template.sizing-template-root .template-content,
      .view-panel[data-view-panel="besoin"] .service-template.sizing-template-root .form-grid,
      .view-panel[data-view-panel="besoin"] .service-template.sizing-template-root .sizing-label {
        width: 100%;
        max-width: 100%;
      }

      .view-panel[data-view-panel="besoin"] .service-template.sizing-template-root .form-grid {
        grid-template-columns: 1fr !important;
      }

      .view-panel[data-view-panel="besoin"] .service-template.sizing-template-root .sizing-label {
        display: block;
      }

      .view-panel[data-view-panel="besoin"] .sizing-wrap {
        position: relative;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        column-gap: 40px !important;
        row-gap: 0 !important;
        align-items: start;
      }

      .view-panel[data-view-panel="besoin"] .sizing-wrap > .sizing-group {
        width: 100%;
        max-width: none;
        align-self: start;
        min-height: 100%;
      }

      .view-panel[data-view-panel="besoin"] .sizing-wrap > .sizing-group:first-child {
        grid-column: 1;
        justify-self: stretch;
      }

      .view-panel[data-view-panel="besoin"] .sizing-wrap > .sizing-group.is-wide {
        grid-column: 2;
        justify-self: stretch;
      }

      .view-panel[data-view-panel="besoin"] .sizing-wrap::after {
        content: "";
        position: absolute;
        top: 12px;
        bottom: 12px;
        left: 50%;
        width: 1px;
        background: var(--line);
        transform: translateX(-20px);
      }

      .view-panel[data-view-panel="besoin"] .sizing-button-row,
      .view-panel[data-view-panel="besoin"] .sizing-details-row {
        grid-template-columns: 1fr !important;
      }

      .view-panel[data-view-panel="besoin"] .sizing-group,
      .view-panel[data-view-panel="besoin"] .service-block,
      .view-panel[data-view-panel="besoin"] .service-item,
      .view-panel[data-view-panel="besoin"] .service-template {
        width: 100%;
        min-width: 0;
      }

      .view-panel[data-view-panel="besoin"] .service-item {
        align-items: flex-start;
      }

      .view-panel[data-view-panel="besoin"] .sizing-group {
        justify-items: stretch;
        padding: 16px;
        gap: 14px;
        min-width: 0;
        overflow: hidden;
      }

      .view-panel[data-view-panel="besoin"] .sizing-group.is-wide {
        margin-left: 0;
      }

      .view-panel[data-view-panel="besoin"] .sizing-group-title {
        text-align: left;
        padding-bottom: 10px;
      }

      .view-panel[data-view-panel="besoin"] .sizing-mutual-pools,
      .view-panel[data-view-panel="besoin"] .sizing-commitments {
        justify-content: start;
        width: 100%;
      }

      .view-panel[data-view-panel="besoin"] .sizing-mutual-pools {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .view-panel[data-view-panel="besoin"] .sizing-commitments {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }

      .view-panel[data-view-panel="besoin"] .sizing-mutual-pools .sizing-block,
      .view-panel[data-view-panel="besoin"] .sizing-commitments .btn,
      .view-panel[data-view-panel="besoin"] .sizing-commitments-create {
        width: 100%;
      }

      .view-panel[data-view-panel="besoin"] .sizing-button-row {
        align-items: start;
        justify-items: stretch;
        gap: 18px;
        grid-template-columns: repeat(2, minmax(220px, 1fr)) !important;
        width: 100%;
      }

      .view-panel[data-view-panel="besoin"] .sizing-block,
      .view-panel[data-view-panel="besoin"] .sizing-details,
      .view-panel[data-view-panel="besoin"] .sizing-actions {
        align-items: stretch;
        justify-items: stretch;
      }

      .view-panel[data-view-panel="besoin"] .sizing-button-row > .sizing-block {
        align-self: start;
        height: 100%;
      }

      .view-panel[data-view-panel="besoin"] .sizing-details-row {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 24px;
        align-items: start;
      }

      .view-panel[data-view-panel="besoin"] .sizing-details {
        grid-template-rows: auto auto 1fr;
        gap: 16px;
        align-content: start;
      }

      .view-panel[data-view-panel="besoin"] .sizing-label,
      .view-panel[data-view-panel="besoin"] .sizing-info,
      .view-panel[data-view-panel="besoin"] .sizing-metrics {
        align-items: flex-start;
        justify-items: start;
        text-align: left;
      }

      .view-panel[data-view-panel="besoin"] .sizing-toggle {
        width: 100%;
      }

      .view-panel[data-view-panel="besoin"] .sizing-input {
        width: 100%;
        max-width: 160px;
        text-align: left;
      }

      .view-panel[data-view-panel="besoin"] .sizing-metrics {
        grid-template-columns: minmax(0, 160px) auto;
        gap: 12px;
        align-items: center;
      }

      .view-panel[data-view-panel="besoin"] .sizing-metrics .sizing-rounded {
        margin-top: 0;
        align-self: center;
      }

      .view-panel[data-view-panel="besoin"] .sizing-memo,
      .view-panel[data-view-panel="besoin"] .sizing-rounded,
      .view-panel[data-view-panel="besoin"] .sizing-result,
      .view-panel[data-view-panel="besoin"] .sizing-title {
        text-align: left;
      }

      .view-panel[data-view-panel="besoin"] .sizing-info {
        align-items: center;
      }

      .view-panel[data-view-panel="besoin"] .sizing-chart {
        margin-inline: auto;
      }

      .view-panel[data-view-panel="besoin"] .sizing-legend,
      .view-panel[data-view-panel="besoin"] .legend-row {
        justify-content: center;
        text-align: center;
      }

      .view-panel[data-view-panel="besoin"] .sizing-actions {
        margin-top: 0;
        gap: 12px;
      }

      .view-panel[data-view-panel="besoin"] .sizing-buttons {
        justify-content: flex-start;
        width: 100%;
      }

      .view-panel[data-view-panel="besoin"] .sizing-buttons-pair {
        display: inline-flex;
        gap: 12px;
        padding: 8px;
        border: 1px solid var(--line);
        border-radius: 12px;
      }

      .view-panel[data-view-panel="besoin"] .sizing-buttons-row {
        justify-content: flex-start;
      }

      .view-panel[data-view-panel="besoin"] .sizing-buttons .btn,
      .view-panel[data-view-panel="besoin"] .sizing-actions > .btn {
        min-width: 120px;
      }

      .view-panel[data-view-panel="besoin"] .sizing-divider button {
        left: 50%;
        transform: translate(-50%, -50%);
      }

      .view-panel[data-view-panel="besoin"] .service-item-left {
        min-width: 0;
        flex: 1 1 auto;
      }

      .view-panel[data-view-panel="besoin"] .template-table {
        width: 100%;
        min-width: 820px;
      }

      .view-panel[data-view-panel="besoin"] .service-template {
        overflow-x: auto;
      }

      @media (max-width: 900px) {
        .app-shell {
          display: block;
        }

        .sidebar {
          position: static;
          width: auto;
          border-right: 0;
          border-bottom: 1px solid var(--line);
        }

        .content-shell {
          margin-left: 0;
          padding: 24px 20px 40px;
        }

        .catalogue-host .form-grid {
          grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }

        .catalogue-host .filters {
          grid-template-columns: 1fr !important;
        }

        .view-panel[data-view-panel="besoin"] .sizing-mutual-pools,
        .view-panel[data-view-panel="besoin"] .sizing-commitments {
          grid-template-columns: 1fr !important;
        }

        .view-panel[data-view-panel="besoin"] .sizing-wrap {
          grid-template-columns: 1fr !important;
        }

        .view-panel[data-view-panel="besoin"] .sizing-wrap::after {
          display: none;
        }

        .view-panel[data-view-panel="besoin"] .sizing-wrap > .sizing-group {
          width: 100%;
          justify-self: stretch;
        }

        .view-panel[data-view-panel="besoin"] .sizing-details-row {
          grid-template-columns: 1fr !important;
        }

        .view-panel[data-view-panel="besoin"] .template-table {
          min-width: 720px;
        }

        .cost-distribution {
          grid-template-columns: 1fr;
        }

        .perimeter-grid {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .sizing-wrap {
          grid-template-columns: 1fr;
        }

        .sizing-group.is-wide {
          grid-column: auto;
          border-left: 1px solid var(--line);
          background: #fcfcfc;
        }

        .layout {
          grid-template-columns: 1fr;
        }
      }
    </style>
  </head>
  <body>
    <div class="app-shell">
      <aside class="sidebar">
        <p class="sidebar-title">Navigation</p>
        <div class="sidebar-nav">
          <?php if (!empty($currentPermissions["perimetre"])): ?>
            <button type="button" class="sidebar-link<?= $defaultView === "perimetre" ? " is-active" : "" ?>" data-view-target="perimetre">Périmètre</button>
          <?php endif; ?>
          <?php if (!empty($currentPermissions["rvtools"])): ?>
            <button type="button" class="sidebar-link<?= $defaultView === "rvtools" ? " is-active" : "" ?>" data-view-target="rvtools">RVtool</button>
          <?php endif; ?>
          <?php if (!empty($currentPermissions["catalogue"])): ?>
            <button type="button" class="sidebar-link<?= $defaultView === "catalogue" ? " is-active" : "" ?>" data-view-target="catalogue">Catalogue</button>
          <?php endif; ?>
          <?php if (!empty($currentPermissions["besoin"])): ?>
            <button type="button" class="sidebar-link<?= $defaultView === "besoin" ? " is-active" : "" ?>" data-view-target="besoin">Besoin client</button>
          <?php endif; ?>
        <?php if (!empty($currentPermissions["devis"])): ?>
          <button type="button" class="sidebar-link<?= $defaultView === "devis" ? " is-active" : "" ?>" data-view-target="devis">Résumé des devis</button>
        <?php endif; ?>
        </div>
        <?php if (!empty($currentPermissions["administration"])): ?>
          <a class="sidebar-link sidebar-admin" href="administration.php">Administration</a>
        <?php endif; ?>
        <a class="sidebar-link<?= empty($currentPermissions["administration"]) ? " sidebar-admin" : "" ?> sidebar-logout" href="index.php?logout=1">Déconnexion</a>
      </aside>

      <main class="content-shell">
        <header>
          <h1>Deviseur</h1>
          <p>
            Un template simple inspire des calculateurs cloud : renseignez le perimetre
            client et visualisez un resume clair des couts.
          </p>
        </header>

        <div class="layout">
          <div class="view-panel<?= $defaultView === "perimetre" ? " is-active" : "" ?><?= empty($currentPermissions["perimetre"]) ? " permission-hidden" : "" ?>" data-view-panel="perimetre">
            <section>
              <h2>Périmètre Client</h2>
              <div class="form-grid perimeter-grid">
                <label class="field-wide">
                  Nom du client
                  <input type="text" id="client-name" />
                </label>
                <label class="field-wide">
                  Nom du projet
                  <input type="text" id="scope-project-name" />
                </label>
                <label>
                  Nombre de VM
                  <input type="number" min="0" step="1" id="scope-vm" />
                </label>
                <div class="field-stack">
                  <label>
                    vCPU
                    <input type="number" min="0" step="1" id="scope-vcpu" />
                  </label>
                  <label>
                    DONT VCPU WINDOWS
                    <input type="number" min="0" step="1" id="scope-vcpu-windows" />
                  </label>
                </div>
                <label>
                  vRAM
                  <input type="number" min="0" step="1" id="scope-vram" />
                </label>
                <label>
                  vDisk
                  <input type="number" min="0" step="1" id="scope-vdisk" />
                </label>
                <label>
                  Bande Passante Internet
                  <input type="number" min="0" step="1" id="scope-transit" />
                </label>
              </div>
              <div class="actions">
                <button type="button" class="btn secondary" id="scope-reset-btn">Reset périmètre client</button>
              </div>
            </section>
          </div>

          <div class="view-panel<?= $defaultView === "rvtools" ? " is-active" : "" ?><?= empty($currentPermissions["rvtools"]) ? " permission-hidden" : "" ?>" data-view-panel="rvtools">
            <section>
              <h2>Import RVTools</h2>
              <div class="actions">
                <button type="button" class="btn secondary" id="rvtools-import-btn">Import RVtools</button>
                <input type="file" id="rvtools-file-input" accept=".xlsx" hidden />
              </div>
              <div class="import-status" id="rvtools-import-status"></div>
            </section>

            <div class="rvtools-frame is-hidden" id="rvtools-results-frame">
              <iframe id="rvtools-results-iframe" title="Analyse RVTools"></iframe>
            </div>
          </div>

          <div class="view-panel<?= $defaultView === "catalogue" ? " is-active" : "" ?><?= empty($currentPermissions["catalogue"]) ? " permission-hidden" : "" ?>" data-view-panel="catalogue">
            <section>
              <div id="catalogue-host" class="catalogue-host is-loading">Chargement du catalogue...</div>
            </section>
          </div>

          <div class="view-panel<?= $defaultView === "besoin" ? " is-active" : "" ?><?= empty($currentPermissions["besoin"]) ? " permission-hidden" : "" ?>" data-view-panel="besoin">
            <section>
              <h2>Besoin client</h2>
              <div id="sizing-host"></div>
            </section>

            <section>
              <h2>Liste des services</h2>
              <div class="service-list" id="service-list"></div>
            </section>
          </div>

          <div class="view-panel<?= $defaultView === "devis" ? " is-active" : "" ?><?= empty($currentPermissions["devis"]) ? " permission-hidden" : "" ?>" data-view-panel="devis">
            <section>
              <h2>Repartition des couts</h2>
              <div class="cost-distribution">
                <div class="cost-distribution-chart-wrap">
                  <div class="cost-distribution-chart" id="cost-distribution-chart">
                    <div class="cost-distribution-total" id="cost-distribution-total">
                      <div>
                        <span>Total suivi</span>
                        <strong>0 EUR</strong>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="cost-distribution-legend" id="cost-distribution-legend"></div>
              </div>
            </section>
            <section>
              <h2>Resume du devis</h2>
              <div class="actions" style="margin-top: 0; margin-bottom: 16px;">
                <button type="button" class="btn" id="generate-quote-btn">Generer le devis</button>
              </div>
              <table>
                <thead>
                  <tr>
                    <th>Service</th>
                    <th>Region</th>
                    <th>Quantite</th>
                    <th>Periode</th>
                    <th>Total</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="lines"></tbody>
              </table>
              <div class="form-grid" style="margin-top: 16px;">
                <label>
                  Remise (%)
                  <input type="number" id="discount" min="0" max="50" value="0" />
                </label>
              </div>
              <div class="total">
                <span>Total estime</span>
                <span id="total">0 EUR</span>
              </div>
            </section>
          </div>
        </div>
      </main>
    </div>

    <script>
      const serviceCatalog = [
        {
          id: "vpc-payg",
          name: "VPC - Pay as you go",
          baseType: "VPC - Pay as you go",
          unit: "mois",
          price: 0,
        },
        {
          id: "vpc-flex",
          name: "VPC - Flex",
          baseType: "VPC - Flex",
          unit: "mois",
          price: 180,
        },
        {
          id: "vpc-flex-12",
          name: "VPC - Flex - 12 mois",
          baseType: "VPC - Flex",
          unit: "mois",
          price: 180,
        },
        {
          id: "vpc-flex-24",
          name: "VPC - Flex - 24 mois",
          baseType: "VPC - Flex",
          unit: "mois",
          price: 180,
        },
        {
          id: "vpc-flex-36",
          name: "VPC - Flex - 36 mois",
          baseType: "VPC - Flex",
          unit: "mois",
          price: 180,
        },
        {
          id: "vpc-flex-48",
          name: "VPC - Flex - 48 mois",
          baseType: "VPC - Flex",
          unit: "mois",
          price: 180,
        },
        {
          id: "vpc-flex-60",
          name: "VPC - Flex - 60 mois",
          baseType: "VPC - Flex",
          unit: "mois",
          price: 180,
        },
        {
          id: "vpc-ss",
          name: "VPC - Dedicated SS",
          baseType: "VPC - Dedicated SS",
          unit: "mois",
          price: 260,
        },
        {
          id: "hpc-enterprise",
          name: "HPC - Entreprise",
          baseType: "HPC - Entreprise",
          unit: "mois",
          price: 300,
        },
        {
          id: "hpc-snc",
          name: "HPC - SecNumCloud",
          baseType: "HPC - SecNumCloud",
          unit: "mois",
          price: 520,
        },
      ];

      const initialViewName = <?= json_encode($defaultView, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

      const formatter = new Intl.NumberFormat("fr-FR", {
        style: "currency",
        currency: "EUR",
      });

      const linesKey = "deviseurLines";
      const serviceItemsKey = "deviseurServiceItems";
      const lineBody = document.getElementById("lines");
      const totalEl = document.getElementById("total");
      const discountInput = document.getElementById("discount");
      const serviceList = document.getElementById("service-list");
      const sizingHost = document.getElementById("sizing-host");
      const clientNameInput = document.getElementById("client-name");
      const scopeVmInput = document.getElementById("scope-vm");
      const scopeVcpuInput = document.getElementById("scope-vcpu");
      const scopeVcpuWindowsInput = document.getElementById("scope-vcpu-windows");
      const scopeVramInput = document.getElementById("scope-vram");
      const scopeVdiskInput = document.getElementById("scope-vdisk");
      const scopeTransitInput = document.getElementById("scope-transit");
      const scopeProjectNameInput = document.getElementById("scope-project-name");
      const scopeResetBtn = document.getElementById("scope-reset-btn");
      const rvtoolsImportBtn = document.getElementById("rvtools-import-btn");
      const rvtoolsFileInput = document.getElementById("rvtools-file-input");
      const rvtoolsImportStatus = document.getElementById("rvtools-import-status");
      const rvtoolsResultsFrame = document.getElementById("rvtools-results-frame");
      const rvtoolsResultsIframe = document.getElementById("rvtools-results-iframe");
      const catalogueHost = document.getElementById("catalogue-host");
      const generateQuoteBtn = document.getElementById("generate-quote-btn");
      const costDistributionChart = document.getElementById("cost-distribution-chart");
      const costDistributionLegend = document.getElementById("cost-distribution-legend");
      const costDistributionTotal = document.getElementById("cost-distribution-total");
      const viewNavButtons = Array.from(
        document.querySelectorAll("[data-view-target]")
      );
      const viewPanels = Array.from(
        document.querySelectorAll("[data-view-panel]")
      );
      const defaultRegionValue = "1";
      const defaultRegionLabel = "France (standard)";
      const defaultQty = 1;
      const defaultMonths = 1;
      let catalogueViewLoaded = false;

      const formatMoney = (value) => formatter.format(value);
      const catalogueKey = "catalogueRows";
      let lines = [];
      let serviceItems = [];
      const collapsedServicesKey = "deviseurCollapsedServices";
      let collapsedServices = [];

      const normalizeKey = (value) =>
        String(value || "")
          .toLowerCase()
          .normalize("NFD")
          .replace(/[\u0300-\u036f]/g, "")
          .replace(/[–—‑]/g, "-")
          .replace(/\s+/g, " ")
          .trim();

      const parseNumeric = (value) => {
        const cleaned = String(value || "")
          .replace(/\s+/g, "")
          .replace(",", ".");
        const parsed = Number.parseFloat(cleaned);
        return Number.isNaN(parsed) ? 0 : parsed;
      };

      const setRvtoolsStatus = (message, isError = false) => {
        if (!rvtoolsImportStatus) return;
        rvtoolsImportStatus.textContent = message || "";
        rvtoolsImportStatus.classList.toggle("is-error", Boolean(isError));
      };

      const formatRvtoolsDashboardHtml = (htmlContent) => {
        if (!htmlContent) return "";

        try {
          const parser = new DOMParser();
          const doc = parser.parseFromString(htmlContent, "text/html");

          const summary = doc.querySelector(".hero .summary");
          if (summary) {
            summary.remove();
          }

          const style = doc.createElement("style");
          style.textContent = `
            .hero .metrics {
              display: grid;
              grid-template-columns: repeat(3, 1fr);
              gap: 8px;
              margin-top: 12px;
            }
            .hero .metric-card {
              display: flex;
              align-items: center;
              justify-content: space-between;
              gap: 10px;
              padding: 9px 10px;
              border-radius: 10px;
              border: 1px solid var(--line);
              background: #fff;
            }
            .hero .metric-card span {
              display: block;
              flex: 1 1 auto;
              margin-bottom: 0;
              color: var(--muted);
              font-size: 13px;
            }
            .hero .metric-card strong {
              display: block;
              flex: 0 0 auto;
              text-align: right;
              font-size: 13px;
              font-weight: 700;
            }
            @media (max-width: 1100px) {
              .hero .metrics {
                grid-template-columns: repeat(2, 1fr);
              }
            }
            @media (max-width: 720px) {
              .hero .metrics {
                grid-template-columns: 1fr;
              }
            }
          `;
          doc.head.appendChild(style);

          return "<!doctype html>\n" + doc.documentElement.outerHTML;
        } catch (error) {
          return htmlContent;
        }
      };

      const loadCatalogueView = async () => {
        if (!catalogueHost || catalogueViewLoaded) return;

        try {
          const response = await fetch("catalogue.php", {
            headers: { Accept: "text/html" },
          });
          if (!response.ok) {
            throw new Error("Chargement du catalogue impossible.");
          }

          const html = await response.text();
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, "text/html");

          const bodyNodes = Array.from(doc.body.childNodes);
          const scriptNodes = Array.from(doc.body.querySelectorAll("script"));
          const styleNodes = Array.from(doc.head.querySelectorAll("style"));

          styleNodes.forEach((node) => {
            const style = document.createElement("style");
            style.textContent = node.textContent || "";
            style.dataset.catalogueEmbedded = "true";
            document.head.appendChild(style);
          });

          catalogueHost.innerHTML = "";
          bodyNodes.forEach((node) => {
            if (node.nodeName.toLowerCase() === "script") return;
            catalogueHost.appendChild(document.importNode(node, true));
          });

          scriptNodes.forEach((node) => {
            const script = document.createElement("script");
            Array.from(node.attributes).forEach((attr) => {
              script.setAttribute(attr.name, attr.value);
            });
            script.textContent = node.textContent || "";
            catalogueHost.appendChild(script);
          });

          catalogueHost.classList.remove("is-loading");
          catalogueViewLoaded = true;
        } catch (error) {
          catalogueHost.textContent = error.message || "Chargement du catalogue impossible.";
          catalogueHost.classList.remove("is-loading");
        }
      };

      const setActiveView = (viewName = initialViewName || "perimetre") => {
        const allowedViewNames = viewNavButtons.map((button) => button.dataset.viewTarget);
        const targetView = allowedViewNames.includes(viewName)
          ? viewName
          : (allowedViewNames[0] || "");
        if (!targetView) return;

        viewNavButtons.forEach((button) => {
          const isActive = button.dataset.viewTarget === targetView;
          button.classList.toggle("is-active", isActive);
        });

        viewPanels.forEach((panel) => {
          const isActive = panel.dataset.viewPanel === targetView;
          panel.classList.toggle("is-active", isActive);
        });

        if (targetView === "catalogue") {
          loadCatalogueView();
        }
      };

      const resizeRvtoolsFrame = () => {
        if (!rvtoolsResultsIframe) return;
        const frameDoc = rvtoolsResultsIframe.contentDocument;
        if (!frameDoc?.body) return;
        const docEl = frameDoc.documentElement;
        const nextHeight = Math.max(
          frameDoc.body.scrollHeight,
          docEl ? docEl.scrollHeight : 0,
          320
        );
        rvtoolsResultsIframe.style.height = `${nextHeight}px`;
      };

      const applyRvtoolsSummary = (summary) => {
        const perimetre = summary?.perimetre;
        if (!perimetre || typeof perimetre !== "object") return;
        if (perimetre.vm !== undefined) scopeVmInput.value = String(perimetre.vm ?? "");
        if (perimetre.vcpu !== undefined) scopeVcpuInput.value = String(perimetre.vcpu ?? "");
        if (perimetre.vcpu_windows !== undefined) {
          scopeVcpuWindowsInput.value = String(perimetre.vcpu_windows ?? "");
        }
        if (perimetre.vram !== undefined) scopeVramInput.value = String(perimetre.vram ?? "");
        if (perimetre.vdisk !== undefined) scopeVdiskInput.value = String(perimetre.vdisk ?? "");
        if (perimetre.transit !== undefined) {
          scopeTransitInput.value = String(perimetre.transit ?? "");
        }
        if (!scopeProjectNameInput.value && perimetre.project_name) {
          scopeProjectNameInput.value = String(perimetre.project_name ?? "");
        }
        renderSizingBlock();
        renderServiceList();
        renderLines();
        saveClientData();
      };

      const renderRvtoolsDashboard = (htmlContent, fileName, summary) => {
        if (!rvtoolsResultsFrame || !rvtoolsResultsIframe) return;
        rvtoolsResultsFrame.classList.remove("is-hidden");
        rvtoolsResultsIframe.srcdoc = formatRvtoolsDashboardHtml(htmlContent || "");
        setActiveView("rvtools");
      };

      const importRvtoolsFile = async (file) => {
        if (!file) return;
        const formData = new FormData();
        formData.append("action", "import_rvtools");
        formData.append("rvtools_file", file);

        rvtoolsImportBtn.disabled = true;
        setRvtoolsStatus(`Import et analyse de ${file.name} en cours...`);

        try {
          const response = await fetch("deviseur.php", {
            method: "POST",
            body: formData,
            headers: { Accept: "application/json" },
          });
          const payload = await response.json();
          if (!response.ok || !payload?.success) {
            throw new Error(payload?.detail || payload?.error || "Import RVTools echoue.");
          }
          applyRvtoolsSummary(payload.summary || {});
          renderRvtoolsDashboard(
            payload.dashboard_html || "",
            payload.file_name || file.name,
            payload.summary || {}
          );
          setRvtoolsStatus(`Import RVTools termine : ${payload.file_name || file.name}`);
        } catch (error) {
          setRvtoolsStatus(error.message || "Import RVTools echoue.", true);
        } finally {
          rvtoolsImportBtn.disabled = false;
          rvtoolsFileInput.value = "";
        }
      };

      if (rvtoolsResultsIframe) {
        rvtoolsResultsIframe.addEventListener("load", () => {
          resizeRvtoolsFrame();
        });
      }

      viewNavButtons.forEach((button) => {
        button.addEventListener("click", () => {
          setActiveView(button.dataset.viewTarget || "perimetre");
        });
      });

      const getScopedVcpuTotal = () => parseNumeric(scopeVcpuInput.value);

      const normalizeClientName = (value) =>
        String(value || "")
          .toLowerCase()
          .replace(/[–—‑]/g, "-")
          .replace(/\s+/g, " ")
          .trim();

      const extractCommitmentMonths = (serviceName) => {
        const match = String(serviceName || "").match(/(\d+)\s*mois/i);
        return match ? Number(match[1]) : 0;
      };

      const buildCommitmentDetailLabel = (labelText = "") => {
        const months = extractCommitmentMonths(labelText);
        if (months > 0) {
          return `Engagement ${months} mois`;
        }
        return "Sans engagement";
      };

      const findSizingServiceBlock = (serviceType = "VPC - Dedicated SS") => {
        if (!serviceList) return null;
        const targetKey = normalizeKey(serviceType);
        const blocks = Array.from(serviceList.querySelectorAll(".service-block"));
        for (let index = blocks.length - 1; index >= 0; index -= 1) {
          const block = blocks[index];
          if (normalizeKey(block.dataset.type) === targetKey) {
            return block;
          }
        }
        return null;
      };

      const getDedicatedServerTemplate = (serviceBlock) => {
        if (!serviceBlock) return null;
        return Array.from(serviceBlock.querySelectorAll(".service-template")).find(
          (template) => {
            const title = template.querySelector(".template-title");
            return title && title.textContent.trim().startsWith("Serveur");
          }
        ) || null;
      };

      const getDedicatedServerQuantity = (serviceBlock) => {
        const serverTemplate = getDedicatedServerTemplate(serviceBlock);
        if (!serverTemplate) return 0;
        return Array.from(
          serverTemplate.querySelectorAll("tbody input[type='number']")
        ).reduce((sum, input) => sum + Number(input.value || 0), 0);
      };

      const parsePrice = (value) => {
        const cleaned = String(value || "")
          .replace(/[^\d,.-]/g, "")
          .replace(",", ".");
        const parsed = Number.parseFloat(cleaned);
        return Number.isNaN(parsed) ? 0 : parsed;
      };

      const getCatalogueTypeKeys = (type) => {
        return [normalizeKey(String(type || ""))];
      };

      const getCatalogueEntry = (type, label) => {
        const rows = JSON.parse(localStorage.getItem(catalogueKey) || "[]");
        const typeKeys = getCatalogueTypeKeys(type);
        const labelKey = normalizeKey(label);

        for (const row of rows) {
          if (!Array.isArray(row) || row.length < 5) continue;
          const [rowType, , rowName, rowDetail, rowPrice] = row;
          if (!typeKeys.includes(normalizeKey(rowType))) continue;
          if (
            normalizeKey(rowName) === labelKey ||
            normalizeKey(rowDetail) === labelKey
          ) {
            return {
              detail: rowDetail || "",
              price: parsePrice(rowPrice),
            };
          }
        }

        return { detail: "", price: 0 };
      };

      const getCatalogueEntryByCommitment = (type, label, months) => {
        const rows = JSON.parse(localStorage.getItem(catalogueKey) || "[]");
        const typeKeys = getCatalogueTypeKeys(type);
        const labelKey = normalizeKey(label);
        const monthsKey = normalizeKey(`${months} mois`);

        for (const row of rows) {
          if (!Array.isArray(row) || row.length < 5) continue;
          const [rowType, , rowName, rowDetail, rowPrice] = row;
          if (!typeKeys.includes(normalizeKey(rowType))) continue;
          if (normalizeKey(rowName) !== labelKey) continue;
          if (!normalizeKey(rowDetail).includes(monthsKey)) continue;
          return {
            detail: rowDetail || "",
            price: parsePrice(rowPrice),
          };
        }

        return { detail: "", price: 0 };
      };

      const getCatalogueRowsByNamePrefix = (type, prefix) => {
        const rows = JSON.parse(localStorage.getItem(catalogueKey) || "[]");
        const typeKeys = getCatalogueTypeKeys(type);
        const prefixKey = normalizeKey(prefix);

        return rows
          .filter((row) => Array.isArray(row) && row.length >= 5)
          .filter((row) => typeKeys.includes(normalizeKey(row[0])))
          .filter((row) => normalizeKey(row[2]).startsWith(prefixKey))
          .map((row) => ({
            label: row[2],
            detail: row[3] || "",
            unitPrice: parsePrice(row[4]),
          }));
      };

      const detailHasCommitmentMonths = (detail) =>
        /(\d+)\s*mois/i.test(String(detail || ""));

      const filterCatalogueRowsByCommitment = (rows, months = 0) => {
        if (!months || months <= 0) return rows;

        const monthsKey = normalizeKey(`${months} mois`);
        const groupedRows = new Map();

        rows.forEach((row) => {
          const key = normalizeKey(row.label);
          if (!groupedRows.has(key)) {
            groupedRows.set(key, []);
          }
          groupedRows.get(key).push(row);
        });

        const filtered = [];
        groupedRows.forEach((group) => {
          const matchingRows = group.filter((row) =>
            normalizeKey(row.detail).includes(monthsKey)
          );
          if (matchingRows.length > 0) {
            filtered.push(...matchingRows);
            return;
          }

          const rowsWithCommitment = group.filter((row) =>
            detailHasCommitmentMonths(row.detail)
          );
          if (rowsWithCommitment.length > 0) {
            return;
          }

          filtered.push(...group);
        });

        return filtered;
      };

      const getCatalogueRowsByCategory = (type, category, options = {}) => {
        const rows = JSON.parse(localStorage.getItem(catalogueKey) || "[]");
        const typeKeys = getCatalogueTypeKeys(type);
        const categoryKey = normalizeKey(category);
        const months = Number(options.months || 0);

        const mappedRows = rows
          .filter((row) => Array.isArray(row) && row.length >= 5)
          .filter((row) => typeKeys.includes(normalizeKey(row[0])))
          .filter((row) => normalizeKey(row[1]) === categoryKey)
          .map((row) => ({
            label: row[2],
            detail: row[3] || "",
            unitPrice: parsePrice(row[4]),
          }));

        return filterCatalogueRowsByCommitment(mappedRows, months);
      };

      const getCatalogueRowsByCategories = (type, categories) => {
        const rows = JSON.parse(localStorage.getItem(catalogueKey) || "[]");
        const typeKeys = getCatalogueTypeKeys(type);
        const categoryKeys = categories.map((category) => normalizeKey(category));

        return rows
          .filter((row) => Array.isArray(row) && row.length >= 5)
          .filter((row) => typeKeys.includes(normalizeKey(row[0])))
          .filter((row) => categoryKeys.includes(normalizeKey(row[1])))
          .map((row) => ({
            label: row[2],
            detail: row[3] || "",
            unitPrice: parsePrice(row[4]),
          }));
      };

      const getCatalogueRowsByNames = (type, names, options = {}) => {
        const rows = JSON.parse(localStorage.getItem(catalogueKey) || "[]");
        const typeKeys = getCatalogueTypeKeys(type);
        const nameKeys = names.map((name) => normalizeKey(name));
        const months = Number(options.months || 0);

        const mappedRows = rows
          .filter((row) => Array.isArray(row) && row.length >= 5)
          .filter((row) => typeKeys.includes(normalizeKey(row[0])))
          .filter((row) => nameKeys.includes(normalizeKey(row[2])))
          .sort(
            (left, right) =>
              nameKeys.indexOf(normalizeKey(left[2])) -
              nameKeys.indexOf(normalizeKey(right[2]))
          )
          .map((row) => ({
            label: row[2],
            detail: row[3] || "",
            unitPrice: parsePrice(row[4]),
          }));

        return filterCatalogueRowsByCommitment(mappedRows, months);
      };

      const getDedicatedStorageCatalogueRows = (serviceType) => {
        const rows = JSON.parse(localStorage.getItem(catalogueKey) || "[]");
        const typeKeys = getCatalogueTypeKeys(serviceType);
        const storageKey = normalizeKey("Stockage mutu");

        return rows
          .filter((row) => Array.isArray(row) && row.length >= 5)
          .filter((row) => typeKeys.includes(normalizeKey(row[0])))
          .filter((row) => {
            const categoryKey = normalizeKey(row[1]);
            const nameKey = normalizeKey(row[2]);
            return categoryKey.includes(storageKey) || nameKey.includes(storageKey);
          })
          .map((row) => ({
            label: row[2],
            detail: row[3] || "",
            unitPrice: parsePrice(row[4]),
          }));
      };

      const loadCatalogueFromServer = async () => {
        try {
          const response = await fetch("deviseur.php?format=json", {
            headers: { Accept: "application/json" },
          });
          if (!response.ok) throw new Error("Catalogue fetch failed");
          const payload = await response.json();
          const rows = Array.isArray(payload.data) ? payload.data : [];
          const normalized = rows
            .filter((row) => row && typeof row === "object")
            .map((row) => [
              row.type,
              row.category,
              row.name,
              row.detail,
              row.price,
            ]);
          if (normalized.length > 0) {
            localStorage.setItem(catalogueKey, JSON.stringify(normalized));
            return true;
          }
          return false;
        } catch (error) {
          return false;
        }
      };

      const loadLines = () => {
        try {
          const stored = JSON.parse(localStorage.getItem(linesKey) || "[]");
          return Array.isArray(stored) ? stored : [];
        } catch (error) {
          return [];
        }
      };

      const saveLines = () => {
        const stored = lines.map(({ templateTotal, ...rest }) => rest);
        localStorage.setItem(linesKey, JSON.stringify(stored));
      };

      const loadServiceItems = () => {
        try {
          const stored = JSON.parse(localStorage.getItem(serviceItemsKey) || "[]");
          return Array.isArray(stored) ? stored : [];
        } catch (error) {
          return [];
        }
      };

      const saveServiceItems = () => {
        localStorage.setItem(serviceItemsKey, JSON.stringify(serviceItems));
      };

      const loadCollapsedServices = () => {
        try {
          const stored = JSON.parse(
            localStorage.getItem(collapsedServicesKey) || "[]"
          );
          return Array.isArray(stored) ? stored : [];
        } catch (error) {
          return [];
        }
      };

      const saveCollapsedServices = () => {
        localStorage.setItem(
          collapsedServicesKey,
          JSON.stringify(collapsedServices)
        );
      };

      const getClientStorageKey = (name) =>
        `deviseurClient:${normalizeClientName(name)}`;

      const captureSizingState = () => {
        if (!sizingHost) return null;
        const wrap = sizingHost.querySelector(".sizing-wrap");
        if (!wrap) return null;
        return {
          activeMode: wrap._besoinBlock?._toggle?.classList.contains("is-active")
            ? "pool"
            : wrap._besoinPcaBlock?._toggle?.classList.contains("is-active")
              ? "pool-pca"
              : wrap._monoBlock?._toggle?.classList.contains("is-active")
                ? "mono"
                : wrap._biBlock?._toggle?.classList.contains("is-active")
                  ? "bi"
                  : "",
          monoValue: wrap._monoBlock?._resultInput?.value || "",
          biValue: wrap._biBlock?._resultInput?.value || "",
          vmware: wrap._vmwareBtn?.classList.contains("is-active") || false,
          microsoftDc: wrap._microsoftDcBtn?.classList.contains("is-active") || false,
          hyperv: wrap._hypervBtn?.classList.contains("is-active") || false,
          core16: wrap._core16Btn?.classList.contains("is-active") || false,
          ssd: wrap._ssdBtn?.classList.contains("is-active") || false,
          nvme: wrap._nvmeBtn?.classList.contains("is-active") || false,
          advancedOpen: !wrap._dedicatedDetailsRow?.classList.contains("is-hidden"),
        };
      };

      const getTemplateTitleText = (template) => {
        const titleEl = template.querySelector(".template-title");
        if (!titleEl) return "";
        const primary = titleEl.childNodes[0]?.textContent;
        return (primary || titleEl.textContent || "").trim();
      };

      const getTemplateRowStorageKey = (row) => {
        if (!row) return "";
        const cells = row.querySelectorAll("td");
        const description = cells[0]?.textContent?.trim() || "";
        const detail = cells[1]?.textContent?.trim() || "";
        return `${description}|||${detail}`;
      };

      const setTemplateRowQuantity = (template, matcher, quantity) => {
        if (!template) return;
        const rows = Array.from(template.querySelectorAll("tbody tr"));
        rows.forEach((row) => {
          const description = row.querySelector("td")?.textContent?.trim() || "";
          const detail = row.querySelectorAll("td")[1]?.textContent?.trim() || "";
          if (!matcher({
            descriptionKey: normalizeKey(description),
            detailKey: normalizeKey(detail),
          })) return;
          const qtyInput = row.querySelector("input[type='number']");
          if (!qtyInput) return;
          qtyInput.value = String(quantity);
          qtyInput.dispatchEvent(new Event("input", { bubbles: true }));
        });
      };

      const applyScopeDefaultsToServiceBlock = (serviceBlock, serviceType = "") => {
        if (!serviceBlock) return;

        const templates = Array.from(serviceBlock.querySelectorAll(".service-template"));
        const hasTransit = parseNumeric(scopeTransitInput.value || 0) > 0;

        templates.forEach((template) => {
          const templateTitle = getTemplateTitleText(template);
          const titleKey = normalizeKey(templateTitle);

          if (titleKey === normalizeKey("Transit Edge")) {
            setTemplateRowQuantity(
              template,
              ({ descriptionKey }) =>
                descriptionKey.includes(normalizeKey("Bande Passante Internet")),
              parseNumeric(scopeTransitInput.value || 0)
            );
            setTemplateRowQuantity(
              template,
              ({ descriptionKey }) =>
                descriptionKey === normalizeKey("Adresse IPv4"),
              hasTransit ? 1 : 0
            );
            setTemplateRowQuantity(
              template,
              ({ descriptionKey }) =>
                descriptionKey === normalizeKey("Firewall"),
              hasTransit ? 1 : 0
            );
            return;
          }

          if (titleKey === normalizeKey("Compute")) {
            setTemplateRowQuantity(
              template,
              ({ descriptionKey }) => descriptionKey === normalizeKey("vCPU"),
              parseNumeric(scopeVcpuInput.value || 0)
            );
            setTemplateRowQuantity(
              template,
              ({ descriptionKey }) => descriptionKey === normalizeKey("vRAM"),
              parseNumeric(scopeVramInput.value || 0)
            );
            setTemplateRowQuantity(
              template,
              ({ descriptionKey }) => descriptionKey === normalizeKey("vDisk"),
              parseNumeric(scopeVdiskInput.value || 0)
            );
          }
        });

        if (serviceType === "VPC - Dedicated SS") {
          const sizingBlock = findSizingBlockForServiceType(serviceType);
          if (typeof sizingBlock?._applyServerSizing === "function") {
            sizingBlock._applyServerSizing();
          }
        }
      };

      const getSizingBlockReferenceCount = (sizingBlock) => {
        if (!sizingBlock) return 0;
        const resultInput =
          sizingBlock._resultInput || sizingBlock.querySelector(".sizing-input");
        const manual = parseNumeric(resultInput?.value || 0);
        if (Number.isFinite(manual)) {
          return Math.ceil(manual) + 1;
        }
        const roundedLabel =
          sizingBlock.querySelector(".sizing-rounded")?.textContent || "";
        const roundedMatch = roundedLabel.match(/N\+1\s*:\s*(\d+)/i);
        return roundedMatch ? Number(roundedMatch[1]) : 0;
      };

      const findSizingBlockForServiceType = (serviceType = "VPC - Dedicated SS") => {
        const sizingToggles = Array.from(document.querySelectorAll(".sizing-toggle"));
        const sizingToggle = serviceType === "VPC - Dedicated SS"
          ? sizingToggles.find((toggle) => toggle.classList.contains("is-active"))
            || sizingToggles.find((toggle) => {
              const titleEl = toggle.querySelector(".sizing-title");
              return normalizeKey(titleEl?.textContent || "").includes("mono");
            })
          : sizingToggles.find((toggle) => {
            const titleEl = toggle.querySelector(".sizing-title");
            return normalizeKey(titleEl?.textContent || "").includes("mono");
          });
        return sizingToggle?.closest(".sizing-block") || null;
      };

      const captureTemplateQuantities = () => {
        const quantities = [];
        const blocks = Array.from(serviceList.querySelectorAll(".service-block"));
        blocks.forEach((block, index) => {
          const sections = {};
          const templates = Array.from(block.querySelectorAll(".service-template"));
          templates.forEach((template) => {
            const sectionTitle = getTemplateTitleText(template);
            const rows = {};
            template.querySelectorAll("tbody tr").forEach((row) => {
              const rowKey = getTemplateRowStorageKey(row);
              const qtyInputEl = row.querySelector("input[type='number']");
              rows[rowKey] = Number(qtyInputEl?.value || 0);
            });
            sections[sectionTitle] = rows;
          });
          quantities[index] = sections;
        });
        return quantities;
      };

      const applyTemplateQuantities = (quantities) => {
        if (!quantities || !Array.isArray(quantities)) return;
        const blocks = Array.from(serviceList.querySelectorAll(".service-block"));
        blocks.forEach((block, index) => {
          const sectionData = quantities[index] || {};
          const templates = Array.from(block.querySelectorAll(".service-template"));
          templates.forEach((template) => {
            const sectionTitle = getTemplateTitleText(template);
            const rows = sectionData[sectionTitle] || {};
            template.querySelectorAll("tbody tr").forEach((row) => {
              const rowKey = getTemplateRowStorageKey(row);
              if (!(rowKey in rows)) return;
              const qtyInputEl = row.querySelector("input[type='number']");
              if (!qtyInputEl) return;
              qtyInputEl.value = rows[rowKey];
              qtyInputEl.dispatchEvent(new Event("input", { bubbles: true }));
            });
          });
        });
      };

      const loadClientData = (name) => {
        try {
          const raw = localStorage.getItem(getClientStorageKey(name));
          if (!raw) return null;
          return JSON.parse(raw);
        } catch (error) {
          return null;
        }
      };

      const saveClientData = () => {
        const name = clientNameInput.value.trim();
        if (!name) return;
        const data = {
          name,
          perimetre: {
            vm: scopeVmInput.value,
            vcpu: scopeVcpuInput.value,
            vcpu_windows: scopeVcpuWindowsInput.value,
            vram: scopeVramInput.value,
            vdisk: scopeVdiskInput.value,
            transit: scopeTransitInput.value,
            project_name: scopeProjectNameInput.value,
          },
          form: {
            region: defaultRegionValue,
            qty: String(defaultQty),
            months: String(defaultMonths),
          },
          discount: discountInput.value,
          lines: lines.map(({ templateTotal, ...rest }) => rest),
          serviceItems,
          collapsedServices,
          sizing: captureSizingState(),
          quantities: captureTemplateQuantities(),
        };
        localStorage.setItem(getClientStorageKey(name), JSON.stringify(data));
        localStorage.setItem("deviseurLastClient", name);
      };

      const escapeHtml = (value) =>
        String(value ?? "")
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/\"/g, "&quot;")
          .replace(/'/g, "&#39;");

      const collectQuoteData = () => {
        const serviceBlocks = Array.from(serviceList.querySelectorAll(".service-block"));
        const discountRate = Number(discountInput.value || 0) / 100;
        const summaryRows = [];
        const detailRows = [];

        serviceBlocks.forEach((block) => {
          const serviceName =
            block.querySelector(".service-item-title")?.textContent?.trim() || "Service";
          const serviceTotal = parsePrice(
            block.querySelector(".service-total")?.textContent || "0"
          );
          summaryRows.push({
            serviceName,
            serviceTotal,
            serviceTotalLabel: formatMoney(serviceTotal),
          });

          const templates = Array.from(block.querySelectorAll(".service-template"));
          templates.forEach((template) => {
            const categoryName = getTemplateTitleText(template) || "Categorie";
            const rows = Array.from(template.querySelectorAll("tbody tr"));
            rows.forEach((row) => {
              const cells = row.querySelectorAll("td");
              const qty = Number(row.querySelector("input[type='number']")?.value || 0);
              const unitPrice = parsePrice(cells[3]?.textContent || "0");
              const totalPrice = parsePrice(cells[4]?.textContent || "0");
              if (qty === 0 || totalPrice === 0) return;
              detailRows.push({
                serviceName,
                categoryName,
                description: cells[0]?.textContent?.trim() || "",
                detail: cells[1]?.textContent?.trim() || "",
                qty,
                unitPrice,
                totalPrice,
                unitPriceLabel: formatMoney(unitPrice),
                totalPriceLabel: formatMoney(totalPrice),
              });
            });
          });
        });

        const subtotalHt = summaryRows.reduce((sum, row) => sum + row.serviceTotal, 0);
        const discountAmount = subtotalHt * discountRate;
        const totalHt = subtotalHt - discountAmount;
        const vatAmount = totalHt * 0.2;
        const totalTtc = totalHt + vatAmount;

        return {
          generatedAt: new Date().toLocaleDateString("fr-FR"),
          clientName: clientNameInput.value.trim(),
          projectName: scopeProjectNameInput.value.trim(),
          discountPercent: Number(discountInput.value || 0),
          summaryRows,
          detailRows,
          subtotalHt,
          discountAmount,
          totalHt,
          vatAmount,
          totalTtc,
        };
      };

      const classifyCostBucket = (categoryName = "", description = "", detail = "") => {
        const categoryKey = normalizeKey(categoryName);
        const descriptionKey = normalizeKey(description);
        const detailKey = normalizeKey(detail);
        const combinedKey = `${categoryKey} ${descriptionKey} ${detailKey}`;

        if (
          categoryKey.includes("licence") ||
          descriptionKey.includes("licence") ||
          combinedKey.includes("vmware") ||
          combinedKey.includes("microsoft windows server")
        ) {
          return "software";
        }

        if (
          categoryKey.includes("managed services") ||
          categoryKey.includes("services manages") ||
          categoryKey.includes("services manages") ||
          descriptionKey.includes("mco")
        ) {
          return "services";
        }

        return "hardware";
      };

      const getCostDistributionData = () => {
        const totals = {
          hardware: 0,
          software: 0,
          services: 0,
        };

        const serviceBlocks = Array.from(serviceList.querySelectorAll(".service-block"));
        serviceBlocks.forEach((block) => {
          const templates = Array.from(block.querySelectorAll(".service-template"));
          templates.forEach((template) => {
            const categoryName = getTemplateTitleText(template);
            const rows = Array.from(template.querySelectorAll("tbody tr"));
            rows.forEach((row) => {
              const cells = row.querySelectorAll("td");
              const totalValue = parsePrice(cells[4]?.textContent || "0");
              if (totalValue <= 0) return;
              const bucket = classifyCostBucket(
                categoryName,
                cells[0]?.textContent || "",
                cells[1]?.textContent || ""
              );
              totals[bucket] += totalValue;
            });
          });
        });

        return [
          {
            key: "hardware",
            label: "Materiel",
            hint:
              "Serveurs physiques, stockage, bande passante, IPv4, firewall, vCPU, vRAM, vDisk, sauvegarde",
            color: "#d97706",
            value: totals.hardware,
          },
          {
            key: "software",
            label: "Logiciel",
            hint: "Toutes les licences",
            color: "#2563eb",
            value: totals.software,
          },
          {
            key: "services",
            label: "Services",
            hint: "MCO",
            color: "#0f766e",
            value: totals.services,
          },
        ];
      };

      const renderCostDistribution = () => {
        if (!costDistributionChart || !costDistributionLegend || !costDistributionTotal) {
          return;
        }

        const entries = getCostDistributionData();
        const totalValue = entries.reduce((sum, entry) => sum + entry.value, 0);
        let currentAngle = 0;
        const gradientParts = [];

        entries.forEach((entry) => {
          const ratio = totalValue > 0 ? entry.value / totalValue : 0;
          const nextAngle = currentAngle + ratio * 360;
          gradientParts.push(`${entry.color} ${currentAngle}deg ${nextAngle}deg`);
          entry.percent = ratio * 100;
          currentAngle = nextAngle;
        });

        costDistributionChart.style.background = totalValue > 0
          ? `conic-gradient(${gradientParts.join(", ")})`
          : "conic-gradient(#d9e1ec 0deg 360deg)";

        costDistributionTotal.innerHTML = `
          <div>
            <span>Total suivi</span>
            <strong>${escapeHtml(formatMoney(totalValue))}</strong>
          </div>
        `;

        costDistributionLegend.innerHTML = entries.map((entry) => `
          <div class="cost-distribution-item">
            <span class="cost-distribution-swatch" style="background:${escapeHtml(entry.color)}"></span>
            <div class="cost-distribution-label">
              <strong>${escapeHtml(`${entry.label} (${entry.percent.toFixed(1)} %)` )}</strong>
              <span>${escapeHtml(entry.hint)}</span>
            </div>
            <div class="cost-distribution-value">${escapeHtml(formatMoney(entry.value))}</div>
          </div>
        `).join("");
      };

      const buildQuoteDocumentHtml = (quoteData) => {
        const summaryRowsHtml = quoteData.summaryRows.length > 0
          ? quoteData.summaryRows.map((row) => `
              <tr>
                <td>${escapeHtml(row.serviceName)}</td>
                <td class="amount">${escapeHtml(row.serviceTotalLabel)}</td>
              </tr>
            `).join("")
          : `
              <tr>
                <td colspan="2" class="empty">Aucun service a integrer au devis.</td>
              </tr>
            `;

        const detailRowsByService = new Map();
        quoteData.detailRows.forEach((row) => {
          if (!detailRowsByService.has(row.serviceName)) {
            detailRowsByService.set(row.serviceName, new Map());
          }
          const categories = detailRowsByService.get(row.serviceName);
          if (!categories.has(row.categoryName)) {
            categories.set(row.categoryName, []);
          }
          categories.get(row.categoryName).push(row);
        });

        const detailSectionsHtml = detailRowsByService.size > 0
          ? Array.from(detailRowsByService.entries()).map(([serviceName, categories]) => `
              <section class="quote-service-section">
                <h2>${escapeHtml(serviceName)}</h2>
                ${Array.from(categories.entries()).map(([categoryName, rows]) => `
                  <div class="quote-category-block">
                    <h3>${escapeHtml(categoryName)}</h3>
                    <table>
                      <thead>
                        <tr>
                          <th>Description</th>
                          <th>Detail</th>
                          <th>Quantite</th>
                          <th>Prix unitaire HT</th>
                          <th>Total HT</th>
                        </tr>
                      </thead>
                      <tbody>
                        ${rows.map((row) => `
                          <tr>
                            <td>${escapeHtml(row.description)}</td>
                            <td>${escapeHtml(row.detail)}</td>
                            <td>${escapeHtml(String(row.qty))}</td>
                            <td class="amount">${escapeHtml(row.unitPriceLabel)}</td>
                            <td class="amount">${escapeHtml(row.totalPriceLabel)}</td>
                          </tr>
                        `).join("")}
                      </tbody>
                    </table>
                  </div>
                `).join("")}
              </section>
            `).join("")
          : '<p class="empty">Aucune ligne detaillee avec quantite et montant superieurs a 0.</p>';

        const discountRowHtml = quoteData.discountPercent > 0
          ? `
              <tr>
                <th>Remise (${escapeHtml(String(quoteData.discountPercent))} %)</th>
                <td class="amount">-${escapeHtml(formatMoney(quoteData.discountAmount))}</td>
              </tr>
            `
          : "";

        return `<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8" />
    <title>Devis - ${escapeHtml(quoteData.clientName || "Client")}</title>
    <style>
      :root {
        color-scheme: light;
        --ink: #14213d;
        --muted: #5c677d;
        --line: #d7deea;
        --accent: #0b6e4f;
        --panel: #f8fafc;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        font-family: Arial, sans-serif;
        color: var(--ink);
        background: #eef2f7;
      }
      .quote-shell {
        width: 210mm;
        margin: 0 auto;
        background: #fff;
      }
      .quote-page {
        min-height: 297mm;
        padding: 18mm 16mm;
        page-break-after: always;
      }
      .quote-page:last-child {
        page-break-after: auto;
      }
      .quote-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 18px;
      }
      .quote-print {
        padding: 10px 16px;
        border: 1px solid var(--accent);
        border-radius: 8px;
        background: var(--accent);
        color: #fff;
        font-weight: 700;
        cursor: pointer;
      }
      .quote-title {
        margin: 0 0 6px;
        font-size: 28px;
      }
      .quote-meta {
        display: grid;
        gap: 4px;
        margin-bottom: 22px;
        color: var(--muted);
        font-size: 14px;
      }
      table {
        width: 100%;
        border-collapse: collapse;
      }
      th, td {
        border: 1px solid var(--line);
        padding: 10px 12px;
        text-align: left;
        vertical-align: top;
      }
      thead th {
        background: var(--panel);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }
      .amount {
        text-align: right;
        white-space: nowrap;
      }
      .quote-totals {
        width: 100%;
        max-width: 360px;
        margin-left: auto;
        margin-top: 16px;
        border-collapse: collapse;
      }
      .quote-totals th,
      .quote-totals td {
        border: 1px solid var(--line);
      }
      .quote-totals tr.total th,
      .quote-totals tr.total td {
        font-size: 16px;
        font-weight: 700;
      }
      .quote-service-section + .quote-service-section {
        margin-top: 24px;
      }
      .quote-service-section h2 {
        margin: 0 0 10px;
        font-size: 20px;
      }
      .quote-category-block + .quote-category-block {
        margin-top: 16px;
      }
      .quote-category-block h3 {
        margin: 0 0 8px;
        font-size: 15px;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }
      .empty {
        color: var(--muted);
        text-align: center;
      }
      @media print {
        body {
          background: #fff;
        }
        .quote-print {
          display: none;
        }
        .quote-shell {
          width: auto;
          margin: 0;
        }
        .quote-page {
          min-height: auto;
          padding: 0;
        }
      }
    </style>
  </head>
  <body>
    <div class="quote-shell">
      <section class="quote-page">
        <div class="quote-topbar">
          <div>
            <h1 class="quote-title">Devis</h1>
            <div class="quote-meta">
              <span><strong>Client :</strong> ${escapeHtml(quoteData.clientName || "-")}</span>
              <span><strong>Projet :</strong> ${escapeHtml(quoteData.projectName || "-")}</span>
              <span><strong>Date :</strong> ${escapeHtml(quoteData.generatedAt)}</span>
            </div>
          </div>
          <button type="button" class="quote-print" onclick="window.print()">Imprimer</button>
        </div>

        <table>
          <thead>
            <tr>
              <th>Service</th>
              <th>Total HT</th>
            </tr>
          </thead>
          <tbody>${summaryRowsHtml}</tbody>
        </table>

        <table class="quote-totals">
          <tbody>
            <tr>
              <th>Sous-total HT</th>
              <td class="amount">${escapeHtml(formatMoney(quoteData.subtotalHt))}</td>
            </tr>
            ${discountRowHtml}
            <tr>
              <th>Total HT</th>
              <td class="amount">${escapeHtml(formatMoney(quoteData.totalHt))}</td>
            </tr>
            <tr>
              <th>TVA 20 %</th>
              <td class="amount">${escapeHtml(formatMoney(quoteData.vatAmount))}</td>
            </tr>
            <tr class="total">
              <th>Total TTC</th>
              <td class="amount">${escapeHtml(formatMoney(quoteData.totalTtc))}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="quote-page">
        <h1 class="quote-title">Detail des services</h1>
        <div class="quote-meta">
          <span>Les lignes affichees ci-dessous ont une quantite et un montant superieurs a 0.</span>
        </div>
        ${detailSectionsHtml}
      </section>
    </div>
  </body>
</html>`;
      };

      const generateQuoteDocument = () => {
        const quoteData = collectQuoteData();
        const quoteWindow = window.open("", "_blank");
        if (!quoteWindow) return;
        quoteWindow.document.open();
        quoteWindow.document.write(buildQuoteDocumentHtml(quoteData));
        quoteWindow.document.close();
      };

      const applyClientData = (data) => {
        if (!data) return;
        scopeVmInput.value = data.perimetre?.vm ?? "";
        scopeVcpuInput.value = data.perimetre?.vcpu ?? "";
        scopeVcpuWindowsInput.value = data.perimetre?.vcpu_windows ?? "";
        scopeVramInput.value = data.perimetre?.vram ?? "";
        scopeVdiskInput.value = data.perimetre?.vdisk ?? "";
        scopeTransitInput.value = data.perimetre?.transit ?? "";
        scopeProjectNameInput.value = data.perimetre?.project_name ?? "";
        discountInput.value = data.discount ?? discountInput.value;
        renderSizingBlock(data.sizing || null);
        lines = Array.isArray(data.lines) ? data.lines : [];
        serviceItems = Array.isArray(data.serviceItems) ? data.serviceItems : [];
        collapsedServices = Array.isArray(data.collapsedServices)
          ? data.collapsedServices
          : [];
        renderServiceList();
        renderLines();
        applyTemplateQuantities(data.quantities);
      };

      const buildTransitEdgeTemplate = (
        serviceType,
        onChange,
        commitmentSource = ""
      ) => {
        const template = document.createElement("div");
        template.className = "service-template";
        const shouldApplyScopeDefaults =
          serviceType === "VPC - Flex" || serviceType === "VPC - Pay as you go";
        const commitmentMonths =
          serviceType === "VPC - Flex"
            ? extractCommitmentMonths(commitmentSource)
            : 0;

        const title = document.createElement("div");
        title.className = "template-title";
        title.textContent = "Transit Edge";
        template.appendChild(title);

        const content = document.createElement("div");
        content.className = "template-content";

        const table = document.createElement("table");
        table.className = "template-table";

        const head = document.createElement("thead");
        head.innerHTML = `
          <tr>
            <th>Description</th>
            <th>Detail</th>
            <th>Quantite</th>
            <th>Prix Unitaire</th>
            <th>Total € HT</th>
          </tr>
        `;
        table.appendChild(head);

        const body = document.createElement("tbody");
        const rows = getCatalogueRowsByCategory(serviceType, "Transit Edge", {
          months: commitmentMonths,
        }).map((rowData) => ({
          ...rowData,
          displayLabel:
            normalizeKey(rowData.label) === normalizeKey("Bande Passante Internet")
              && normalizeKey(rowData.detail).includes(normalizeKey("en Mbps"))
              ? "Bande Passante Internet (en Mbps)"
              : rowData.label,
        }));

        const hasTransit = Number(scopeTransitInput.value || 0) > 0;
        const scopeDefaults = {
          "Bande Passante Internet (en Mbps)": scopeTransitInput.value,
          "Bande Passante Internet": scopeTransitInput.value,
          Firewall: hasTransit ? 1 : undefined,
          "Adresse IPv4": hasTransit ? 1 : undefined,
        };
        if (rows.length === 0) return null;

        rows.forEach((rowData) => {
          const row = document.createElement("tr");
          const displayLabel = rowData.displayLabel || rowData.label;
          row.dataset.label = normalizeKey(displayLabel);
          const unitPrice = rowData.unitPrice;

          const descCell = document.createElement("td");
          descCell.textContent = displayLabel;
          row.appendChild(descCell);

          const detailCell = document.createElement("td");
          detailCell.textContent = rowData.detail;
          row.appendChild(detailCell);

          const qtyCell = document.createElement("td");
          const qtyInput = document.createElement("input");
          qtyInput.type = "number";
          qtyInput.min = "0";
          qtyInput.step = "1";
          if (shouldApplyScopeDefaults && scopeDefaults[displayLabel] !== undefined) {
            qtyInput.value = String(scopeDefaults[displayLabel] || 0);
          } else {
            qtyInput.value = "0";
          }
          qtyCell.appendChild(qtyInput);
          row.appendChild(qtyCell);

          const unitCell = document.createElement("td");
          unitCell.textContent = formatMoney(unitPrice);
          row.appendChild(unitCell);

          const totalCell = document.createElement("td");
          totalCell.textContent = formatMoney(0);
          row.appendChild(totalCell);

          const updateTotal = () => {
            const qty = Number(qtyInput.value || 0);
            totalCell.textContent = formatMoney(qty * unitPrice);
            if (onChange) onChange();
          };

          qtyInput.addEventListener("input", updateTotal);
          updateTotal();

          body.appendChild(row);
        });

        table.appendChild(body);
        template.appendChild(table);
        return template;
      };

      const buildComputeTemplate = (
        serviceType,
        onChange,
        commitmentSource = ""
      ) => {
        const template = document.createElement("div");
        template.className = "service-template";
        const shouldApplyScopeDefaults =
          serviceType === "VPC - Flex" || serviceType === "VPC - Pay as you go";
        const commitmentMonths =
          serviceType === "VPC - Flex"
            ? extractCommitmentMonths(commitmentSource)
            : 0;

        const title = document.createElement("div");
        title.className = "template-title";
        title.textContent = "Compute";
        template.appendChild(title);

        const table = document.createElement("table");
        table.className = "template-table";

        const head = document.createElement("thead");
        head.innerHTML = `
          <tr>
            <th>Description</th>
            <th>Detail</th>
            <th>Quantite</th>
            <th>Prix Unitaire</th>
            <th>Total € HT</th>
          </tr>
        `;
        table.appendChild(head);

        const body = document.createElement("tbody");
        const rows = getCatalogueRowsByCategory(serviceType, "Compute", {
          months: commitmentMonths,
        });
        const scopeDefaults = {
          vCPU: scopeVcpuInput.value,
          vRAM: scopeVramInput.value,
          vDisk: scopeVdiskInput.value,
        };
        if (rows.length === 0) return null;

        rows.forEach((rowData) => {
          const row = document.createElement("tr");
          const unitPrice = rowData.unitPrice;

          const descCell = document.createElement("td");
          descCell.textContent = rowData.label;
          row.appendChild(descCell);

          const detailCell = document.createElement("td");
          detailCell.textContent = rowData.detail;
          row.appendChild(detailCell);

          const qtyCell = document.createElement("td");
          const qtyInput = document.createElement("input");
          qtyInput.type = "number";
          qtyInput.min = "0";
          qtyInput.step = "1";
          if (shouldApplyScopeDefaults && scopeDefaults[rowData.label] !== undefined) {
            qtyInput.value = scopeDefaults[rowData.label] || "0";
          } else {
            qtyInput.value = "0";
          }
          qtyCell.appendChild(qtyInput);
          row.appendChild(qtyCell);

          const unitCell = document.createElement("td");
          unitCell.textContent = formatMoney(unitPrice);
          row.appendChild(unitCell);

          const totalCell = document.createElement("td");
          totalCell.textContent = formatMoney(0);
          row.appendChild(totalCell);

          const updateTotal = () => {
            const qty = Number(qtyInput.value || 0);
            totalCell.textContent = formatMoney(qty * unitPrice);
            if (onChange) onChange();
          };

          qtyInput.addEventListener("input", updateTotal);
          updateTotal();

          body.appendChild(row);
        });

        table.appendChild(body);
        template.appendChild(table);
        return template;
      };

      const buildOptionsTemplate = (
        serviceType,
        onChange,
        commitmentSource = ""
      ) => {
        const template = document.createElement("div");
        template.className = "service-template";
        const commitmentMonths =
          serviceType === "VPC - Flex"
            ? extractCommitmentMonths(commitmentSource)
            : 0;

        const title = document.createElement("div");
        title.className = "template-title";
        title.textContent = "Options";
        const toggleButton = document.createElement("button");
        toggleButton.type = "button";
        toggleButton.setAttribute("aria-label", "Masquer les options");
        const icon = document.createElement("span");
        icon.className = "template-icon eye";
        toggleButton.appendChild(icon);
        title.appendChild(toggleButton);
        template.appendChild(title);

        const content = document.createElement("div");
        content.className = "template-content";

        const table = document.createElement("table");
        table.className = "template-table";

        const head = document.createElement("thead");
        head.innerHTML = `
          <tr>
            <th>Description</th>
            <th>Detail</th>
            <th>Quantite</th>
            <th>Prix Unitaire</th>
            <th>Total € HT</th>
          </tr>
        `;
        table.appendChild(head);

        const body = document.createElement("tbody");
        const rows = getCatalogueRowsByCategory(serviceType, "Options", {
          months: commitmentMonths,
        });
        if (rows.length === 0) return null;

        rows.forEach((rowData) => {
          const row = document.createElement("tr");
          const unitPrice = rowData.unitPrice;

          const descCell = document.createElement("td");
          descCell.textContent = rowData.label;
          row.appendChild(descCell);

          const detailCell = document.createElement("td");
          detailCell.textContent = rowData.detail;
          row.appendChild(detailCell);

          const qtyCell = document.createElement("td");
          const qtyInput = document.createElement("input");
          qtyInput.type = "number";
          qtyInput.min = "0";
          qtyInput.step = "1";
          qtyInput.value = "0";
          qtyCell.appendChild(qtyInput);
          row.appendChild(qtyCell);

          const unitCell = document.createElement("td");
          unitCell.textContent = formatMoney(unitPrice);
          row.appendChild(unitCell);

          const totalCell = document.createElement("td");
          totalCell.textContent = formatMoney(0);
          row.appendChild(totalCell);

          const updateTotal = () => {
            const qty = Number(qtyInput.value || 0);
            totalCell.textContent = formatMoney(qty * unitPrice);
            if (onChange) onChange();
          };

          qtyInput.addEventListener("input", updateTotal);
          updateTotal();

          body.appendChild(row);
        });

        table.appendChild(body);
        content.appendChild(table);
        template.appendChild(content);

        toggleButton.addEventListener("click", () => {
          const isHidden = content.classList.toggle("is-hidden");
          icon.classList.toggle("closed", isHidden);
          toggleButton.setAttribute(
            "aria-label",
            isHidden ? "Afficher les options" : "Masquer les options"
          );
        });
        return template;
      };

      const buildProtectionTemplate = (
        serviceType,
        onChange,
        rowNames = null,
        commitmentSource = ""
      ) => {
        const template = document.createElement("div");
        template.className = "service-template";
        const commitmentMonths =
          serviceType === "VPC - Flex"
            ? extractCommitmentMonths(commitmentSource)
            : 0;

        const title = document.createElement("div");
        title.className = "template-title";
        title.textContent = "Protection";
        const toggleButton = document.createElement("button");
        toggleButton.type = "button";
        toggleButton.setAttribute("aria-label", "Masquer la protection");
        const icon = document.createElement("span");
        icon.className = "template-icon eye";
        toggleButton.appendChild(icon);
        title.appendChild(toggleButton);
        template.appendChild(title);

        const content = document.createElement("div");
        content.className = "template-content";

        const table = document.createElement("table");
        table.className = "template-table";

        const head = document.createElement("thead");
        head.innerHTML = `
          <tr>
            <th>Description</th>
            <th>Detail</th>
            <th>Quantite</th>
            <th>Prix Unitaire</th>
            <th>Total € HT</th>
          </tr>
        `;
        table.appendChild(head);

        const body = document.createElement("tbody");
        const rows = Array.isArray(rowNames) && rowNames.length > 0
          ? getCatalogueRowsByNames(serviceType, rowNames, {
              months: commitmentMonths,
            })
          : getCatalogueRowsByCategory(serviceType, "Protection", {
              months: commitmentMonths,
            });
        if (rows.length === 0) return null;

        rows.forEach((rowData) => {
          const row = document.createElement("tr");

          const descCell = document.createElement("td");
          descCell.textContent = rowData.label;
          row.appendChild(descCell);

          const detailCell = document.createElement("td");
          detailCell.textContent = rowData.detail;
          row.appendChild(detailCell);

          const qtyCell = document.createElement("td");
          const qtyInput = document.createElement("input");
          qtyInput.type = "number";
          qtyInput.min = "0";
          qtyInput.step = "1";
          qtyInput.value = "0";
          qtyCell.appendChild(qtyInput);
          row.appendChild(qtyCell);

          const unitCell = document.createElement("td");
          unitCell.textContent = formatMoney(rowData.unitPrice);
          row.appendChild(unitCell);

          const totalCell = document.createElement("td");
          totalCell.textContent = formatMoney(0);
          row.appendChild(totalCell);

          const updateTotal = () => {
            const qty = Number(qtyInput.value || 0);
            totalCell.textContent = formatMoney(qty * rowData.unitPrice);
            if (onChange) onChange();
            saveClientData();
          };

          qtyInput.addEventListener("input", updateTotal);
          updateTotal();

          body.appendChild(row);
        });

        table.appendChild(body);
        content.appendChild(table);
        template.appendChild(content);

        toggleButton.addEventListener("click", () => {
          const isHidden = content.classList.toggle("is-hidden");
          icon.classList.toggle("closed", isHidden);
          toggleButton.setAttribute(
            "aria-label",
            isHidden ? "Afficher la protection" : "Masquer la protection"
          );
        });
        return template;
      };

      const buildManagedServicesTemplate = (
        serviceType,
        onChange,
        commitmentSource = ""
      ) => {
        const template = document.createElement("div");
        template.className = "service-template";
        const commitmentMonths =
          serviceType === "VPC - Flex"
            ? extractCommitmentMonths(commitmentSource)
            : 0;

        const title = document.createElement("div");
        title.className = "template-title";
        title.textContent = "Services Managés";
        const toggleButton = document.createElement("button");
        toggleButton.type = "button";
        toggleButton.setAttribute("aria-label", "Masquer les services managés");
        const icon = document.createElement("span");
        icon.className = "template-icon eye";
        toggleButton.appendChild(icon);
        title.appendChild(toggleButton);
        template.appendChild(title);

        const content = document.createElement("div");
        content.className = "template-content";

        const table = document.createElement("table");
        table.className = "template-table";

        const head = document.createElement("thead");
        head.innerHTML = `
          <tr>
            <th>Description</th>
            <th>Detail</th>
            <th>Quantite</th>
            <th>Prix Unitaire</th>
            <th>Total € HT</th>
          </tr>
        `;
        table.appendChild(head);

        const body = document.createElement("tbody");
        const rows = getCatalogueRowsByCategory(
          serviceType,
          "Services Managés",
          { months: commitmentMonths }
        );
        if (rows.length === 0) return null;

        rows.forEach((rowData) => {
          const row = document.createElement("tr");

          const descCell = document.createElement("td");
          descCell.textContent = rowData.label;
          row.appendChild(descCell);

          const detailCell = document.createElement("td");
          detailCell.textContent = rowData.detail;
          row.appendChild(detailCell);

          const qtyCell = document.createElement("td");
          const qtyInput = document.createElement("input");
          qtyInput.type = "number";
          qtyInput.min = "0";
          qtyInput.step = "1";
          qtyInput.value = "0";
          qtyCell.appendChild(qtyInput);
          row.appendChild(qtyCell);

          const unitCell = document.createElement("td");
          unitCell.textContent = formatMoney(rowData.unitPrice);
          row.appendChild(unitCell);

          const totalCell = document.createElement("td");
          totalCell.textContent = formatMoney(0);
          row.appendChild(totalCell);

          const updateTotal = () => {
            const qty = Number(qtyInput.value || 0);
            totalCell.textContent = formatMoney(qty * rowData.unitPrice);
            if (onChange) onChange();
          };

          qtyInput.addEventListener("input", updateTotal);
          updateTotal();

          body.appendChild(row);
        });

        table.appendChild(body);
        content.appendChild(table);
        template.appendChild(content);

        toggleButton.addEventListener("click", () => {
          const isHidden = content.classList.toggle("is-hidden");
          icon.classList.toggle("closed", isHidden);
          toggleButton.setAttribute(
            "aria-label",
            isHidden ? "Afficher les services managés" : "Masquer les services managés"
          );
        });

        return template;
      };

      const buildManagedServicesCloudTemplate = (serviceType, onChange) => {
        const template = document.createElement("div");
        template.className = "service-template";

        const title = document.createElement("div");
        title.className = "template-title";
        title.textContent = "Managed services";
        const toggleButton = document.createElement("button");
        toggleButton.type = "button";
        toggleButton.setAttribute("aria-label", "Masquer les managed services");
        const icon = document.createElement("span");
        icon.className = "template-icon eye";
        toggleButton.appendChild(icon);
        title.appendChild(toggleButton);
        template.appendChild(title);

        const content = document.createElement("div");
        content.className = "template-content";

        const table = document.createElement("table");
        table.className = "template-table";

        const head = document.createElement("thead");
        head.innerHTML = `
          <tr>
            <th>Description</th>
            <th>Detail</th>
            <th>Quantite</th>
            <th>Prix Unitaire</th>
            <th>Total € HT</th>
          </tr>
        `;
        table.appendChild(head);

        const body = document.createElement("tbody");
        let rows = getCatalogueRowsByCategories(serviceType, [
          "Managed services Cloud",
          "Managed services",
          "Services Managés",
          "Services Manages",
        ]);
        if (rows.length === 0) {
          rows = getCatalogueRowsByNames(serviceType, ["MCO 24/7", "Console"]);
        }
        if (rows.length === 0) return null;

        const managedRowInputs = [];
        rows.forEach((rowData) => {
          const row = document.createElement("tr");

          const descCell = document.createElement("td");
          descCell.textContent = rowData.label;
          row.appendChild(descCell);

          const detailCell = document.createElement("td");
          detailCell.textContent = rowData.detail;
          row.appendChild(detailCell);

          const qtyCell = document.createElement("td");
          const qtyInput = document.createElement("input");
          qtyInput.type = "number";
          qtyInput.min = "0";
          qtyInput.step = "1";
          qtyInput.value = "0";
          qtyCell.appendChild(qtyInput);
          row.appendChild(qtyCell);

          const unitCell = document.createElement("td");
          unitCell.textContent = formatMoney(rowData.unitPrice);
          row.appendChild(unitCell);

          const totalCell = document.createElement("td");
          totalCell.textContent = formatMoney(0);
          row.appendChild(totalCell);

          const updateTotal = () => {
            const qty = Number(qtyInput.value || 0);
            totalCell.textContent = formatMoney(qty * rowData.unitPrice);
            if (onChange) onChange();
          };

          qtyInput.addEventListener("input", updateTotal);
          updateTotal();

          managedRowInputs.push({
            labelKey: normalizeKey(rowData.label),
            input: qtyInput,
          });
          body.appendChild(row);
        });

        const syncManagedServicesCloud = () => {
          const serviceBlock = template.closest(".service-block");
          if (!serviceBlock) return;
          const serverQuantity = getDedicatedServerQuantity(serviceBlock);
          const sizingBlock = findSizingBlockForServiceType(serviceType);
          const nPlusOne = getSizingBlockReferenceCount(sizingBlock);
          const referenceQuantity = serverQuantity > 0 ? serverQuantity : nPlusOne;

          managedRowInputs.forEach(({ labelKey, input }) => {
            let nextValue = input.value;
            if (labelKey.includes(normalizeKey("MCO 24/7")) || labelKey.includes("mco")) {
              nextValue = String(referenceQuantity);
            } else if (labelKey.includes(normalizeKey("Console"))) {
              nextValue = "1";
            } else {
              return;
            }

            if (input.value !== nextValue) {
              input.value = nextValue;
              input.dispatchEvent(new Event("input", { bubbles: true }));
            }
          });
        };

        table.appendChild(body);
        content.appendChild(table);
        template.appendChild(content);

        toggleButton.addEventListener("click", () => {
          const isHidden = content.classList.toggle("is-hidden");
          icon.classList.toggle("closed", isHidden);
          toggleButton.setAttribute(
            "aria-label",
            isHidden ? "Afficher les managed services" : "Masquer les managed services"
          );
        });

        requestAnimationFrame(() => {
          const serviceBlock = template.closest(".service-block");
          const serverTemplate = serviceBlock
            ? Array.from(serviceBlock.querySelectorAll(".service-template")).find((candidate) => {
                const candidateTitle = candidate.querySelector(".template-title");
                return candidateTitle && candidateTitle.textContent.trim().startsWith("Serveur");
              })
            : null;
          if (serverTemplate) {
            serverTemplate.querySelectorAll("input[type='number']").forEach((input) => {
              input.addEventListener("input", syncManagedServicesCloud);
            });
          }
          syncManagedServicesCloud();
        });

        return template;
      };

      const buildSizingTemplate = (initialState = null) => {
        const template = document.createElement("div");
        template.className = "service-template sizing-template-root";

        const content = document.createElement("div");
        content.className = "template-content";

        const grid = document.createElement("div");
        grid.className = "form-grid";

        const label = document.createElement("div");
        label.className = "sizing-label";
        const wrap = document.createElement("div");
        wrap.className = "sizing-wrap";

        let requestVmwareSync = null;

        const createStaticSizingBlock = (titleTextValue) => {
          const block = document.createElement("div");
          block.className = "sizing-block";

          const titleText = document.createElement("div");
          titleText.className = "sizing-title";
          titleText.textContent = titleTextValue;

          const resultEl = document.createElement("div");
          resultEl.className = "sizing-result sizing-info";
          const subtitle = document.createElement("span");
          subtitle.className = "sizing-subtitle";
          subtitle.textContent = " ";
          resultEl.appendChild(subtitle);

          const toggle = document.createElement("button");
          toggle.type = "button";
          toggle.className = "sizing-toggle sizing-block";
          toggle.appendChild(titleText);
          toggle.appendChild(resultEl);

          toggle.addEventListener("click", () => {
            const toggles = wrap.querySelectorAll(".sizing-toggle");
            toggles.forEach((btn) => btn.classList.remove("is-active"));
            toggle.classList.add("is-active");
            clearDedicatedActive();
            saveClientData();
          });

          block.appendChild(toggle);
          block._toggle = toggle;
          return block;
        };

        const createSizingBlock = (titleTextValue, cores) => {
          const block = document.createElement("div");
          block.className = "sizing-block";

          const titleText = document.createElement("div");
          titleText.className = "sizing-title";
          titleText.textContent = titleTextValue;

          const resultEl = document.createElement("div");
          resultEl.className = "sizing-result sizing-info";
          const subtitle = document.createElement("span");
          subtitle.className = "sizing-subtitle";
          subtitle.textContent = "";
          resultEl.appendChild(subtitle);
          const resultInput = document.createElement("input");
          resultInput.type = "text";
          resultInput.className = "sizing-input";
          resultInput.value = "0.00";
          const roundedEl = document.createElement("div");
          roundedEl.className = "sizing-rounded";
          roundedEl.textContent = "N+1: 0";

          const metrics = document.createElement("div");
          metrics.className = "sizing-metrics";
          metrics.appendChild(resultInput);
          metrics.appendChild(roundedEl);

          const chartWrap = document.createElement("div");
          chartWrap.className = "sizing-info";

          const chart = document.createElement("div");
          chart.className = "sizing-chart";
          chartWrap.appendChild(chart);

          const legend = document.createElement("div");
          legend.className = "sizing-legend";
          const maxRow = document.createElement("div");
          maxRow.className = "legend-row";
          const maxSwatch = document.createElement("span");
          maxSwatch.className = "legend-swatch";
          maxSwatch.style.background = "#7cb342";
          const maxText = document.createElement("span");
          maxText.textContent = "Quantite max: 0";
          maxRow.appendChild(maxSwatch);
          maxRow.appendChild(maxText);

          const occRow = document.createElement("div");
          occRow.className = "legend-row";
          const occSwatch = document.createElement("span");
          occSwatch.className = "legend-swatch";
          occSwatch.style.background = "#1e88e5";
          const occText = document.createElement("span");
          occText.textContent = "Quantite occupee: 0";
          occRow.appendChild(occSwatch);
          occRow.appendChild(occText);

          legend.appendChild(maxRow);
          legend.appendChild(occRow);
          chartWrap.appendChild(legend);

          const memoText = document.createElement("div");
          memoText.className = "sizing-memo";
          memoText.textContent = "Quantite de memoire par serveur: 0.00";

          const updateFromValue = () => {
            const vcpu = getScopedVcpuTotal();
            const baseValue = parseNumeric(resultInput.value);
            const nPlusOne = Number.isFinite(baseValue)
              ? Math.ceil(baseValue) + 1
              : "";
            const qtyMax = nPlusOne ? nPlusOne * cores * 4 : 0;
            const occupiedPercent =
              qtyMax > 0 ? Math.min(100, (vcpu * 100) / qtyMax) : 0;
            const occupiedAngle = (occupiedPercent / 100) * 360;
            roundedEl.textContent = `N+1: ${nPlusOne}`;
            chart.style.background = `conic-gradient(#1e88e5 0deg ${occupiedAngle}deg, #7cb342 ${occupiedAngle}deg 360deg)`;
            maxText.textContent = `Quantite max: ${qtyMax}`;
            occText.textContent = `Quantite occupee: ${occupiedPercent.toFixed(1)}%`;
            const vram = parseNumeric(scopeVramInput.value);
            const calcValue = parseNumeric(resultInput.value);
            const roundedCalc = Number.isFinite(calcValue)
              ? Math.ceil(calcValue)
              : 0;
            const memPerServer =
              roundedCalc && Number.isFinite(vram) ? vram / roundedCalc : 0;
            memoText.textContent = `Quantite de memoire par serveur: ${memPerServer.toFixed(2)}`;
          };

          const updateResult = () => {
            const vcpu = getScopedVcpuTotal();
            const value = vcpu / cores / 4;
            const text = Number.isFinite(value) ? value.toFixed(2) : "";
            resultInput.value = text;
            updateFromValue();
          };

          const updateFromManual = () => {
            updateFromValue();
          };

          scopeVcpuInput.addEventListener("input", updateResult);
          scopeVramInput.addEventListener("input", () => {
            updateFromValue();
          });
          resultInput.addEventListener("input", updateFromManual);
          updateResult();

          const toggle = document.createElement("button");
          toggle.type = "button";
          toggle.className = "sizing-toggle sizing-block";
          toggle.appendChild(titleText);
          toggle.appendChild(resultEl);
          const applyServerSizing = () => {
            const isMono = normalizeKey(titleTextValue).includes("mono");
            const isBi = normalizeKey(titleTextValue).includes("bi");
            if (!isMono && !isBi) return;
            const targetServiceType = "VPC - Dedicated SS";
            const serviceBlock = findSizingServiceBlock(targetServiceType);
            if (!serviceBlock) return;
            const serverTemplate = getDedicatedServerTemplate(serviceBlock);
            if (!serverTemplate) return;
            const rows = Array.from(serverTemplate.querySelectorAll("tbody tr"));
            const findRowInputByHint = (hint) => {
              const target = normalizeKey(hint);
              const row = rows.find((r) => {
                const cell = r.querySelector("td");
                return cell && normalizeKey(cell.textContent).includes(target);
              });
              return row ? row.querySelector("input[type='number']") : null;
            };
            const input512 = findRowInputByHint("512 Go");
            const input768 = findRowInputByHint("768 Go");
            const input1024 = findRowInputByHint("1024 Go");
            const input1536 = findRowInputByHint("1536 Go");
            const manual = parseNumeric(resultInput.value);
            const nPlusOne = getSizingBlockReferenceCount(block);
            const vram = parseNumeric(scopeVramInput.value);
            const roundedCalc = Number.isFinite(manual) ? Math.ceil(manual) : 0;
            const memPerServer =
              roundedCalc && Number.isFinite(vram) ? vram / roundedCalc : 0;

            if (input512) input512.value = "0";
            if (input768) input768.value = "0";
            if (input1024) input1024.value = "0";
            if (input1536) input1536.value = "0";

            if (isMono) {
              if (memPerServer <= 512 && input512) {
                input512.value = String(nPlusOne);
                input512.dispatchEvent(new Event("input", { bubbles: true }));
              } else if (memPerServer >= 513 && memPerServer <= 768 && input768) {
                input768.value = String(nPlusOne);
                input768.dispatchEvent(new Event("input", { bubbles: true }));
              } else if (memPerServer >= 769 && memPerServer <= 1024 && input1024) {
                input1024.value = String(nPlusOne);
                input1024.dispatchEvent(new Event("input", { bubbles: true }));
              } else if (memPerServer >= 1025 && memPerServer <= 1536 && input1536) {
                input1536.value = String(nPlusOne);
                input1536.dispatchEvent(new Event("input", { bubbles: true }));
              }
            }

            if (isBi) {
              if (memPerServer <= 1024 && input1024) {
                input1024.value = String(nPlusOne);
                input1024.dispatchEvent(new Event("input", { bubbles: true }));
              } else if (memPerServer > 1024 && memPerServer <= 1536 && input1536) {
                input1536.value = String(nPlusOne);
                input1536.dispatchEvent(new Event("input", { bubbles: true }));
              } else if (memPerServer > 1536) {
                const fallbackInput = input1536 || input1024;
                if (fallbackInput) {
                  fallbackInput.value = String(nPlusOne);
                  fallbackInput.dispatchEvent(new Event("input", { bubbles: true }));
                }
              }
            }
            saveClientData();
            if (requestVmwareSync) requestVmwareSync(targetServiceType, block);
          };
          block._applyServerSizing = applyServerSizing;

          toggle.addEventListener("click", () => {
            const toggles = wrap.querySelectorAll(".sizing-toggle");
            toggles.forEach((btn) => btn.classList.remove("is-active"));
            toggle.classList.add("is-active");
            clearMutualActive();
            updateResult();
            applyServerSizing();
            saveClientData();
          });

          resultInput.addEventListener("input", () => {
            applyServerSizing();
            saveClientData();
          });

          block.appendChild(toggle);
          block._toggle = toggle;
          const detailsWrap = document.createElement("div");
          detailsWrap.className = "sizing-details";
          detailsWrap.appendChild(metrics);
          detailsWrap.appendChild(memoText);
          detailsWrap.appendChild(chartWrap);
          block._details = detailsWrap;
          block._resultInput = resultInput;
          return block;
        };

        const besoinBlock = createStaticSizingBlock("Pool de ressources");
        const besoinPcaBlock = createStaticSizingBlock("Pool de ressources PCA");
        const monoBlock = createSizingBlock("IaaS - Serveurs mono processeurs", 16);
        const biBlock = createSizingBlock("IaaS - Serveurs bi processeurs", 32);
        // No default active sizing selection on init.
        const mutualGroup = document.createElement("div");
        mutualGroup.className = "sizing-group";
        const buildCreatedServiceName = (baseServiceName) => {
          const parts = [
            clientNameInput.value.trim(),
            scopeProjectNameInput.value.trim(),
            String(baseServiceName || "").trim(),
          ].filter((value) => value !== "");
          return parts.join(" - ");
        };
        const mutualTitle = document.createElement("div");
        mutualTitle.className = "sizing-group-title";
        mutualTitle.textContent = "Ressources mutualisées";
        mutualGroup.appendChild(mutualTitle);
        const mutualPoolsRow = document.createElement("div");
        mutualPoolsRow.className = "sizing-mutual-pools";
        mutualPoolsRow.appendChild(besoinBlock);
        mutualPoolsRow.appendChild(besoinPcaBlock);
        mutualGroup.appendChild(mutualPoolsRow);
        const commitmentButtons = document.createElement("div");
        commitmentButtons.className = "sizing-commitments";
        const commitmentLabels = [
          "Sans engagement",
          "12 mois",
          "24 mois",
          "36 mois",
          "48 mois",
          "60 mois",
        ];
        const commitmentBtnList = commitmentLabels.map((labelText, index) => {
          const btn = document.createElement("button");
          btn.type = "button";
          btn.className = "btn secondary";
          if (index === 0) btn.classList.add("is-active");
          btn.textContent = labelText;
          btn.addEventListener("click", () => {
            clearDedicatedActive();
            commitmentBtnList.forEach((candidate) =>
              candidate.classList.toggle("is-active", candidate === btn)
            );
            saveClientData();
          });
          commitmentButtons.appendChild(btn);
          return btn;
        });
        mutualGroup.appendChild(commitmentButtons);
        const commitmentCreateBtn = document.createElement("button");
        commitmentCreateBtn.type = "button";
        commitmentCreateBtn.className = "btn sizing-commitments-create";
        commitmentCreateBtn.textContent = "Créer";
        commitmentCreateBtn.addEventListener("click", () => {
          const isPoolSelected = besoinBlock._toggle?.classList.contains("is-active");
          if (!isPoolSelected) return;
          const activeCommitment = commitmentBtnList.find((btn) =>
            btn.classList.contains("is-active")
          );
          const label = normalizeKey(activeCommitment?.textContent || "");
          const serviceName = buildCreatedServiceName("VPC");
          if (label.includes("sans engagement")) {
            if (typeof addServiceById === "function") {
              addServiceById("vpc-payg", {
                name: serviceName,
                detail: buildCommitmentDetailLabel(label),
              });
            }
            return;
          }
          const serviceMap = {
            "12 mois": "vpc-flex-12",
            "24 mois": "vpc-flex-24",
            "36 mois": "vpc-flex-36",
            "48 mois": "vpc-flex-48",
            "60 mois": "vpc-flex-60",
          };
          const serviceId = Object.entries(serviceMap).find(([key]) =>
            label.includes(key)
          )?.[1];
          if (!serviceId || typeof addServiceById !== "function") return;
          addServiceById(serviceId, {
            name: serviceName,
            detail: buildCommitmentDetailLabel(activeCommitment?.textContent || ""),
          });
        });
        mutualGroup.appendChild(commitmentCreateBtn);

        const dedicatedGroup = document.createElement("div");
        dedicatedGroup.className = "sizing-group is-wide";
        const dedicatedTitle = document.createElement("div");
        dedicatedTitle.className = "sizing-group-title";
        dedicatedTitle.textContent = "Ressources dédiées";
        dedicatedGroup.appendChild(dedicatedTitle);
        const dedicatedButtonsRow = document.createElement("div");
        dedicatedButtonsRow.className = "sizing-button-row";
        const buttons = document.createElement("div");
        buttons.className = "sizing-buttons";
        const platformRow = document.createElement("div");
        platformRow.className = "sizing-buttons-row";
        const vmwarePair = document.createElement("div");
        vmwarePair.className = "sizing-buttons-pair";
        const storageRow = document.createElement("div");
        storageRow.className = "sizing-buttons-row";
        const storagePair = document.createElement("div");
        storagePair.className = "sizing-buttons-pair";
        const vmwareBtn = document.createElement("button");
        vmwareBtn.type = "button";
        vmwareBtn.className = "btn secondary";
        vmwareBtn.textContent = "VMware";
        const microsoftDcBtn = document.createElement("button");
        microsoftDcBtn.type = "button";
        microsoftDcBtn.className = "btn secondary";
        microsoftDcBtn.textContent = "Microsoft DC";
        const hypervBtn = document.createElement("button");
        hypervBtn.type = "button";
        hypervBtn.className = "btn secondary";
        hypervBtn.textContent = "Hyper-V";
        const ssdBtn = document.createElement("button");
        ssdBtn.type = "button";
        ssdBtn.className = "btn secondary";
        ssdBtn.textContent = "SSD";
        const nvmeBtn = document.createElement("button");
        nvmeBtn.type = "button";
        nvmeBtn.className = "btn secondary";
        nvmeBtn.textContent = "NVME";
        const setPlatformActive = (activeBtn) => {
          [vmwareBtn, hypervBtn].forEach((btn) =>
            btn.classList.toggle("is-active", btn === activeBtn)
          );
          if (activeBtn !== vmwareBtn) {
            microsoftDcBtn.classList.remove("is-active");
          }
        };
        const setStorageActive = (activeBtn) => {
          [ssdBtn, nvmeBtn].forEach((btn) =>
            btn.classList.toggle("is-active", btn === activeBtn)
          );
        };
        const toggleStorageActive = (targetBtn) => {
          const isActive = targetBtn.classList.contains("is-active");
          if (isActive) {
            setStorageActive(null);
            return;
          }
          setStorageActive(targetBtn);
        };
        function clearMutualActive() {
          [besoinBlock._toggle, besoinPcaBlock._toggle, ...commitmentBtnList]
            .forEach((btn) => btn && btn.classList.remove("is-active"));
        }
        function clearDedicatedActive() {
          [monoBlock._toggle, biBlock._toggle, vmwareBtn, microsoftDcBtn, hypervBtn, core16Btn]
            .forEach((btn) => btn && btn.classList.remove("is-active"));
        }
        const persistSizingState = () => {
          saveClientData();
        };
        const applyDedicatedLicences = (preferredServiceType = "", sourceSizingBlock = null) => {
          const vmwareActive = vmwareBtn.classList.contains("is-active");
          const hypervActive = hypervBtn.classList.contains("is-active");
          const microsoftDcActive = microsoftDcBtn.classList.contains("is-active");
          if (!vmwareActive && !hypervActive) return;
          const activeToggle = wrap.querySelector(".sizing-toggle.is-active");
          const activeTitleText =
            activeToggle?.querySelector(".sizing-title")?.textContent || "";
          const activeTitleKey = normalizeKey(activeTitleText);
          const targetServiceType = preferredServiceType || (
            activeTitleKey.includes("mono")
              ? "VPC - Dedicated SS"
              : activeTitleKey.includes("bi")
                ? "VPC - Dedicated SS"
                : ""
          );
          if (!targetServiceType) return;
          const activeBlock =
            sourceSizingBlock || findSizingBlockForServiceType(targetServiceType);
          if (!activeBlock) return;
          const titleText =
            activeBlock.querySelector(".sizing-title")?.textContent || "";
          const titleKey = normalizeKey(titleText);
          const serviceBlock = findSizingServiceBlock(targetServiceType);
          if (!serviceBlock) return;
          const nPlusOne = getSizingBlockReferenceCount(activeBlock);
          const serverTemplate = getDedicatedServerTemplate(serviceBlock);
          const serverRows = serverTemplate
            ? Array.from(serverTemplate.querySelectorAll("tbody tr"))
            : [];
          const activeServerRow = serverRows.find((row) => {
            const qtyInput = row.querySelector("input[type='number']");
            return Number(qtyInput?.value || 0) > 0;
          });
          const serverQuantity = getDedicatedServerQuantity(serviceBlock) || nPlusOne;
          if (serverQuantity <= 0) return;

          let coreMultiplier = 0;
          if (vmwareActive) {
            if (core16Btn.classList.contains("is-active")) {
              coreMultiplier = 16;
            } else if (titleKey.includes("mono")) {
              coreMultiplier = 16;
            } else if (titleKey.includes("bi")) {
              coreMultiplier = 32;
            }
          } else if (hypervActive && (titleKey.includes("mono") || titleKey.includes("bi"))) {
            coreMultiplier = 16;
          }

          if (!coreMultiplier) {
            const label =
              activeServerRow?.querySelector("td")?.textContent?.trim() || "";
            const labelKey = normalizeKey(label);
            if (vmwareActive) {
              if (labelKey.includes("16 coeurs")) coreMultiplier = 16;
              if (labelKey.includes("32 coeurs")) coreMultiplier = 32;
            } else if (hypervActive && labelKey.includes("16 coeurs")) {
              coreMultiplier = 16;
            }
          }

          if (!coreMultiplier) return;

          const licencesTemplate = Array.from(
            serviceBlock.querySelectorAll(".service-template")
          ).find((template) => {
            const title = template.querySelector(".template-title");
            return title && title.textContent.trim().startsWith("Licences");
          });
          if (!licencesTemplate) return;
          const rows = Array.from(licencesTemplate.querySelectorAll("tbody tr"));
          const vmwareRow = rows.find((row) => {
            const cell = row.querySelector("td");
            if (!cell) return false;
            return normalizeKey(cell.textContent).includes(
              normalizeKey("VMware Cloud Foundation")
            );
          });
          const microsoftDcRow = rows.find((row) => {
            const cell = row.querySelector("td");
            if (!cell) return false;
            return normalizeKey(cell.textContent).includes(
              normalizeKey("Microsoft Windows Server Datacenter - SPLA JN")
            );
          });
          const hypervRow = rows.find((row) => {
            const cell = row.querySelector("td");
            if (!cell) return false;
            return normalizeKey(cell.textContent).includes(
              normalizeKey(
                "Microsoft Windows Server Core Infrastructure Suite Datacenter - SCVMM - SPLA"
              )
            );
          });
          const setRowQuantity = (row, quantity) => {
            const qtyInput = row?.querySelector("input[type='number']");
            if (!qtyInput) return;
            qtyInput.value = String(quantity);
            qtyInput.dispatchEvent(new Event("input", { bubbles: true }));
          };
          setRowQuantity(vmwareRow, 0);
          setRowQuantity(microsoftDcRow, 0);
          setRowQuantity(hypervRow, 0);
          if (vmwareActive) {
            setRowQuantity(vmwareRow, serverQuantity * coreMultiplier);
          }
          if (vmwareActive && microsoftDcActive) {
            setRowQuantity(microsoftDcRow, (serverQuantity * coreMultiplier) / 2);
          }
          if (hypervActive) {
            setRowQuantity(hypervRow, (serverQuantity * coreMultiplier) / 2);
          }
          saveClientData();
        };

        const applyDedicatedStorage = (preferredServiceType = "", sourceSizingBlock = null) => {
          const ssdActive = ssdBtn.classList.contains("is-active");
          const nvmeActive = nvmeBtn.classList.contains("is-active");
          if (!ssdActive && !nvmeActive) return;

          const activeBlock = sourceSizingBlock || wrap.querySelector(".sizing-block .sizing-toggle.is-active")?.closest(".sizing-block");
          const titleText =
            activeBlock?.querySelector(".sizing-title")?.textContent || "";
          const titleKey = normalizeKey(titleText);
          const targetServiceType = preferredServiceType || (
            titleKey.includes("mono")
              ? "VPC - Dedicated SS"
              : titleKey.includes("bi")
                ? "VPC - Dedicated SS"
                : ""
          );
          if (!targetServiceType) return;

          const serviceBlock = findSizingServiceBlock(targetServiceType);
          if (!serviceBlock) return;
          const storageTemplate = Array.from(
            serviceBlock.querySelectorAll(".service-template")
          ).find((template) => {
            const title = template.querySelector(".template-title");
            return title && title.textContent.trim().startsWith("Stockage");
          });
          if (!storageTemplate) return;

          const rows = Array.from(storageTemplate.querySelectorAll("tbody tr"));
          const ssdRow = rows.find((row) => {
            const cell = row.querySelector("td");
            if (!cell) return false;
            return normalizeKey(cell.textContent).includes(
              normalizeKey("SSD SHARED-NFS-SSD-MONO-1M")
            );
          });
          const nvmeRow = rows.find((row) => {
            const cell = row.querySelector("td");
            if (!cell) return false;
            return normalizeKey(cell.textContent).includes(
              normalizeKey("NVME SHARED-NFS-NVME-MONO-1M")
            );
          });
          const setRowQuantity = (row, quantity) => {
            const qtyInput = row?.querySelector("input[type='number']");
            if (!qtyInput) return;
            qtyInput.value = String(quantity);
            qtyInput.dispatchEvent(new Event("input", { bubbles: true }));
          };
          const vdiskQuantity = parseNumeric(scopeVdiskInput.value || 0);
          const nextQuantity = Number.isFinite(vdiskQuantity) ? vdiskQuantity : 0;

          setRowQuantity(ssdRow, 0);
          setRowQuantity(nvmeRow, 0);
          if (ssdActive) {
            setRowQuantity(ssdRow, nextQuantity);
          }
          if (nvmeActive) {
            setRowQuantity(nvmeRow, nextQuantity);
          }
          saveClientData();
        };

        requestVmwareSync = applyDedicatedLicences;

        vmwareBtn.addEventListener("click", () => {
          clearMutualActive();
          setPlatformActive(vmwareBtn);
          core16Btn.classList.add("is-active");
          applyDedicatedLicences();
          persistSizingState();
        });
        microsoftDcBtn.addEventListener("click", () => {
          if (!vmwareBtn.classList.contains("is-active")) return;
          clearMutualActive();
          microsoftDcBtn.classList.toggle("is-active");
          core16Btn.classList.add("is-active");
          applyDedicatedLicences();
          persistSizingState();
        });
        hypervBtn.addEventListener("click", () => {
          clearMutualActive();
          setPlatformActive(hypervBtn);
          core16Btn.classList.add("is-active");
          applyDedicatedLicences();
          persistSizingState();
        });
        ssdBtn.addEventListener("click", () => {
          clearMutualActive();
          toggleStorageActive(ssdBtn);
          persistSizingState();
        });
        nvmeBtn.addEventListener("click", () => {
          clearMutualActive();
          toggleStorageActive(nvmeBtn);
          persistSizingState();
        });
        vmwarePair.appendChild(vmwareBtn);
        vmwarePair.appendChild(microsoftDcBtn);
        platformRow.appendChild(vmwarePair);
        platformRow.appendChild(hypervBtn);
        storagePair.appendChild(ssdBtn);
        storagePair.appendChild(nvmeBtn);
        storageRow.appendChild(storagePair);
        buttons.appendChild(platformRow);
        buttons.appendChild(storageRow);

        const createBtn = document.createElement("button");
        createBtn.type = "button";
        createBtn.className = "btn";
        createBtn.textContent = "Créer";
        const core16Btn = document.createElement("button");
        core16Btn.type = "button";
        core16Btn.className = "btn secondary is-active";
        core16Btn.textContent = "16 coeurs";
        core16Btn.addEventListener("click", () => {
          clearMutualActive();
          core16Btn.classList.add("is-active");
          persistSizingState();
        });
        createBtn.addEventListener("click", () => {
          const monoActive = monoBlock._toggle?.classList.contains("is-active");
          const biActive = biBlock._toggle?.classList.contains("is-active");
          if (!monoActive && !biActive) return;

          const serviceId = "vpc-ss";
          const targetServiceType = "VPC - Dedicated SS";
          const targetSizingBlock = monoActive ? monoBlock : biBlock;
          const createdServiceName = buildCreatedServiceName(
            targetServiceType
          );
          if (typeof addServiceById === "function") {
            addServiceById(serviceId, {
              name: createdServiceName,
            });
          }

          requestAnimationFrame(() => {
            if (typeof targetSizingBlock._applyServerSizing === "function") {
              targetSizingBlock._applyServerSizing();
            }
            if (
              vmwareBtn.classList.contains("is-active") ||
              hypervBtn.classList.contains("is-active")
            ) {
              applyDedicatedLicences(targetServiceType, targetSizingBlock);
            }
            applyDedicatedStorage(targetServiceType, targetSizingBlock);
          });
        });

        const actions = document.createElement("div");
        actions.className = "sizing-actions";
        actions.appendChild(core16Btn);
        actions.appendChild(buttons);
        actions.appendChild(createBtn);

        dedicatedButtonsRow.appendChild(monoBlock);
        dedicatedButtonsRow.appendChild(biBlock);
        dedicatedButtonsRow.appendChild(actions);
        dedicatedGroup.appendChild(dedicatedButtonsRow);
        const dedicatedDivider = document.createElement("div");
        dedicatedDivider.className = "sizing-divider";
        const advancedToggle = document.createElement("button");
        advancedToggle.type = "button";
        advancedToggle.textContent = "Avancé";
        advancedToggle.setAttribute("aria-expanded", "true");
        dedicatedDivider.appendChild(advancedToggle);
        dedicatedGroup.appendChild(dedicatedDivider);
        const dedicatedDetailsRow = document.createElement("div");
        dedicatedDetailsRow.className = "sizing-details-row is-hidden";
        const monoDetailsPanel = document.createElement("div");
        monoDetailsPanel.className = "sizing-details-panel";
        const monoDetailsTitle = document.createElement("p");
        monoDetailsTitle.className = "sizing-details-panel-title";
        monoDetailsTitle.textContent = "Serveurs mono";
        monoDetailsPanel.appendChild(monoDetailsTitle);
        monoDetailsPanel.appendChild(monoBlock._details);

        const biDetailsPanel = document.createElement("div");
        biDetailsPanel.className = "sizing-details-panel";
        const biDetailsTitle = document.createElement("p");
        biDetailsTitle.className = "sizing-details-panel-title";
        biDetailsTitle.textContent = "Serveurs bipro";
        biDetailsPanel.appendChild(biDetailsTitle);
        biDetailsPanel.appendChild(biBlock._details);

        dedicatedDetailsRow.appendChild(monoDetailsPanel);
        dedicatedDetailsRow.appendChild(biDetailsPanel);
        dedicatedGroup.appendChild(dedicatedDetailsRow);

        advancedToggle.setAttribute("aria-expanded", "false");
        advancedToggle.addEventListener("click", () => {
          const isHidden = dedicatedDetailsRow.classList.toggle("is-hidden");
          advancedToggle.setAttribute("aria-expanded", String(!isHidden));
          persistSizingState();
        });

        wrap._besoinBlock = besoinBlock;
        wrap._besoinPcaBlock = besoinPcaBlock;
        wrap._monoBlock = monoBlock;
        wrap._biBlock = biBlock;
        wrap._vmwareBtn = vmwareBtn;
        wrap._microsoftDcBtn = microsoftDcBtn;
        wrap._hypervBtn = hypervBtn;
        wrap._core16Btn = core16Btn;
        wrap._ssdBtn = ssdBtn;
        wrap._nvmeBtn = nvmeBtn;
        wrap._advancedToggle = advancedToggle;
        wrap._dedicatedDetailsRow = dedicatedDetailsRow;

        if (initialState && typeof initialState === "object") {
          if (typeof initialState.monoValue === "string") {
            monoBlock._resultInput.value = initialState.monoValue;
          }
          if (typeof initialState.biValue === "string") {
            biBlock._resultInput.value = initialState.biValue;
          }

          [besoinBlock._toggle, besoinPcaBlock._toggle, monoBlock._toggle, biBlock._toggle]
            .forEach((btn) => btn && btn.classList.remove("is-active"));
          if (initialState.activeMode === "pool") besoinBlock._toggle.classList.add("is-active");
          if (initialState.activeMode === "pool-pca") besoinPcaBlock._toggle.classList.add("is-active");
          if (initialState.activeMode === "mono") monoBlock._toggle.classList.add("is-active");
          if (initialState.activeMode === "bi") biBlock._toggle.classList.add("is-active");

          setPlatformActive(null);
          if (initialState.vmware) {
            setPlatformActive(vmwareBtn);
          } else if (initialState.hyperv) {
            setPlatformActive(hypervBtn);
          }
          microsoftDcBtn.classList.toggle(
            "is-active",
            Boolean(initialState.vmware && initialState.microsoftDc)
          );
          core16Btn.classList.toggle("is-active", Boolean(initialState.core16));
          setStorageActive(null);
          if (initialState.ssd) setStorageActive(ssdBtn);
          if (initialState.nvme) setStorageActive(nvmeBtn);
          const isAdvancedOpen = Boolean(initialState.advancedOpen);
          dedicatedDetailsRow.classList.toggle("is-hidden", !isAdvancedOpen);
          advancedToggle.setAttribute("aria-expanded", String(isAdvancedOpen));
        }

        wrap.appendChild(mutualGroup);
        wrap.appendChild(dedicatedGroup);
        label.appendChild(wrap);
        grid.appendChild(label);

        content.appendChild(grid);
        template.appendChild(content);
        return template;
      };

      const buildServerTemplate = (serviceType, onChange) => {
        const template = document.createElement("div");
        template.className = "service-template";
        const rows = getCatalogueRowsByNamePrefix(serviceType, "Serveur");
        if (rows.length === 0) return null;

        const title = document.createElement("div");
        title.className = "template-title";
        title.textContent = "Serveur";
        template.appendChild(title);

        const table = document.createElement("table");
        table.className = "template-table";

        const head = document.createElement("thead");
        head.innerHTML = `
          <tr>
            <th>Description</th>
            <th>Detail</th>
            <th>Quantite</th>
            <th>Prix Unitaire</th>
            <th>Total € HT</th>
          </tr>
        `;
        table.appendChild(head);

        const body = document.createElement("tbody");

        rows.forEach((rowData) => {
          const row = document.createElement("tr");

          const descCell = document.createElement("td");
          descCell.textContent = rowData.label;
          row.appendChild(descCell);

          const detailCell = document.createElement("td");
          detailCell.textContent = rowData.detail;
          row.appendChild(detailCell);

          const qtyCell = document.createElement("td");
          const qtyInput = document.createElement("input");
          qtyInput.type = "number";
          qtyInput.min = "0";
          qtyInput.step = "1";
          qtyInput.value = "0";
          qtyCell.appendChild(qtyInput);
          row.appendChild(qtyCell);

          const unitCell = document.createElement("td");
          unitCell.textContent = formatMoney(rowData.unitPrice);
          row.appendChild(unitCell);

          const totalCell = document.createElement("td");
          totalCell.textContent = formatMoney(0);
          row.appendChild(totalCell);

          const updateTotal = () => {
            const qty = Number(qtyInput.value || 0);
            totalCell.textContent = formatMoney(qty * rowData.unitPrice);
            if (onChange) onChange();
          };

          qtyInput.addEventListener("input", updateTotal);
          updateTotal();

          body.appendChild(row);
        });

        table.appendChild(body);
        template.appendChild(table);
        return template;
      };

      const buildStorageTemplate = (serviceType, onChange) => {
        const template = document.createElement("div");
        template.className = "service-template";
        const rows = getDedicatedStorageCatalogueRows(serviceType);
        if (rows.length === 0) return null;

        const title = document.createElement("div");
        title.className = "template-title";
        title.textContent = "Stockage";
        template.appendChild(title);

        const table = document.createElement("table");
        table.className = "template-table";

        const head = document.createElement("thead");
        head.innerHTML = `
          <tr>
            <th>Description</th>
            <th>Detail</th>
            <th>Quantite</th>
            <th>Prix Unitaire</th>
            <th>Total € HT</th>
          </tr>
        `;
        table.appendChild(head);

        const body = document.createElement("tbody");

        rows.forEach((rowData) => {
          const row = document.createElement("tr");

          const descCell = document.createElement("td");
          descCell.textContent = rowData.label;
          row.appendChild(descCell);

          const detailCell = document.createElement("td");
          detailCell.textContent = rowData.detail;
          row.appendChild(detailCell);

          const qtyCell = document.createElement("td");
          const qtyInput = document.createElement("input");
          qtyInput.type = "number";
          qtyInput.min = "0";
          qtyInput.step = "1";
          qtyInput.value = "0";
          qtyCell.appendChild(qtyInput);
          row.appendChild(qtyCell);

          const unitCell = document.createElement("td");
          unitCell.textContent = formatMoney(rowData.unitPrice);
          row.appendChild(unitCell);

          const totalCell = document.createElement("td");
          totalCell.textContent = formatMoney(0);
          row.appendChild(totalCell);

          const updateTotal = () => {
            const qty = Number(qtyInput.value || 0);
            totalCell.textContent = formatMoney(qty * rowData.unitPrice);
            if (onChange) onChange();
          };

          qtyInput.addEventListener("input", updateTotal);
          updateTotal();

          body.appendChild(row);
        });

        table.appendChild(body);
        template.appendChild(table);
        return template;
      };

      const buildLicencesTemplate = (serviceType, onChange) => {
        const template = document.createElement("div");
        template.className = "service-template";
        const rows = getCatalogueRowsByCategories(serviceType, [
          "Licence",
          "Licences",
        ]);
        if (rows.length === 0) return null;

        const title = document.createElement("div");
        title.className = "template-title";
        title.textContent = "Licences";
        template.appendChild(title);

        const table = document.createElement("table");
        table.className = "template-table";

        const head = document.createElement("thead");
        head.innerHTML = `
          <tr>
            <th>Description</th>
            <th>Detail</th>
            <th>Quantite</th>
            <th>Prix Unitaire</th>
            <th>Total € HT</th>
          </tr>
        `;
        table.appendChild(head);

        const body = document.createElement("tbody");

        rows.forEach((rowData) => {
          const row = document.createElement("tr");

          const descCell = document.createElement("td");
          descCell.textContent = rowData.label;
          row.appendChild(descCell);

          const detailCell = document.createElement("td");
          detailCell.textContent = rowData.detail;
          row.appendChild(detailCell);

          const qtyCell = document.createElement("td");
          const qtyInput = document.createElement("input");
          qtyInput.type = "number";
          qtyInput.min = "0";
          qtyInput.step = "1";
          qtyInput.value = "0";
          qtyCell.appendChild(qtyInput);
          row.appendChild(qtyCell);

          const unitCell = document.createElement("td");
          unitCell.textContent = formatMoney(rowData.unitPrice);
          row.appendChild(unitCell);

          const totalCell = document.createElement("td");
          totalCell.textContent = formatMoney(0);
          row.appendChild(totalCell);

          const updateTotal = () => {
            const qty = Number(qtyInput.value || 0);
            totalCell.textContent = formatMoney(qty * rowData.unitPrice);
            if (onChange) onChange();
          };

          qtyInput.addEventListener("input", updateTotal);
          updateTotal();

          body.appendChild(row);
        });

        table.appendChild(body);
        template.appendChild(table);
        return template;
      };

      const createServiceBlock = (item, index) => {
        const listItem = document.createElement("div");
        listItem.className = "service-block";
        listItem.dataset.type = item.type;

        const headerRow = document.createElement("div");
        headerRow.className = "service-item";

        const leftGroup = document.createElement("div");
        leftGroup.className = "service-item-left";

        const arrowButton = document.createElement("button");
        arrowButton.type = "button";
        arrowButton.className = "service-item-button";
        arrowButton.setAttribute("aria-label", "Ouvrir le service");
        arrowButton.addEventListener("click", () => {
          const templates = listItem.querySelectorAll(".service-template");
          const isCollapsed = listItem.classList.toggle("is-collapsed");
          templates.forEach((template) => {
            template.classList.toggle("is-collapsed", isCollapsed);
          });
          arrowButton.classList.toggle("is-collapsed", isCollapsed);
          collapsedServices[index] = isCollapsed;
          saveCollapsedServices();
        });

        const title = document.createElement("span");
        title.className = "service-item-title";
        title.textContent = item.name;
        const titleBlock = document.createElement("div");
        titleBlock.className = "service-title-stack";
        titleBlock.appendChild(title);
        if (item.detail) {
          const detail = document.createElement("div");
          detail.className = "service-item-detail";
          detail.textContent = item.detail;
          titleBlock.appendChild(detail);
        }

        leftGroup.appendChild(arrowButton);
        leftGroup.appendChild(titleBlock);

        const titleActions = document.createElement("div");
        titleActions.className = "service-title-actions";

        const actions = document.createElement("div");
        actions.className = "row-actions";

        const editButton = document.createElement("button");
        editButton.type = "button";
        editButton.className = "edit-row";
        editButton.setAttribute("aria-label", "Editer le service");
        editButton.addEventListener("click", () => {
          if (title.isContentEditable) {
            title.contentEditable = "false";
            const updatedName = title.textContent.trim();
            serviceItems[index].name = updatedName;
            if (lines[index]) {
              lines[index].name = updatedName;
            }
            saveServiceItems();
            saveLines();
            renderLines();
          } else {
            title.contentEditable = "true";
            title.focus();
          }
        });

        const deleteButton = document.createElement("button");
        deleteButton.type = "button";
        deleteButton.className = "delete-row";
        deleteButton.setAttribute("aria-label", "Supprimer le service");
        deleteButton.addEventListener("click", () => {
          serviceItems.splice(index, 1);
          lines.splice(index, 1);
          saveServiceItems();
          saveLines();
          renderServiceList();
          renderLines();
        });

        actions.appendChild(editButton);
        actions.appendChild(deleteButton);
        titleActions.appendChild(actions);
        leftGroup.appendChild(titleActions);

        const totalValue = document.createElement("span");
        totalValue.className = "service-total";
        totalValue.textContent = formatMoney(0);

        headerRow.appendChild(leftGroup);
        headerRow.appendChild(totalValue);
        listItem.appendChild(headerRow);

        const isCollapsed = Boolean(collapsedServices[index]);
        if (isCollapsed) {
          listItem.classList.add("is-collapsed");
        }

        const updateServiceTotal = () => {
          const totals = Array.from(
            listItem.querySelectorAll(".template-table tbody tr")
          );
          const sum = totals.reduce((acc, row) => {
            const totalCell = row.querySelector("td:last-child");
            const value = totalCell ? parsePrice(totalCell.textContent) : 0;
            return acc + value;
          }, 0);
          totalValue.textContent = formatMoney(sum);
          if (lines[index]) {
            lines[index].templateTotal = sum;
          }
          renderLines();
          renderCostDistribution();
          saveClientData();
        };

        if (item.type === "VPC - Flex" || item.type === "VPC - Pay as you go") {
          const transitEdgeTemplate = buildTransitEdgeTemplate(
            item.type,
            updateServiceTotal,
            item.detail || item.name
          );
          if (transitEdgeTemplate) {
            listItem.appendChild(transitEdgeTemplate);
          }
        }
        if (item.type === "VPC - Flex" || item.type === "VPC - Pay as you go") {
          const computeTemplate = buildComputeTemplate(
            item.type,
            updateServiceTotal,
            item.detail || item.name
          );
          if (computeTemplate) {
            listItem.appendChild(computeTemplate);
          }
        }
        if (item.type === "VPC - Flex") {
          const optionsTemplate = buildOptionsTemplate(
            item.type,
            updateServiceTotal,
            item.detail || item.name
          );
          if (optionsTemplate) {
            listItem.appendChild(optionsTemplate);
          }
        }
        if (item.type === "VPC - Flex") {
          const protectionTemplate = buildProtectionTemplate(
            item.type,
            updateServiceTotal,
            null,
            item.detail || item.name
          );
          if (protectionTemplate) {
            listItem.appendChild(protectionTemplate);
          }
        }
        if (item.type === "VPC - Pay as you go") {
          const protectionTemplate = buildProtectionTemplate(
            item.type,
            updateServiceTotal,
            ["VM Répliquées", "VM Sauvegardée"],
            item.detail || item.name
          );
          if (protectionTemplate) {
            listItem.appendChild(protectionTemplate);
          }
        }
        if (item.type === "VPC - Flex") {
          const managedServicesTemplate = buildManagedServicesTemplate(
            item.type,
            updateServiceTotal,
            item.detail || item.name
          );
          if (managedServicesTemplate) {
            listItem.appendChild(managedServicesTemplate);
          }
        }
        if (item.type === "VPC - Dedicated SS") {
          const serverTemplate = buildServerTemplate(item.type, updateServiceTotal);
          if (serverTemplate) {
            listItem.appendChild(serverTemplate);
          }
        }
        if (item.type === "VPC - Dedicated SS") {
          const storageTemplate = buildStorageTemplate(
            item.type,
            updateServiceTotal
          );
          if (storageTemplate) {
            listItem.appendChild(storageTemplate);
          }
        }
        if (item.type === "VPC - Dedicated SS") {
          const licencesTemplate = buildLicencesTemplate(item.type, updateServiceTotal);
          if (licencesTemplate) {
            listItem.appendChild(licencesTemplate);
          }
        }
        if (item.type === "VPC - Dedicated SS") {
          const managedServicesCloudTemplate = buildManagedServicesCloudTemplate(
            item.type,
            updateServiceTotal
          );
          if (managedServicesCloudTemplate) {
            listItem.appendChild(managedServicesCloudTemplate);
          }
        }
        if (isCollapsed) {
          const templates = listItem.querySelectorAll(".service-template");
          templates.forEach((template) => {
            template.classList.add("is-collapsed");
          });
          arrowButton.classList.add("is-collapsed");
        }
        updateServiceTotal();

        return listItem;
      };

      const renderServiceList = () => {
        serviceList.innerHTML = "";
        serviceItems.forEach((item, index) => {
          const block = createServiceBlock(item, index);
          serviceList.appendChild(block);
        });
        renderCostDistribution();
      };

      const renderSizingBlock = (initialState = null) => {
        if (!sizingHost) return;
        const nextState = initialState || captureSizingState();
        sizingHost.innerHTML = "";
        sizingHost.appendChild(buildSizingTemplate(nextState));
      };

      const regionLabel = () => defaultRegionLabel;

      const computeLineTotal = (line) => {
        if (typeof line.templateTotal === "number") {
          return line.templateTotal;
        }
        return line.price * line.multiplier * line.qty * line.months;
      };

      const renderLines = () => {
        lineBody.innerHTML = "";
        lines.forEach((line, index) => {
          const row = document.createElement("tr");
          const total = computeLineTotal(line);

          [
            line.name,
            line.regionLabel,
            line.qty,
            `${line.months} mois`,
            formatMoney(total),
          ].forEach((value) => {
            const cell = document.createElement("td");
            cell.textContent = value;
            row.appendChild(cell);
          });

          const actionCell = document.createElement("td");
          const removeButton = document.createElement("button");
          removeButton.type = "button";
          removeButton.className = "remove-row";
          removeButton.setAttribute("aria-label", "Supprimer la ligne");
          removeButton.addEventListener("click", () => {
            lines.splice(index, 1);
            serviceItems.splice(index, 1);
            saveServiceItems();
            saveLines();
            renderServiceList();
            renderLines();
          });
          actionCell.appendChild(removeButton);
          row.appendChild(actionCell);

          lineBody.appendChild(row);
        });

        updateTotal();
      };

      const updateTotal = () => {
        const linesTotal = lines.reduce(
          (sum, line) => sum + computeLineTotal(line),
          0
        );
        const discount = Number(discountInput.value || 0) / 100;
        const subtotal = linesTotal;
        const total = subtotal - subtotal * discount;
        totalEl.textContent = formatMoney(total);
        renderCostDistribution();
      };

      const addServiceById = (serviceId, overrides = {}) => {
        const selectedService = serviceCatalog.find(
          (service) => service.id === serviceId
        );
        if (!selectedService) return;
        const existingQuantities = captureTemplateQuantities();

        const serviceName =
          typeof overrides.name === "string" && overrides.name.trim() !== ""
            ? overrides.name.trim()
            : selectedService.name;
        const serviceDetail =
          typeof overrides.detail === "string" ? overrides.detail.trim() : "";

        lines.push({
          id: selectedService.id,
          name: serviceName,
          price: selectedService.price,
          unit: selectedService.unit,
          multiplier: Number(defaultRegionValue),
          regionLabel: regionLabel(),
          qty: defaultQty,
          months: defaultMonths,
        });

        serviceItems.push({
          name: serviceName,
          type: selectedService.baseType || selectedService.name,
          detail: serviceDetail,
        });
        saveLines();
        saveServiceItems();
        renderServiceList();
        applyTemplateQuantities(existingQuantities);
        const blocks = Array.from(serviceList.querySelectorAll(".service-block"));
        const newServiceBlock = blocks[blocks.length - 1] || null;
        applyScopeDefaultsToServiceBlock(
          newServiceBlock,
          selectedService.baseType || selectedService.name
        );
        renderLines();
        saveClientData();
      };

      const resetClientScopeAndServices = () => {
        const confirmed = window.confirm(
          "Reinitialiser le perimetre client, supprimer tous les services crees dans le besoin client et vider l'import RVTools ?"
        );
        if (!confirmed) return;

        [
          scopeVmInput,
          scopeVcpuInput,
          scopeVcpuWindowsInput,
          scopeVramInput,
          scopeVdiskInput,
          scopeTransitInput,
          scopeProjectNameInput,
        ].forEach((input) => {
          input.value = "";
        });

        lines = [];
        serviceItems = [];
        collapsedServices = [];

        saveLines();
        saveServiceItems();
        saveCollapsedServices();
        if (rvtoolsFileInput) {
          rvtoolsFileInput.value = "";
        }
        setRvtoolsStatus("");
        if (rvtoolsResultsIframe) {
          rvtoolsResultsIframe.srcdoc = "";
          rvtoolsResultsIframe.removeAttribute("src");
          rvtoolsResultsIframe.style.height = "";
        }
        if (rvtoolsResultsFrame) {
          rvtoolsResultsFrame.classList.add("is-hidden");
        }
        renderSizingBlock();
        renderServiceList();
        renderLines();
        saveClientData();
      };

      discountInput.addEventListener("input", updateTotal);
      if (generateQuoteBtn) {
        generateQuoteBtn.addEventListener("click", generateQuoteDocument);
      }
      if (scopeResetBtn) {
        scopeResetBtn.addEventListener("click", resetClientScopeAndServices);
      }
      rvtoolsImportBtn.addEventListener("click", () => {
        rvtoolsFileInput.click();
      });
      rvtoolsFileInput.addEventListener("change", () => {
        const file = rvtoolsFileInput.files?.[0];
        if (file) {
          importRvtoolsFile(file);
        }
      });

      [scopeVmInput, scopeVcpuInput, scopeVcpuWindowsInput, scopeVramInput, scopeVdiskInput, scopeTransitInput, scopeProjectNameInput]
        .forEach((input) => {
          input.addEventListener("input", saveClientData);
        });

      clientNameInput.addEventListener("change", () => {
        const name = clientNameInput.value.trim();
        if (!name) return;
        const data = loadClientData(name);
        if (data) {
          applyClientData(data);
        } else {
          saveClientData();
        }
      });

      const init = async () => {
        await loadCatalogueFromServer();
        lines = loadLines();
        serviceItems = loadServiceItems();
        collapsedServices = loadCollapsedServices();
        renderServiceList();
        renderSizingBlock();
        renderLines();

        const lastClient = localStorage.getItem("deviseurLastClient");
        if (lastClient) {
          clientNameInput.value = lastClient;
          const data = loadClientData(lastClient);
          if (data) applyClientData(data);
        }
      };

      init();
    </script>
  </body>
</html>
