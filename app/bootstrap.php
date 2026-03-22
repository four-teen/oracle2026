<?php

declare(strict_types=1);

require_once __DIR__ . '/Env.php';

Env::load(dirname(__DIR__) . '/.env');

require_once __DIR__ . '/helpers.php';

start_secure_session();

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TitleSimilarity.php';
require_once __DIR__ . '/GoogleOAuth.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AdminPage.php';
