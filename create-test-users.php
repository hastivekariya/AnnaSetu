
<?php

require_once __DIR__ . '/includes/init.php';

// Test accounts for development only.
$users = [
    [
        'name' => 'Test Worker',
        'username' => 'worker1',
        'mobile' => '9876500001',
        'password' => 'Worker@123',
        'role' => 'WORKER'
    ],
    [
        'name' => 'Test Helper',
        'username' => 'helper1',
        'mobile' => '9876500002',
        'password' => 'Helper@123',
        'role' => 'HELPER'
    ],
    [
        'name' => 'Test Supervisor',
        'username' => 'supervisor1',
        'mobile' => '9876500003',
        'password' => 'Super@123',
        'role' => 'SUPERVISOR'
    ],
    [
        'name' => 'Test CDPO',
        'username' => 'cdpo1',
        'mobile' => '9876500004',
        'password' => 'Cdpo@123',
        'role' => 'CDPO'
    ],
    [
        'name' => 'Test Admin',
        'username' => 'admin1',
        'mobile' => '9876500005',
        'password' => 'Admin@123',
        'role' => 'ADMIN'
    ]
];

try {
    foreach ($users as $user) {

        // Find the role ID.
        $roleQuery = $pdo->prepare(
            "SELECT role_id FROM roles WHERE role_name = :role LIMIT 1"
        );

        $roleQuery->execute(['role' => $user['role']]);
        $role = $roleQuery->fetch();

        if (!$role) {
            echo "Role not found: " . htmlspecialchars($user['role']) . "<br>";
            continue;
        }

        // Generate a secure password hash.
        $passwordHash = password_hash(
            $user['password'],
            PASSWORD_DEFAULT
        );

        // Check whether this username already exists.
        $check = $pdo->prepare(
            "SELECT user_id FROM users WHERE username = :username LIMIT 1"
        );

        $check->execute(['username' => $user['username']]);
        $existingUser = $check->fetch();

        if ($existingUser) {

            // Update the existing test account.
            $sql = "UPDATE users
                    SET full_name = :full_name,
                        password_hash = :password_hash,
                        role_id = :role_id,
                        preferred_language = 'gu',
                        is_active = 1
                    WHERE user_id = :user_id";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                'full_name' => $user['name'],
                'password_hash' => $passwordHash,
                'role_id' => $role['role_id'],
                'user_id' => $existingUser['user_id']
            ]);

            echo htmlspecialchars($user['username']) . " updated successfully.<br>";

        } else {

            // Create a new test account.
            $sql = "INSERT INTO users
                    (
                        role_id,
                        full_name,
                        username,
                        mobile_number,
                        password_hash,
                        preferred_language,
                        is_active
                    )
                    VALUES
                    (
                        :role_id,
                        :full_name,
                        :username,
                        :mobile_number,
                        :password_hash,
                        'gu',
                        1
                    )";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                'role_id' => $role['role_id'],
                'full_name' => $user['name'],
                'username' => $user['username'],
                'mobile_number' => $user['mobile'],
                'password_hash' => $passwordHash
            ]);

            echo htmlspecialchars($user['username']) . " created successfully.<br>";
        }
    }

    echo "<h3>Test account setup completed!</h3>";

} catch (PDOException $e) {
    error_log('Test account setup failed: ' . $e->getMessage());
    echo "Setup failed. Check your PHP error log and database schema.";
}
