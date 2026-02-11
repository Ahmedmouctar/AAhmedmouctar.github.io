<?php
session_start();

// 1. CONFIGURATION & CONNEXION
$host = 'localhost'; $db = 'gym_db'; $user = 'root'; $pass = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8 COLLATE utf8_general_ci; USE `$db` ");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50), password VARCHAR(255))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tarifs (type_abo VARCHAR(50) PRIMARY KEY, prix DECIMAL(15,0))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(50), prenom VARCHAR(50), telephone VARCHAR(20), 
        abonnement VARCHAR(50), date_inscription DATE, date_debut DATE, date_fin DATE, 
        montant_total DECIMAL(15,0), montant_paye DECIMAL(15,0))");

    if ($pdo->query("SELECT count(*) FROM tarifs")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO tarifs (type_abo, prix) VALUES ('Journalier', 10000), ('Hebdomadaire', 50000), ('Mensuelle', 150000), ('Annuelle', 1500000)");
    }
    if ($pdo->query("SELECT count(*) FROM admin")->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO admin (username, password) VALUES (?, ?)")->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    }
} catch (PDOException $e) { die("Erreur : " . $e->getMessage()); }

$current_file = basename(__FILE__);
$msg = "";
$view_filter = isset($_GET['view']) ? $_GET['view'] : 'Tous';

// TRAITEMENT AJAX POUR MODIFICATION DIRECTE
if (isset($_POST['ajax_update'])) {
    $field = $_POST['field']; $value = $_POST['value']; $id = $_POST['id'];
    $allowed = ['nom', 'prenom', 'telephone'];
    if (in_array($field, $allowed)) {
        $stmt = $pdo->prepare("UPDATE clients SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
    }
    exit; 
}

// 2. ACTIONS PHP
if (isset($_GET['action']) && $_GET['action'] == 'logout') { session_destroy(); header("Location: $current_file"); exit(); }

if (isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
    $stmt->execute([$_GET['delete_id']]);
    header("Location: $current_file"); exit();
}

if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = ?");
    $stmt->execute([$_POST['username']]);
    $u = $stmt->fetch();
    if ($u && password_verify($_POST['password'], $u['password'])) { $_SESSION['admin'] = $u['username']; }
}

if (isset($_POST['update_pw'])) {
    $new_pw = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE admin SET password = ? WHERE username = ?");
    $stmt->execute([$new_pw, $_SESSION['admin']]);
    $msg = "<div style='background:#27ae60; color:white; padding:10px; border-radius:5px; margin-bottom:15px; text-align:center;'>✅ Mot de passe mis à jour !</div>";
}

if (isset($_POST['update_tarifs'])) {
    foreach ($_POST['nouveaux_prix'] as $type => $prix) {
        $stmt = $pdo->prepare("UPDATE tarifs SET prix = ? WHERE type_abo = ?");
        $stmt->execute([$prix, $type]);
    }
    $msg = "<div style='background:#27ae60; color:white; padding:10px; border-radius:5px; margin-bottom:15px; text-align:center;'>✅ Tarifs mis à jour !</div>";
}

if (isset($_POST['save_client'])) {
    $m_total = $_POST['m_total']; $m_paye = $_POST['m_paye'];
    if ($m_paye < $m_total) {
        $msg = "<div style='background:#e74c3c; color:white; padding:10px; border-radius:5px; margin-bottom:15px; text-align:center;'>❌ Erreur : Paiement intégral requis !</div>";
    } else {
        if (!empty($_POST['client_id'])) {
            $sql = "UPDATE clients SET nom=?, prenom=?, telephone=?, abonnement=?, date_debut=?, date_fin=?, montant_total=?, montant_paye=? WHERE id=?";
            $pdo->prepare($sql)->execute([$_POST['nom'], $_POST['prenom'], $_POST['tel'], $_POST['abo'], $_POST['date_debut'], $_POST['date_fin'], $m_total, $m_paye, $_POST['client_id']]);
        } else {
            $sql = "INSERT INTO clients (nom, prenom, telephone, abonnement, date_inscription, date_debut, date_fin, montant_total, montant_paye) VALUES (?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([$_POST['nom'], $_POST['prenom'], $_POST['tel'], $_POST['abo'], date('Y-m-d'), $_POST['date_debut'], $_POST['date_fin'], $m_total, $m_paye]);
        }
        header("Location: $current_file"); exit();
    }
}

