<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/db.php';
$pdo->exec(file_get_contents(__DIR__.'/schema.sql'));
echo "PrivacyLib consent table ready.\n";
