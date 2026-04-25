<?php
require __DIR__ . '/_auth/bootstrap_session.php';
require __DIR__ . '/_auth/auth.php';
portal_require_login();
readfile(__DIR__ . '/_auth/portal_index.html');
