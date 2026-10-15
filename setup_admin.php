<?php
declare(strict_types=1);

/*
 * One-time admin setup. Run on the server from this folder:
 *     php setup_admin.php
 * Creates config.php with the admin ID and a password hash (the password itself is never stored).
 * Run it again at any time to change the ID or password.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function ask(string $prompt, bool $hidden = false): string
{
    echo $prompt;
    if ($hidden) {
        @shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hidden) {
        @shell_exec('stty echo');
        echo "\n";
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$file = __DIR__ . '/config.php';
if (is_file($file) && strtolower(ask('An admin account already exists. Replace it? (yes/no): ')) !== 'yes') {
    echo "Nothing changed.\n";
    exit(0);
}

$id = trim(ask('Admin ID: '));
if (strlen($id) < 3) {
    fwrite(STDERR, "Admin ID must be at least 3 characters.\n");
    exit(1);
}

$pw = ask('Password (min 8 characters): ', true);
if (strlen($pw) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
if ($pw !== ask('Confirm password: ', true)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$config = ['admin_id' => $id, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)];
$php = "<?php\nreturn " . var_export($config, true) . ";\n";
if (file_put_contents($file, $php, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write config.php.\n");
    exit(1);
}
@chmod($file, 0640);

if (!is_dir(__DIR__ . '/data')) {
    @mkdir(__DIR__ . '/data', 0770, true);
}

echo "Admin account saved to config.php.\n";
echo "Make sure the web server user can read config.php and write to the data/ folder, for example:\n";
echo "    sudo chgrp www-data config.php data && sudo chmod 640 config.php && sudo chmod 770 data\n";