// DONNÉES DASHBOARD
$tarifs_query = $pdo->query("SELECT * FROM tarifs")->fetchAll(PDO::FETCH_KEY_PAIR);
$counts = $pdo->query("SELECT abonnement, COUNT(*) as nb FROM clients GROUP BY abonnement")->fetchAll(PDO::FETCH_KEY_PAIR);
$ca_total = $pdo->query("SELECT SUM(montant_paye) FROM clients")->fetchColumn() ?? 0;
$total_membres = array_sum($counts);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Kamissoko Fitnest Club - Gestion</title>
    <style>
        :root { --kamissoko-blue: #1e4ea1; --bg-gray: #f4f7f6; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg-gray); margin: 20px; }
        .container { max-width: 1250px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .gym-banner { width: 100%; height: 200px; object-fit: cover; border-radius: 8px; margin-bottom: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 20px; margin-bottom: 20px; }
        
        .dashboard { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { color: white; padding: 20px; border-radius: 10px; text-align: center; text-decoration: none; transition: transform 0.2s; cursor: pointer; border: none; }
        .stat-card:hover { transform: translateY(-5px); opacity: 0.9; }
        .stat-card h3 { margin: 0; font-size: 14px; opacity: 0.8; }
        .stat-card p { margin: 5px 0 0; font-size: 22px; font-weight: bold; }
        
        .admin-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        .admin-box { background: #f9f9f9; padding: 20px; border-radius: 10px; border: 1px solid #ddd; }
        
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px; background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 25px; }
        input, select { padding: 10px; border: 1px solid #ccc; border-radius: 5px; width: 100%; box-sizing: border-box; }
        .btn { padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-blue { background: var(--kamissoko-blue); color: white; }
        .btn-red { background: #d9534f; color: white; }
        .btn-green { background: #27ae60; color: white; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #2c3e50; color: white; padding: 12px; text-align: left; }
        td { padding: 12px; border-bottom: 1px solid #eee; }
        
        .filter-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; background: #eee; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>

<div class="container">
    <?php if (!isset($_SESSION['admin'])): ?>
        <div style="max-width: 400px; margin: 100px auto; text-align: center;">
            <form method="POST">
                <h2>Connexion Admin</h2>
                <input type="text" name="username" placeholder="Identifiant" required style="margin-bottom:10px;">
                <input type="password" name="password" placeholder="Mot de passe" required style="margin-bottom:10px;">
                <button type="submit" name="login" class="btn btn-blue" style="width:100%;">SE CONNECTER</button>
            </form>
        </div>
    <?php else: ?>
        <img src="https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=1470&auto=format&fit=crop" class="gym-banner">
        
        <div class="header">
            <h1>🏆 Kamissoko Fitnest Club</h1>
            <a href="?action=logout" class="btn btn-red">Déconnexion</a>
        </div>

        <div class="dashboard">
            <a href="?view=Tous" class="stat-card" style="background: var(--kamissoko-blue);">
                <h3>Total Membres</h3>
                <p><?= $total_membres ?></p>
            </a>
            <div class="stat-card" style="background: #27ae60; cursor: default;">
                <h3>Revenus Totaux</h3>
                <p><?= number_format($ca_total, 0, '.', ' ') ?> GNF</p>
            </div>
            <?php foreach($tarifs_query as $type => $prix): ?>
            <a href="?view=<?= urlencode($type) ?>" class="stat-card" style="background: #f39c12;">
                <h3><?= $type ?></h3>
                <p><?= $counts[$type] ?? 0 ?></p>
            </a>
            <?php endforeach; ?>
        </div>

        <?= $msg ?>

        <div class="admin-grid">
            <div class="admin-box">
                <h3 style="margin-top:0;">💰 Gestion des Tarifs</h3>
                <form method="POST">
                    <table style="font-size: 13px;">
                        <?php foreach($tarifs_query as $type => $prix): ?>
                        <tr>
                            <td style="padding: 5px;"><?= $type ?></td>
                            <td style="padding: 5px;"><input type="number" name="nouveaux_prix[<?= $type ?>]" value="<?= $prix ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <button type="submit" name="update_tarifs" class="btn btn-green" style="width:100%; margin-top:10px;">Mettre à jour</button>
                </form>
            </div>
            <div class="admin-box">
                <h3 style="margin-top:0;">🔒 Sécurité Compte</h3>
                <form method="POST">
                    <input type="password" name="new_password" placeholder="Nouveau mot de passe" required style="margin-bottom:10px;">
                    <button type="submit" name="update_pw" class="btn btn-blue" style="width:100%;">Changer le mot de passe</button>
                </form>
            </div>
        </div>

        <form method="POST" id="clientForm">
            <input type="hidden" name="client_id" id="client_id">
            <div class="form-row">
                <input type="text" name="nom" id="f_nom" placeholder="Nom" required>
                <input type="text" name="prenom" id="f_prenom" placeholder="Prénom" required>
                <input type="text" name="tel" id="f_tel" placeholder="Tél">
                <select name="abo" id="type_abo" onchange="calculer()">
                    <?php foreach($tarifs_query as $type => $prix): ?>
                        <option value="<?= $type ?>"><?= $type ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="date_debut" id="d_debut" value="<?= date('Y-m-d') ?>" onchange="calculer()">
                <input type="date" name="date_fin" id="d_fin" readonly style="background:#eee">
                <input type="number" name="m_total" id="m_total" readonly style="background:#eee">
                <input type="number" name="m_paye" id="m_paye" placeholder="Versé" required>
                <button type="submit" name="save_client" id="btn_submit" class="btn btn-blue">Inscrire / Valider</button>
            </div>
        </form>

        <div class="filter-header">
            <strong>Liste : <?= htmlspecialchars($view_filter) ?></strong>
            <input type="text" id="searchInput" placeholder="🔍 Rechercher..." onkeyup="searchTable()" style="width: 250px;">
        </div>

        <table id="membersTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nom</th>
                    <th>Prénom</th>
                    <th>Téléphone</th>
                    <th>Abonnement</th>
                    <th>Fin</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sql = "SELECT * FROM clients";
                if ($view_filter != 'Tous') {
                    $sql .= " WHERE abonnement = :abo";
                }
                $sql .= " ORDER BY id DESC";
                
                $stmt = $pdo->prepare($sql);
                if ($view_filter != 'Tous') $stmt->bindValue(':abo', $view_filter);
                $stmt->execute();

                while ($c = $stmt->fetch()):
                ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td contenteditable="true" onblur="updateCell(this, 'nom', <?= $c['id'] ?>)"><strong><?= strtoupper($c['nom']) ?></strong></td>
                    <td contenteditable="true" onblur="updateCell(this, 'prenom', <?= $c['id'] ?>)"><?= $c['prenom'] ?></td>
                    <td contenteditable="true" onblur="updateCell(this, 'telephone', <?= $c['id'] ?>)"><?= $c['telephone'] ?></td>
                    <td><?= $c['abonnement'] ?></td>
                    <td style="color:<?= (strtotime($c['date_fin']) < time()) ? 'red' : 'green' ?>; font-weight:bold;">
                        <?= date('d/m/Y', strtotime($c['date_fin'])) ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-blue btn-sm" onclick='remplirForm(<?= json_encode($c) ?>)'>🔄</button>
                        <a href="?delete_id=<?= $c['id'] ?>" class="btn btn-red btn-sm" onclick="return confirm('Supprimer ?')">🗑️</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
const tarifs = <?= json_encode($tarifs_query) ?>;

function calculer() {
    const type = document.getElementById('type_abo').value;
    const dateStart = document.getElementById('d_debut').value;
    document.getElementById('m_total').value = tarifs[type];
    document.getElementById('m_paye').value = tarifs[type]; 
    if(dateStart) {
        let d = new Date(dateStart);
        if(type === 'Journalier') d.setDate(d.getDate() + 1);
        else if(type === 'Hebdomadaire') d.setDate(d.getDate() + 7);
        else if(type === 'Mensuelle') d.setMonth(d.getMonth() + 1);
        else if(type === 'Annuelle') d.setFullYear(d.getFullYear() + 1);
        document.getElementById('d_fin').value = d.toISOString().split('T')[0];
    }
}

function updateCell(element, field, id) {
    let newValue = element.innerText.trim();
    let formData = new FormData();
    formData.append('ajax_update', '1');
    formData.append('field', field);
    formData.append('value', newValue);
    formData.append('id', id);
    fetch(window.location.href, { method: 'POST', body: formData }).then(() => {
        element.style.background = "#d4edda";
        setTimeout(() => { element.style.background = "transparent"; }, 500);
    });
}

function searchTable() {
    let input = document.getElementById("searchInput").value.toUpperCase();
    let tr = document.getElementById("membersTable").getElementsByTagName("tr");
    for (let i = 1; i < tr.length; i++) {
        tr[i].style.display = tr[i].innerText.toUpperCase().includes(input) ? "" : "none";
    }
}

function remplirForm(data) {
    document.getElementById('client_id').value = data.id;
    document.getElementById('f_nom').value = data.nom;
    document.getElementById('f_prenom').value = data.prenom;
    document.getElementById('f_tel').value = data.telephone;
    document.getElementById('type_abo').value = data.abonnement;
    document.getElementById('d_debut').value = data.date_fin;
    document.getElementById('btn_submit').innerText = "Confirmer Renouvellement";
    calculer();
    window.scrollTo(0,0);
}
window.onload = calculer;
</script>
</body>
</html>