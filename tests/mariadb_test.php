<?php
/** Integration test: use only a disposable MariaDB server, never a production account. */
declare(strict_types=1);
require dirname(__DIR__) . '/import.php';

// Runtime compatibility regressions must fail CI, not merely print a warning.
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_ALL);

$dsn = getenv('MYSQL_TEST_DSN');
$password = getenv('MYSQL_TEST_PASSWORD');
if (!$dsn || $password === false) {
    fwrite(STDERR, "Set MYSQL_TEST_DSN (without dbname) and MYSQL_TEST_PASSWORD for a disposable MariaDB server.\n");
    exit(2);
}
$admin = new PDO($dsn, getenv('MYSQL_TEST_USER') ?: 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'importer_test_' . bin2hex(random_bytes(6));
$user = 'imp_' . bin2hex(random_bytes(6));
$secret = bin2hex(random_bytes(16));
$databaseCreated = false;
$userCreated = false;
try {
    $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");
    $databaseCreated = true;
    $admin->exec("USE `{$name}`");
    $admin->exec("CREATE USER '{$user}'@'%' IDENTIFIED BY '{$secret}'");
    $userCreated = true;
    $admin->exec(<<<'SQL'
        CREATE TABLE ps_customer (
            id_customer INT PRIMARY KEY, id_shop INT, email VARCHAR(255), firstname VARCHAR(255),
            lastname VARCHAR(255), is_guest INT, id_default_group INT, newsletter INT, active INT,
            deleted INT, passwd VARCHAR(255) DEFAULT 'not-for-importer'
        ) ENGINE=InnoDB;
        CREATE TABLE ps_customer_group (id_customer INT, id_group INT) ENGINE=InnoDB;
        CREATE TABLE ps_group_lang (id_group INT, id_lang INT, name VARCHAR(255)) ENGINE=InnoDB;
        CREATE TABLE ps_emailsubscription (id INT PRIMARY KEY, id_shop INT, email VARCHAR(255), active INT) ENGINE=InnoDB;
        INSERT INTO ps_group_lang VALUES (2,1,'Guest'),(3,1,'Customer'),(7,1,'Wholesale'),(3,2,'Zákazník');
        INSERT INTO ps_customer (id_customer,id_shop,email,firstname,lastname,is_guest,id_default_group,newsletter,active,deleted) VALUES
            (1,1,'jane@example.test','Old','Account',0,2,1,1,0),
            (2,1,' JANE@example.test ',' Jane ',' Novák ',0,3,1,1,0),
            (3,1,'jane@example.test','Newer','Guest',1,2,1,1,0),
            (4,1,'guest@example.test','Guest','Buyer',1,9,1,1,0),
            (5,1,'invalid-email','Bad','Email',0,3,1,1,0),
            (6,2,'other-shop@example.test','Other','Shop',0,3,1,1,0),
            (7,1,'unsubscribed@example.test','No','Consent',0,3,0,1,0),
            (8,1,'inactive@example.test','Not','Active',0,3,1,0,0),
            (9,1,'deleted@example.test','Was','Deleted',0,3,1,1,1);
        INSERT INTO ps_customer_group VALUES (1,2),(2,3),(2,7),(3,2),(5,3),(5,7);
        INSERT INTO ps_emailsubscription VALUES
            (1,1,'homepage@example.test',1),(2,1,'jane@example.test',1),
            (3,1,'unconfirmed@example.test',0),(4,2,'other-newsletter@example.test',1),
            (5,1,'invalid-email',1);
        SQL);
    foreach ([
        'ps_customer' => 'id_customer,id_shop,email,firstname,lastname,is_guest,id_default_group,newsletter,active,deleted',
        'ps_customer_group' => 'id_customer,id_group',
        'ps_group_lang' => 'id_group,id_lang,name',
        'ps_emailsubscription' => 'id,id_shop,email,active',
    ] as $table => $columns) {
        $admin->exec("GRANT SELECT ({$columns}) ON `{$name}`.`{$table}` TO '{$user}'@'%'");
    }
    $sourceDsn = $dsn . ';dbname=' . $name;
    $restricted = new PDO($sourceDsn, $user, $secret, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (["UPDATE ps_customer SET newsletter=0 WHERE id_customer=2", 'SELECT passwd FROM ps_customer'] as $forbidden) {
        try {
            $restricted->exec($forbidden);
        } catch (PDOException $e) {
            if (in_array($e->errorInfo[1] ?? null, [1142, 1143], true)) {
                continue;
            }
            throw $e;
        }
        throw new RuntimeException('Restricted account allowed a forbidden operation.');
    }
    [$contacts, $invalid] = PrestaShopMautic\readSubscribers([
        'dsn' => $sourceDsn, 'username' => $user, 'password' => $secret,
        'table_prefix' => 'ps_', 'shop_id' => 1, 'language_id' => 1,
    ], static function (string $level, string $message, array $context = []): void {});
    $expected = [
        'guest@example.test' => [
            'email' => 'guest@example.test', 'firstname' => 'Guest', 'lastname' => 'Buyer',
            'type' => 'guest', 'rank' => [0, 4], 'customer_id' => 4,
            'default_group_id' => 9, 'default_group_name' => '', 'groups' => [],
        ],
        'homepage@example.test' => [
            'email' => 'homepage@example.test', 'firstname' => '', 'lastname' => '',
            'type' => 'newsletter_only', 'rank' => [-1, 0], 'customer_id' => null,
            'default_group_id' => 0, 'default_group_name' => '', 'groups' => [],
        ],
        'jane@example.test' => [
            'email' => 'jane@example.test', 'firstname' => 'Jane', 'lastname' => 'Novák',
            'type' => 'customer', 'rank' => [1, 2], 'customer_id' => 2,
            'default_group_id' => 3, 'default_group_name' => 'Customer', 'groups' => [3 => 'Customer', 7 => 'Wholesale'],
        ],
    ];
    if ($contacts !== $expected || $invalid !== 2) {
        throw new RuntimeException('Unexpected extraction: ' . var_export([$contacts, $invalid], true));
    }
    echo "PASS MariaDB extraction: complete contact map, merging, invalid-record count and restricted grants\n";
} finally {
    $restricted = null;
    if ($userCreated) {
        $admin->exec("DROP USER '{$user}'@'%'");
    }
    if ($databaseCreated) {
        $admin->exec("DROP DATABASE `{$name}`");
    }
}
