<?php
require_once __DIR__ . '/app/app.php';

// Déconnexion optionnelle via ?logout=1
if (isset($_GET['logout'])) {
    logout();
    header('Location: login.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = "Session expirée. Réessaie.";
    } else {
        $username = (string)($_POST['username'] ?? '');
        $pwd      = (string)($_POST['password'] ?? '');
        if (login_with_password($username, $pwd)) {
            header('Location: index.php');
            exit;
        }
        $error = "Identifiants invalides.";
    }
}
$csrf = csrf_token();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>connexion admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 (CDN) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-12 col-sm-10 col-md-7 col-lg-5">
        <div class="card shadow">
          <div class="card-body p-4">
            <h1 class="h4 mb-3 text-center">connexion admin</h1>
            <?php if ($error): ?>
              <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

              <div class="mb-3">
                <label class="form-label" for="username">nom d’utilisateur</label>
                <input id="username" name="username" type="text" class="form-control" required autofocus>
              </div>

              <div class="mb-3">
                <label class="form-label" for="password">mot de passe</label>
                <input id="password" name="password" type="password" class="form-control" required>
              </div>

              <div class="d-grid">
                <button class="btn btn-primary" type="submit">se connecter</button>
              </div>
            </form>
          </div>
        </div>
        <p class="text-center mt-3">
          <a class="link-light link-underline-opacity-25" href="?logout=1">réinitialiser la session</a>
        </p>
      </div>
    </div>
  </div>
</body>
</html>
