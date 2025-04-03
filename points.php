<?php
session_start();

// Read MySQL credentials
$cred_file = '/home/points/.mysql_user';
if (!file_exists($cred_file)) {
    die('Error: Credentials file not found.');
}
$credentials = file_get_contents($cred_file);
if ($credentials === false) {
    die('Error: Unable to read credentials file.');
}
list($db_user, $db_pass) = explode(':', trim($credentials));

// Connect to database
$conn = new mysqli('localhost', $db_user, $db_pass, 'points_points');
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

// Handle login
if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $stmt = $conn->prepare("SELECT id, password, is_admin, active FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password']) && $row['active'] == 1) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['is_admin'] = $row['is_admin'];
            header("Location: points.php?page=summary");
            exit;
        }
    }
    $stmt->close();
}

// Handle logout
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    header("Location: points.php");
    exit;
}

// Handle add user
if (isset($_POST['add_user']) && isset($_SESSION['user_id']) && $_SESSION['is_admin'] == 1) {
    $username = $_POST['username'];
    $firstname = $_POST['firstname'];
    $lastname = $_POST['lastname'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    $active = isset($_POST['active']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO users (username, firstname, lastname, password, is_admin, active) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssii", $username, $firstname, $lastname, $password, $is_admin, $active);
    $stmt->execute();
    $stmt->close();
}

// Handle edit user
if (isset($_POST['edit_user']) && isset($_SESSION['user_id']) && $_SESSION['is_admin'] == 1) {
    $user_id = $_POST['user_id'];
    $firstname = $_POST['firstname'];
    $lastname = $_POST['lastname'];
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    $active = isset($_POST['active']) ? 1 : 0;

    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET firstname = ?, lastname = ?, is_admin = ?, active = ?, password = ? WHERE id = ?");
        $stmt->bind_param("ssiisi", $firstname, $lastname, $is_admin, $active, $password, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET firstname = ?, lastname = ?, is_admin = ?, active = ? WHERE id = ?");
        $stmt->bind_param("ssiii", $firstname, $lastname, $is_admin, $active, $user_id);
    }
    $stmt->execute();
    $stmt->close();
}

// Handle add points entry (admin only)
$message = '';
$prev_datetime = isset($_POST['prev_datetime']) && isset($_POST['add_points_another']) ? $_POST['prev_datetime'] : '';
$prev_user_id = isset($_POST['prev_user_id']) && isset($_POST['add_points_another']) ? $_POST['prev_user_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '');
$prev_vendor_id = isset($_POST['prev_vendor_id']) && isset($_POST['add_points_another']) ? $_POST['prev_vendor_id'] : '';
$prev_amount = isset($_POST['prev_amount']) && isset($_POST['add_points_another']) ? $_POST['prev_amount'] : '';
$prev_description = isset($_POST['prev_description']) && isset($_POST['add_points_another']) ? $_POST['prev_description'] : '';

if ((isset($_POST['add_points']) || isset($_POST['add_points_another'])) && isset($_SESSION['user_id']) && $_SESSION['is_admin'] == 1) {
    $datetime = $_POST['datetime'];
    $user_id = $_POST['user_id'];
    $vendor_id = $_POST['vendor_id'];
    $amount = (int)$_POST['amount'];
    $description = $_POST['description'];

    $stmt = $conn->prepare("INSERT INTO points_register (datetime, vendor_id, user_id, amount, description) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("siiis", $datetime, $vendor_id, $user_id, $amount, $description);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT short_name FROM vendors WHERE id = ?");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $vendor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT firstname FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $message = number_format($amount, 0, '.', ',') . " " . htmlspecialchars($vendor['short_name']) . " Points added for " . htmlspecialchars($user['firstname']) . "!";

    if (isset($_POST['add_points_another'])) {
        $prev_datetime = $datetime;
        $prev_user_id = ($user_id == 1) ? 2 : 1;
        $prev_vendor_id = $vendor_id;
        $prev_amount = $amount;
        $prev_description = $description;
    }
}

// Handle spend points
$spend_message = '';
if (isset($_POST['spend_points']) && isset($_SESSION['user_id'])) {
    $datetime = $_POST['datetime'];
    $user_id = $_SESSION['user_id'];
    $vendor_id = 1; // Hardcoded to Amex
    $amount = (int)$_POST['amount'];
    $description = $_POST['description'];

    if ($amount > 0) {
        $neg_amount = -$amount;
        $stmt = $conn->prepare("INSERT INTO points_register (datetime, vendor_id, user_id, amount, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("siiis", $datetime, $vendor_id, $user_id, $neg_amount, $description);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT firstname FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $spend_message = number_format($amount, 0, '.', ',') . " Amex Points spent by " . htmlspecialchars($user['firstname']) . "!";
    }
}

// Handle transfer points (admin only)
$transfer_message = '';
$prev_transfer_datetime = isset($_POST['prev_transfer_datetime']) && isset($_POST['transfer_points_another']) ? $_POST['prev_transfer_datetime'] : '';
$prev_from_user_id = isset($_POST['prev_from_user_id']) && isset($_POST['transfer_points_another']) ? $_POST['prev_from_user_id'] : '';
$prev_to_user_id = isset($_POST['prev_to_user_id']) && isset($_POST['transfer_points_another']) ? $_POST['prev_to_user_id'] : '';
$prev_transfer_amount = isset($_POST['prev_transfer_amount']) && isset($_POST['transfer_points_another']) ? $_POST['prev_transfer_amount'] : '';
$prev_transfer_description = isset($_POST['prev_transfer_description']) && isset($_POST['transfer_points_another']) ? $_POST['prev_transfer_description'] : '';

if ((isset($_POST['transfer_points']) || isset($_POST['transfer_points_another'])) && isset($_SESSION['user_id']) && $_SESSION['is_admin'] == 1) {
    $datetime = $_POST['datetime'];
    $vendor_id = $_POST['vendor_id'];
    $from_user_id = $_POST['from_user_id'];
    $to_user_id = $_POST['to_user_id'];
    $amount = (int)$_POST['amount'];
    $description = $_POST['description'];

    if ($amount > 0) {
        $stmt = $conn->prepare("INSERT INTO points_register (datetime, vendor_id, user_id, amount, description) VALUES (?, ?, ?, ?, ?)");
        $neg_amount = -$amount;
        $stmt->bind_param("siiis", $datetime, $vendor_id, $from_user_id, $neg_amount, $description);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO points_register (datetime, vendor_id, user_id, amount, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("siiis", $datetime, $vendor_id, $to_user_id, $amount, $description);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT short_name FROM vendors WHERE id = ?");
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $vendor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("SELECT firstname FROM users WHERE id = ?");
        $stmt->bind_param("i", $from_user_id);
        $stmt->execute();
        $from_user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("SELECT firstname FROM users WHERE id = ?");
        $stmt->bind_param("i", $to_user_id);
        $stmt->execute();
        $to_user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $transfer_message = number_format($amount, 0, '.', ',') . " " . htmlspecialchars($vendor['short_name']) . " Points transferred from " . htmlspecialchars($from_user['firstname']) . " to " . htmlspecialchars($to_user['firstname']) . "!";

        if (isset($_POST['transfer_points_another'])) {
            $prev_transfer_datetime = $datetime;
            $prev_from_user_id = ($from_user_id == 1) ? 2 : 1;
            $prev_to_user_id = ($to_user_id == 1) ? 2 : 1;
            $prev_transfer_amount = $amount;
            $prev_transfer_description = $description;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Points System</title>
    <style>
        .menu { overflow: hidden; background-color: #f2f2f2; margin-bottom: 20px; }
        .menu ul { list-style-type: none; margin: 0; padding: 0; }
        .menu li { float: left; }
        .menu li a, .menu li button { display: block; padding: 14px 16px; text-decoration: none; color: black; border: none; background: none; cursor: pointer; }
        .menu li a.active { font-weight: bold; }
        table.summary { width: 300px; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ccc; padding: 5px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .bold { font-weight: bold; }
        .right { text-align: right; }
        .success { color: green; background-color: #e0ffe0; padding: 10px; margin: 10px 0; }
        .alt-row { background-color: #f5f5f5; }
        .total-row { background-color: #f5f5f5; }
        .filter-form { margin-bottom: 10px; }
        .filter-form select, .filter-form input { margin-right: 10px; }
        .form-table { border-spacing: 2px; padding: 2px; }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function formatDateTime(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                return `${year}-${month}-${day}T${hours}:${minutes}`;
            }

            const addDateTime = document.getElementById('add_datetime');
            if (addDateTime && !addDateTime.value) {
                addDateTime.value = formatDateTime(new Date());
            }

            const spendDateTime = document.getElementById('spend_datetime');
            if (spendDateTime && !spendDateTime.value) {
                spendDateTime.value = formatDateTime(new Date());
            }

            const transferDateTime = document.getElementById('transfer_datetime');
            if (transferDateTime && !transferDateTime.value) {
                transferDateTime.value = formatDateTime(new Date());
            }
        });
    </script>
</head>
<body>
    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="menu">
            <ul>
                <?php if ($_SESSION['is_admin'] == 1): ?>
                    <li><a href="?page=manage_users" class="<?php echo (isset($_GET['page']) && $_GET['page'] == 'manage_users') ? 'active' : ''; ?>">Manage Users</a></li>
                    <li><a href="?page=add_points" class="<?php echo (isset($_GET['page']) && $_GET['page'] == 'add_points') ? 'active' : ''; ?>">Add Points Entry</a></li>
                    <li><a href="?page=transfer_points" class="<?php echo (isset($_GET['page']) && $_GET['page'] == 'transfer_points') ? 'active' : ''; ?>">Transfer Points</a></li>
                <?php endif; ?>
                <li><a href="?page=spend_points" class="<?php echo (isset($_GET['page']) && $_GET['page'] == 'spend_points') ? 'active' : ''; ?>">Spend Points</a></li>
                <li><a href="?page=summary" class="<?php echo (!isset($_GET['page']) || $_GET['page'] == 'summary') ? 'active' : ''; ?>">Summary</a></li>
                <li><a href="?page=register" class="<?php echo (isset($_GET['page']) && $_GET['page'] == 'register') ? 'active' : ''; ?>">Register</a></li>
                <li><form method="post" style="display:inline;"><button type="submit" name="logout">Logout</button></form></li>
            </ul>
        </div>

        <?php if (!isset($_GET['page']) || $_GET['page'] == 'summary'): ?>
            <h1>Summary</h1>
            <?php
            if ($_SESSION['is_admin'] == 1) {
                $vendors = [];
                $vendor_result = $conn->query("SELECT id, short_name FROM vendors");
                while ($vendor = $vendor_result->fetch_assoc()) {
                    $vendors[$vendor['id']] = $vendor['short_name'];
                }
                $balance_query = "SELECT user_id, vendor_id, SUM(amount) as balance FROM points_register GROUP BY user_id, vendor_id";
            } else {
                $vendors = ['1' => 'Amex'];
                $balance_query = "SELECT user_id, vendor_id, SUM(amount) as balance FROM points_register WHERE vendor_id = 1 GROUP BY user_id, vendor_id";
            }

            $users = [];
            $user_result = $conn->query("SELECT id, firstname FROM users WHERE id <= 2 AND active = 1");
            while ($user = $user_result->fetch_assoc()) {
                $users[$user['id']] = $user['firstname'];
            }

            $balances = array_fill_keys(array_keys($users), array_fill_keys(array_keys($vendors), 0));
            $vendor_totals = array_fill_keys(array_keys($vendors), 0);
            $balance_result = $conn->query($balance_query);
            while ($row = $balance_result->fetch_assoc()) {
                if (isset($balances[$row['user_id']])) {
                    $balances[$row['user_id']][$row['vendor_id']] = (int)$row['balance'];
                    $vendor_totals[$row['vendor_id']] += (int)$row['balance'];
                }
            }
            ?>
            <table class="summary">
                <tr>
                    <th>User</th>
                    <?php foreach ($vendors as $vendor_name): ?>
                        <th class="right"><?php echo htmlspecialchars($vendor_name); ?></th>
                    <?php endforeach; ?>
                </tr>
                <?php foreach ($users as $user_id => $firstname): ?>
                    <tr>
                        <td class="bold"><?php echo htmlspecialchars($firstname); ?></td>
                        <?php foreach ($vendors as $vendor_id => $vendor_name): ?>
                            <td class="right"><?php echo number_format($balances[$user_id][$vendor_id], 0, '.', ','); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td class="bold">Total</td>
                    <?php foreach ($vendors as $vendor_id => $vendor_name): ?>
                        <td class="right bold"><?php echo number_format($vendor_totals[$vendor_id], 0, '.', ','); ?></td>
                    <?php endforeach; ?>
                </tr>
            </table>
        <?php elseif (isset($_GET['page']) && $_GET['page'] == 'manage_users' && $_SESSION['is_admin'] == 1): ?>
            <h1>Manage Users</h1>
            <?php
            $result = $conn->query("SELECT id, username, firstname, lastname, is_admin, active FROM users");
            ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Admin</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                        <td><?php echo htmlspecialchars($row['firstname']); ?></td>
                        <td><?php echo htmlspecialchars($row['lastname']); ?></td>
                        <td><?php echo $row['is_admin'] ? 'Yes' : 'No'; ?></td>
                        <td><?php echo $row['active'] ? 'Yes' : 'No'; ?></td>
                        <td><a href="?page=manage_users&edit_id=<?php echo $row['id']; ?>">Edit</a></td>
                    </tr>
                <?php endwhile; ?>
            </table>

            <?php if (isset($_GET['edit_id'])): ?>
                <h2>Edit User</h2>
                <?php
                $edit_id = $_GET['edit_id'];
                $stmt = $conn->prepare("SELECT id, username, firstname, lastname, is_admin, active FROM users WHERE id = ?");
                $stmt->bind_param("i", $edit_id);
                $stmt->execute();
                $edit_user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                ?>
                <form method="post">
                    <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                    <label>Username: <?php echo htmlspecialchars($edit_user['username']); ?> (cannot be changed)</label><br>
                    <label>First Name: <input type="text" name="firstname" value="<?php echo htmlspecialchars($edit_user['firstname']); ?>" required></label><br>
                    <label>Last Name: <input type="text" name="lastname" value="<?php echo htmlspecialchars($edit_user['lastname']); ?>" required></label><br>
                    <label>Password: <input type="password" name="password" placeholder="Leave blank to keep current password"></label><br>
                    <label>Admin: <input type="checkbox" name="is_admin" <?php echo $edit_user['is_admin'] ? 'checked' : ''; ?>></label><br>
                    <label>Active: <input type="checkbox" name="active" <?php echo $edit_user['active'] ? 'checked' : ''; ?>></label><br>
                    <input type="submit" name="edit_user" value="Update User">
                </form>
            <?php endif; ?>

            <h2>Add New User</h2>
            <form method="post">
                <label>Username: <input type="text" name="username" required></label><br>
                <label>First Name: <input type="text" name="firstname" required></label><br>
                <label>Last Name: <input type="text" name="lastname" required></label><br>
                <label>Password: <input type="password" name="password" required></label><br>
                <label>Admin: <input type="checkbox" name="is_admin"></label><br>
                <label>Active: <input type="checkbox" name="active" checked></label><br>
                <input type="submit" name="add_user" value="Add User">
            </form>
        <?php elseif (isset($_GET['page']) && $_GET['page'] == 'add_points' && $_SESSION['is_admin'] == 1): ?>
            <h1>Add Points Entry</h1>
            <?php if ($message): ?>
                <div class="success"><?php echo $message; ?></div>
            <?php endif; ?>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <td>Date and Time:</td>
                        <td><input type="datetime-local" id="add_datetime" name="datetime" value="<?php echo $prev_datetime ? htmlspecialchars($prev_datetime) : ''; ?>" required></td>
                    </tr>
                    <tr>
                        <td>User:</td>
                        <td>
                            <select name="user_id" required>
                                <?php
                                $user_result = $conn->query("SELECT id, firstname FROM users WHERE id <= 2 AND active = 1");
                                while ($user = $user_result->fetch_assoc()) {
                                    $selected = ($user['id'] == $prev_user_id) ? 'selected' : '';
                                    echo "<option value='{$user['id']}' $selected>" . htmlspecialchars($user['firstname']) . "</option>";
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>Vendor:</td>
                        <td>
                            <select name="vendor_id" required>
                                <option value="">Select Vendor</option>
                                <?php
                                $vendor_result = $conn->query("SELECT id, short_name FROM vendors");
                                while ($vendor = $vendor_result->fetch_assoc()) {
                                    $selected = ($vendor['id'] == $prev_vendor_id) ? 'selected' : '';
                                    echo "<option value='{$vendor['id']}' $selected>" . htmlspecialchars($vendor['short_name']) . "</option>";
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>Amount:</td>
                        <td><input type="number" name="amount" value="<?php echo htmlspecialchars($prev_amount); ?>" required></td>
                    </tr>
                    <tr>
                        <td>Description:</td>
                        <td><input type="text" name="description" value="<?php echo htmlspecialchars($prev_description); ?>" required></td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <input type="submit" name="add_points" value="Add Points Entry">
                            <input type="submit" name="add_points_another" value="Add Points Entry and Another">
                        </td>
                    </tr>
                </table>
                <input type="hidden" name="prev_datetime" value="<?php echo htmlspecialchars($prev_datetime); ?>">
                <input type="hidden" name="prev_user_id" value="<?php echo htmlspecialchars($prev_user_id); ?>">
                <input type="hidden" name="prev_vendor_id" value="<?php echo htmlspecialchars($prev_vendor_id); ?>">
                <input type="hidden" name="prev_amount" value="<?php echo htmlspecialchars($prev_amount); ?>">
                <input type="hidden" name="prev_description" value="<?php echo htmlspecialchars($prev_description); ?>">
            </form>
        <?php elseif (isset($_GET['page']) && $_GET['page'] == 'spend_points' && isset($_SESSION['user_id'])): ?>
            <h1>Spend Points</h1>
            <?php
            $stmt = $conn->prepare("SELECT SUM(amount) as amex_balance FROM points_register WHERE user_id = ? AND vendor_id = 1");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $amex_balance = $result['amex_balance'] ?? 0;
            $stmt->close();

            $stmt = $conn->prepare("SELECT firstname FROM users WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $current_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            ?>
            <p>Current Amex Balance: <?php echo number_format($amex_balance, 0, '.', ','); ?></p>
            <?php if ($spend_message): ?>
                <div class="success"><?php echo $spend_message; ?></div>
            <?php endif; ?>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <td>Date and Time:</td>
                        <td><input type="datetime-local" id="spend_datetime" name="datetime" required></td>
                    </tr>
                    <tr>
                        <td>User:</td>
                        <td><?php echo htmlspecialchars($current_user['firstname']); ?></td>
                    </tr>
                    <tr>
                        <td>Vendor:</td>
                        <td>Amex</td>
                    </tr>
                    <tr>
                        <td>Amount:</td>
                        <td><input type="number" name="amount" min="1" required></td>
                    </tr>
                    <tr>
                        <td>Description:</td>
                        <td><input type="text" name="description" required></td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <input type="submit" name="spend_points" value="Spend Points">
                        </td>
                    </tr>
                </table>
                <input type="hidden" name="user_id" value="<?php echo $_SESSION['user_id']; ?>">
            </form>
        <?php elseif (isset($_GET['page']) && $_GET['page'] == 'register'): ?>
            <h1>Register</h1>
            <form method="post" class="filter-form">
                <label>Vendor: 
                    <select name="vendor_id">
                        <option value="">All Vendors</option>
                        <?php
                        $vendor_result = $conn->query("SELECT id, short_name FROM vendors");
                        while ($vendor = $vendor_result->fetch_assoc()) {
                            $selected = (isset($_POST['apply_filters']) && $_POST['vendor_id'] == $vendor['id']) ? 'selected' : '';
                            echo "<option value='{$vendor['id']}' $selected>" . htmlspecialchars($vendor['short_name']) . "</option>";
                        }
                        ?>
                    </select>
                </label>
                <label>User: 
                    <select name="user_id">
                        <option value="">All Users</option>
                        <?php
                        $user_result = $conn->query("SELECT id, firstname FROM users WHERE id <= 2 AND active = 1");
                        while ($user = $user_result->fetch_assoc()) {
                            $selected = (isset($_POST['apply_filters']) && $_POST['user_id'] == $user['id']) ? 'selected' : '';
                            echo "<option value='{$user['id']}' $selected>" . htmlspecialchars($user['firstname']) . "</option>";
                        }
                        ?>
                    </select>
                </label>
                <input type="submit" name="apply_filters" value="Apply">
            </form>
            <?php
            $query = "SELECT pr.datetime, v.short_name, u.firstname, pr.amount, pr.description 
                      FROM points_register pr 
                      JOIN vendors v ON pr.vendor_id = v.id 
                      JOIN users u ON pr.user_id = u.id";
            $params = [];
            $types = '';
            if (isset($_POST['apply_filters'])) {
                $where = [];
                if (!empty($_POST['vendor_id'])) {
                    $where[] = "pr.vendor_id = ?";
                    $params[] = $_POST['vendor_id'];
                    $types .= 'i';
                }
                if (!empty($_POST['user_id'])) {
                    $where[] = "pr.user_id = ?";
                    $params[] = $_POST['user_id'];
                    $types .= 'i';
                }
                if (!empty($where)) {
                    $query .= " WHERE " . implode(" AND ", $where);
                }
            }
            $query .= " ORDER BY pr.datetime ASC, pr.id ASC";
            
            if (empty($params)) {
                $result = $conn->query($query);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
            }
            ?>
            <table>
                <tr>
                    <th>Date/Time</th>
                    <th>Vendor</th>
                    <th>User</th>
                    <th>Description</th>
                    <th class="right">Amount</th>
                </tr>
                <?php $row_count = 0; while ($row = $result->fetch_assoc()): $row_count++; ?>
                    <tr class="<?php echo ($row_count % 2 == 0) ? 'alt-row' : ''; ?>">
                        <td><?php echo date('n/j/Y H:i', strtotime($row['datetime'])); ?></td>
                        <td><?php echo htmlspecialchars($row['short_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['firstname']); ?></td>
                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                        <td class="right"><?php echo number_format($row['amount'], 0, '.', ','); ?></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        <?php elseif (isset($_GET['page']) && $_GET['page'] == 'transfer_points' && $_SESSION['is_admin'] == 1): ?>
            <h1>Transfer Points</h1>
            <?php if ($transfer_message): ?>
                <div class="success"><?php echo $transfer_message; ?></div>
            <?php endif; ?>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <td>Date and Time:</td>
                        <td><input type="datetime-local" id="transfer_datetime" name="datetime" value="<?php echo $prev_transfer_datetime ? htmlspecialchars($prev_transfer_datetime) : ''; ?>" required></td>
                    </tr>
                    <tr>
                        <td>Vendor:</td>
                        <td>
                            <select name="vendor_id" required>
                                <option value="">Select Vendor</option>
                                <?php
                                $vendor_result = $conn->query("SELECT id, short_name FROM vendors");
                                while ($vendor = $vendor_result->fetch_assoc()) {
                                    echo "<option value='{$vendor['id']}'>" . htmlspecialchars($vendor['short_name']) . "</option>";
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>From User:</td>
                        <td>
                            <select name="from_user_id" required>
                                <option value="">Select User</option>
                                <?php
                                $user_result = $conn->query("SELECT id, firstname FROM users WHERE id <= 2 AND active = 1");
                                while ($user = $user_result->fetch_assoc()) {
                                    $selected = ($user['id'] == $prev_from_user_id) ? 'selected' : '';
                                    echo "<option value='{$user['id']}' $selected>" . htmlspecialchars($user['firstname']) . "</option>";
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>To User:</td>
                        <td>
                            <select name="to_user_id" required>
                                <option value="">Select User</option>
                                <?php
                                $user_result = $conn->query("SELECT id, firstname FROM users WHERE id <= 2 AND active = 1");
                                while ($user = $user_result->fetch_assoc()) {
                                    $selected = ($user['id'] == $prev_to_user_id) ? 'selected' : '';
                                    echo "<option value='{$user['id']}' $selected>" . htmlspecialchars($user['firstname']) . "</option>";
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>Amount:</td>
                        <td><input type="number" name="amount" min="1" value="<?php echo htmlspecialchars($prev_transfer_amount); ?>" required></td>
                    </tr>
                    <tr>
                        <td>Description:</td>
                        <td><input type="text" name="description" value="<?php echo htmlspecialchars($prev_transfer_description); ?>" required></td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <input type="submit" name="transfer_points" value="Transfer Points">
                            <input type="submit" name="transfer_points_another" value="Transfer Points and Another">
                        </td>
                    </tr>
                </table>
                <input type="hidden" name="prev_transfer_datetime" value="<?php echo htmlspecialchars($prev_transfer_datetime); ?>">
                <input type="hidden" name="prev_from_user_id" value="<?php echo htmlspecialchars($prev_from_user_id); ?>">
                <input type="hidden" name="prev_to_user_id" value="<?php echo htmlspecialchars($prev_to_user_id); ?>">
                <input type="hidden" name="prev_transfer_amount" value="<?php echo htmlspecialchars($prev_transfer_amount); ?>">
                <input type="hidden" name="prev_transfer_description" value="<?php echo htmlspecialchars($prev_transfer_description); ?>">
            </form>
        <?php endif; ?>
    <?php else: ?>
        <h1>Summary</h1>
        <?php
        $vendors = ['1' => 'A']; // Amex only, first letter
        $users = [];
        $user_result = $conn->query("SELECT id, firstname FROM users WHERE id <= 2 AND active = 1");
        while ($user = $user_result->fetch_assoc()) {
            $users[$user['id']] = substr($user['firstname'], 0, 1);
        }

        $balances = array_fill_keys(array_keys($users), array_fill_keys(array_keys($vendors), 0));
        $vendor_totals = array_fill_keys(array_keys($vendors), 0);
        $balance_result = $conn->query("SELECT user_id, vendor_id, SUM(amount) as balance FROM points_register WHERE vendor_id = 1 GROUP BY user_id");
        while ($row = $balance_result->fetch_assoc()) {
            if (isset($balances[$row['user_id']])) {
                $balances[$row['user_id']][$row['vendor_id']] = (int)$row['balance'];
                $vendor_totals[$row['vendor_id']] += (int)$row['balance'];
            }
        }
        ?>
        <table class="summary">
            <tr>
                <th>User</th>
                <?php foreach ($vendors as $vendor_name): ?>
                    <th class="right"><?php echo htmlspecialchars($vendor_name); ?></th>
                <?php endforeach; ?>
            </tr>
            <?php foreach ($users as $user_id => $first_letter): ?>
                <tr>
                    <td class="bold"><?php echo htmlspecialchars($first_letter); ?></td>
                    <?php foreach ($vendors as $vendor_id => $vendor_name): ?>
                        <td class="right"><?php echo number_format($balances[$user_id][$vendor_id], 0, '.', ','); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td class="bold">Total</td>
                <?php foreach ($vendors as $vendor_id => $vendor_name): ?>
                    <td class="right bold"><?php echo number_format($vendor_totals[$vendor_id], 0, '.', ','); ?></td>
                <?php endforeach; ?>
            </tr>
        </table>
        <h1>Login</h1>
        <form method="post">
            <label>Username: <input type="text" name="username" required></label><br>
            <label>Password: <input type="password" name="password" required></label><br>
            <input type="submit" name="login" value="Login">
        </form>
    <?php endif; ?>
</body>
</html>

<?php
$conn->close();
?>
