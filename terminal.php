<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web Terminal Backend
|--------------------------------------------------------------------------
| Features:
| - Persistent current directory using PHP session
| - cd / cd .. / cd ~ / cd -
| - pwd
| - ls / ls -la
| - php artisan ...
| - composer ...
| - git ...
| - JSON response
| - PHP error handling
| - Fatal error handling
| - Command validation
| - Home-directory restriction
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| SESSION
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| PHP 7.4 Compatibility Polyfills
|--------------------------------------------------------------------------
| str_starts_with() / str_ends_with() / str_contains() were added in
| PHP 8.0. These polyfills let this file run unmodified on PHP 7.4
| (ea-php74) while still using the native functions on PHP 8.x.
|--------------------------------------------------------------------------
*/

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}


/*
|--------------------------------------------------------------------------
| JSON Headers
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


/*
|--------------------------------------------------------------------------
| PHP Error Settings
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

error_reporting(E_ALL);


/*
|--------------------------------------------------------------------------
| Output Buffer
|--------------------------------------------------------------------------
*/

while (ob_get_level() > 0) {
    ob_end_clean();
}

ob_start();


/*
|--------------------------------------------------------------------------
| Helper: JSON Response
|--------------------------------------------------------------------------
*/

function sendResponse(
    bool $success,
    string $output = '',
    string $error = '',
    int $code = 0,
    ?string $cwd = null
) {

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $response = [
        'success' => $success,
        'output'  => $output,
        'error'   => $error,
        'code'    => $code,
        'cwd'     => $cwd ?? (getcwd() ?: '')
    ];

    $json = json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'output'  => '',
            'error'   => 'JSON encoding failed: ' . json_last_error_msg(),
            'code'    => 500,
            'cwd'     => ''
        ]);

        exit;
    }

    http_response_code(
        $success ? 200 : (
            $code >= 400 ? $code : 400
        )
    );

    echo $json;

    exit;
}


/*
|--------------------------------------------------------------------------
| PHP Error Handler
|--------------------------------------------------------------------------
*/

