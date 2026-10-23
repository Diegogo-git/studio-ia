<?php
declare(strict_types=1);
// STUDIO_IA_CHAT_CONTROLLER_V1
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('STUDIO_CHAT_ENDPOINT', 'worker');
require __DIR__.'/index.php';
