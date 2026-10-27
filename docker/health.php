<?php

// Healthcheck untuk compose: memastikan Laravel benar-benar menjawab, bukan
// hanya proses PHP yang hidup.
$port = getenv('PORT') ?: 8000;
$headers = @get_headers('http://127.0.0.1:'.$port.'/up');

exit(is_array($headers) && str_contains($headers[0] ?? '', '200') ? 0 : 1);