set_error_handler(
    function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {

        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);


/*
|--------------------------------------------------------------------------
| Fatal Error Handler
|--------------------------------------------------------------------------
*/

register_shutdown_function(
    function (): void {

        $error = error_get_last();

        if ($error === null) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR
        ];

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'output'  => '',
            'error'   => sprintf(
                "PHP Fatal Error: %s\nFile: %s\nLine: %d",
                $error['message'],
                $error['file'],
                $error['line']
            ),
            'code' => 500,
            'cwd'  => $_SESSION['terminal_cwd'] ?? (getcwd() ?: '')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
);


/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | Request Method
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        sendResponse(
            false,
            '',
            'Invalid request method. POST request required.',
            405
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Get Command
    |--------------------------------------------------------------------------
    */

    $command = trim(
        (string)($_POST['command'] ?? '')
    );


    /*
    |--------------------------------------------------------------------------
    | Empty Command
    |--------------------------------------------------------------------------
    */

    if ($command === '') {

        sendResponse(
            true,
            '',
            '',
            0,
            $_SESSION['terminal_cwd'] ?? getcwd()
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Command Length
    |--------------------------------------------------------------------------
    */

    if (strlen($command) > 1000) {

        sendResponse(
            false,
            '',
            'Command is too long. Maximum 1000 characters allowed.',
            400
        );
    }


    /*
    |--------------------------------------------------------------------------
    | HOME DIRECTORY
    |--------------------------------------------------------------------------
    */

    $homeDirectory = getenv('HOME');

    if (!$homeDirectory) {
        $homeDirectory = dirname(
            dirname(
                dirname(__DIR__)
            )
        );
    }

    $homeDirectory = realpath($homeDirectory);


    if ($homeDirectory === false || !is_dir($homeDirectory)) {

        sendResponse(
            false,
            '',
            'Unable to determine server home directory.',
            500
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Initialize Current Directory
    |--------------------------------------------------------------------------
    */

    if (
        !isset($_SESSION['terminal_cwd']) ||
        !is_dir($_SESSION['terminal_cwd'])
    ) {

        $_SESSION['terminal_cwd'] = getcwd() ?: $homeDirectory;
    }


    /*
    |--------------------------------------------------------------------------
    | Make sure current directory is inside HOME
    |--------------------------------------------------------------------------
    */

    $currentDirectory = realpath(
        $_SESSION['terminal_cwd']
    );


    if (
        $currentDirectory === false ||
        !is_dir($currentDirectory)
    ) {

        $currentDirectory = $homeDirectory;
    }


    /*
    |--------------------------------------------------------------------------
    | Security:
    | Current directory cannot leave HOME
    |--------------------------------------------------------------------------
    */

    if (
        $currentDirectory !== $homeDirectory &&
        strpos(
            $currentDirectory,
            $homeDirectory . DIRECTORY_SEPARATOR
        ) !== 0
    ) {

        $currentDirectory = $homeDirectory;
    }


    $_SESSION['terminal_cwd'] = $currentDirectory;


    /*
    |--------------------------------------------------------------------------
    | HELP
    |--------------------------------------------------------------------------
    */

    if ($command === 'help') {

        $help = <<<TEXT
Available commands:

Navigation:
  pwd
  ls
  ls -la
  cd folder
  cd ..
  cd ../folder
  cd ~
  cd -
  clear

System:
  whoami
  id
  date
  uname -a
  df -h
  free -m

PHP:
  php -v
  which php
  php artisan ...

Composer:
  composer -V
  which composer
  composer install
  composer update
  composer dump-autoload

Git:
  git --version
  git status
  git branch
  git log

Current directory:
  {$currentDirectory}

TEXT;

        sendResponse(
            true,
            $help,
            '',
            0,
            $currentDirectory
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CLEAR
    |--------------------------------------------------------------------------
    */

    if ($command === 'clear') {

        sendResponse(
            true,
            '',
            '',
            0,
            $currentDirectory
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PWD
    |--------------------------------------------------------------------------
    */

    if ($command === 'pwd') {

        sendResponse(
            true,
            $currentDirectory,
            '',
            0,
            $currentDirectory
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CD COMMAND
    |--------------------------------------------------------------------------
    */

    if (
        preg_match(
            '/^cd(?:\s+(.*))?$/',
            $command,
            $matches
        )
    ) {

        $target = trim(
            $matches[1] ?? ''
        );


        /*
        |--------------------------------------------------------------------------
        | Save previous directory
        |--------------------------------------------------------------------------
        */

        $previousDirectory = $currentDirectory;


        /*
        |--------------------------------------------------------------------------
        | cd with no argument
        |--------------------------------------------------------------------------
        */

        if ($target === '' || $target === '~') {

            $targetDirectory = $homeDirectory;

        }

        /*
        |--------------------------------------------------------------------------
        | cd -
        |--------------------------------------------------------------------------
        */

        elseif ($target === '-') {

            $targetDirectory =
                $_SESSION['terminal_previous_cwd']
                ?? $homeDirectory;

        }

        /*
        |--------------------------------------------------------------------------
        | Absolute Path
        |--------------------------------------------------------------------------
        */

        elseif (
            str_starts_with(
                $target,
                DIRECTORY_SEPARATOR
            )
        ) {

            $targetDirectory = $target;

        }

        /*
        |--------------------------------------------------------------------------
        | Relative Path
        |--------------------------------------------------------------------------
        */

        else {

            $targetDirectory =
                $currentDirectory .
                DIRECTORY_SEPARATOR .
                $target;
        }


        /*
        |--------------------------------------------------------------------------
        | Resolve real path
        |--------------------------------------------------------------------------
        */

        $resolvedDirectory = realpath(
            $targetDirectory
        );


        /*
        |--------------------------------------------------------------------------
        | Directory doesn't exist
        |--------------------------------------------------------------------------
        */

        if (
            $resolvedDirectory === false ||
            !is_dir($resolvedDirectory)
        ) {

            sendResponse(
                false,
                '',
                "cd: no such directory: {$target}",
                1,
                $currentDirectory
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Security:
        | Prevent leaving HOME
        |--------------------------------------------------------------------------
        */

        if (
            $resolvedDirectory !== $homeDirectory &&
            strpos(
                $resolvedDirectory,
                $homeDirectory . DIRECTORY_SEPARATOR
            ) !== 0
        ) {

            sendResponse(
                false,
                '',
                'cd: permission denied. You cannot leave the account home directory.',
                1,
                $currentDirectory
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Save previous directory
        |--------------------------------------------------------------------------
        */

        $_SESSION['terminal_previous_cwd'] =
            $previousDirectory;


        /*
        |--------------------------------------------------------------------------
        | Save current directory
        |--------------------------------------------------------------------------
        */

        $_SESSION['terminal_cwd'] =
            $resolvedDirectory;


        /*
        |--------------------------------------------------------------------------
        | Success
        |--------------------------------------------------------------------------
        */

        sendResponse(
            true,
            '',
            '',
            0,
            $resolvedDirectory
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SECURITY:
    | Block shell chaining / injection characters
    |--------------------------------------------------------------------------
    |
    | We don't allow:
    |
    | ;
    | &&
    | ||
    | |
    | >
    | >>
    | <
    | `
    | $()
    |
    |--------------------------------------------------------------------------
    */

    $dangerousPatterns = [
        ';',
        '&&',
        '||',
        '|',
        '>',
        '<',
        '`',
        '$(',
        "\n",
        "\r"
    ];


    foreach ($dangerousPatterns as $dangerous) {

        if (strpos($command, $dangerous) !== false) {

            sendResponse(
                false,
                '',
                'Command contains a blocked shell operator: ' .
                $dangerous,
                403,
                $currentDirectory
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Allowed Commands
    |--------------------------------------------------------------------------
    */

    $allowed = false;


    /*
    |--------------------------------------------------------------------------
    | Simple Commands
    |--------------------------------------------------------------------------
    */

    $simpleCommands = [

        'ls',
        'ls -la',
        'ls -l',
        'ls -lah',

        'whoami',
        'id',
        'date',

        'uname -a',

        'php -v',
        'which php',

        'composer -V',
        'composer --version',
        'which composer',

        'git --version',
        'git version',

        'df -h',
        'free -m'
    ];


    if (in_array($command, $simpleCommands, true)) {

        $allowed = true;
    }


    /*
    |--------------------------------------------------------------------------
    | LS with safe arguments
    |--------------------------------------------------------------------------
    */

    if (
        preg_match(
            '/^ls(?:\s+[-a-zA-Z0-9_\.\/~]+)?$/',
            $command
        )
    ) {

        $allowed = true;
    }


    /*
    |--------------------------------------------------------------------------
    | PHP Artisan
    |--------------------------------------------------------------------------
    */

    if (
        preg_match(
            '/^php\s+artisan(?:\s+[a-zA-Z0-9_\-\.\/:@=,\-]+)*$/',
            $command
        )
    ) {
    
        $allowed = true;
    }


    /*
    |--------------------------------------------------------------------------
    | Composer
    |--------------------------------------------------------------------------
    */

    if (
        preg_match(
            '/^composer\s+[a-zA-Z0-9_\-\.\/]+(?:\s+[a-zA-Z0-9_\-\.\/=:@]+)*$/',
            $command
        )
    ) {

        $allowed = true;
    }


    /*
    |--------------------------------------------------------------------------
    | Git
    |--------------------------------------------------------------------------
    */

    if (
        preg_match(
            '/^git\s+[a-zA-Z0-9_\-\.\/]+(?:\s+[a-zA-Z0-9_\-\.\/=:@]+)*$/',
            $command
        )
    ) {

        $allowed = true;
    }


    /*
    |--------------------------------------------------------------------------
    | Blocked Command
    |--------------------------------------------------------------------------
    */

    if (!$allowed) {

        sendResponse(
            false,
            '',
            "Command not allowed: {$command}\n\nType 'help' to see available commands.",
            403,
            $currentDirectory
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Check exec()
    |--------------------------------------------------------------------------
    */

    if (!function_exists('exec')) {

        sendResponse(
            false,
            '',
            'PHP exec() function is not available on this server.',
            500,
            $currentDirectory
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Check disable_functions
    |--------------------------------------------------------------------------
    */

    $disabledFunctions =
        (string)ini_get('disable_functions');


    if ($disabledFunctions !== '') {

        $disabledList = array_map(
            'trim',
            explode(',', $disabledFunctions)
        );


        if (
            in_array(
                'exec',
                $disabledList,
                true
            )
        ) {

            sendResponse(
                false,
                '',
                'PHP exec() is disabled in disable_functions.',
                500,
                $currentDirectory
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Execute Command
    |--------------------------------------------------------------------------
    */

    $output = [];

    $returnCode = 0;


    /*
    |--------------------------------------------------------------------------
    | Important:
    | Execute command from current session directory
    |--------------------------------------------------------------------------
    */

    $shellCommand =
        'cd ' .
        escapeshellarg($currentDirectory) .
        ' && ' .
        $command .
        ' 2>&1';


    exec(
        $shellCommand,
        $output,
        $returnCode
    );


    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    $result = implode(
        PHP_EOL,
        $output
    );


    /*
    |--------------------------------------------------------------------------
    | Command Failed
    |--------------------------------------------------------------------------
    */

    if ($returnCode !== 0) {

        sendResponse(
            false,
            $result,
            "Command failed with exit code: {$returnCode}",
            $returnCode,
            $currentDirectory
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    sendResponse(
        true,
        $result,
        '',
        0,
        $currentDirectory
    );


} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Catch All Errors
    |--------------------------------------------------------------------------
    */

    sendResponse(
        false,
        '',
        sprintf(
            "%s: %s\nFile: %s\nLine: %d",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ),
        500,
        $_SESSION['terminal_cwd']
            ?? getcwd()
    );
}