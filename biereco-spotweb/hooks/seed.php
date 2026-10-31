<?php

/**
 * First-run / start-up configuration for the Spotweb Umbrel app.
 *
 * This script is mounted into the Spotweb container and executed by the
 * compose entrypoint before the upstream /entrypoint.sh:
 *
 *   1. writes /config/dbsettings.inc.php from the SPOTWEB_DB_* env vars
 *   2. waits until MariaDB accepts connections
 *   3. upgrades the schema and default settings (same sequence as
 *      bin/upgrade-db.php)
 *   4. applies the optional SPOTWEB_NNTP_* and SPOTWEB_ADMIN_* values
 *
 * Everything is idempotent. NNTP/admin values are only applied when they
 * changed since the last run, so values edited inside Spotweb itself are not
 * overwritten on every restart.
 */

declare(strict_types=1);

const SEED_ROOT = '/var/www/spotweb';
const SEED_CONFIG = '/config';
const SEED_STATE_FILE = SEED_CONFIG.'/.biereco-seed-state.json';
const SEED_WAIT_SECONDS = 120;

function seed_log(string $message): void
{
    fwrite(STDOUT, '[biereco-seed] '.$message.PHP_EOL);
}

function seed_env(string $name): string
{
    $value = getenv($name);

    return $value === false ? '' : trim($value);
}

/**
 * Writes the Spotweb database configuration, mirroring the upstream entrypoint.
 */
function seed_write_db_settings(): void
{
    $engine = seed_env('SPOTWEB_DB_TYPE');
    $host = seed_env('SPOTWEB_DB_HOST');
    $name = seed_env('SPOTWEB_DB_NAME');
    $user = seed_env('SPOTWEB_DB_USER');
    $pass = seed_env('SPOTWEB_DB_PASS');
    $port = seed_env('SPOTWEB_DB_PORT');

    if ($engine === '' || $host === '' || $name === '' || $user === '') {
        return;
    }

    $php = "<?php\n";
    $php .= '$dbsettings[\'engine\'] = '.var_export($engine, true).";\n";
    $php .= '$dbsettings[\'host\'] = '.var_export($host, true).";\n";
    $php .= '$dbsettings[\'dbname\'] = '.var_export($name, true).";\n";
    $php .= '$dbsettings[\'user\'] = '.var_export($user, true).";\n";
    if ($pass !== '') {
        $php .= '$dbsettings[\'pass\'] = '.var_export($pass, true).";\n";
    }
    if ($port !== '') {
        $php .= '$dbsettings[\'port\'] = '.var_export($port, true).";\n";
    }

    if (!is_dir(SEED_CONFIG)) {
        @mkdir(SEED_CONFIG, 0775, true);
    }

    if (@file_put_contents(SEED_CONFIG.'/dbsettings.inc.php', $php) === false) {
        seed_log('Could not write /config/dbsettings.inc.php');
    }
}

/**
 * Makes /config/dbsettings.inc.php and /config/ownsettings.php available to
 * Spotweb's CLI bootstrap. The upstream entrypoint repeats this, but it runs
 * after us.
 */
function seed_link_config(): void
{
    if (!is_file(SEED_CONFIG.'/dbsettings.inc.php')) {
        return;
    }

    if (!is_file(SEED_CONFIG.'/ownsettings.php')) {
        if (is_file(SEED_ROOT.'/ownsettings.php')) {
            @copy(SEED_ROOT.'/ownsettings.php', SEED_CONFIG.'/ownsettings.php');
        } else {
            @touch(SEED_CONFIG.'/ownsettings.php');
        }
    }

    $files = [
        SEED_CONFIG.'/dbsettings.inc.php' => SEED_ROOT.'/dbsettings.inc.php',
        SEED_CONFIG.'/ownsettings.php' => SEED_ROOT.'/ownsettings.php',
    ];

    foreach ($files as $target => $link) {
        if (is_link($link) || file_exists($link)) {
            @unlink($link);
        }
        if (!@symlink($target, $link)) {
            @copy($target, $link);
        }
    }
}

/**
 * Blocks until the configured database accepts connections.
 */
