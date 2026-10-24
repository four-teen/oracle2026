<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

// Directors currently use the campus-scoped analytics dashboard.
$researchWorkspaceRole = Auth::ROLE_DIRECTOR;
require dirname(__DIR__) . '/coordinator/index.php';
