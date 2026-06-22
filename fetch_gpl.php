<?php
$opts = [
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0\r\n"
    ]
];
$context = stream_context_create($opts);
$gpl = file_get_contents('https://www.gnu.org/licenses/gpl-3.0.txt', false, $context);
if ($gpl) file_put_contents('gpl.txt', $gpl);