function seed_wait_for_database(): bool
{
    $engine = seed_env('SPOTWEB_DB_TYPE');
    if (strpos($engine, 'mysql') === false) {
        return true;
    }

    $host = seed_env('SPOTWEB_DB_HOST');
    $name = seed_env('SPOTWEB_DB_NAME');
    $user = seed_env('SPOTWEB_DB_USER');
    $pass = seed_env('SPOTWEB_DB_PASS');
    $port = seed_env('SPOTWEB_DB_PORT');
    if ($port === '') {
        $port = '3306';
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $deadline = time() + SEED_WAIT_SECONDS;

    do {
        try {
            new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            return true;
        } catch (PDOException $exception) {
            if (time() >= $deadline) {
                seed_log('Database is not reachable: '.$exception->getMessage());

                return false;
            }
            sleep(2);
        }
    } while (true);
}

/**
 * Maps the SPOTWEB_NNTP_ENCRYPTION value to Spotweb's encoding setting.
 *
 * @return string|false
 */
function seed_nntp_encryption(string $value)
{
    switch (strtolower($value)) {
        case 'none':
        case 'off':
        case 'false':
            return false;
        case 'starttls':
            return 'starttls';
        case 'ssl':
        case 'tls':
        case 'on':
        case 'true':
        default:
            return 'ssl';
    }
}

function seed_read_state(): array
{
    if (!is_file(SEED_STATE_FILE)) {
        return [];
    }

    $state = json_decode((string) @file_get_contents(SEED_STATE_FILE), true);

    return is_array($state) ? $state : [];
}

function seed_write_state(array $state): void
{
    @file_put_contents(SEED_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

function seed_apply_nntp_servers(Services_Settings_Container $settings, array $state): array
{
    $host = seed_env('SPOTWEB_NNTP_HOST');
    if ($host === '') {
        return $state;
    }

    $port = (int) (seed_env('SPOTWEB_NNTP_PORT') ?: '563');
    $user = seed_env('SPOTWEB_NNTP_USER');
    $pass = seed_env('SPOTWEB_NNTP_PASS');
    $encryption = seed_nntp_encryption(seed_env('SPOTWEB_NNTP_ENCRYPTION'));

    $fingerprint = hash('sha256', json_encode([$host, $port, $user, $pass, $encryption]) ?: '');
    if (($state['nntp'] ?? '') === $fingerprint) {
        return $state;
    }

    $server = Services_Nntp_PipelineDepth::normalizeServer([
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
        'enc' => $encryption,
        'buggy' => false,
        'verifyname' => true,
        'article_pipeline_depth' => Services_Nntp_PipelineDepth::DefaultDepth,
    ]);

    foreach (['nntp_nzb', 'nntp_hdr', 'nntp_post'] as $name) {
        $settings->set($name, $server);
    }

    $state['nntp'] = $fingerprint;
    seed_log(sprintf('Applied Usenet server settings for %s:%d (user: %s)', $host, $port, $user === '' ? 'none' : $user));

    return $state;
}

function seed_apply_admin(Services_Settings_Container $settings, Dao_Factory $daoFactory, array $state): array
{
    $password = seed_env('SPOTWEB_ADMIN_PASS');
    if ($password === '') {
        return $state;
    }

    $username = seed_env('SPOTWEB_ADMIN_USER');
    $fingerprint = hash('sha256', $username."\0".$password);
    if (($state['admin'] ?? '') === $fingerprint) {
        return $state;
    }

    $userDao = $daoFactory->getUserDao();
    $adminId = (int) $settings->get('custom_admin_userid');
    if ($adminId <= 0) {
        $adminId = SPOTWEB_ADMIN_USERID;
    }
    $admin = $userDao->getUser($adminId);
    if ($admin === false) {
        seed_log('Admin user does not exist yet, skipping credential update');

        return $state;
    }

    if ($username !== '' && $username !== $admin['username']) {
        if (strlen($username) < 3) {
            seed_log('Ignoring admin username shorter than 3 characters');
        } elseif (!empty($userDao->findUserIdForName($username))) {
            seed_log('Ignoring admin username "'.$username.'": the name is already in use');
        } else {
            $daoFactory->getConnection()->modify(
                'UPDATE users SET username = :username WHERE id = :userid',
                [
                    ':username' => [$username, PDO::PARAM_STR],
                    ':userid' => [$admin['userid'], PDO::PARAM_INT],
                ]
            );
            seed_log('Updated admin username to "'.$username.'"');
        }
    }

    $admin['passhash'] = Services_User_Util::passToHash($settings->get('pass_salt'), $password);
    $userDao->setUserPassword($admin);
    seed_log('Updated the admin password');

    $state['admin'] = $fingerprint;

    return $state;
}

function seed_run(): void
{
    seed_write_db_settings();
    seed_link_config();

    if (!is_file(SEED_CONFIG.'/dbsettings.inc.php')) {
        seed_log('No database settings available, skipping configuration');

        return;
    }

    if (!seed_wait_for_database()) {
        return;
    }

    require_once SEED_ROOT.'/vendor/autoload.php';

    $bootstrap = new Bootstrap();
    $daoFactory = $bootstrap->getDaoFactory();
    $dbSettings = $bootstrap->getDbSettings();

    // The settings table must exist before the DB-backed settings source can be
    // loaded. On a fresh install the schema does not exist yet, so create it
    // first and only then bootstrap the settings container.
    $dbStruct = SpotStruct_abs::factory($dbSettings['engine'], $daoFactory->getConnection());
    $dbStruct->updateSchema();

    $settings = $bootstrap->getSettings($daoFactory, false);

    $upgrade = new Services_Upgrade_Base($daoFactory, $settings, $dbSettings['engine']);
    // Settings come before users: the admin password hash depends on the
    // pass_salt that is created by the settings upgrade.
    $upgrade->settings();
    $upgrade->users();
    $upgrade->usenetState();

    $state = seed_read_state();
    $state = seed_apply_nntp_servers($settings, $state);
    $state = seed_apply_admin($settings, $daoFactory, $state);
    seed_write_state($state);
}

try {
    seed_run();
} catch (Throwable $exception) {
    seed_log('Configuration failed: '.$exception->getMessage());
}

exit(0);
