<?php

declare(strict_types=1);

$capturePath = $argv[1] ?? null;
$acceptMessage = ($argv[2] ?? null) === 'accept';

if (! is_string($capturePath) || $capturePath === '') {
    exit(64);
}

$errorCode = 0;
$errorMessage = '';
$server = stream_socket_server(
    'tcp://127.0.0.1:0',
    $errorCode,
    $errorMessage,
);

if ($server === false) {
    fwrite(
        STDERR,
        "Unable to start SMTP test server [{$errorCode}]: {$errorMessage}\n",
    );

    exit(1);
}

$address = stream_socket_get_name($server, false);
$separator = is_string($address) ? strrpos($address, ':') : false;

if ($separator === false) {
    fclose($server);

    exit(1);
}

fwrite(STDOUT, substr($address, $separator + 1)."\n");
fflush(STDOUT);
$connection = stream_socket_accept($server, 10);

if ($connection === false) {
    fclose($server);

    exit(1);
}

fwrite($connection, "220 localhost ESMTP NVL test server\r\n");
$receivingData = false;
$message = '';
$deliveryExitCode = null;

while (($line = fgets($connection)) !== false) {
    $command = rtrim($line, "\r\n");

    if ($receivingData) {
        if ($command === '.') {
            file_put_contents($capturePath, $message);
            fwrite(
                $connection,
                $acceptMessage
                    ? "250 2.0.0 accepted\r\n"
                    : "550 5.0.0 rejected\r\n",
            );
            $receivingData = false;
            $deliveryExitCode = $acceptMessage ? 0 : 2;

            break;
        }

        $message .= str_starts_with($line, '..')
            ? substr($line, 1)
            : $line;

        continue;
    }

    if (str_starts_with(strtoupper($command), 'EHLO ')) {
        fwrite($connection, "250-localhost\r\n250 8BITMIME\r\n");

        continue;
    }

    if (strtoupper($command) === 'DATA') {
        $receivingData = true;
        fwrite($connection, "354 End data with <CR><LF>.<CR><LF>\r\n");

        continue;
    }

    if (strtoupper($command) === 'QUIT') {
        fwrite($connection, "221 2.0.0 Bye\r\n");
        fclose($connection);
        fclose($server);

        exit(1);
    }

    fwrite($connection, "250 2.0.0 OK\r\n");
}

fclose($connection);
fclose($server);

exit($deliveryExitCode ?? 1);
